<?php
// admin_bet_history.php
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

// Date filtering setup
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'custom';

$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code  = $stmt->get_result();
$referral_code = $referral_code->fetch_assoc();

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
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : '';
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for bet count
$count_sql = "SELECT COUNT(*) as total 
              FROM bets b
              JOIN users u ON b.user_id = u.id
              JOIN games g ON b.game_type_id = g.id
              WHERE DATE(b.placed_at) BETWEEN ? AND ? AND u.referral_code = '".$referral_code['referral_code']."'";
$params = [$start_date, $end_date];
$types = 'ss';

if ($filter_user) {
    $count_sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $types .= 'ss';
}

if ($filter_game) {
    $count_sql .= " AND g.name LIKE ?";
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_status) {
    $count_sql .= " AND b.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$stmt_count = $conn->prepare($count_sql." AND u.referral_code = '".$referral_code['referral_code']."'");
if ($params) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Build query for bets with pagination
$sql = "SELECT b.*, u.username, u.email, g.name as game_name, g.open_time, g.close_time,
               gs.session_date, gs.open_result, gs.close_result, gs.jodi_result
        FROM bets b
        JOIN users u ON b.user_id = u.id
        JOIN games g ON b.game_type_id = g.id
        LEFT JOIN game_sessions gs ON b.game_session_id = gs.id
        WHERE DATE(b.placed_at) BETWEEN ? AND ? AND u.referral_code = '".$referral_code['referral_code']."'";

if ($filter_user) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
}

if ($filter_game) {
    $sql .= " AND g.name LIKE ?";
}

if ($filter_status) {
    $sql .= " AND b.status = ?";
}

$sql .= "AND u.referral_code = '".$referral_code['referral_code']."' ORDER BY b.placed_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [$start_date, $end_date];
$types = 'ss';

if ($filter_user) {
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $types .= 'ss';
}

if ($filter_game) {
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_status) {
    $params[] = $filter_status;
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
$bets = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $bets[] = $row;
    }
}

// Get stats for dashboard
$stats_sql = "SELECT 
    COUNT(*) as total_bets,
    SUM(b.amount) as total_amount,
    SUM(b.potential_win) as total_potential,
    COUNT(CASE WHEN b.status = 'won' THEN 1 END) as won_bets,
    COUNT(CASE WHEN b.status = 'pending' THEN 1 END) as pending_bets
    FROM bets b
    JOIN users u ON b.user_id = u.id 
    WHERE DATE(b.placed_at) BETWEEN ? AND ? AND u.referral_code = '".$referral_code['referral_code']."'";

$stats_params = [$start_date, $end_date];
$stats_types = 'ss';

if ($filter_user) {
    $stats_sql .= " AND b.user_id IN (SELECT id FROM users WHERE username LIKE ? OR email LIKE ?)";
    $stats_params[] = "%$filter_user%";
    $stats_params[] = "%$filter_user%";
    $stats_types .= 'ss';
}

if ($filter_game) {
    $stats_sql .= " AND b.game_id IN (SELECT id FROM games WHERE name LIKE ?)";
    $stats_params[] = "%$filter_game%";
    $stats_types .= 's';
}

if ($filter_status) {
    $stats_sql .= " AND b.status = ?";
    $stats_params[] = $filter_status;
    $stats_types .= 's';
}

