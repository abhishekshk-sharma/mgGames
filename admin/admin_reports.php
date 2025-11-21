<?php
// admin_reports.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit;
}

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code_result = $stmt->get_result();
$referral_code_data = $referral_code_result->fetch_assoc();
$referral_code = $referral_code_data['referral_code'];

if($referral_code_data['status'] == 'suspend'){
    header("location: dashboard.php");
    exit;   
}
                            
                   

// Get admin bet limits and PNL ratio
$limit_stmt = $conn->prepare("SELECT bet_limit, pnl_ratio FROM broker_limit WHERE admin_id = ?");
$limit_stmt->execute([$admin_id]);
$limit_result = $limit_stmt->get_result();
$admin_limits = $limit_result->fetch_assoc();

$bet_limit = $admin_limits['bet_limit'] ?? 100;
$pnl_ratio = $admin_limits['pnl_ratio'];

// Parse PNL ratio if set
$admin_ratio = 0;
$forward_ratio = 0;
if ($pnl_ratio && strpos($pnl_ratio, ':') !== false) {
    $ratio_parts = explode(':', $pnl_ratio);
    $admin_ratio = intval($ratio_parts[0]);
    $forward_ratio = intval($ratio_parts[1]);
}

// Date filtering setup - Flexible date range
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Validate dates to ensure they're not in the future
$today = date('Y-m-d');
if ($start_date > $today) {
    $start_date = $today;
}
if ($end_date > $today) {
    $end_date = $today;
}

// Ensure end date is not before start date
if ($end_date < $start_date) {
    $end_date = $start_date;
}

// Additional filters
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Calculate overall monthly statistics
$overall_stats_sql = "SELECT 
    COUNT(DISTINCT gs.id) as total_sessions,
    COUNT(DISTINCT b.id) as total_bets,
    SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as won_bets,
    SUM(CASE WHEN b.status = 'lost' THEN 1 ELSE 0 END) as lost_bets,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets
FROM game_sessions gs
JOIN bets b ON gs.id = b.game_session_id
JOIN users u ON b.user_id = u.id
WHERE DATE(gs.session_date) BETWEEN ? AND ? 
AND u.referral_code = ?";

$overall_params = [$start_date, $end_date, $referral_code];
$overall_stmt = $conn->prepare($overall_stats_sql);
$overall_stmt->bind_param('sss', ...$overall_params);
$overall_stmt->execute();
$overall_result = $overall_stmt->get_result();
$overall_stats = $overall_result->fetch_assoc();

// Pre-fetch game types payout ratios for efficiency
$game_type_ratios = [];
$stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
$stmt_game_types->execute();
$game_types_result = $stmt_game_types->get_result();
while ($game_type_row = $game_types_result->fetch_assoc()) {
    $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
}

// Calculate financial statistics with forwarding logic - FIXED JOIN CONDITIONS
$financial_sql = "SELECT 
    gs.id as session_id,
    g.name as game_name,
    b.id as bet_id,
    b.amount as bet_amount,
    b.potential_win as potential_win,
    b.status as bet_status,
    b.numbers_played,
    b.game_type_id,
    b.bet_mode
FROM game_sessions gs
JOIN games g ON gs.game_id = g.id  -- FIXED: Changed g.game_id to g.id
JOIN bets b ON gs.id = b.game_session_id
JOIN users u ON b.user_id = u.id
WHERE DATE(gs.session_date) BETWEEN ? AND ? 
AND u.referral_code = ?";

// Apply game filter if set
if ($filter_game) {
    $financial_sql .= " AND g.name LIKE ?";
}

$financial_params = [$start_date, $end_date, $referral_code];
if ($filter_game) {
    $financial_params[] = "%$filter_game%";
}

$financial_stmt = $conn->prepare($financial_sql);
if ($filter_game) {
    $financial_stmt->bind_param('ssss', ...$financial_params);
} else {
    $financial_stmt->bind_param('sss', ...$financial_params);
}
$financial_stmt->execute();
$financial_result = $financial_stmt->get_result();

$total_bet_amount = 0;
$total_payout = 0;
$total_forwarded = 0;
$total_actual_exposure = 0;
$total_actual_payout = 0;
$game_stats = [];

