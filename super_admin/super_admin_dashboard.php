<?php
// super_admin_dashboard.php
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

// Get stats for dashboard
$admins_count = 0;
$active_admins = 0;
$total_users = 0;
$active_users = 0;
$total_deposits = 0;
$total_withdrawals = 0;
$pending_withdrawals = 0;
$total_games = 0;
$total_revenue = 0;

// Count total admins
$sql = "SELECT COUNT(*) as count FROM admins WHERE status = 'active'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $admins_count = $row['count'];
}

// Count active admins (admins with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT a.id) as count 
        FROM admins a 
        WHERE a.status = 'active' 
        AND EXISTS (
            SELECT 1 FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            WHERE u.referral_code = a.referral_code 
            AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        )";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $active_admins = $row['count'];
}

// Count total users
$sql = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_users = $row['count'];
}

// Count active users (users with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT user_id) as count FROM transactions 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $active_users = $row['count'];
}

// Count total games
$sql = "SELECT COUNT(*) as count FROM games WHERE status = 'active'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_games = $row['count'];
}

// Get total deposits across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'deposit' AND t.status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_deposits = $row['total'] ? $row['total'] : 0;
}

// Get total withdrawals across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'withdrawal' AND t.status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get pending withdrawals across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'withdrawal' AND t.status = 'pending'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $pending_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Calculate total revenue (deposits - withdrawals)
$total_revenue = $total_deposits - $total_withdrawals;

// Get admin performance data
function get_admin_performance($conn) {
    $sql = "SELECT 
            a.id,
            a.username,
            a.referral_code,
            a.status,
            COUNT(DISTINCT u.id) as total_users,
            COUNT(DISTINCT CASE WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN u.id END) as new_users,
            SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN t.type = 'withdrawal' AND t.status = 'completed' THEN t.amount ELSE 0 END) as total_withdrawals,
            COUNT(DISTINCT b.id) as total_bets
            FROM admins a
            LEFT JOIN users u ON u.referral_code = a.referral_code
            LEFT JOIN transactions t ON t.user_id = u.id
            LEFT JOIN bets b ON b.user_id = u.id
            WHERE a.status = 'active'
            GROUP BY a.id, a.username, a.referral_code
            ORDER BY total_deposits DESC
            LIMIT 10";
    
    $result = $conn->query($sql);
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    return $admins;
}

// Get recent transactions with admin info
function get_recent_transactions($conn, $limit = 10) {
    $sql = "SELECT 
            t.id, 
            u.username as user_name,
            a.username as admin_name,
            t.type, 
            t.amount, 
            t.status, 
            t.created_at 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY t.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

// Get recent withdrawals with admin info
function get_recent_withdrawals($conn, $limit = 10) {
    $sql = "SELECT 
            w.id, 
            u.username as user_name,
            a.username as admin_name,
            w.amount, 
            w.status, 
            w.created_at 
            FROM withdrawals w 
            JOIN users u ON w.user_id = u.id 
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY w.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    return $withdrawals;
}

// Get recent user registrations with admin info
function get_recent_registrations($conn, $limit = 10) {
    $sql = "SELECT 
            u.id,
            u.username,
            u.email,
            a.username as admin_name,
            u.created_at
            FROM users u
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY u.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $registrations = [];
    while ($row = $result->fetch_assoc()) {
        $registrations[] = $row;
    }
    return $registrations;
}

// Get revenue data for charts
function get_revenue_data($conn, $period = 'week') {
    $start_date = '';
    $end_date = date('Y-m-d');
    $date_format = 'M j';
    
    switch ($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $date_format = 'D';
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $date_format = 'M j';
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            $date_format = 'M j';
            break;
        case 'custom':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $date_format = 'M j';
            break;
    }
    
    // Generate dates array
    $dates = [];
    $current = strtotime($start_date);
    $last = strtotime($end_date);
    
    while ($current <= $last) {
        $dates[] = date($date_format, $current);
        $current = strtotime('+1 day', $current);
    }
    
    $sql = "SELECT 
            DATE(t.created_at) as date,
            SUM(CASE WHEN type = 'deposit' AND t.status = 'completed' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type = 'withdrawal' AND t.status = 'completed' THEN amount ELSE 0 END) as withdrawals,
            SUM(CASE WHEN type = 'winning' THEN amount ELSE 0 END) as winnings
            FROM transactions t
            WHERE DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $result = $stmt->get_result();
    
    // Initialize arrays with zeros
    $deposits = array_fill(0, count($dates), 0);
    $withdrawals = array_fill(0, count($dates), 0);
    $winnings = array_fill(0, count($dates), 0);
    
    while ($row = $result->fetch_assoc()) {
        $date_formatted = date($date_format, strtotime($row['date']));
        $day_index = array_search($date_formatted, $dates);
        if ($day_index !== false) {
            $deposits[$day_index] = floatval($row['deposits']);
            $withdrawals[$day_index] = floatval($row['withdrawals']);
            $winnings[$day_index] = floatval($row['winnings']);
        }
    }
    
    return [
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'dates' => $dates,
        'deposits' => $deposits,
        'withdrawals' => $withdrawals,
        'winnings' => $winnings
    ];
}

// Get admin distribution data
function get_admin_distribution_data($conn) {
    $sql = "SELECT 
            a.username,
            COUNT(DISTINCT u.id) as user_count,
            SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END) as deposit_amount
            FROM admins a
            LEFT JOIN users u ON u.referral_code = a.referral_code
            LEFT JOIN transactions t ON t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed'
            WHERE a.status = 'active'
            GROUP BY a.id, a.username
            ORDER BY deposit_amount DESC
            LIMIT 10";
    
    $result = $conn->query($sql);
    $admins = [];
    $user_counts = [];
    $deposit_amounts = [];
    
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row['username'];
        $user_counts[] = $row['user_count'];
        $deposit_amounts[] = floatval($row['deposit_amount']);
    }
    
    return [
        'admins' => $admins,
        'user_counts' => $user_counts,
        'deposit_amounts' => $deposit_amounts
    ];
}