$stmt_stats = $conn->prepare($stats_sql);
if ($stats_params) {
    $stmt_stats->bind_param($stats_types, ...$stats_params);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();

$total_bets = $stats['total_bets'] ? $stats['total_bets'] : 0;
$total_amount = $stats['total_amount'] ? $stats['total_amount'] : 0;
$total_potential = $stats['total_potential'] ? $stats['total_potential'] : 0;
$won_bets = $stats['won_bets'] ? $stats['won_bets'] : 0;
$pending_bets = $stats['pending_bets'] ? $stats['pending_bets'] : 0;

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
    <title>All Users Bet History - RB Games Admin</title>
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

        .bets-icon {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
        }

        .amount-icon {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }

        .potential-icon {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }

        .won-icon {
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

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        .data-table th, .data-table td {
            padding: 1.2rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-won {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-lost {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        .status-cancelled {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        .game-type {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        .bet-mode {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        /* Results display */
        .results {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .result-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
        }

        /* Numbers played display */
        .numbers-played {
            max-width: 200px;
            word-break: break-word;
            font-family: monospace;
            font-size: 0.85rem;
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

        /* Table container for horizontal scrolling */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1.8rem;
            padding: 0 1.8rem;
        }

        /* Card view for mobile */
        .bets-cards {
            display: none;
            flex-direction: column;
            gap: 1rem;
        }

        .bet-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid var(--border-color);
        }

        .bet-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .bet-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .bet-label {
            color: var(--text-muted);
            font-weight: 500;
            min-width: 120px;
        }

        .bet-value {
            text-align: right;
            flex: 1;
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
            
            
            
            
            
            .menu-item i {
                margin-right: 0;
            }
            
            .sidebar-footer {
                padding: 0.8rem;
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
            .menu-item span{
                display: none;
            }
            .menu-item i {
                margin-right: 0;
            }
            .sidebar-footer {
                padding: 0.8rem;
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
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }

            .menu-item span{
                margin-left: 12px;
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
            
            .table-container {
                margin: 0 -1rem;
                padding: 0 1rem;
            }
            
            .data-table {
                display: none;
            }
            
            .bets-cards {
                display: flex;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .view-all {
                align-self: flex-end;
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
        }

        @media (max-width: 576px) {

            .sidebar {
                width: 0;
                transform: translateX(-100%);
            }
            .sidebar.active {
                width: 260px;
                transform: translateX(0);
            }
            .main-content {
                padding: 1rem;
            }
            .menu-toggle {
                display: block;
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
            
            .table-container {
                margin: 0 -0.8rem;
                padding: 0 0.8rem;
            }
            
            .bet-card {
                padding: 0.8rem;
            }
            
            .bet-label {
                min-width: 100px;
                font-size: 0.9rem;
            }
            
            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card-value {
                font-size: 1.6rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.8rem;
            }
            
            .dashboard-section {
                padding: 0.7rem;
                border-radius: 8px;
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
            
            .section-title {
                font-size: 1.1rem;
            }
            
            .btn {
                padding: 0.5rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .bet-card {
                padding: 0.7rem;
            }
            
            .bet-row {
                flex-direction: column;
                gap: 0.3rem;
            }
            
            .bet-label, .bet-value {
                width: 100%;
                text-align: left;
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

        @media (max-width: 400px) {
            .main-content {
                padding: 0.8rem;
            }
            
            .dashboard-section {
                padding: 0.6rem;
                margin-bottom: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .welcome h1 {
                font-size: 1.2rem;
            }

            .header-actions {
                flex-direction: column;
                width: 100%;
                gap: 0.8rem;
            }
            
            .section-title {
                font-size: 1rem;
            }
            
            .btn {
                padding: 0.4rem 0.7rem;
                font-size: 0.75rem;
            }
            
            .filter-control {
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            
            .bet-card {
                padding: 0.6rem;
            }
            
            .admin-badge, .current-time {
                width: 100%;
                justify-content: center;
            }
            .admin-badge, .current-time, .logout-btn {
                font-size: 0.85rem;
                padding: 0.4rem 0.8rem;
            }
            
            .status, .game-type, .bet-mode {
                padding: 0.3rem 0.7rem;
                font-size: 0.75rem;
            }
            
            .pagination a, .pagination span {
                padding: 0.4rem 0.7rem;
                min-width: 35px;
                font-size: 0.8rem;
            }
            .logout-btn {
                width: 100%;
                justify-content: center;
            }
            .menu-toggle {
                top: 0.8rem;
                left: 0.8rem;
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

        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
                <a href="all_users_history.php" class="menu-item active">
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
                    <h1>Bet History</h1>
                    <p>View and analyze user betting activity</p>
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

            <!-- Date Range Display -->
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                Showing bets from <span><?php echo date('M j, Y', strtotime($start_date)); ?></span> to <span><?php echo date('M j, Y', strtotime($end_date)); ?></span>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
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
                    <div class="stat-card-value">$<?php echo number_format($total_amount, 2); ?></div>
                    <div class="stat-card-title">Total Bet Amount</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon potential-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_potential, 2); ?></div>
                    <div class="stat-card-title">Potential Winnings</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon won-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($won_bets); ?></div>
                    <div class="stat-card-title">Won Bets</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-filter"></i> Filter Bets</h3>
                
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
                            <label class="filter-label">User Search</label>
                            <input type="text" name="filter_user" class="filter-control" placeholder="Username or email" value="<?php echo htmlspecialchars($filter_user); ?>">
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
                            <label class="filter-label">Status</label>
                            <select name="filter_status" class="filter-control">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="won" <?php echo $filter_status == 'won' ? 'selected' : ''; ?>>Won</option>
                                <option value="lost" <?php echo $filter_status == 'lost' ? 'selected' : ''; ?>>Lost</option>
                                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                        <a href="admin_bet_history.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bets Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Bet History</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> bets</div>
                </div>
                
                <?php if (!empty($bets)): ?>
                    <!-- Desktop Table View -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Game</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Numbers Played</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Results</th>
                                    <th>Status</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><?php echo $bet['id']; ?></td>
                                        <td>
                                            <div><?php echo $bet['username']; ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?php echo $bet['email']; ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo $bet['game_name']; ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;">
                                                <?php echo date('h:i A', strtotime($bet['open_time'])); ?> - 
                                                <?php echo date('h:i A', strtotime($bet['close_time'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="game-type">
                                                <?php echo str_replace('_', ' ', $bet['game_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="bet-mode">
                                                <?php echo ucfirst($bet['bet_mode']); ?>
                                            </span>
                                        </td>
                                        <td class="numbers-played">
                                            <?php 
                                            $numbers = json_decode($bet['numbers_played'], true);
                                            if (is_array($numbers)) {
                                                if (isset($numbers['selected_digits'])) {
                                                    echo "Digits: " . $numbers['selected_digits'];
                                                    if (isset($numbers['pana_combinations'])) {
                                                        echo "<br>Panas: " . implode(', ', $numbers['pana_combinations']);
                                                    }
                                                } else {
                                                    foreach ($numbers as $number => $amount) {
                                                        echo $number . " ($" . $amount . ")<br>";
                                                    }
                                                }
                                            } else {
                                                echo htmlspecialchars($bet['numbers_played']);
                                            }
                                            ?>
                                        </td>
                                        <td>$<?php echo number_format($bet['amount'], 2); ?></td>
                                        <td>$<?php echo number_format($bet['potential_win'], 2); ?></td>
                                        <td>
                                            <div class="results">
                                                <?php if ($bet['open_result']): ?>
                                                    <span class="result-badge">Open: <?php echo $bet['open_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if ($bet['close_result']): ?>
                                                    <span class="result-badge">Close: <?php echo $bet['close_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if ($bet['jodi_result']): ?>
                                                    <span class="result-badge">Jodi: <?php echo $bet['jodi_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if (!$bet['open_result'] && !$bet['close_result'] && !$bet['jodi_result']): ?>
                                                    <span class="text-muted">No results</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $bet['status']; ?>">
                                                <?php echo ucfirst($bet['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="bets-cards">
                        <?php foreach ($bets as $bet): ?>
                            <div class="bet-card">
                                <div class="bet-row">
                                    <span class="bet-label">ID:</span>
                                    <span class="bet-value"><?php echo $bet['id']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">User:</span>
                                    <span class="bet-value"><?php echo $bet['username']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Game:</span>
                                    <span class="bet-value"><?php echo $bet['game_name']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Type:</span>
                                    <span class="bet-value">
                                        <span class="game-type">
                                            <?php echo str_replace('_', ' ', $bet['game_type']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Mode:</span>
                                    <span class="bet-value">
                                        <span class="bet-mode">
                                            <?php echo ucfirst($bet['bet_mode']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Numbers:</span>
                                    <span class="bet-value numbers-played">
                                        <?php 
                                        $numbers = json_decode($bet['numbers_played'], true);
                                        if (is_array($numbers)) {
                                            if (isset($numbers['selected_digits'])) {
                                                echo "Digits: " . $numbers['selected_digits'];
                                                if (isset($numbers['pana_combinations'])) {
                                                    echo " | Panas: " . implode(', ', $numbers['pana_combinations']);
                                                }
                                            } else {
                                                foreach ($numbers as $number => $amount) {
                                                    echo $number . " ($" . $amount . ") ";
                                                }
                                            }
                                        } else {
                                            echo htmlspecialchars($bet['numbers_played']);
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Amount:</span>
                                    <span class="bet-value">$<?php echo number_format($bet['amount'], 2); ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Potential Win:</span>
                                    <span class="bet-value">$<?php echo number_format($bet['potential_win'], 2); ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Results:</span>
                                    <span class="bet-value">
                                        <div class="results">
                                            <?php if ($bet['open_result']): ?>
                                                <span class="result-badge">Open: <?php echo $bet['open_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($bet['close_result']): ?>
                                                <span class="result-badge">Close: <?php echo $bet['close_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($bet['jodi_result']): ?>
                                                <span class="result-badge">Jodi: <?php echo $bet['jodi_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if (!$bet['open_result'] && !$bet['close_result'] && !$bet['jodi_result']): ?>
                                                <span class="text-muted">No results</span>
                                            <?php endif; ?>
                                        </div>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Status:</span>
                                    <span class="bet-value">
                                        <span class="status status-<?php echo $bet['status']; ?>">
                                            <?php echo ucfirst($bet['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Date:</span>
                                    <span class="bet-value"><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                        <p>No bets found for the selected criteria.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
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

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });

        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
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
        }

        handleResize();
        window.addEventListener('resize', handleResize);
        
        // Quick filter functionality
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                
                // Update active state
                document.querySelectorAll('.quick-filter-btn').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                // Enable/disable date inputs
                const startDate = document.querySelector('input[name="start_date"]');
                const endDate = document.querySelector('input[name="end_date"]');
                
                if (filter === 'custom') {
                    startDate.removeAttribute('readonly');
                    endDate.removeAttribute('readonly');
                } else {
                    startDate.setAttribute('readonly', 'readonly');
                    endDate.setAttribute('readonly', 'readonly');
                }
                
                // Submit form
                document.getElementById('filterForm').submit();
            });
        });
        
        // Update time every minute
        function updateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeElement = document.querySelector('.current-time span');
            if (timeElement) {
                timeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        
        // Initial call
        updateTime();
        
        // Update every minute
        setInterval(updateTime, 60000);
    </script>
</body>
</html>