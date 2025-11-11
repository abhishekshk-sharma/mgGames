<?php
// admin_dashboard.php
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

// Get admin referral code
$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_result = $stmt->get_result();
$admin_data = $referral_result->fetch_assoc();
$referral_code = $admin_data['referral_code'];

// Get broker limit details
$broker_limit = [];
$stmt = $conn->prepare("SELECT * FROM broker_limit WHERE admin_id = ?");
$stmt->execute([$admin_id]);
$broker_result = $stmt->get_result();
if ($broker_result->num_rows > 0) {
    $broker_limit = $broker_result->fetch_assoc();
}

// Get stats for dashboard
$users_count = 0;
$active_users = 0;
$total_deposits = 0;
$total_withdrawals = 0;
$pending_withdrawals = 0;
$total_games = 0;

// Count total users
$sql = "SELECT COUNT(*) as count FROM users WHERE referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $users_count = $row['count'];
}

// Count active users (users with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT user_id) as count FROM transactions 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
        AND user_id IN (SELECT id FROM users WHERE referral_code = ?)";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
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

// Get total deposits
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id  
        WHERE t.type = 'deposit' AND t.status = 'completed' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $total_deposits = $row['total'] ? $row['total'] : 0;
}

// Get total withdrawals
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.type = 'withdrawal' AND t.status = 'completed' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $total_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get pending withdrawals
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.type = 'withdrawal' AND t.status = 'pending' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $pending_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get recent transactions function
function get_recent_transactions($conn, $referral_code, $limit = 5) {
    $sql = "SELECT t.id, u.username, t.type, t.amount, t.status, t.created_at 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            WHERE u.referral_code = ?
            ORDER BY t.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $limit]);
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

// Get recent withdrawals function
function get_recent_withdrawals($conn, $referral_code, $limit = 5) {
    $sql = "SELECT w.id, u.username, w.amount, w.status, w.created_at 
            FROM withdrawals w 
            JOIN users u ON w.user_id = u.id 
            WHERE u.referral_code = ?
            ORDER BY w.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $limit]);
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    return $withdrawals;
}

// Get revenue data for charts with different time periods
function get_revenue_data($conn, $referral_code, $period = 'week') {
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
            // For custom, we'll handle via AJAX
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
            JOIN users u ON t.user_id = u.id
            WHERE u.referral_code = ? 
            AND DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $start_date, $end_date]);
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

// Get user activity data
function get_user_activity_data($conn, $referral_code) {
    // Active users (played in last 7 days)
    $sql = "SELECT COUNT(DISTINCT user_id) as active_users 
            FROM bets 
            WHERE user_id IN (SELECT id FROM users WHERE referral_code = ?)
            AND placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $active_users = $result->fetch_assoc()['active_users'] ?? 0;
    
    // New users (registered in last 7 days)
    $sql = "SELECT COUNT(*) as new_users 
            FROM users 
            WHERE referral_code = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $new_users = $result->fetch_assoc()['new_users'] ?? 0;
    
    // Total users
    $sql = "SELECT COUNT(*) as total_users 
            FROM users 
            WHERE referral_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $total_users = $result->fetch_assoc()['total_users'] ?? 0;
    
    $returning_users = max(0, $total_users - $new_users);
    
    return [
        'active' => $active_users,
        'new' => $new_users,
        'returning' => $returning_users
    ];
}

// Get initial data for charts (default: week)
$revenue_data = get_revenue_data($conn, $referral_code, 'week');
$user_activity_data = get_user_activity_data($conn, $referral_code);

