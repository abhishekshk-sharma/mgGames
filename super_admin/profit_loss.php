<?php
// profit_loss.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_login.php");
    exit;
}

// Get super admin details
$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

// AJAX request to search admins
if (isset($_GET['ajax']) && $_GET['ajax'] == 'search_admins') {
    $search_term = isset($_GET['search']) ? $_GET['search'] : '';
    
    $admins_stmt = $conn->prepare("SELECT id, username FROM admins WHERE status = 'active' AND username LIKE ? ORDER BY username LIMIT 20");
    $search_param = "%$search_term%";
    $admins_stmt->bind_param('s', $search_param);
    $admins_stmt->execute();
    $admins_result = $admins_stmt->get_result();
    
    $admins = [];
    while ($row = $admins_result->fetch_assoc()) {
        $admins[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($admins);
    exit;
}

// Get selected admins for comparison
$selected_admin_ids = [];
if (isset($_GET['compare_admins']) && is_array($_GET['compare_admins'])) {
    $selected_admin_ids = array_map('intval', $_GET['compare_admins']);
    $selected_admin_ids = array_filter($selected_admin_ids); // Remove empty values
}

// Date filtering setup
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Validate dates
$today = date('Y-m-d');
if ($start_date > $today) {
    $start_date = $today;
}
if ($end_date > $today) {
    $end_date = $today;
}
if ($end_date < $start_date) {
    $end_date = $start_date;
}

// Function to calculate super admin's profit/loss for specific admin(s)
function calculateSuperAdminProfitLoss($conn, $start_date, $end_date, $admin_ids = []) {
    $stats = [
        'total_bets' => 0,
        'total_bet_amount' => 0,
        'total_potential_payout' => 0,
        'total_actual_payout' => 0,
        'total_forwarded_amount' => 0,
        'total_super_admin_share' => 0,
        'total_super_admin_profit_loss' => 0,
        'won_bets' => 0,
        'lost_bets' => 0,
        'pending_bets' => 0,
        'admin_stats' => []
    ];
    
    // Get referral codes for selected admin(s)
    if (!empty($admin_ids)) {
        $placeholders = str_repeat('?,', count($admin_ids) - 1) . '?';
        $ref_stmt = $conn->prepare("SELECT id, username, referral_code FROM admins WHERE id IN ($placeholders) AND status = 'active'");
        $ref_stmt->bind_param(str_repeat('i', count($admin_ids)), ...$admin_ids);
    } else {
        $ref_stmt = $conn->prepare("SELECT id, username, referral_code FROM admins WHERE status = 'active'");
    }
    $ref_stmt->execute();
    $ref_result = $ref_stmt->get_result();
    $admins_data = [];
    $referral_codes = [];
    
    while ($row = $ref_result->fetch_assoc()) {
        $admins_data[$row['id']] = $row;
        $referral_codes[] = $row['referral_code'];
    }
    
    if (empty($referral_codes)) {
        return $stats;
    }
    
    // Get admin bet limits and PNL ratios
    $admin_limits = [];
    if (!empty($admin_ids)) {
        $placeholders = str_repeat('?,', count($admin_ids) - 1) . '?';
        $limit_stmt = $conn->prepare("SELECT admin_id, bet_limit, pnl_ratio FROM broker_limit WHERE admin_id IN ($placeholders)");
        $limit_stmt->bind_param(str_repeat('i', count($admin_ids)), ...$admin_ids);
    } else {
        $limit_stmt = $conn->prepare("SELECT admin_id, bet_limit, pnl_ratio FROM broker_limit");
    }
    $limit_stmt->execute();
    $limit_result = $limit_stmt->get_result();
    while ($limit_row = $limit_result->fetch_assoc()) {
        $admin_limits[$limit_row['admin_id']] = $limit_row;
    }
    
    // Pre-fetch game types payout ratios
    $game_type_ratios = [];
    $stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
    $stmt_game_types->execute();
    $game_types_result = $stmt_game_types->get_result();
    while ($game_type_row = $game_types_result->fetch_assoc()) {
        $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
    }
    
    // Get admin referral codes mapping
    $admin_refs = [];
    foreach ($admins_data as $admin_id => $admin_data) {
        $admin_refs[$admin_data['referral_code']] = $admin_id;
    }
    
    // Calculate financial statistics
    $placeholders = str_repeat('?,', count($referral_codes) - 1) . '?';
    $financial_sql = "SELECT 
        gs.id as session_id,
        g.name as game_name,
        b.id as bet_id,
        b.amount as bet_amount,
        b.potential_win as potential_win,
        b.status as bet_status,
        b.numbers_played,
        b.game_type_id,
        b.bet_mode,
        u.referral_code
    FROM game_sessions gs
    JOIN games g ON gs.game_id = g.id
    JOIN bets b ON gs.id = b.game_session_id
    JOIN users u ON b.user_id = u.id
    WHERE DATE(gs.session_date) BETWEEN ? AND ? 
    AND u.referral_code IN ($placeholders)";
    
    $financial_params = array_merge([$start_date, $end_date], $referral_codes);
    $financial_stmt = $conn->prepare($financial_sql);
    $types = str_repeat('s', count($financial_params));
    $financial_stmt->bind_param($types, ...$financial_params);
    $financial_stmt->execute();
    $financial_result = $financial_stmt->get_result();
    
    while ($row = $financial_result->fetch_assoc()) {
        $bet_amount = $row['bet_amount'];
        $potential_win = $row['potential_win'];
        $bet_status = $row['bet_status'];
        $numbers_played = json_decode($row['numbers_played'], true);
        $game_type_id = $row['game_type_id'];
        $referral_code = $row['referral_code'];
        
        // Get admin ID from referral code
        $current_admin_id = $admin_refs[$referral_code] ?? 0;
        $admin_limit_data = $admin_limits[$current_admin_id] ?? ['bet_limit' => 100, 'pnl_ratio' => null];
        
        $bet_limit = $admin_limit_data['bet_limit'] ?? 100;
        $pnl_ratio = $admin_limit_data['pnl_ratio'];
        
        // Parse PNL ratio if set
        $admin_ratio = 0;
        $super_admin_ratio = 0;
        if ($pnl_ratio && strpos($pnl_ratio, ':') !== false) {
            $ratio_parts = explode(':', $pnl_ratio);
            $admin_ratio = intval($ratio_parts[0]);
            $super_admin_ratio = intval($ratio_parts[1]);
        }
        
        // Get the payout ratio for this game type
        $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00;
        
        // Calculate super admin's share based on admin configuration
        $super_admin_share = 0;
        $admin_retained_amount = $bet_amount;
        
        if (is_array($numbers_played)) {
            if (isset($numbers_played['selected_digits'])) {
                // For SP Motor type bets
                if (isset($numbers_played['pana_combinations'])) {
                    $amount_per_pana = $numbers_played['amount_per_pana'] ?? 0;
                    foreach ($numbers_played['pana_combinations'] as $pana) {
                        if ($pnl_ratio) {
                            // PNL Ratio: Super admin gets super_admin_ratio%
                            $super_admin_share += ($amount_per_pana * $super_admin_ratio) / 100;
                            $admin_retained_amount -= ($amount_per_pana * $super_admin_ratio) / 100;
                        } else {
                            // Bet Limit: Super admin gets amount above bet_limit
                            $super_admin_share += max(0, $amount_per_pana - $bet_limit);
                            $admin_retained_amount -= max(0, $amount_per_pana - $bet_limit);
                        }
                    }
                }
            } else {
                // For regular number bets
                foreach ($numbers_played as $number => $amount) {
                    if ($pnl_ratio) {
                        // PNL Ratio: Super admin gets super_admin_ratio%
                        $super_admin_share += ($amount * $super_admin_ratio) / 100;
                        $admin_retained_amount -= ($amount * $super_admin_ratio) / 100;
                    } else {
                        // Bet Limit: Super admin gets amount above bet_limit
                        $super_admin_share += max(0, $amount - $bet_limit);
                        $admin_retained_amount -= max(0, $amount - $bet_limit);
                    }
                }
            }
        }
        
        // Calculate actual payout for won bets (super admin's responsibility)
        $super_admin_payout_share = 0;
        if ($bet_status == 'won') {
            if ($bet_amount > 0) {
                $super_admin_payout_ratio = $super_admin_share / $bet_amount;
                $super_admin_payout_share = $potential_win * $super_admin_payout_ratio;
            }
        }
        
        // Update overall totals
        $stats['total_bets']++;
        $stats['total_bet_amount'] += $bet_amount;
        $stats['total_potential_payout'] += $potential_win;
        $stats['total_forwarded_amount'] += $super_admin_share;
        $stats['total_actual_payout'] += $super_admin_payout_share;
        $stats['total_super_admin_share'] += $super_admin_share;
        
        if ($bet_status == 'won') {
            $stats['won_bets']++;
        } elseif ($bet_status == 'lost') {
            $stats['lost_bets']++;
        } else {
            $stats['pending_bets']++;
        }
        
        // Update admin-specific statistics
        if (!isset($stats['admin_stats'][$current_admin_id])) {
            $stats['admin_stats'][$current_admin_id] = [
                'admin_name' => $admins_data[$current_admin_id]['username'] ?? 'Unknown',
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_potential_payout' => 0,
                'total_actual_payout' => 0,
                'total_forwarded_amount' => 0,
                'total_super_admin_share' => 0,
                'super_admin_profit_loss' => 0,
                'won_bets' => 0,
                'lost_bets' => 0,
                'pending_bets' => 0,
                'config_type' => $pnl_ratio ? 'PNL Ratio' : 'Bet Limit',
                'config_value' => $pnl_ratio ? $pnl_ratio : $bet_limit
            ];
        }
        
        $stats['admin_stats'][$current_admin_id]['total_bets']++;
        $stats['admin_stats'][$current_admin_id]['total_bet_amount'] += $bet_amount;
        $stats['admin_stats'][$current_admin_id]['total_potential_payout'] += $potential_win;
        $stats['admin_stats'][$current_admin_id]['total_actual_payout'] += $super_admin_payout_share;
        $stats['admin_stats'][$current_admin_id]['total_forwarded_amount'] += $super_admin_share;
        $stats['admin_stats'][$current_admin_id]['total_super_admin_share'] += $super_admin_share;
        
        if ($bet_status == 'won') {
            $stats['admin_stats'][$current_admin_id]['won_bets']++;
        } elseif ($bet_status == 'lost') {
            $stats['admin_stats'][$current_admin_id]['lost_bets']++;
        } else {
            $stats['admin_stats'][$current_admin_id]['pending_bets']++;
        }
    }
    
    // Calculate final profit/loss for each admin and overall
    foreach ($stats['admin_stats'] as $admin_id => &$admin_stat) {
        $admin_stat['super_admin_profit_loss'] = $admin_stat['total_super_admin_share'] - $admin_stat['total_actual_payout'];
    }
    
    $stats['total_super_admin_profit_loss'] = $stats['total_super_admin_share'] - $stats['total_actual_payout'];
    
    return $stats;
}

// Calculate statistics
$stats = calculateSuperAdminProfitLoss($conn, $start_date, $end_date, $selected_admin_ids);

// Get all admins for comparison dropdown
$all_admins_stmt = $conn->prepare("SELECT id, username FROM admins WHERE status = 'active' ORDER BY username");
$all_admins_stmt->execute();
$all_admins_result = $all_admins_stmt->get_result();
$all_admins = [];
while ($row = $all_admins_result->fetch_assoc()) {
    $all_admins[$row['id']] = $row['username'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Report - RB Games Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


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

        /* Include sidebar styles from previous files */
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

        .admin-info {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
            transition: all 0.3s ease;
            min-height: 100vh;
            width: calc(100% - 260px);
        }

        /* Header styles */
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

        /* Dashboard sections */
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

        /* Stats cards */
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

        .stat-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Profit/Loss indicators */
        .profit {
            color: var(--success);
        }

        .loss {
            color: var(--danger);
        }

        .neutral {
            color: var(--warning);
        }

        /* Comparison section */
        .comparison-controls {
            background: rgba(11, 180, 201, 0.2);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .comparison-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .admin-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .checkbox-group label {
            color: var(--text-light);
            cursor: pointer;
        }

        /* Chart container */
        .chart-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px solid var(--border-color);
        }

        /* Table styles */
        .profit-loss-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .profit-loss-table th,
        .profit-loss-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .profit-loss-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--text-light);
        }

        .profit-loss-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Filter controls */
        .filter-control {
            width: 100%;
            padding: 0.7rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 0.9rem;
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
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 60, 126, 0.3);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .comparison-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-checkboxes {
                grid-template-columns: 1fr;
            }
        }

        .quick-date-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .quick-date-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
            transform: translateY(-1px);
        }

        .quick-date-btn:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>RB Games</h2>
            </div>
            <div class="sidebar-menu">
                <a href="super_admin_dashboard.php" class="menu-item ">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="super_admin_manage_admins.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>Manage Admins</span>
                </a>
                <a href="super_admin_all_users.php" class="menu-item ">
                    <i class="fas fa-users"></i>
                    <span>All Users</span>
                </a>
                <a href="super_admin_transactions.php" class="menu-item">
                    <i class="fas fa-exchange-alt"></i>
                    <span>All Transactions</span>
                </a>
                <a href="super_admin_withdrawals.php" class="menu-item">
                    <i class="fas fa-credit-card"></i>
                    <span>All Withdrawals</span>
                </a>
                <a href="super_admin_deposits.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>All Deposits</span>
                </a>
                <a href="admin_games.php" class="menu-item">
                    <i class="fa-regular fa-pen-to-square"></i>
                    <span>Edit Games</span>
                </a>
                <a href="super_admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Platform Reports</span>
                </a>
                <a href="profit_loss.php" class="menu-item active">
                    <i class="fa-solid fa-sack-dollar"></i>
                    <span>Profit & Loss</span>
                </a>
                <a href="super_admin_profile.php" class="menu-item">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="super_admin_settings.php" class="menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Platform Settings</span>
                </a>
            </div>
            <div class="sidebar-footer">
                <div class="admin-info">
                    <p>Logged in as <strong><?php echo $super_admin_username; ?></strong></p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Profit & Loss Report</h1>
                    <p>Super Admin's comprehensive profit and loss analysis</p>
                </div>
                <div class="header-actions">
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo $super_admin_username; ?></span>
                    </div>
                </div>
            </div>

            <!-- Date Range Selection -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-calendar"></i> Select Date Range</h2>
                </div>
                <form method="GET" id="reportForm">
                    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end; margin-bottom: 1rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-light);">Start Date</label>
                            <input type="date" name="start_date" class="filter-control" value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-light);">End Date</label>
                            <input type="date" name="end_date" class="filter-control" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
                                <i class="fas fa-search"></i> Generate
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Date Buttons -->
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="quick-date-btn" data-days="7">Last 7 Days</button>
                        <button type="button" class="quick-date-btn" data-days="30">Last 30 Days</button>
                        <button type="button" class="quick-date-btn" data-type="month">This Month</button>
                        <button type="button" class="quick-date-btn" data-type="last_month">Last Month</button>
                        <button type="button" class="quick-date-btn" data-days="90">Last 90 Days</button>
                    </div>
                    
                    <!-- Hidden fields for comparison -->
                    <?php if (!empty($selected_admin_ids)): ?>
                        <?php foreach ($selected_admin_ids as $admin_id): ?>
                            <input type="hidden" name="compare_admins[]" value="<?php echo $admin_id; ?>">
                        <?php endforeach; ?>
                    <?php endif; ?>
                </form>
            </div>

                        <!-- Export Button Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-file-export"></i> Export Reports</h2>
                </div>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-success" onclick="exportProfitLossToExcel()" style="padding: 12px 24px;">
                        <i class="fas fa-file-excel"></i> Export Profit & Loss Report
                    </button>
                    <span style="color: var(--text-muted); font-size: 0.9rem; display: flex; align-items: center;">
                        <i class="fas fa-info-circle" style="margin-right: 8px;"></i>
                        Exports all admins data for selected period
                    </span>
                </div>
            </div>

            <!-- Overall Profit/Loss Summary -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Overall Profit & Loss Summary</h2>
                    <span>Period: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></span>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-value <?php echo $stats['total_super_admin_profit_loss'] > 0 ? 'profit' : ($stats['total_super_admin_profit_loss'] < 0 ? 'loss' : 'neutral'); ?>">
                            ₹<?php echo number_format(abs($stats['total_super_admin_profit_loss']), 2); ?>
                        </div>
                        <div class="stat-card-title">Net Profit/Loss</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value">₹<?php echo number_format($stats['total_super_admin_share'], 2); ?></div>
                        <div class="stat-card-title">Total Share Received</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value">₹<?php echo number_format($stats['total_actual_payout'], 2); ?></div>
                        <div class="stat-card-title">Total Payout Given</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value"><?php echo number_format($stats['total_bets']); ?></div>
                        <div class="stat-card-title">Total Bets</div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-value" style="color: var(--success);"><?php echo number_format($stats['won_bets']); ?></div>
                        <div class="stat-card-title">Won Bets</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value" style="color: var(--danger);"><?php echo number_format($stats['lost_bets']); ?></div>
                        <div class="stat-card-title">Lost Bets</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value" style="color: var(--warning);"><?php echo number_format($stats['pending_bets']); ?></div>
                        <div class="stat-card-title">Pending Bets</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-card-value">₹<?php echo number_format($stats['total_bet_amount'], 2); ?></div>
                        <div class="stat-card-title">Total Bet Amount</div>
                    </div>
                </div>
            </div>

                       <!-- Admin Comparison Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-balance-scale"></i> Compare Admins</h2>
                </div>

                <div class="comparison-controls">
                    <form method="GET" id="comparisonForm">
                        <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                        <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                        
                        <h4 style="margin-bottom: 1rem; color: var(--text-light);">Select Admins to Compare (Max 5):</h4>
                        
                        <!-- Styled Search and Select Admins -->
                        <div style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; margin-bottom: 1rem; align-items: end;">
                            <div style="position: relative;">
                                <div style="position: relative;">
                                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); z-index: 2;"></i>
                                    <input type="text" id="adminSearch" 
                                           style="width: 100%; padding: 10px 10px 10px 40px; background: rgba(255, 255, 255, 0.1); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-light); font-size: 14px; transition: all 0.3s ease;"
                                           placeholder="Type admin name to search..." 
                                           autocomplete="off"
                                           onfocus="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 0 0 2px rgba(255, 60, 126, 0.2)';"
                                           onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                                </div>
                                <div id="adminResults" style="position: absolute; top: 100%; left: 0; right: 0; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1000; display: none; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3); margin-top: 5px;"></div>
                            </div>
                            <button type="button" class="btn" style="background: rgba(255, 60, 126, 0.2); color: var(--primary); border: 1px solid rgba(255, 60, 126, 0.3); white-space: nowrap;" onclick="clearAllSelections()">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>
                        
                        <!-- Selected Admins Display -->
                        <div id="selectedAdminsContainer" style="min-height: 80px; border: 2px dashed var(--border-color); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; background: rgba(255, 255, 255, 0.03); transition: all 0.3s ease;">
                            <div id="noAdminsSelected" style="text-align: center; color: var(--text-muted); padding: 1rem;">
                                <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                <p style="margin-bottom: 0.5rem; font-weight: 500;">No admins selected</p>
                                <small style="font-size: 0.85rem;">Search and select up to 5 admins to compare their performance</small>
                            </div>
                            <div id="selectedAdminsList" style="display: none; display: flex; flex-wrap: wrap; gap: 0.75rem;"></div>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                            <button type="submit" class="btn btn-primary" id="compareButton" disabled style="padding: 12px 24px; font-weight: 600;">
                                <i class="fas fa-chart-line"></i> Compare Selected Admins
                            </button>
                            <span id="selectionCount" style="color: var(--text-muted); font-size: 0.9rem; background: rgba(255, 255, 255, 0.05); padding: 6px 12px; border-radius: 20px; border: 1px solid var(--border-color);">
                                <i class="fas fa-users" style="margin-right: 5px;"></i>
                                <span id="selectedCount">0</span>/5 admins selected
                            </span>
                            <?php if (!empty($selected_admin_ids)): ?>
                                <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn" style="background: rgba(255, 255, 255, 0.1); color: var(--text-light); padding: 12px 20px;">
                                    <i class="fas fa-times"></i> Clear Comparison
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Hidden inputs for selected admins -->
                        <div id="hiddenInputsContainer"></div>
                    </form>
                </div>

                <?php if (!empty($selected_admin_ids) && count($selected_admin_ids) >= 2): ?>
                <div style="margin-top: 2rem;">
                    <h4 style="margin-bottom: 1rem; color: var(--text-light);">
                        <i class="fas fa-chart-bar"></i> Comparison Results 
                        <small style="color: var(--text-muted); font-size: 0.8em; margin-left: 1rem;">
                            (<?php echo count($selected_admin_ids); ?> admins selected)
                        </small>
                    </h4>
                    
                    <!-- Quick Stats Comparison -->
                    <div class="stats-grid" style="margin-bottom: 2rem;">
                        <?php foreach ($selected_admin_ids as $admin_id): 
                            if (isset($stats['admin_stats'][$admin_id])): 
                                $admin_stat = $stats['admin_stats'][$admin_id];
                        ?>
                            <div class="stat-card">
                                <div class="stat-card-value <?php echo $admin_stat['super_admin_profit_loss'] > 0 ? 'profit' : ($admin_stat['super_admin_profit_loss'] < 0 ? 'loss' : 'neutral'); ?>">
                                    ₹<?php echo number_format(abs($admin_stat['super_admin_profit_loss']), 2); ?>
                                </div>
                                <div class="stat-card-title"><?php echo htmlspecialchars($admin_stat['admin_name']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
                                    <?php echo $admin_stat['config_type']; ?>: <?php echo $admin_stat['config_value']; ?>
                                </div>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>

                    <!-- Detailed Comparison Table -->
                    <div style="background: var(--card-bg); border-radius: 8px; padding: 1.5rem; border: 1px solid var(--border-color); margin-bottom: 2rem;">
                        <h5 style="margin-bottom: 1rem; color: var(--text-light);">Detailed Comparison</h5>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Metric</th>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <th style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color); color: var(--secondary);">
                                                <?php echo htmlspecialchars($admin_stat['admin_name']); ?>
                                            </th>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">Total Bets</td>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color);">
                                                <?php echo number_format($admin_stat['total_bets']); ?>
                                            </td>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">Bet Amount</td>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color);">
                                                ₹<?php echo number_format($admin_stat['total_bet_amount'], 2); ?>
                                            </td>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">Share Received</td>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color);">
                                                ₹<?php echo number_format($admin_stat['total_super_admin_share'], 2); ?>
                                            </td>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">Payout Given</td>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color);">
                                                ₹<?php echo number_format($admin_stat['total_actual_payout'], 2); ?>
                                            </td>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color); font-weight: bold;">Net Profit/Loss</td>
                                        <?php foreach ($selected_admin_ids as $admin_id): 
                                            if (isset($stats['admin_stats'][$admin_id])): 
                                                $admin_stat = $stats['admin_stats'][$admin_id];
                                        ?>
                                            <td style="padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color); font-weight: bold;" class="<?php echo $admin_stat['super_admin_profit_loss'] > 0 ? 'profit' : ($admin_stat['super_admin_profit_loss'] < 0 ? 'loss' : 'neutral'); ?>">
                                                ₹<?php echo number_format(abs($admin_stat['super_admin_profit_loss']), 2); ?>
                                            </td>
                                        <?php endif; endforeach; ?>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Comparison Chart -->
                    <div class="chart-container">
                        <canvas id="comparisonChart" height="100"></canvas>
                    </div>
                </div>
                <?php elseif (!empty($selected_admin_ids) && count($selected_admin_ids) === 1): ?>
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <h4>Select at least 2 admins to compare</h4>
                    <p>Choose one more admin from the search above to see comparison charts and tables.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Detailed Admin-wise Profit/Loss -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-table"></i> Admin-wise Profit & Loss Details</h2>
                </div>

                <?php if (!empty($stats['admin_stats'])): ?>
                <div class="table-responsive">
                    <table class="profit-loss-table">
                        <thead>
                            <tr>
                                <th>Admin Name</th>
                                <th>Configuration</th>
                                <th class="text-right">Total Bets</th>
                                <th class="text-right">Bet Amount</th>
                                <th class="text-right">Share Received</th>
                                <th class="text-right">Payout Given</th>
                                <th class="text-right">Net P&L</th>
                                <th class="text-right">Win/Loss/Pending</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['admin_stats'] as $admin_stat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($admin_stat['admin_name']); ?></strong></td>
                                    <td><?php echo $admin_stat['config_type']; ?> (<?php echo $admin_stat['config_value']; ?>)</td>
                                    <td class="text-right"><?php echo number_format($admin_stat['total_bets']); ?></td>
                                    <td class="text-right">₹<?php echo number_format($admin_stat['total_bet_amount'], 2); ?></td>
                                    <td class="text-right">₹<?php echo number_format($admin_stat['total_super_admin_share'], 2); ?></td>
                                    <td class="text-right">₹<?php echo number_format($admin_stat['total_actual_payout'], 2); ?></td>
                                    <td class="text-right <?php echo $admin_stat['super_admin_profit_loss'] > 0 ? 'profit' : ($admin_stat['super_admin_profit_loss'] < 0 ? 'loss' : 'neutral'); ?>">
                                        ₹<?php echo number_format(abs($admin_stat['super_admin_profit_loss']), 2); ?>
                                    </td>
                                    <td class="text-right">
                                        <span style="color: var(--success);"><?php echo number_format($admin_stat['won_bets']); ?></span> /
                                        <span style="color: var(--danger);"><?php echo number_format($admin_stat['lost_bets']); ?></span> /
                                        <span style="color: var(--warning);"><?php echo number_format($admin_stat['pending_bets']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <h3>No Data Available</h3>
                    <p>No profit/loss data found for the selected period and filters.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        <?php if (!empty($selected_admin_ids) && count($selected_admin_ids) > 1): ?>
        // Initialize comparison chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('comparisonChart').getContext('2d');
            const comparisonData = {
                labels: [<?php echo implode(',', array_map(function($admin_id) use ($stats) { 
                    return "'" . addslashes($stats['admin_stats'][$admin_id]['admin_name']) . "'"; 
                }, $selected_admin_ids)); ?>],
                datasets: [{
                    label: 'Profit/Loss (₹)',
                    data: [<?php echo implode(',', array_map(function($admin_id) use ($stats) { 
                        return $stats['admin_stats'][$admin_id]['super_admin_profit_loss']; 
                    }, $selected_admin_ids)); ?>],
                    backgroundColor: [<?php echo implode(',', array_map(function($admin_id) use ($stats) { 
                        return $stats['admin_stats'][$admin_id]['super_admin_profit_loss'] > 0 ? 
                            "'rgba(0, 184, 148, 0.8)'" : 
                            "'rgba(214, 48, 49, 0.8)'"; 
                    }, $selected_admin_ids)); ?>],
                    borderColor: [<?php echo implode(',', array_map(function($admin_id) use ($stats) { 
                        return $stats['admin_stats'][$admin_id]['super_admin_profit_loss'] > 0 ? 
                            "'rgba(0, 184, 148, 1)'" : 
                            "'rgba(214, 48, 49, 1)'"; 
                    }, $selected_admin_ids)); ?>],
                    borderWidth: 2
                }]
            };

            new Chart(ctx, {
                type: 'bar',
                data: comparisonData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                color: '#f5f6fa'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const profitLoss = value >= 0 ? 'Profit' : 'Loss';
                                    return `${profitLoss}: ₹${Math.abs(value).toFixed(2)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: '#f5f6fa',
                                callback: function(value) {
                                    return '₹' + value.toFixed(2);
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#f5f6fa'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>

        // Date validation
        document.querySelector('input[name="start_date"]').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDate = new Date(document.querySelector('input[name="end_date"]').value);
            const today = new Date();
            
            if (startDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            if (endDate < startDate) {
                document.querySelector('input[name="end_date"]').value = this.value;
            }
        });

        document.querySelector('input[name="end_date"]').addEventListener('change', function() {
            const endDate = new Date(this.value);
            const startDate = new Date(document.querySelector('input[name="start_date"]').value);
            const today = new Date();
            
            if (endDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            if (endDate < startDate) {
                document.querySelector('input[name="start_date"]').value = this.value;
            }
        });
    </script>

    <script>
// Admin Search and Selection Functionality
const adminSearch = document.getElementById('adminSearch');
const adminResults = document.getElementById('adminResults');
const selectedAdminsList = document.getElementById('selectedAdminsList');
const noAdminsSelected = document.getElementById('noAdminsSelected');
const hiddenInputsContainer = document.getElementById('hiddenInputsContainer');
const compareButton = document.getElementById('compareButton');
const selectionCount = document.getElementById('selectionCount');
let selectedAdmins = new Map();
let searchTimeout;

// Initialize with previously selected admins if any
<?php if (!empty($selected_admin_ids)): ?>
    <?php foreach ($selected_admin_ids as $admin_id): ?>
        <?php if (isset($all_admins[$admin_id])): ?>
            selectedAdmins.set(<?php echo $admin_id; ?>, '<?php echo addslashes($all_admins[$admin_id]); ?>');
        <?php endif; ?>
    <?php endforeach; ?>
    updateSelectedAdminsDisplay();
<?php endif; ?>

adminSearch.addEventListener('input', function() {
    const searchTerm = this.value.trim();
    
    clearTimeout(searchTimeout);
    
    if (searchTerm === '') {
        adminResults.style.display = 'none';
        return;
    }
    
    adminResults.innerHTML = '<div class="admin-result-item"><div class="loading"></div> Searching...</div>';
    adminResults.style.display = 'block';
    
    searchTimeout = setTimeout(() => {
        searchAdmins(searchTerm);
    }, 300);
});

// Hide results when clicking outside
document.addEventListener('click', function(e) {
    if (!adminSearch.contains(e.target) && !adminResults.contains(e.target)) {
        adminResults.style.display = 'none';
    }
});

function searchAdmins(searchTerm) {
    fetch(`?ajax=search_admins&search=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(admins => {
            if (admins.length === 0) {
                adminResults.innerHTML = '<div class="admin-result-item">No admins found</div>';
            } else {
                adminResults.innerHTML = admins.map(admin => {
                    const isSelected = selectedAdmins.has(admin.id);
                    return `
                        <div class="admin-result-item ${isSelected ? 'selected' : ''}" 
                             data-admin-id="${admin.id}" 
                             data-admin-name="${admin.username}">
                            ${admin.username}
                            ${isSelected ? '<i class="fas fa-check" style="margin-left: auto; color: var(--success);"></i>' : ''}
                        </div>
                    `;
                }).join('');
                
                document.querySelectorAll('.admin-result-item[data-admin-id]').forEach(item => {
                    item.addEventListener('click', function() {
                        const adminId = this.getAttribute('data-admin-id');
                        const adminName = this.getAttribute('data-admin-name');
                        
                        if (selectedAdmins.has(adminId)) {
                            removeAdmin(adminId);
                        } else {
                            if (selectedAdmins.size >= 5) {
                                showNotification('Maximum 5 admins can be selected for comparison', 'warning');
                                return;
                            }
                            addAdmin(adminId, adminName);
                        }
                    });
                });
            }
        })
        .catch(error => {
            console.error('Error searching admins:', error);
            adminResults.innerHTML = '<div class="admin-result-item">Error searching admins</div>';
        });
}

function addAdmin(adminId, adminName) {
    selectedAdmins.set(adminId, adminName);
    updateSelectedAdminsDisplay();
    adminSearch.value = '';
    adminResults.style.display = 'none';
}

function removeAdmin(adminId) {
    selectedAdmins.delete(adminId);
    updateSelectedAdminsDisplay();
}

function updateSelectedAdminsDisplay() {
    const selectedCount = selectedAdmins.size;
    
    // Update selection count
    selectionCount.textContent = `${selectedCount}/5 admins selected`;
    
    // Enable/disable compare button
    compareButton.disabled = selectedCount < 2;
    
    // Update hidden inputs
    hiddenInputsContainer.innerHTML = '';
    selectedAdmins.forEach((adminName, adminId) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'compare_admins[]';
        input.value = adminId;
        hiddenInputsContainer.appendChild(input);
    });
    
    // Update visual display
    if (selectedCount === 0) {
        selectedAdminsList.style.display = 'none';
        noAdminsSelected.style.display = 'block';
    } else {
        noAdminsSelected.style.display = 'none';
        selectedAdminsList.style.display = 'block';
        selectedAdminsList.innerHTML = Array.from(selectedAdmins.entries()).map(([adminId, adminName]) => `
            <div class="selected-admin-tag" style="display: inline-flex; align-items: center; background: rgba(11, 180, 201, 0.2); padding: 0.5rem 1rem; border-radius: 20px; margin: 0.25rem; border: 1px solid rgba(11, 180, 201, 0.3);">
                <span>${adminName}</span>
                <button type="button" onclick="removeAdmin('${adminId}')" style="background: none; border: none; color: var(--text-muted); margin-left: 0.5rem; cursor: pointer; padding: 0.2rem; border-radius: 50%;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `).join('');
    }
}

function clearAllSelections() {
    selectedAdmins.clear();
    updateSelectedAdminsDisplay();
}

function showNotification(message, type = 'info') {
    // Simple notification implementation
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        color: white;
        z-index: 10000;
        background: ${type === 'warning' ? 'var(--warning)' : type === 'error' ? 'var(--danger)' : 'var(--secondary)'};
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Rest of your existing JavaScript...


// Export function for Profit & Loss report
function exportProfitLossToExcel() {
    const startDate = document.querySelector('input[name="start_date"]').value;
    const endDate = document.querySelector('input[name="end_date"]').value;
    
    const filename = `super_admin_profit_loss_${startDate}_to_${endDate}.csv`;
    
    let csv = '';
    
    // Header Section
    csv += '"RB GAMES - SUPER ADMIN PROFIT & LOSS REPORT"\n';
    csv += `"Report Period: ${new Date(startDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} to ${new Date(endDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}"\n`;
    csv += `"Generated on: <?php echo date('F j, Y \\a\\t g:i A'); ?>"\n`;
    csv += `"Super Admin: <?php echo $super_admin_username; ?>"\n\n`;
    
    // Overall Summary Section
    csv += '"OVERALL SUMMARY"\n';
    csv += '"Description","Amount (₹)","Count"\n';
    csv += `"Total Bets","<?php echo number_format($stats['total_bet_amount'], 2); ?>","<?php echo number_format($stats['total_bets']); ?>"\n`;
    csv += `"Total Share Received","<?php echo number_format($stats['total_super_admin_share'], 2); ?>","-"\n`;
    csv += `"Total Payout Given","<?php echo number_format($stats['total_actual_payout'], 2); ?>","-"\n`;
    csv += `"","",""\n`; // Empty row for spacing
    csv += `"NET PROFIT/LOSS","<?php echo number_format($stats['total_super_admin_profit_loss'], 2); ?>","-"\n`;
    csv += `"Status","<?php echo $stats['total_super_admin_profit_loss'] > 0 ? 'PROFIT' : ($stats['total_super_admin_profit_loss'] < 0 ? 'LOSS' : 'BREAK-EVEN'); ?>","-"\n\n`;
    
    // Bet Statistics Section
    csv += '"BET STATISTICS"\n';
    csv += '"Description","Count","Percentage"\n';
    csv += `"Total Bets","<?php echo number_format($stats['total_bets']); ?>","100.00%"\n`;
    csv += `"Won Bets","<?php echo number_format($stats['won_bets']); ?>","<?php echo $stats['total_bets'] > 0 ? number_format(($stats['won_bets'] / $stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Lost Bets","<?php echo number_format($stats['lost_bets']); ?>","<?php echo $stats['total_bets'] > 0 ? number_format(($stats['lost_bets'] / $stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Pending Bets","<?php echo number_format($stats['pending_bets']); ?>","<?php echo $stats['total_bets'] > 0 ? number_format(($stats['pending_bets'] / $stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n\n`;
    
    // Admin-wise Detailed Statistics
    csv += '"ADMIN-WISE PROFIT & LOSS DETAILS"\n';
    csv += '"Admin Name","Configuration","Total Bets","Won","Lost","Pending","Total Bet Amount (₹)","Super Admin Share (₹)","Super Admin Payout (₹)","Super Admin Net P&L (₹)","P&L Status"\n';
    
    <?php 
    $grand_total_bets = 0;
    $grand_total_won = 0;
    $grand_total_lost = 0;
    $grand_total_pending = 0;
    $grand_total_bet_amount = 0;
    $grand_total_share = 0;
    $grand_total_payout = 0;
    $grand_total_net_pl = 0;
    
    foreach ($stats['admin_stats'] as $admin_stat): 
        $grand_total_bets += $admin_stat['total_bets'];
        $grand_total_won += $admin_stat['won_bets'];
        $grand_total_lost += $admin_stat['lost_bets'];
        $grand_total_pending += $admin_stat['pending_bets'];
        $grand_total_bet_amount += $admin_stat['total_bet_amount'];
        $grand_total_share += $admin_stat['total_super_admin_share'];
        $grand_total_payout += $admin_stat['total_actual_payout'];
        $grand_total_net_pl += $admin_stat['super_admin_profit_loss'];
    ?>
        csv += `"<?php echo $admin_stat['admin_name']; ?>","<?php echo $admin_stat['config_type']; ?> (<?php echo $admin_stat['config_value']; ?>)","<?php echo $admin_stat['total_bets']; ?>","<?php echo $admin_stat['won_bets']; ?>","<?php echo $admin_stat['lost_bets']; ?>","<?php echo $admin_stat['pending_bets']; ?>","<?php echo number_format($admin_stat['total_bet_amount'], 2); ?>","<?php echo number_format($admin_stat['total_super_admin_share'], 2); ?>","<?php echo number_format($admin_stat['total_actual_payout'], 2); ?>","<?php echo number_format($admin_stat['super_admin_profit_loss'], 2); ?>","<?php echo $admin_stat['super_admin_profit_loss'] > 0 ? 'PROFIT' : ($admin_stat['super_admin_profit_loss'] < 0 ? 'LOSS' : 'BREAK-EVEN'); ?>"\n`;
    <?php endforeach; ?>
    
    // Grand Totals Row
    csv += `"GRAND TOTALS","-","<?php echo $grand_total_bets; ?>","<?php echo $grand_total_won; ?>","<?php echo $grand_total_lost; ?>","<?php echo $grand_total_pending; ?>","<?php echo number_format($grand_total_bet_amount, 2); ?>","<?php echo number_format($grand_total_share, 2); ?>","<?php echo number_format($grand_total_payout, 2); ?>","<?php echo number_format($grand_total_net_pl, 2); ?>","<?php echo $grand_total_net_pl > 0 ? 'PROFIT' : ($grand_total_net_pl < 0 ? 'LOSS' : 'BREAK-EVEN'); ?>"\n\n`;
    
    // Configuration Summary
    csv += '"CONFIGURATION SUMMARY"\n';
    csv += '"Configuration Type","Admin Count","Total Bets","Total Bet Amount (₹)","Total Share (₹)","Total Payout (₹)","Net P&L (₹)"\n';
    
    <?php
    $config_summary = [];
    foreach ($stats['admin_stats'] as $admin_stat) {
        $config_type = $admin_stat['config_type'];
        if (!isset($config_summary[$config_type])) {
            $config_summary[$config_type] = [
                'admin_count' => 0,
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_share' => 0,
                'total_payout' => 0,
                'net_pl' => 0
            ];
        }
        $config_summary[$config_type]['admin_count']++;
        $config_summary[$config_type]['total_bets'] += $admin_stat['total_bets'];
        $config_summary[$config_type]['total_bet_amount'] += $admin_stat['total_bet_amount'];
        $config_summary[$config_type]['total_share'] += $admin_stat['total_super_admin_share'];
        $config_summary[$config_type]['total_payout'] += $admin_stat['total_actual_payout'];
        $config_summary[$config_type]['net_pl'] += $admin_stat['super_admin_profit_loss'];
    }
    
    foreach ($config_summary as $config_type => $summary):
    ?>
        csv += `"<?php echo $config_type; ?>","<?php echo $summary['admin_count']; ?>","<?php echo $summary['total_bets']; ?>","<?php echo number_format($summary['total_bet_amount'], 2); ?>","<?php echo number_format($summary['total_share'], 2); ?>","<?php echo number_format($summary['total_payout'], 2); ?>","<?php echo number_format($summary['net_pl'], 2); ?>"\n`;
    <?php endforeach; ?>
    
    // Performance Analysis
    csv += '"\nPERFORMANCE ANALYSIS"\n';
    csv += '"Metric","Value","Notes"\n';
    csv += `"Overall Profit/Loss","₹<?php echo number_format($stats['total_super_admin_profit_loss'], 2); ?>","<?php echo $stats['total_super_admin_profit_loss'] > 0 ? 'Positive performance' : ($stats['total_super_admin_profit_loss'] < 0 ? 'Negative performance' : 'Break-even'); ?>"\n`;
    csv += `"Profit Margin","<?php echo $stats['total_super_admin_share'] > 0 ? number_format(($stats['total_super_admin_profit_loss'] / $stats['total_super_admin_share']) * 100, 2) : '0.00'; ?>%","Net profit as percentage of total share"\n`;
    csv += `"Average Bet Size","₹<?php echo $stats['total_bets'] > 0 ? number_format($stats['total_bet_amount'] / $stats['total_bets'], 2) : '0.00'; ?>","Total bet amount divided by total bets"\n`;
    csv += `"Win Rate","<?php echo $stats['total_bets'] > 0 ? number_format(($stats['won_bets'] / $stats['total_bets']) * 100, 2) : '0.00'; ?>%","Percentage of won bets"\n`;
    csv += `"Payout Ratio","<?php echo $stats['total_super_admin_share'] > 0 ? number_format(($stats['total_actual_payout'] / $stats['total_super_admin_share']) * 100, 2) : '0.00'; ?>%","Payout as percentage of share received"\n`;
    
    // Top Performers
    csv += '"\nTOP PERFORMERS (BY PROFIT)"\n';
    csv += '"Rank","Admin Name","Configuration","Net Profit/Loss (₹)"\n';
    
    <?php
    $sorted_admins = $stats['admin_stats'];
    usort($sorted_admins, function($a, $b) {
        return $b['super_admin_profit_loss'] <=> $a['super_admin_profit_loss'];
    });
    
    $top_count = min(5, count($sorted_admins));
    for ($i = 0; $i < $top_count; $i++):
        $admin = $sorted_admins[$i];
    ?>
        csv += `"<?php echo $i + 1; ?>","<?php echo $admin['admin_name']; ?>","<?php echo $admin['config_type']; ?> (<?php echo $admin['config_value']; ?>)","<?php echo number_format($admin['super_admin_profit_loss'], 2); ?>"\n`;
    <?php endfor; ?>
    
    // Bottom Performers
    csv += '"\nBOTTOM PERFORMERS (BY LOSS)"\n';
    csv += '"Rank","Admin Name","Configuration","Net Profit/Loss (₹)"\n';
    
    <?php
    $bottom_admins = $stats['admin_stats'];
    usort($bottom_admins, function($a, $b) {
        return $a['super_admin_profit_loss'] <=> $b['super_admin_profit_loss'];
    });
    
    $bottom_count = min(5, count($bottom_admins));
    for ($i = 0; $i < $bottom_count; $i++):
        $admin = $bottom_admins[$i];
    ?>
        csv += `"<?php echo $i + 1; ?>","<?php echo $admin['admin_name']; ?>","<?php echo $admin['config_type']; ?> (<?php echo $admin['config_value']; ?>)","<?php echo number_format($admin['super_admin_profit_loss'], 2); ?>"\n`;
    <?php endfor; ?>
    
    // Report Summary
    csv += '"\nREPORT SUMMARY"\n';
    csv += '"Total Admins","<?php echo count($stats['admin_stats']); ?>"\n';
    csv += `"Report Period","${new Date(startDate).toLocaleDateString()} to ${new Date(endDate).toLocaleDateString()}"\n`;
    csv += `"Total Days","<?php echo round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1; ?>"\n`;
    csv += `"Generated By","<?php echo $super_admin_username; ?>"\n`;
    csv += `"Generation Time","<?php echo date('Y-m-d H:i:s'); ?>"\n`;
    
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


    // Quick date buttons functionality
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
        document.querySelector('input[name="start_date"]').value = formatDate(startDate);
        document.querySelector('input[name="end_date"]').value = formatDate(endDate);

        // Submit the form
        document.getElementById('reportForm').submit();
    });
});
</script>
</body>
</html>