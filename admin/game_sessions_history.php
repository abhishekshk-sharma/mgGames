<?php
// game_sessions_history.php - CORRECTED VERSION
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

$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code_result = $stmt->get_result();
$referral_code_data = $referral_code_result->fetch_assoc();
$referral_code = $referral_code_data['referral_code'];

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

// Date filtering setup
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'custom';

// Set dates based on quick filters
if ($date_filter != 'custom') {
    switch ($date_filter) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d');
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            break;
    }
}

// Additional filters
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';
$filter_result = isset($_GET['filter_result']) ? $_GET['filter_result'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for game sessions count
$count_sql = "SELECT COUNT(DISTINCT CONCAT(g.name, '_', DATE(gs.session_date))) as total 
              FROM game_sessions gs
              JOIN games g ON gs.game_id = g.id
              WHERE DATE(gs.session_date) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types = 'ss';

if ($filter_game) {
    $count_sql .= " AND g.name LIKE ?";
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $count_sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $count_sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$stmt_count = $conn->prepare($count_sql);
if ($params) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Build main query for game sessions with aggregated bet data
$sql = "SELECT 
            g.name as game_name,
            DATE(gs.session_date) as session_date,
            g.open_time,
            g.close_time,
            gs.open_result,
            gs.close_result,
            gs.jodi_result,
            gs.id as session_id,
            g.id as game_id,
            COUNT(u.id) as total_bets,
            SUM(b.amount) as total_bet_amount,
            SUM(CASE WHEN b.status = 'won' THEN b.potential_win ELSE 0 END) as total_payout,
            SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as won_bets,
            SUM(CASE WHEN b.status = 'lost' THEN 1 ELSE 0 END) as lost_bets,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets,
            MAX(b.placed_at) as last_bet_time
        FROM game_sessions gs
        JOIN games g ON gs.game_id = g.id
        LEFT JOIN bets b ON gs.id = b.game_session_id
        JOIN users u ON b.user_id = u.id
        WHERE DATE(gs.session_date) BETWEEN ? AND ? AND u.referral_code = ?";

if ($filter_game) {
    $sql .= " AND g.name LIKE ?";
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$sql .= " GROUP BY g.name, DATE(gs.session_date), g.open_time, g.close_time, gs.open_result, gs.close_result, gs.jodi_result, gs.id, g.id
          ORDER BY gs.session_date DESC, g.open_time DESC
          LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [$start_date, $end_date, $referral_code];
$types = 'sss';

if ($filter_game) {
    $params[] = "%$filter_game%";
    $types .= 's';
}

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$game_sessions = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $game_sessions[] = $row;
    }
}

// Get detailed bet data for each session to calculate CORRECTED statistics
$adjusted_sessions = [];

// Pre-fetch game types payout ratios for efficiency
$game_type_ratios = [];
$stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
$stmt_game_types->execute();
$game_types_result = $stmt_game_types->get_result();
while ($game_type_row = $game_types_result->fetch_assoc()) {
    $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
}

foreach ($game_sessions as $session) {
    $session_id = $session['session_id'];
    
    // Get all bets for this session to calculate adjusted amounts
    $bets_sql = "SELECT b.*, u.username 
                 FROM bets b 
                 JOIN users u ON b.user_id = u.id 
                 WHERE b.game_session_id = ? AND u.referral_code = ?";
    $stmt_bets = $conn->prepare($bets_sql);
    $stmt_bets->bind_param('is', $session_id, $referral_code);
    $stmt_bets->execute();
    $bets_result = $stmt_bets->get_result();
    
    $admin_actual_bet_amount = 0;  // Total amount admin actually risked (after forwarding)
    $admin_actual_payout = 0;      // Total payout admin actually paid for won bets (after forwarding)
    $forwarded_total = 0;          // Total forwarded to super admin
    
    if ($bets_result && $bets_result->num_rows > 0) {
        while ($bet = $bets_result->fetch_assoc()) {
            $bet_amount = $bet['amount'];
            $bet_potential_win = $bet['potential_win'];
            $bet_status = $bet['status'];
            $game_type_id = $bet['game_type_id'];
            
            // Get the payout ratio for this game type
            $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00; // Default to 9 if not found
            
            // Calculate automatic forwarding based on bet_limit/pnl_ratio
            $bet_admin_amount = $bet_amount;
            $bet_forwarded_amount = 0;
            
            $bet_numbers = json_decode($bet['numbers_played'], true);
            
            if (is_array($bet_numbers)) {
                if (isset($bet_numbers['selected_digits'])) {
                    // For SP Motor
                    if (isset($bet_numbers['pana_combinations'])) {
                        $amount_per_pana = $bet_numbers['amount_per_pana'] ?? 0;
                        foreach ($bet_numbers['pana_combinations'] as $pana) {
                            if ($pnl_ratio) {
                                // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                                $forwarded = ($amount_per_pana * $forward_ratio) / 100;
                            } else {
                                // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                                $forwarded = max(0, $amount_per_pana - $bet_limit);
                            }
                            
                            $bet_admin_amount -= $forwarded;
                            $bet_forwarded_amount += $forwarded;
                        }
                    }
                } else {
                    // For single number bets
                    foreach ($bet_numbers as $number => $amount) {
                        if ($pnl_ratio) {
                            // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                            $forwarded = ($amount * $forward_ratio) / 100;
                        } else {
                            // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                            $forwarded = max(0, $amount - $bet_limit);
                        }
                        
                        $bet_admin_amount -= $forwarded;
                        $bet_forwarded_amount += $forwarded;
                    }
                }
            }
            
            // Update admin's actual bet amount (exposure)
            $admin_actual_bet_amount += $bet_admin_amount;
            $forwarded_total += $bet_forwarded_amount;
            
            // Calculate admin's actual payout for WON bets with proper payout ratio
            if ($bet_status == 'won') {
                if ($bet_amount > 0) {
                    $admin_payout_ratio_amount = $bet_admin_amount / $bet_amount;
                    $admin_win_amount = $bet_amount * $admin_payout_ratio_amount;
                    $admin_payout = $admin_win_amount * $payout_ratio;
                    
                    $admin_actual_payout += $admin_payout;
                }
            }
        }
    } else {
        // If no bets, use original values
        $admin_actual_bet_amount = $session['total_bet_amount'];
        $admin_actual_payout = $session['total_payout'];
    }
    
    // CORRECTED P&L Calculation:
    // Your P&L = (Your Actual Bet Amount - Your Actual Payout)
    // Positive = Profit, Negative = Loss
    $profit_loss = $admin_actual_bet_amount - $admin_actual_payout;
    
    // Add adjusted values to session data
    $session['adjusted_total_exposure'] = $admin_actual_bet_amount;  // What admin actually risked
    $session['adjusted_total_payout'] = $admin_actual_payout;       // What admin actually paid out
    $session['forwarded_total'] = $forwarded_total;
    $session['profit_loss'] = $profit_loss;                         // Net profit/loss for admin
    
    $adjusted_sessions[] = $session;
}

// Replace original sessions with adjusted sessions
$game_sessions = $adjusted_sessions;

// Get overall stats for dashboard (using adjusted amounts)
$stats_sql = "SELECT 
    COUNT(DISTINCT CONCAT(g.name, '_', DATE(gs.session_date))) as total_sessions,
    SUM(bets_data.total_bets) as total_bets
    FROM game_sessions gs
    JOIN games g ON gs.game_id = g.id
    LEFT JOIN (
        SELECT 
            user_id,
            game_session_id,
            COUNT(u.id) as total_bets
        FROM bets b JOIN users u ON b.user_id = u.id WHERE u.referral_code = ?
        GROUP BY game_session_id
    ) bets_data ON gs.id = bets_data.game_session_id
    JOIN users u ON bets_data.user_id = u.id
    WHERE DATE(gs.session_date) BETWEEN ? AND ? AND u.referral_code = ?";

$stats_params = [$referral_code, $start_date, $end_date, $referral_code];
$stats_types = 'ssss';

if ($filter_game) {
    $stats_sql .= " AND g.name LIKE ?";
    $stats_params[] = "%$filter_game%";
    $stats_types .= 's';
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $stats_sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $stats_sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$stmt_stats = $conn->prepare($stats_sql);
if ($stats_params) {
    $stmt_stats->bind_param($stats_types, ...$stats_params);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();

// Calculate adjusted overall stats from individual sessions
$total_sessions = $stats['total_sessions'] ? $stats['total_sessions'] : 0;
$total_bets = $stats['total_bets'] ? $stats['total_bets'] : 0;

$total_exposure = 0;      // Total amount admin actually risked
$total_payout = 0;        // Total payout admin actually paid
$total_forwarded = 0;     // Total forwarded to super admin
$total_profit_loss = 0;   // Net profit/loss for admin

foreach ($game_sessions as $session) {
    $total_exposure += $session['adjusted_total_exposure'];
    $total_payout += $session['adjusted_total_payout'];
    $total_forwarded += $session['forwarded_total'];
    $total_profit_loss += $session['profit_loss'];
}

// Overall profit/loss is already calculated in total_payout
$total_profit_loss = $total_payout;

// Get unique games for filter dropdown
$games_sql = "SELECT DISTINCT name FROM games ORDER BY name";
$games_result = $conn->query($games_sql);
$games = [];
if ($games_result && $games_result->num_rows > 0) {
    while ($row = $games_result->fetch_assoc()) {
        $games[] = $row['name'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Sessions History - RB Games Admin</title>
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
        /* ... (keep all your existing CSS styles) ... */

        /* Add new styles for adjusted statistics */
        .scenario-indicator {
            background: rgba(11, 180, 201, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid rgba(11, 180, 201, 0.2);
        }

        .forward-badge {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            margin-top: 0.5rem;
            text-align: center;
            font-size: 0.9rem;
        }

        .stat-card.mini {
            padding: 0.8rem;
            margin: 0.5rem 0;
        }

        .stat-card.mini .stat-card-value {
            font-size: 1.2rem;
        }

        .stat-card.mini .stat-card-title {
            font-size: 0.8rem;
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
                <a href="game_sessions_history.php" class="menu-item active">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
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
                <a href="admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
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
                    <h1>Game Sessions History</h1>
                    <p>View and analyze game sessions with detailed statistics</p>
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
            <div class="scenario-indicator">
                <h4><i class="fas fa-cog"></i> Current Configuration</h4>
                <?php if ($pnl_ratio): ?>
                    <p><strong>PNL Ratio Mode:</strong> <?php echo $pnl_ratio; ?> (Admin:<?php echo $admin_ratio; ?>% | Forward:<?php echo $forward_ratio; ?>%)</p>
                    <p>Profit/Loss sharing enabled. Your share: <?php echo $admin_ratio; ?>%</p>
                <?php else: ?>
                    <p><strong>Bet Limit Mode:</strong> ₹<?php echo number_format($bet_limit); ?> per number</p>
                    <p>Individual number bets capped at limit. Excess amounts forwarded.</p>
                <?php endif; ?>
            </div>

            <!-- Date Range Display -->
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                Showing game sessions from <span><?php echo date('M j, Y', strtotime($start_date)); ?></span> to <span><?php echo date('M j, Y', strtotime($end_date)); ?></span>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon sessions-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_sessions); ?></div>
                    <div class="stat-card-title">Total Sessions</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon bets-icon">
                        <i class="fas fa-dice"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_bets); ?></div>
                    <div class="stat-card-title">Total Bets</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon amount-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-card-value">₹<?php echo number_format($total_exposure, 2); ?></div>
                    <div class="stat-card-title">Your Actual Exposure</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon payout-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-card-value">₹<?php echo number_format($total_payout, 2); ?></div>
                    <div class="stat-card-title">Your Net P&L</div> <!-- Changed label -->
                </div>

                <!-- Forwarded Amount Card -->
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: rgba(255, 193, 7, 0.2); color: #ffc107;">
                        <i class="fas fa-share-alt"></i>
                    </div>
                    <div class="stat-card-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                    <div class="stat-card-title">Forwarded Amount</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-filter"></i> Filter Game Sessions</h3>
                
                <!-- Quick Date Filters -->
                <div class="quick-filters">
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'today' ? 'active' : ''; ?>" data-filter="today">Today</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'yesterday' ? 'active' : ''; ?>" data-filter="yesterday">Yesterday</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'this_week' ? 'active' : ''; ?>" data-filter="this_week">This Week</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'last_week' ? 'active' : ''; ?>" data-filter="last_week">Last Week</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'this_month' ? 'active' : ''; ?>" data-filter="this_month">This Month</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'last_month' ? 'active' : ''; ?>" data-filter="last_month">Last Month</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'custom' ? 'active' : ''; ?>" data-filter="custom">Custom</button>
                </div>

                <form method="GET" id="filterForm">
                    <input type="hidden" name="date_filter" id="dateFilter" value="<?php echo $date_filter; ?>">
                    <input type="hidden" name="page" value="1">
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Start Date</label>
                            <input type="date" name="start_date" class="filter-control" value="<?php echo $start_date; ?>" 
                                   <?php echo $date_filter != 'custom' ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">End Date</label>
                            <input type="date" name="end_date" class="filter-control" value="<?php echo $end_date; ?>" 
                                   <?php echo $date_filter != 'custom' ? 'readonly' : ''; ?>>
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
                            <label class="filter-label">Results Status</label>
                            <select name="filter_result" class="filter-control">
                                <option value="">All Sessions</option>
                                <option value="has_results" <?php echo $filter_result == 'has_results' ? 'selected' : ''; ?>>With Results</option>
                                <option value="no_results" <?php echo $filter_result == 'no_results' ? 'selected' : ''; ?>>Without Results</option>
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
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="game_sessions_history.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Game Sessions List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Game Sessions</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> sessions</div>
                </div>
                
                <?php if (!empty($game_sessions)): ?>
                    <div class="sessions-list">
                        <?php foreach ($game_sessions as $session): 
                            $profit_loss = $session['profit_loss'];
                            $profit_loss_class = $profit_loss > 0 ? 'profit' : ($profit_loss < 0 ? 'loss' : 'neutral');
                        ?>
                            <div class="session-card">
                                <div class="session-header">
                                    <div class="session-title">
                                        <?php echo htmlspecialchars($session['game_name']); ?>
                                    </div>
                                    <div class="session-date">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M j, Y', strtotime($session['session_date'])); ?>
                                    </div>
                                </div>
                                
                                <div class="session-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Time Slot</span>
                                        <span class="detail-value">
                                            <?php echo date('h:i A', strtotime($session['open_time'])); ?> - 
                                            <?php echo date('h:i A', strtotime($session['close_time'])); ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Session ID</span>
                                        <span class="detail-value">#<?php echo $session['session_id']; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Last Bet Time</span>
                                        <span class="detail-value">
                                            <?php echo $session['last_bet_time'] ? date('h:i A', strtotime($session['last_bet_time'])) : 'No bets'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($session['open_result'] || $session['close_result'] || $session['jodi_result']): ?>
                                    <div class="session-results">
                                        <?php if ($session['open_result']): ?>
                                            <div class="result-badge result-open">
                                                <i class="fas fa-sun"></i>
                                                Open: <?php echo $session['open_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['close_result']): ?>
                                            <div class="result-badge result-close">
                                                <i class="fas fa-moon"></i>
                                                Close: <?php echo $session['close_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['jodi_result']): ?>
                                            <div class="result-badge result-jodi">
                                                <i class="fas fa-link"></i>
                                                Jodi: <?php echo $session['jodi_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="session-results">
                                        <div class="result-badge" style="background: rgba(108, 117, 125, 0.2); color: #6c757d; border: 1px solid rgba(108, 117, 125, 0.3);">
                                            <i class="fas fa-clock"></i>
                                            Results Pending
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Adjusted Session Statistics -->
                                <div class="session-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['total_bets']); ?></div>
                                        <div class="stat-label">Total Bets</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">₹<?php echo number_format($session['adjusted_total_exposure'], 2); ?></div>
                                        <div class="stat-label">Your Exposure</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">₹<?php echo number_format($session['adjusted_total_payout'], 2); ?></div>
                                        <div class="stat-label">Your Payout</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value <?php echo $profit_loss_class; ?>">
                                            ₹<?php echo number_format(abs($session['profit_loss']), 2); ?>
                                            <?php if ($session['profit_loss'] > 0): ?>
                                                <i class="fas fa-arrow-up"></i>
                                            <?php elseif ($session['profit_loss'] < 0): ?>
                                                <i class="fas fa-arrow-down"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Your P&L</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['won_bets']); ?></div>
                                        <div class="stat-label">Won Bets</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['lost_bets']); ?></div>
                                        <div class="stat-label">Lost Bets</div>
                                    </div>
                                </div>

                                <!-- Forwarding Information -->
                                <?php if ($session['forwarded_total'] > 0): ?>
                                    <div class="forward-badge">
                                        <i class="fas fa-share-alt"></i>
                                        <strong>Forwarded Amount:</strong> ₹<?php echo number_format($session['forwarded_total'], 2); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="session-actions">
                                    <a href="game_session_detail.php?session_id=<?php echo $session['session_id']; ?>&game_id=<?php echo $session['game_id']; ?>&date=<?php echo $session['session_date']; ?>" 
                                       class="btn btn-info">
                                        <i class="fas fa-chart-bar"></i>
                                        View Detailed Analytics
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Game Sessions Found</h3>
                        <p>No game sessions match your current filter criteria.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
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
        // Mobile menu functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function updateMenuTextVisibility() {
            const menuSpans = document.querySelectorAll('.menu-item span');
            
            if (window.innerWidth >= 993) {
                // Large screens - always show text
                menuSpans.forEach(span => {
                    span.style.display = 'inline-block';
                });
            } else if (window.innerWidth >= 769) {
                // Medium screens - hide text (icons only)
                menuSpans.forEach(span => {
                    span.style.display = 'none';
                });
            } else {
                // Small screens - show text only when sidebar is active
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

        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    updateMenuTextVisibility();
                }
            });
        });

        function handleResize() {
            if (window.innerWidth >= 993) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                menuToggle.style.display = 'none';
            } else if (window.innerWidth >= 769) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                menuToggle.style.display = 'none';
            } else {
                menuToggle.style.display = 'block';
            }
            
            updateMenuTextVisibility();
        }

        // Initialize
        handleResize();
        window.addEventListener('resize', handleResize);

        // Quick filter buttons
        document.querySelectorAll('.quick-filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                
                // Update active state
                document.querySelectorAll('.quick-filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Submit form
                document.getElementById('filterForm').submit();
            });
        });

        // Make date inputs readonly for non-custom filters
        const dateFilter = document.getElementById('dateFilter');
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');

        function updateDateInputs() {
            if (dateFilter.value === 'custom') {
                startDateInput.removeAttribute('readonly');
                endDateInput.removeAttribute('readonly');
            } else {
                startDateInput.setAttribute('readonly', 'readonly');
                endDateInput.setAttribute('readonly', 'readonly');
            }
        }

        updateDateInputs();
        dateFilter.addEventListener('change', updateDateInputs);
    </script>
</body>
</html>