// Get initial data for charts
$revenue_data = get_revenue_data($conn, 'week');
$admin_distribution_data = get_admin_distribution_data($conn);

// Get recent data
$recent_transactions = get_recent_transactions($conn, 5);
$recent_withdrawals = get_recent_withdrawals($conn, 5);
$recent_registrations = get_recent_registrations($conn, 5);
$top_admins = get_admin_performance($conn);

$title = "Super Admin Dashboard - RB Games";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
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
                transition: all 0.3s ease;
                min-height: 100vh;
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 2.2rem;
                padding-bottom: 1.2rem;
                border-bottom: 1px solid var(--border-color);
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

            /* Stats Cards */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.8rem;
                margin-bottom: 2.2rem;
            }

            
            .stat-card {
                background: var(--card-bg);
                border-radius: 12px;
                padding: 1.8rem;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
                border: 1px solid var(--border-color);
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
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
                transform: translateY(-5px);
                box-shadow: 0 12px 25px rgba(0, 0, 0, 0.3);
            }

            .stat-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.2rem;
            }

            .stat-card-title {
                font-size: 1rem;
                color: var(--text-muted);
                font-weight: 500;
            }

            .stat-card-icon {
                width: 55px;
                height: 55px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.6rem;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            }

            .users-icon {
                background: rgba(0, 184, 148, 0.2);
                color: var(--success);
            }

            .active-users-icon {
                background: rgba(11, 142, 215, 0.2);
                color: #0b8ed7;
            }

            .deposits-icon {
                background: rgba(253, 203, 110, 0.2);
                color: var(--warning);
            }

            .withdrawals-icon {
                background: rgba(255, 60, 126, 0.2);
                color: var(--primary);
            }

            .pending-icon {
                background: rgba(214, 48, 49, 0.2);
                color: var(--danger);
            }

            .stat-card-value {
                font-size: 2.4rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
                background: linear-gradient(to right, var(--text-light), var(--text-muted));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                
            }

            .stat-card-desc {
                color: var(--text-muted);
                font-size: 0.9rem;
            }

            /* Dashboard Sections */
            .dashboard-section {
                background: var(--card-bg);
                border-radius: 12px;
                padding: 1.8rem;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
                border: 1px solid var(--border-color);
                margin-bottom: 2.2rem;
            }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
                padding-bottom: 0.8rem;
                border-bottom: 1px solid var(--border-color);
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

            /* Charts */
            .charts-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 1.8rem;
                margin-bottom: 2.2rem;
            }

            .chart-container::-webkit-scrollbar{
                display:none;
            }
            .chart-container {
                background: var(--card-bg);
                border-radius: 12px;
                padding: 1.8rem;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
                border: 1px solid var(--border-color);
                height: 350px;
            }

            .chart-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
            }

            .chart-title {
                font-size: 1.2rem;
                font-weight: 600;
            }

            .chart-actions {
                display: flex;
                gap: 0.8rem;
            }

            .chart-action-btn {
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid var(--border-color);
                color: var(--text-light);
                padding: 0.4rem 0.8rem;
                border-radius: 4px;
                font-size: 0.8rem;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .chart-action-btn.active {
                background: var(--primary);
            }

            .chart-action-btn:hover {
                background: rgba(255, 60, 126, 0.3);
            }

            /* Tables */
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }

            .data-table th, .data-table td {
                padding: 1.2rem;
                text-align: left;
                border-bottom: 1px solid var(--border-color);
            }

            .data-table th {
                color: var(--text-muted);
                font-weight: 600;
                font-size: 0.95rem;
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
                font-size: 0.85rem;
                font-weight: 500;
                display: inline-block;
            }

            .status-completed {
                background: rgba(0, 184, 148, 0.2);
                color: var(--success);
                border: 1px solid rgba(0, 184, 148, 0.3);
            }

            .status-pending {
                background: rgba(253, 203, 110, 0.2);
                color: var(--warning);
                border: 1px solid rgba(253, 203, 110, 0.3);
            }

            .status-failed {
                background: rgba(214, 48, 49, 0.2);
                color: var(--danger);
                border: 1px solid rgba(214, 48, 49, 0.3);
            }
            .status-rejected {
                background: rgba(214, 48, 49, 0.2);
                color: var(--danger);
                border: 1px solid rgba(214, 48, 49, 0.3);
            }

            /* Two-column layout for recent data */
            .recent-data-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.8rem;
                overflow-x:scroll;
            }
            .recent-data-grid::-webkit-scrollbar{
                display:none;
            }

            /* Quick Actions */
            .quick-actions {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 1.2rem;
                margin-top: 1.5rem;
            }

            .action-btn {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 1.8rem 1.2rem;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                text-decoration: none;
                color: var(--text-light);
                transition: all 0.3s ease;
                border: 1px solid var(--border-color);
            }

            .action-btn:hover {
                background: linear-gradient(to right, rgba(255, 60, 126, 0.2), rgba(11, 180, 201, 0.2));
                transform: translateY(-3px);
                box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            }

            .action-btn i {
                font-size: 2rem;
                margin-bottom: 0.8rem;
                background: linear-gradient(to right, var(--primary), var(--secondary));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }

            .action-btn span {
                font-size: 0.95rem;
                text-align: center;
                font-weight: 500;
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
            }

            .admin-badge i {
                color: var(--primary);
            }

            .admin-name {
                color: var(--primary);
                font-weight: 600;
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



        /* Responsive Design */
            @media (max-width: 1200px) {
                .charts-grid {
                    grid-template-columns: 1fr;
                }
                
                .recent-data-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 993px) {
                .sidebar {
                    width: 260px;
                    left: 0;
                    position: fixed;
                }
                
                .sidebar-header h2 {
                    font-size: 1.2rem;
                }

                .menu-item span {
                    display: none;
                }

                .menu-item {
                    justify-content: center;
                    padding: 1rem;
                }
                
                .menu-item i {
                    margin-right: 0;
                }
                
                .sidebar-footer {
                    padding: 0.8rem;
                }
                
                .main-content {
                    margin-left: 80px;
                    padding: 1.5rem;
                }
                
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
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
            }

            /* Medium screens (769px - 992px) */
            @media (max-width: 992px) and (min-width: 769px) {
                .sidebar {
                    width: 80px;
                    left: 0;
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
                .stats-grid {
                    grid-template-columns: 1fr;
                }
                
                .header-actions {
                    flex-direction: column;
                    gap: 1rem;
                }
                
                .admin-badge, .current-time {
                    width: 100%;
                    justify-content: center;
                }
                
                .main-content {
                    padding: 1rem;
                }
                
                .quick-actions {
                    grid-template-columns: 1fr;
                }
                
                .data-table {
                    display: block;
                    overflow-x: auto;
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
                    margin-left: 0;
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
                
                .stat-card-value {
                    font-size: 2rem;
                }
                
                .dashboard-section {
                    padding: 1rem;
                }
                
                .data-table th, .data-table td {
                    padding: 0.8rem;
                }
            }

            /* Extra small devices (400px and below) */
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
                    padding: 0.5rem 1rem;
                    font-size: 0.9rem;
                }
                
                .logout-btn {
                    width: 100%;
                    justify-content: center;
                }
                
                .stats-grid {
                    gap: 1rem;
                }
                
                .stat-card {
                    padding: 1.2rem;
                }
                
                .stat-card-value {
                    font-size: 1.8rem;
                }
                
                .stat-card-icon {
                    width: 45px;
                    height: 45px;
                    font-size: 1.3rem;
                }
                
                .dashboard-section {
                    padding: 1rem 0.8rem;
                }
                
                .section-title {
                    font-size: 1.1rem;
                }
                
                .data-table th, .data-table td {
                    padding: 0.6rem;
                    font-size: 0.85rem;
                }
                
                .quick-actions {
                    grid-template-columns: 1fr;
                    gap: 0.8rem;
                }
                
                .action-btn {
                    padding: 1.2rem 0.8rem;
                }
                
                .action-btn i {
                    font-size: 1.6rem;
                }
                
                .action-btn span {
                    font-size: 0.85rem;
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

            /* Success/error messages */
            .alert {
                padding: 1rem;
                border-radius: 6px;
                margin-bottom: 1rem;
            }

            .alert-success {
                background: rgba(0, 184, 148, 0.2);
                border: 1px solid rgba(0, 184, 148, 0.3);
                color: var(--success);
            }

            .alert-error {
                background: rgba(214, 48, 49, 0.2);
                border: 1px solid rgba(214, 48, 49, 0.3);
                color: var(--danger);
            }
            
            /* Progress bars */
            .progress-bar {
                height: 8px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 4px;
                overflow: hidden;
                margin-top: 0.5rem;
            }
            
            .progress-fill {
                height: 100%;
                border-radius: 4px;
                transition: width 0.5s ease;
            }
            
            .progress-success {
                background: var(--success);
            }
            
            .progress-warning {
                background: var(--warning);
            }
            
            .progress-danger {
                background: var(--danger);
            }
            
            /* Mini stats */
            .mini-stats {
                display: flex;
                justify-content: space-between;
                margin-top: 1rem;
            }
            
            .mini-stat {
                text-align: center;
            }
            
            .mini-stat-value {
                font-size: 1.2rem;
                font-weight: 600;
            }
            
            .mini-stat-label {
                font-size: 0.8rem;
                color: var(--text-muted);
            }
    </style>

    <style>
        /* Use the same CSS styles from your admin dashboard */
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

        /* Include all your existing CSS styles from the admin dashboard */
        /* I'm including the key styles but you can copy the complete CSS from your admin dashboard */

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
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.8rem;
            margin-bottom: 2.2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
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

        .dashboard-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        /* Add any additional styles needed for super admin specific elements */
        .admin-badge {
            background: rgba(255, 193, 7, 0.2);
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .revenue-positive {
            color: var(--success);
        }

        .revenue-negative {
            color: var(--danger);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Menu Toggle -->
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games - Super Admin</h2>
            </div>
            <div class="sidebar-menu">
                <a href="super_admin_dashboard.php" class="menu-item active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="super_admin_manage_admins.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>Manage Admins</span>
                </a>
                <a href="super_admin_all_users.php" class="menu-item">
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
                <a href="profit_loss.php" class="menu-item ">
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
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>Super Admin Dashboard</h1>
                    <p>Welcome back, <span class="admin-name">Super Admin <?php echo $super_admin_username; ?></span>. Platform overview and analytics.</p>
                </div>
                <div class="header-actions" title="<?php echo date('l, F j, Y'); ?>">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span><?php echo date('l, F j, Y'); ?></span>
                    </div>
                    <a href="super_admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <!-- Platform Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Admins</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $admins_count; ?></div>
                    <div class="stat-card-desc">Active platform admins</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Users</div>
                        <div class="stat-card-icon active-users-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_users; ?></div>
                    <div class="stat-card-desc">Registered users</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Revenue</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-card-value <?php echo $total_revenue >= 0 ? 'revenue-positive' : 'revenue-negative'; ?>">
                        $<?php echo number_format($total_revenue, 2); ?>
                    </div>
                    <div class="stat-card-desc">Platform net revenue</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Games</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_games; ?></div>
                    <div class="stat-card-desc">Active games</div>
                </div>
            </div>

            <!-- Financial Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Deposits</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_deposits, 2); ?></div>
                    <div class="stat-card-desc">All time deposits</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Withdrawals</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">All time withdrawals</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Pending Withdrawals</div>
                        <div class="stat-card-icon pending-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($pending_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">Awaiting approval</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Active Users</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $active_users; ?></div>
                    <div class="stat-card-desc">Last 30 days activity</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Platform Revenue Overview</h3>
                        <div class="chart-actions">
                            <button class="chart-action-btn active" data-period="week">Week</button>
                            <button class="chart-action-btn" data-period="this_month">This Month</button>
                            <button class="chart-action-btn" data-period="last_month">Last Month</button>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Top Admins by Deposits</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="adminDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Performing Admins -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-trophy"></i> Top Performing Admins</h2>
                    <a href="super_admin_manage_admins.php" class="view-all">View All Admins</a>
                </div>
                
                <div class="recent_data">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Admin</th>
                                <th>Users</th>
                                <th>New Users</th>
                                <th>Total Deposits</th>
                                <th>Total Withdrawals</th>
                                <th>Total Bets</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_admins)): ?>
                                <?php foreach ($top_admins as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                            <div class="admin-badge"><?php echo ucfirst($admin['status']); ?></div>
                                        </td>
                                        <td><?php echo $admin['total_users']; ?></td>
                                        <td><?php echo $admin['new_users']; ?></td>
                                        <td>$<?php echo number_format($admin['total_deposits'], 2); ?></td>
                                        <td>$<?php echo number_format($admin['total_withdrawals'], 2); ?></td>
                                        <td><?php echo $admin['total_bets']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No admin data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-data-grid">
                <!-- Recent Transactions -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                        <a href="super_admin_transactions.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Admin</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_transactions)): ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['user_name']); ?></td>
                                            <td>
                                                <span class="admin-badge"><?php echo htmlspecialchars($transaction['admin_name']); ?></span>
                                            </td>
                                            <td><?php echo ucfirst($transaction['type']); ?></td>
                                            <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $transaction['status']; ?>">
                                                    <?php echo ucfirst($transaction['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center;">No recent transactions</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Withdrawals -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-credit-card"></i> Recent Withdrawals</h2>
                        <a href="super_admin_withdrawals.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Admin</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_withdrawals)): ?>
                                    <?php foreach ($recent_withdrawals as $withdrawal): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($withdrawal['user_name']); ?></td>
                                            <td>
                                                <span class="admin-badge"><?php echo htmlspecialchars($withdrawal['admin_name']); ?></span>
                                            </td>
                                            <td>$<?php echo number_format($withdrawal['amount'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $withdrawal['status']; ?>">
                                                    <?php echo ucfirst($withdrawal['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center;">No recent withdrawals</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="super_admin_manage_admins.php" class="action-btn">
                        <i class="fas fa-user-shield"></i>
                        <span>Manage Admins</span>
                    </a>
                    <a href="super_admin_all_users.php" class="action-btn">
                        <i class="fas fa-users"></i>
                        <span>View All Users</span>
                    </a>
                    <a href="super_admin_transactions.php" class="action-btn">
                        <i class="fas fa-search-dollar"></i>
                        <span>All Transactions</span>
                    </a>
                    <a href="super_admin_withdrawals.php" class="action-btn">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Process Withdrawals</span>
                    </a>
                    <a href="super_admin_reports.php" class="action-btn">
                        <i class="fas fa-chart-pie"></i>
                        <span>Platform Reports</span>
                    </a>
                    <a href="super_admin_settings.php" class="action-btn">
                        <i class="fas fa-cog"></i>
                        <span>Platform Settings</span>
                    </a>
                </div>
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

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($revenue_data['dates']); ?>,
                    datasets: [
                        {
                            label: 'Deposits',
                            data: <?php echo json_encode($revenue_data['deposits']); ?>,
                            borderColor: '#00b894',
                            backgroundColor: 'rgba(0, 184, 148, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Withdrawals',
                            data: <?php echo json_encode($revenue_data['withdrawals']); ?>,
                            borderColor: '#d63031',
                            backgroundColor: 'rgba(214, 48, 49, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                callback: function(value) {
                                    return '$' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        }
                    }
                }
            });

            // Admin Distribution Chart
            const adminCtx = document.getElementById('adminDistributionChart').getContext('2d');
            const adminChart = new Chart(adminCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($admin_distribution_data['admins']); ?>,
                    datasets: [{
                        label: 'Deposit Amount',
                        data: <?php echo json_encode($admin_distribution_data['deposit_amounts']); ?>,
                        backgroundColor: '#ff3c7e',
                        borderColor: '#ff3c7e',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                callback: function(value) {
                                    return '$' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });

            // Chart period switching
            document.querySelectorAll('.chart-action-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const period = this.dataset.period;
                    
                    document.querySelectorAll('.chart-action-btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // In a real implementation, you would fetch new data for the selected period
                    console.log('Switching to period:', period);
                });
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
        
        updateTime();
        setInterval(updateTime, 60000);
    </script>
</body>
</html>