// Get recent data
$recent_transactions = get_recent_transactions($conn, $referral_code, 5);
$recent_withdrawals = get_recent_withdrawals($conn, $referral_code, 5);
$title = "Admin Dashboard - RB Games.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($title)?$title:"Admin Dashboard - RB Games"; ?> </title>
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
        /* Your existing CSS styles remain the same */
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

        /* All your existing CSS styles... */
        /* I'm keeping them the same as in your original file to save space */

        /* Chart container fixes */
        .chart-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            height: 400px;
            position: relative;
            overflow-x:scroll;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Custom date range styles */
        .custom-date-range {
            display: none;
            gap: 10px;
            margin-top: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .custom-date-range.active {
            display: flex;
        }

        .date-input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .date-input-group label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .date-input {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 8px 12px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .date-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .apply-custom-date {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        .apply-custom-date:hover {
            background: var(--secondary);
        }

        /* Broker Limit Styles */
        .broker-limit-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
            position: relative;
            overflow: hidden;
        }

        .broker-limit-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--warning), var(--accent));
        }

        .limit-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .limit-item:last-child {
            border-bottom: none;
        }

        .limit-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .limit-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .limit-used {
            color: var(--text-muted);
            font-size: 0.9rem;
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
                <h2>RB Games</h2>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item active">
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
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <span class="admin-name"><?php echo $admin_username; ?></span>. Here's what's happening with your platform today.</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span><?php echo date('l, F j, Y'); ?></span>
                    </div>
                    <a href="admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
      


            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Users</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $users_count; ?></div>
                    <div class="stat-card-desc">Registered users</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Games</div>
                        <div class="stat-card-icon active-users-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_games; ?></div>
                    <div class="stat-card-desc">Available games</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Deposits</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_deposits, 2); ?></div>
                    <div class="stat-card-desc">All time</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Withdrawals</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-money-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">All time</div>
                </div>
            </div>

            <!-- Broker Limit Card -->
            <?php if (!empty($broker_limit)): ?>
            <div class="broker-limit-card">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-line"></i> Broker Limits</h2>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Deposit Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['deposit_limit'], 2); ?></div>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Withdrawal Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['withdrawal_limit'], 2); ?></div>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Bet Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['bet_limit'], 2); ?></div>
                </div>
                <?php if ($broker_limit['pnl_ratio']): ?>
                <div class="limit-item">
                    <div class="limit-label">P&L Ratio</div>
                    <div class="limit-value"><?php echo $broker_limit['pnl_ratio']; ?>%</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <!-- Your stats cards remain the same -->
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Revenue Overview</h3>
                        <div class="chart-actions">
                            <button class="chart-action-btn active" data-period="week">Week</button>
                            <button class="chart-action-btn" data-period="this_month">This Month</button>
                            <button class="chart-action-btn" data-period="last_month">Last Month</button>
                            <button class="chart-action-btn" data-period="custom">Custom</button>
                        </div>
                    </div>
                    
                    <!-- Custom Date Range Input -->
                    <div class="custom-date-range" id="customDateRange">
                        <div class="date-input-group">
                            <label for="startDate">From</label>
                            <input type="date" id="startDate" class="date-input" value="<?php echo date('Y-m-d', strtotime('-6 days')); ?>">
                        </div>
                        <div class="date-input-group">
                            <label for="endDate">To</label>
                            <input type="date" id="endDate" class="date-input" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button class="apply-custom-date" id="applyCustomDate">Apply</button>
                    </div>
                    
                    <div class="chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">User Activity</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="userActivityChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="users.php" class="action-btn">
                        <i class="fas fa-users"></i>
                        <span>Manage Users</span>
                    </a>
                    <a href="admin_transactions.php" class="action-btn">
                        <i class="fas fa-search-dollar"></i>
                        <span>View Transactions</span>
                    </a>
                    <a href="admin_withdrawals.php" class="action-btn">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Process Withdrawals</span>
                    </a>
                    <a href="admin_reports.php" class="action-btn">
                        <i class="fas fa-chart-pie"></i>
                        <span>Generate Reports</span>
                    </a>
                    
                    <a href="admin_deposits.php" class="action-btn">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Approve Deposits</span>
                    </a>
                </div>
            </div>

            <!-- Recent Data -->
            <div class="recent-data-grid">
                <!-- Recent Transactions -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                        <a href="admin_transactions.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_transactions)): ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['username']); ?></td>
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
                                        <td colspan="4" style="text-align: center;">No recent transactions</td>
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
                        <a href="admin_withdrawals.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_withdrawals)): ?>
                                    <?php foreach ($recent_withdrawals as $withdrawal): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($withdrawal['username']); ?></td>
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
                                        <td colspan="3" style="text-align: center;">No recent withdrawals</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Replace the entire JavaScript section in your dashboard.php with this: -->