while ($row = $financial_result->fetch_assoc()) {
    $game_name = $row['game_name'];
    $bet_amount = $row['bet_amount'];
    $potential_win = $row['potential_win'];
    $bet_status = $row['bet_status'];
    $numbers_played = json_decode($row['numbers_played'], true);
    $game_type_id = $row['game_type_id'];
    
    // Get the payout ratio for this game type
    $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00; // Default to 9 if not found
    
    // Calculate forwarding based on admin configuration
    $admin_amount = $bet_amount;
    $forwarded_amount = 0;
    
    if (is_array($numbers_played)) {
        if (isset($numbers_played['selected_digits'])) {
            // For SP Motor type bets
            if (isset($numbers_played['pana_combinations'])) {
                $amount_per_pana = $numbers_played['amount_per_pana'] ?? 0;
                foreach ($numbers_played['pana_combinations'] as $pana) {
                    if ($pnl_ratio) {
                        // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                        $forwarded = ($amount_per_pana * $forward_ratio) / 100;
                    } else {
                        // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                        $forwarded = max(0, $amount_per_pana - $bet_limit);
                    }
                    $admin_amount -= $forwarded;
                    $forwarded_amount += $forwarded;
                }
            }
        } else {
            // For regular number bets
            foreach ($numbers_played as $number => $amount) {
                if ($pnl_ratio) {
                    // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                    $forwarded = ($amount * $forward_ratio) / 100;
                } else {
                    // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                    $forwarded = max(0, $amount - $bet_limit);
                }
                $admin_amount -= $forwarded;
                $forwarded_amount += $forwarded;
            }
        }
    }
    
    // Calculate actual payout for won bets with proper payout ratio
    $actual_payout = 0;
    if ($bet_status == 'won') {
        if ($bet_amount > 0) {
            $admin_payout_ratio_amount = $admin_amount / $bet_amount;
            $admin_win_amount = $bet_amount * $admin_payout_ratio_amount;
            $actual_payout = $admin_win_amount * $payout_ratio;
        }
    }
    
    // Update overall totals
    $total_bet_amount += $bet_amount;
    $total_payout += $potential_win;
    $total_forwarded += $forwarded_amount;
    $total_actual_exposure += $admin_amount;
    $total_actual_payout += $actual_payout;
    
    // Update game-specific statistics
    if (!isset($game_stats[$game_name])) {
        $game_stats[$game_name] = [
            'total_bets' => 0,
            'total_bet_amount' => 0,
            'total_payout' => 0,
            'total_forwarded' => 0,
            'total_actual_exposure' => 0,
            'total_actual_payout' => 0,
            'won_bets' => 0,
            'lost_bets' => 0,
            'pending_bets' => 0
        ];
    }
    
    $game_stats[$game_name]['total_bets']++;
    $game_stats[$game_name]['total_bet_amount'] += $bet_amount;
    $game_stats[$game_name]['total_payout'] += $potential_win;
    $game_stats[$game_name]['total_forwarded'] += $forwarded_amount;
    $game_stats[$game_name]['total_actual_exposure'] += $admin_amount;
    $game_stats[$game_name]['total_actual_payout'] += $actual_payout;
    
    if ($bet_status == 'won') {
        $game_stats[$game_name]['won_bets']++;
    } elseif ($bet_status == 'lost') {
        $game_stats[$game_name]['lost_bets']++;
    } else {
        $game_stats[$game_name]['pending_bets']++;
    }
}

// Calculate overall profit/loss
$total_profit_loss = $total_actual_exposure - $total_actual_payout;

// Apply game filter to game stats if needed
if ($filter_game) {
    $filtered_game_stats = [];
    foreach ($game_stats as $game_name => $stats) {
        if (stripos($game_name, $filter_game) !== false) {
            $filtered_game_stats[$game_name] = $stats;
        }
    }
    $game_stats = $filtered_game_stats;
}

// Build query for paginated game stats
$game_stats_paginated = array_slice($game_stats, $offset, $limit);
$total_records = count($game_stats);
$total_pages = ceil($total_records / $limit);

// Get unique games for filter dropdown
$games_sql = "SELECT DISTINCT name FROM games ORDER BY name";
$games_result = $conn->query($games_sql);
$games = [];
if ($games_result && $games_result->num_rows > 0) {
    while ($row = $games_result->fetch_assoc()) {
        $games[] = $row['name'];
    }
}