<script>
    // Mobile menu functionality (same as before)
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

    // Initialize charts after DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeRevenueChart();
        initializeUserActivityChart();
        
        // Add event listeners for chart controls
        setupChartControls();
    });

    // Initialize Revenue Chart
    function initializeRevenueChart() {
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        revenueChart = new Chart(revenueCtx, {
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
                    },
                    {
                        label: 'Winnings',
                        data: <?php echo json_encode($revenue_data['winnings']); ?>,
                        borderColor: '#fdcb6e',
                        backgroundColor: 'rgba(253, 203, 110, 0.1)',
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
                            color: 'rgba(255, 255, 255, 0.7)',
                            padding: 15,
                            usePointStyle: true
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
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                animations: {
                    tension: {
                        duration: 1000,
                        easing: 'linear'
                    }
                }
            }
        });
    }

    // Initialize User Activity Chart
    function initializeUserActivityChart() {
        const userActivityCtx = document.getElementById('userActivityChart').getContext('2d');
        const userActivityChart = new Chart(userActivityCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active Users', 'New Users', 'Returning Users'],
                datasets: [{
                    data: [
                        <?php echo $user_activity_data['active']; ?>,
                        <?php echo $user_activity_data['new']; ?>,
                        <?php echo $user_activity_data['returning']; ?>
                    ],
                    backgroundColor: [
                        '#ff3c7e',
                        '#0fb4c9',
                        '#00cec9'
                    ],
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }

    // Setup chart controls
    function setupChartControls() {
        // Chart period switching
        document.querySelectorAll('.chart-action-btn').forEach(button => {
            button.addEventListener('click', function() {
                const period = this.dataset.period;
                
                document.querySelectorAll('.chart-action-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Show/hide custom date range
                const customDateRange = document.getElementById('customDateRange');
                if (period === 'custom') {
                    customDateRange.classList.add('active');
                } else {
                    customDateRange.classList.remove('active');
                    updateChartData(period);
                }
                
                currentPeriod = period;
            });
        });

        // Apply custom date range
        document.getElementById('applyCustomDate').addEventListener('click', function() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date');
                return;
            }
            
            updateChartData('custom', startDate, endDate);
        });
    }

    // Update chart data via AJAX
    function updateChartData(period, startDate = '', endDate = '') {
        console.log('Updating chart data for period:', period, 'Start:', startDate, 'End:', endDate);
        
        // Show loading state
        const chartTitle = document.querySelector('.chart-container .chart-title');
        const originalTitle = chartTitle.textContent;
        chartTitle.textContent = 'Loading...';
        
        // Create request data
        const requestData = {
            period: period,
            referral_code: '<?php echo $referral_code; ?>'
        };
        
        if (period === 'custom' && startDate && endDate) {
            requestData.start_date = startDate;
            requestData.end_date = endDate;
        }
        
        console.log('Sending request data:', requestData);
        
        // Send AJAX request
        fetch('get_chart_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(requestData)
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Received data:', data);
            if (data.success) {
                // Update chart data
                revenueChart.data.labels = data.dates;
                revenueChart.data.datasets[0].data = data.deposits;
                revenueChart.data.datasets[1].data = data.withdrawals;
                revenueChart.data.datasets[2].data = data.winnings;
                revenueChart.update('none'); // Use 'none' to prevent animation issues
                
                // Update chart title with period info
                let periodText = '';
                switch(period) {
                    case 'week':
                        periodText = ' (Last 7 Days)';
                        break;
                    case 'this_month':
                        periodText = ' (This Month)';
                        break;
                    case 'last_month':
                        periodText = ' (Last Month)';
                        break;
                    case 'custom':
                        periodText = ` (${formatDate(data.start_date)} to ${formatDate(data.end_date)})`;
                        break;
                }
                chartTitle.textContent = 'Revenue Overview' + periodText;
                
                console.log('Chart updated successfully');
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading chart data: ' + error.message);
            chartTitle.textContent = originalTitle;
        });
    }

    // Format date for display
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

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