// Generate month options for dropdown
$month_options = [];
for ($i = -6; $i <= 0; $i++) {
    $month = date('Y-m', strtotime("$i months"));
    $month_options[$month] = date('F Y', strtotime($month));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports - RB Games Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9;
            --accent: #00cec9;
            --dark: #1a1a2e;
            --darker: #16213e;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --text-light: #f5f6fa;
            --text-muted: rgba(255, 255, 255, 0.7);
            --card-bg: rgba(26, 26, 46, 0.8);
            --border-color: rgba(255, 60, 126, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--dark) 0%, var(--darker) 100%);
            color: var(--text-light);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: var(--dark);
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            transition: all 0.3s ease;
            overflow-y: auto;
            left: 0;
            top: 0;
        }
        .sidebar::-webkit-scrollbar{
            display:none;
        }
        .sidebar.active {
            left: 0;
        }

        /* Mobile menu toggle */
        .menu-toggle {
                display: none;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            z-index: 1001;
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: var(--card-bg);
            border-radius: 6px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
            transition: all 0.3s ease;
            min-height: 100vh;
            width: calc(100% - 260px);
        }

        .sidebar-header {
            padding: 1.8rem 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            padding: 1.5rem 0;
            flex-grow: 1;
        }

        .menu-item {
            padding: 1rem 1.8rem;
            display: flex;
            align-items: center;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
            margin: 0.3rem 0.8rem;
            border-radius: 8px;
        }

        .menu-item:hover, .menu-item.active {
            background: linear-gradient(to right, rgba(255, 60, 126, 0.2), rgba(11, 180, 201, 0.2));
            border-left: 4px solid var(--primary);
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 1.2rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
            width: calc(100% - 260px);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.2rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .welcome h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .welcome p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }

        .logout-btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.6rem 1.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            box-shadow: 0 4px 10px rgba(255, 60, 126, 0.3);
            text-decoration: none;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 60, 126, 0.4);
        }

        /* Dashboard Sections */
        .dashboard-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
            overflow: hidden;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .view-all:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .sessions-icon {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
        }

        .bets-icon {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }

        .amount-icon {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }

        .payout-icon {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
        }

        .stat-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            background: linear-gradient(to right, var(--text-light), var(--text-muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2.2rem;
            border: 1px solid var(--border-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .filter-control {
            width: 100%;
            padding: 0.7rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .filter-control option{
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        /* border-radius: 6px; */
        color: var(--text-light);
        }

        .quick-filters {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .quick-filter-btn {
            padding: 0.6rem 1.2rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .quick-filter-btn.active {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-color: var(--primary);
            color: white;
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.7rem 1.3rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 60, 126, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-info {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
            text-decoration: none;
        }

        .btn-info:hover {
            background: rgba(11, 180, 201, 0.3);
        }

        /* Game Session Cards */
        .session-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .session-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border-color: var(--primary);
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .session-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .session-date {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .session-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.3rem;
        }

        .detail-value {
            font-weight: 500;
        }

        .session-results {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .result-badge {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-open {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .result-close {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .result-jodi {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        .session-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.8rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .session-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        /* Admin badge and time */
        .admin-badge {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 500;
            border: 1px solid rgba(255, 60, 126, 0.3);
            white-space: nowrap;
        }

        .admin-badge i {
            color: var(--primary);
        }

        .current-time {
            background: rgba(11, 180, 201, 0.2);
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 500;
            border: 1px solid rgba(11, 180, 201, 0.3);
            white-space: nowrap;
        }

        .current-time i {
            color: var(--secondary);
        }

        /* Mobile menu toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            z-index: 1001;
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: var(--card-bg);
            border-radius: 6px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            min-width: 40px;
        }

        .pagination a:hover {
            background: linear-gradient(to right, rgba(255, 60, 126, 0.2), rgba(11, 180, 201, 0.2));
            border-color: var(--primary);
        }

        .pagination .current {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border-color: var(--primary);
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Date range display */
        .date-range {
            background: rgba(11, 180, 201, 0.1);
            padding: 0.8rem 1.2rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(11, 180, 201, 0.2);
            text-align: center;
        }

        .date-range span {
            font-weight: 600;
            color: var(--secondary);
        }

        /* No results state */
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1.5rem;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 993px) {

            .sidebar {
                width: 260px;
                left: 0;
                position: fixed;
            }
            .main-content {
                margin-left: 260px;
                width: calc(100% - 260px);
            }
            .menu-toggle {
                display: none;
            }
            .sidebar-overlay {
                display: none !important;
            }
            
            .sidebar-header h2 {
                font-size: 1.2rem;
            }           
            
            .sidebar-footer {
                padding: 0.8rem;
            }
            
            .main-content {
                margin-left: 80px;
                padding: 1.5rem;
                width: calc(100% - 80px);
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.2rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .session-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .session-actions {
                width: 100%;
                justify-content: center;
            }
        }

        /* Medium screens (769px - 992px) */
        @media (max-width: 992px) and (min-width: 769px) {
            .sidebar {
                width: 10%;
                left: 0;
            }
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
                padding: 1.5rem;
            }
            .menu-toggle {
                display: none;
            }
            .sidebar-header h2 {
                font-size: 1.2rem;
            }
            
            .menu-item {
                justify-content: center;
                padding: 1rem;
            }
            
            .sidebar-footer {
                padding: 0.8rem;
            }
            /* Hide menu text on medium screens (icons only) */
            .menu-item span {
                display: none !important;
            }
            
        }

        @media (max-width: 768px) {

            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            .menu-item{
                width :100%;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
            .menu-toggle {
                display: block;
            }
            .header {
                margin-top: 4rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .header-actions {
                flex-direction: column;
                width: 100%;
                gap: 0.8rem;
            }
            .admin-badge, .logout-btn {
                width: 100%;
                justify-content: center;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .dashboard-section {
                padding: 1rem;
            }
            
            .session-details, .session-stats {
                grid-template-columns: 1fr;
            }
            
            .session-results {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-filters {
                justify-content: center;
            }
            
            .filter-actions {
                justify-content: center;
            }
            /* Show menu text when sidebar is active on small screens */
            .sidebar.active .menu-item span {
                display: inline-block !important;
                margin-left: 12px;
            }
            
            /* Hide menu text when sidebar is inactive on small screens */
            .sidebar:not(.active) .menu-item span {
                display: none !important;
            }
        }

        @media (max-width: 576px) {

            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                padding: 1rem;
            }
            .menu-toggle {
                display: block;
            }
            
            
            /* Ensure menu text is visible when sidebar is active */
            .sidebar.active .menu-item span {
                display: inline-block !important;
            }
            
            .sidebar:not(.active) .menu-item span {
                display: none !important;
            }
            .sidebar {
                width: 0;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                width: 260px;
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            
            .header {
                margin-top: 3rem;
            }
            
            .welcome h1 {
                font-size: 1.5rem;
            }
            
            .dashboard-section {
                padding: 0.8rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card-value {
                font-size: 1.6rem;
            }
            
            .session-card {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .dashboard-section {
                padding: 0.7rem;
                border-radius: 8px;
            }
            
            .header {
                margin-bottom: 1.5rem;
            }
            
            .welcome h1 {
                font-size: 1.3rem;
            }
            
            .welcome p {
                font-size: 0.9rem;
            }
            
            .section-title {
                font-size: 1.1rem;
            }
            
            .btn {
                padding: 0.5rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .session-card {
                padding: 0.8rem;
            }
            
            .admin-badge, .current-time, .logout-btn {
                font-size: 0.9rem;
                padding: 0.5rem 1rem;
            }
            
            .quick-filters {
                flex-direction: column;
            }
            
            .quick-filter-btn {
                width: 100%;
                text-align: center;
            }
        }

        /* Ultra small devices (400px and below) */
        @media (max-width: 400px) {
            .main-content {
                padding: 0.8rem;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .welcome h1 {
                font-size: 1.3rem;
            }
            .welcome p {
                font-size: 0.9rem;
            }
            .header-actions {
                flex-direction: column;
                width: 100%;
                gap: 0.8rem;
            }
            .admin-badge, .current-time {
                width: 100%;
                justify-content: center;
            }
            .logout-btn {
                width: 100%;
                justify-content: center;
            }
            .menu-toggle {
                top: 0.8rem;
                left: 0.8rem;
            }
            
            /* Ensure menu text is visible when sidebar is active */
            .sidebar.active .menu-item span {
                display: inline-block !important;
            }
            
            .sidebar:not(.active) .menu-item span {
                display: none !important;
            }
        }

        /* Utility classes */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .mt-1 { margin-top: 0.5rem; }
        .mt-2 { margin-top: 1rem; }
        .mt-3 { margin-top: 1.5rem; }
        .mb-1 { margin-bottom: 0.5rem; }
        .mb-2 { margin-bottom: 1rem; }
        .mb-3 { margin-bottom: 1.5rem; }
        .p-1 { padding: 0.5rem; }
        .p-2 { padding: 1rem; }
        .p-3 { padding: 1.5rem; }

        /* Profit/Loss indicators */
        .profit {
            color: var(--success);
        }

        .loss {
            color: var(--danger);
        }

        .neutral {
            color: var(--text-muted);
        }
    </style>

    <style>
        /* Include all CSS styles from game_sessions_history.php */
        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9;
            --accent: #00cec9;
            --dark: #1a1a2e;
            --darker: #16213e;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --text-light: #f5f6fa;
            --text-muted: rgba(255, 255, 255, 0.7);
            --card-bg: rgba(26, 26, 46, 0.8);
            --border-color: rgba(255, 60, 126, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--dark) 0%, var(--darker) 100%);
            color: var(--text-light);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Include all other CSS styles from game_sessions_history.php */
        /* ... (copy all CSS styles from game_sessions_history.php) ... */

        /* Additional styles for reports */
        .profit-positive {
            color: var(--success);
            font-weight: bold;
        }

        .profit-negative {
            color: var(--danger);
            font-weight: bold;
        }

        .profit-neutral {
            color: var(--warning);
            font-weight: bold;
        }

        .summary-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .summary-item {
            text-align: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .summary-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .configuration-badge {
            background: rgba(11, 180, 201, 0.2);
            padding: 0.8rem 1.2rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid rgba(11, 180, 201, 0.3);
            text-align: center;
        }

        .game-stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .game-stats-table th,
        .game-stats-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .game-stats-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--text-light);
        }

        .game-stats-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .month-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .month-selector label {
            font-weight: 500;
            color: var(--text-light);
        }

        .export-btn {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .export-btn:hover {
            background: rgba(0, 184, 148, 0.3);
        }

        /* Horizontal Statistics Bar Styles */
        .stats-bar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .stats-bar-item {
            text-align: center;
            padding: 1.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stats-bar-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border-color: var(--primary);
        }

        .stats-bar-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
        }

        .stats-bar-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .stats-bar-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            background: linear-gradient(to right, var(--text-light), var(--text-muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stats-bar-description {
            font-size: 0.8rem;
            color: var(--text-muted);
            opacity: 0.8;
        }

        /* Make the forwarded amount stand out */
        .stats-bar-item:nth-child(3) .stats-bar-value {
            color: #ffc107 !important;
            -webkit-text-fill-color: #ffc107;
        }

        /* Make profit/loss stand out */
        .stats-bar-item:nth-child(5) .stats-bar-value {
            font-size: 1.6rem;
            font-weight: 800;
        }

        /* Responsive design for stats bar */
        @media (max-width: 768px) {
            .stats-bar-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .stats-bar-item {
                padding: 1rem 0.8rem;
            }
            
            .stats-bar-value {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 480px) {
            .stats-bar-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Quick Date Buttons */
        .quick-date-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .quick-date-btn {
            padding: 0.5rem 0.8rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .quick-date-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
            transform: translateY(-1px);
        }

        .quick-date-btn:active {
            transform: translateY(0);
        }

        /* Date input styles */
        .filter-control[type="date"] {
            cursor: pointer;
        }

        .filter-control[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }

        /* Responsive design for date buttons */
        @media (max-width: 768px) {
            .quick-date-buttons {
                flex-direction: column;
            }
            
            .quick-date-btn {
                width: 100%;
                text-align: center;
            }
        }


    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games</h2>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="edit_result.php" class="menu-item">
                    <i class="fas fa-flag-checkered"></i>
                    <span>Set Game Results</span>
                </a>
                <a href="all_users_history.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>All Users Bet History</span>
                </a>
                <a href="admin_transactions.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Transactions</span>
                </a>
                <a href="admin_withdrawals.php" class="menu-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Withdrawals</span>
                </a>
                <a href="admin_deposits.php" class="menu-item">
                    <i class="fas fa-money-bill"></i>
                    <span>Deposits</span>
                </a>
                <a href="applications.php" class="menu-item">
                    <i class="fas fa-tasks"></i>
                    <span>Applications</span>
                </a>
                <a href="admin_reports.php" class="menu-item active">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="admin_profile.php" class="menu-item ">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </div>
            <div class="sidebar-footer">
                <div class="admin-info">
                    <p>Logged in as <strong><?php echo $admin_username; ?></strong></p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Admin Reports</h1>
                    <p>Comprehensive financial reports and analytics</p>
                </div>
                <div class="header-actions">
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo $admin_username; ?></span>
                    </div>
                    <a href="admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <!-- Configuration Indicator -->
            <div class="configuration-badge">
                <h4><i class="fas fa-cog"></i> Current Configuration</h4>
                <?php if ($pnl_ratio): ?>
                    <p><strong>PNL Ratio Mode:</strong> <?php echo $pnl_ratio; ?> (Admin:<?php echo $admin_ratio; ?>% | Forward:<?php echo $forward_ratio; ?>%)</p>
                <?php else: ?>
                    <p><strong>Bet Limit Mode:</strong> ₹<?php echo number_format($bet_limit); ?> per number</p>
                <?php endif; ?>
            </div>

            <!-- Date Range Selection -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-calendar"></i> Select Date Range</h3>
                <form method="GET" id="reportForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="filter-control" 
                                value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); ?>"
                                max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="filter-control" 
                                value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t'); ?>"
                                max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Quick Date Ranges</label>
                            <div class="quick-date-buttons">
                                <button type="button" class="quick-date-btn" data-days="7">Last 7 Days</button>
                                <button type="button" class="quick-date-btn" data-days="30">Last 30 Days</button>
                                <button type="button" class="quick-date-btn" data-days="90">Last 90 Days</button>
                                <button type="button" class="quick-date-btn" data-type="month">This Month</button>
                                <button type="button" class="quick-date-btn" data-type="last_month">Last Month</button>
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Game</label>
                            <select name="filter_game" class="filter-control">
                                <option value="">All Games</option>
                                <?php foreach ($games as $game): ?>
                                    <option value="<?php echo htmlspecialchars($game); ?>" <?php echo $filter_game == $game ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($game); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Records per page</label>
                            <select name="limit" class="filter-control">
                                <?php foreach ($allowed_limits as $allowed_limit): ?>
                                    <option value="<?php echo $allowed_limit; ?>" <?php echo $limit == $allowed_limit ? 'selected' : ''; ?>>
                                        <?php echo $allowed_limit; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Generate Report
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetDateRange()">
                            <i class="fas fa-redo"></i> Reset to Current Month
                        </button>
                    </div>
                    
                    <input type="hidden" name="page" value="1">
                </form>
            </div>

            <!-- Monthly Summary -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Report Summary - <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></h2>
                    <a href="#" class="btn btn-info export-btn" onclick="exportToExcel()">
                        <i class="fas fa-file-export"></i> Export to Excel
                    </a>
                </div>

                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['total_bets']); ?></div>
                        <div class="summary-label">Total Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['won_bets']); ?></div>
                        <div class="summary-label">Won Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['lost_bets']); ?></div>
                        <div class="summary-label">Lost Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['pending_bets']); ?></div>
                        <div class="summary-label">Pending Bets</div>
                    </div>
                </div>

                <div class="summary-grid mt-3">
                    <div class="summary-item">
                        <div class="summary-value">₹<?php echo number_format($total_actual_exposure, 2); ?></div>
                        <div class="summary-label">Your Actual Exposure</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value">₹<?php echo number_format($total_actual_payout, 2); ?></div>
                        <div class="summary-label">Your Actual Payout</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value <?php echo $total_profit_loss > 0 ? 'profit-positive' : ($total_profit_loss < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                            ₹<?php echo number_format(abs($total_profit_loss), 2); ?>
                            <?php if ($total_profit_loss > 0): ?>
                                <i class="fas fa-arrow-up"></i>
                            <?php elseif ($total_profit_loss < 0): ?>
                                <i class="fas fa-arrow-down"></i>
                            <?php endif; ?>
                        </div>
                        <div class="summary-label">Your Net Profit/Loss</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                        <div class="summary-label">Forwarded to Super Admin</div>
                    </div>
                </div>
            </div>


            <!-- Horizontal Statistics Bar -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-bar"></i> Financial Overview - <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></h2>
                </div>
                
                <div class="stats-bar-grid">
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Total Bet Amount</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_bet_amount, 2); ?></div>
                        <div class="stats-bar-description">All bets placed by users</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Your Actual Exposure</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_actual_exposure, 2); ?></div>
                        <div class="stats-bar-description">After risk management</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Forwarded Amount</div>
                        <div class="stats-bar-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                        <div class="stats-bar-description">To super admin</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Your Actual Payout</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_actual_payout, 2); ?></div>
                        <div class="stats-bar-description">For won bets</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Net Profit/Loss</div>
                        <div class="stats-bar-value <?php echo $total_profit_loss > 0 ? 'profit-positive' : ($total_profit_loss < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                            ₹<?php echo number_format(abs($total_profit_loss), 2); ?>
                            <?php if ($total_profit_loss > 0): ?>
                                <i class="fas fa-arrow-up"></i>
                            <?php elseif ($total_profit_loss < 0): ?>
                                <i class="fas fa-arrow-down"></i>
                            <?php endif; ?>
                        </div>
                        <div class="stats-bar-description">Your final position</div>
                    </div>
                </div>
            </div>

            <!-- Game-wise Statistics -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-gamepad"></i> Game-wise Statistics</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> games</div>
                </div>

                <?php if (!empty($game_stats_paginated)): ?>
                    <div class="table-responsive">
                        <table class="game-stats-table">
                            <thead>
                                <tr>
                                    <th>Game Name</th>
                                    <th class="text-right">Total Bets</th>
                                    <th class="text-right">Won/Lost/Pending</th>
                                    <th class="text-right">Total Bet Amount</th>
                                    <th class="text-right">Your Exposure</th>
                                    <th class="text-right">Your Payout</th>
                                    <th class="text-right">Net P&L</th>
                                    <th class="text-right">Forwarded</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($game_stats_paginated as $game_name => $stats): 
                                    $game_profit_loss = $stats['total_actual_exposure'] - $stats['total_actual_payout'];
                                    $profit_loss_class = $game_profit_loss > 0 ? 'profit-positive' : ($game_profit_loss < 0 ? 'profit-negative' : 'profit-neutral');
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($game_name); ?></strong></td>
                                        <td class="text-right"><?php echo number_format($stats['total_bets']); ?></td>
                                        <td class="text-right">
                                            <span style="color: var(--success);"><?php echo number_format($stats['won_bets']); ?></span> /
                                            <span style="color: var(--danger);"><?php echo number_format($stats['lost_bets']); ?></span> /
                                            <span style="color: var(--warning);"><?php echo number_format($stats['pending_bets']); ?></span>
                                        </td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_bet_amount'], 2); ?></td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_actual_exposure'], 2); ?></td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_actual_payout'], 2); ?></td>
                                        <td class="text-right <?php echo $profit_loss_class; ?>">
                                            ₹<?php echo number_format(abs($game_profit_loss), 2); ?>
                                            <?php if ($game_profit_loss > 0): ?>
                                                <i class="fas fa-arrow-up"></i>
                                            <?php elseif ($game_profit_loss < 0): ?>
                                                <i class="fas fa-arrow-down"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right" style="color: #ffc107;">₹<?php echo number_format($stats['total_forwarded'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-chart-bar"></i>
                        <h3>No Game Data Found</h3>
                        <p>No game statistics available for the selected month and filters.</p>
                    </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                            <span class="disabled"><i class="fas fa-angle-left"></i></span>
                        <?php endif; ?>
                        
                        <?php
                        // Show page numbers
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-angle-right"></i></span>
                            <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality (same as game_sessions_history.php)
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function updateMenuTextVisibility() {
            const menuSpans = document.querySelectorAll('.menu-item span');
            
            if (window.innerWidth >= 993) {
                menuSpans.forEach(span => {
                    span.style.display = 'inline-block';
                });
            } else if (window.innerWidth >= 769) {
                menuSpans.forEach(span => {
                    span.style.display = 'none';
                });
            } else {
                if (sidebar.classList.contains('active')) {
                    menuSpans.forEach(span => {
                        span.style.display = 'inline-block';
                    });
                } else {
                    menuSpans.forEach(span => {
                        span.style.display = 'none';
                    });
                }
            }
        }

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            updateMenuTextVisibility();
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            updateMenuTextVisibility();
        });

        // Initialize
        updateMenuTextVisibility();
        window.addEventListener('resize', updateMenuTextVisibility);


        // Quick date range functionality
        document.querySelectorAll('.quick-date-btn').forEach(button => {
            button.addEventListener('click', function() {
                const days = this.getAttribute('data-days');
                const type = this.getAttribute('data-type');
                const today = new Date();
                let startDate, endDate;

                if (days) {
                    // For "Last X Days" buttons
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - parseInt(days));
                    endDate = new Date(today);
                } else if (type === 'month') {
                    // This Month
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                } else if (type === 'last_month') {
                    // Last Month
                    startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                }

                // Format dates as YYYY-MM-DD
                const formatDate = (date) => {
                    return date.toISOString().split('T')[0];
                };

                // Set the date inputs
                document.getElementById('startDate').value = formatDate(startDate);
                document.getElementById('endDate').value = formatDate(endDate);

                // Submit the form
                document.getElementById('reportForm').submit();
            });
        });

        // Reset to current month
        function resetDateRange() {
            const today = new Date();
            const startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            const endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            const formatDate = (date) => {
                return date.toISOString().split('T')[0];
            };
            
            document.getElementById('startDate').value = formatDate(startDate);
            document.getElementById('endDate').value = formatDate(endDate);
            document.getElementById('reportForm').submit();
        }

        // Date validation
        document.getElementById('startDate').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDate = new Date(document.getElementById('endDate').value);
            const today = new Date();
            
            // Ensure start date is not in future
            if (startDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            // Ensure end date is not before start date
            if (endDate < startDate) {
                document.getElementById('endDate').value = this.value;
            }
        });

        document.getElementById('endDate').addEventListener('change', function() {
            const endDate = new Date(this.value);
            const startDate = new Date(document.getElementById('startDate').value);
            const today = new Date();
            
            // Ensure end date is not in future
            if (endDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            // Ensure end date is not before start date
            if (endDate < startDate) {
                document.getElementById('startDate').value = this.value;
            }
        });



        // Simple Export to Excel function without currency symbols
function exportToExcel() {



    <?php
        // Log report generation action
            try {
                $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, title, description, created_at) VALUES (?, 'Generated Report', 'Admin generated a report', NOW())");
                $stmt->execute([$admin_id]);
            } catch (Exception $e) {
                // Silently fail if logging doesn't work
                error_log("Failed to log dashboard access: " . $e->getMessage());
            }

    ?>

    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const startFormatted = new Date(startDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const endFormatted = new Date(endDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    
    const filename = `reports_${startDate}_to_${endDate}.csv`;
    
    let csv = '';
    
    // Header Section
    csv += '"RB GAMES - ADMIN REPORTS"\n';
    csv += `"Report Period: ${startFormatted} to ${endFormatted}"\n`;
    csv += `"Generated on: <?php echo date('F j, Y \\a\\t g:i A'); ?>"\n`;
    csv += `"Admin: <?php echo $admin_username; ?>"\n\n`;
    
    // Financial Summary Section
    csv += '"FINANCIAL SUMMARY"\n';
    csv += '"Description","Amount (₹)","Count"\n';
    csv += `"Total Bets","<?php echo number_format($total_bet_amount, 2); ?>","<?php echo number_format($overall_stats['total_bets']); ?>"\n`;
    csv += `"Your Actual Exposure","<?php echo number_format($total_actual_exposure, 2); ?>","-"\n`;
    csv += `"Forwarded to Super Admin","<?php echo number_format($total_forwarded, 2); ?>","-"\n`;
    csv += `"Your Actual Payout","<?php echo number_format($total_actual_payout, 2); ?>","-"\n`;
    csv += `"","",""\n`; // Empty row for spacing
    csv += `"TOTAL PROFIT/LOSS","<?php echo number_format($total_profit_loss, 2); ?>","-"\n`;
    csv += `"Status","<?php echo $total_profit_loss > 0 ? 'PROFIT' : ($total_profit_loss < 0 ? 'LOSS' : 'BREAK-EVEN'); ?>","-"\n\n`;
    
    // Bet Statistics Section
    csv += '"BET STATISTICS"\n';
    csv += '"Description","Count","Percentage"\n';
    csv += `"Total Bets","<?php echo number_format($overall_stats['total_bets']); ?>","100.00%"\n`;
    csv += `"Won Bets","<?php echo number_format($overall_stats['won_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['won_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Lost Bets","<?php echo number_format($overall_stats['lost_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['lost_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Pending Bets","<?php echo number_format($overall_stats['pending_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['pending_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n\n`;
    
    // Game-wise Detailed Statistics
    csv += '"GAME-WISE DETAILED STATISTICS"\n';
    csv += '"Game Name","Total Bets","Won","Lost","Pending","Total Bet Amount (₹)","Your Exposure (₹)","Your Payout (₹)","Net P&L (₹)","Forwarded (₹)"\n';
    
    <?php 
    $grand_total_bets = 0;
    $grand_total_won = 0;
    $grand_total_lost = 0;
    $grand_total_pending = 0;
    $grand_total_bet_amount = 0;
    $grand_total_exposure = 0;
    $grand_total_payout = 0;
    $grand_total_forwarded = 0;
    
    foreach ($game_stats as $game_name => $stats): 
        $game_profit_loss = $stats['total_actual_exposure'] - $stats['total_actual_payout'];
        
        $grand_total_bets += $stats['total_bets'];
        $grand_total_won += $stats['won_bets'];
        $grand_total_lost += $stats['lost_bets'];
        $grand_total_pending += $stats['pending_bets'];
        $grand_total_bet_amount += $stats['total_bet_amount'];
        $grand_total_exposure += $stats['total_actual_exposure'];
        $grand_total_payout += $stats['total_actual_payout'];
        $grand_total_forwarded += $stats['total_forwarded'];
    ?>
        csv += `"<?php echo $game_name; ?>","<?php echo $stats['total_bets']; ?>","<?php echo $stats['won_bets']; ?>","<?php echo $stats['lost_bets']; ?>","<?php echo $stats['pending_bets']; ?>","<?php echo number_format($stats['total_bet_amount'], 2); ?>","<?php echo number_format($stats['total_actual_exposure'], 2); ?>","<?php echo number_format($stats['total_actual_payout'], 2); ?>","<?php echo number_format($game_profit_loss, 2); ?>","<?php echo number_format($stats['total_forwarded'], 2); ?>"\n`;
    <?php endforeach; ?>
    
    // Grand Totals Row
    csv += `"GRAND TOTALS","<?php echo $grand_total_bets; ?>","<?php echo $grand_total_won; ?>","<?php echo $grand_total_lost; ?>","<?php echo $grand_total_pending; ?>","<?php echo number_format($grand_total_bet_amount, 2); ?>","<?php echo number_format($grand_total_exposure, 2); ?>","<?php echo number_format($grand_total_payout, 2); ?>","<?php echo number_format($total_profit_loss, 2); ?>","<?php echo number_format($grand_total_forwarded, 2); ?>"\n\n`;
    
    // Configuration Section
    csv += '"SYSTEM CONFIGURATION"\n';
    csv += '"Setting","Value"\n';
    <?php if ($pnl_ratio): ?>
        csv += `"Risk Management","PNL Ratio (<?php echo $pnl_ratio; ?>)"\n`;
        csv += `"Your Share","<?php echo $admin_ratio; ?>%"\n`;
        csv += `"Forwarded Share","<?php echo $forward_ratio; ?>%"\n`;
    <?php else: ?>
        csv += `"Risk Management","Bet Limit"\n`;
        csv += `"Limit per Number","<?php echo number_format($bet_limit); ?>"\n`;
    <?php endif; ?>
    csv += `"Date Range","${startFormatted} to ${endFormatted}"\n`;
    csv += `"Total Days","<?php echo round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1; ?>"\n`;
    
    // Create and download the file with proper encoding
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv; charset=utf-8' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
        
    </script>
</body>
</html>