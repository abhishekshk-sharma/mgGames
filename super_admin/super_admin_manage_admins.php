<?php
// super_admin_manage_admins.php
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

// Pagination settings
$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Search parameters
$search_username = isset($_GET['search_username']) ? sanitize_input($conn, $_GET['search_username']) : '';
$search_status = isset($_GET['search_status']) ? sanitize_input($conn, $_GET['search_status']) : '';
$search_date_from = isset($_GET['search_date_from']) ? sanitize_input($conn, $_GET['search_date_from']) : '';
$search_date_to = isset($_GET['search_date_to']) ? sanitize_input($conn, $_GET['search_date_to']) : '';

// Build WHERE clause for search
$where_clauses = [];
$params = [];
$param_types = '';

if (!empty($search_username)) {
    $where_clauses[] = "a.username LIKE ?";
    $params[] = "%$search_username%";
    $param_types .= 's';
}

if (!empty($search_status)) {
    $where_clauses[] = "a.status = ?";
    $params[] = $search_status;
    $param_types .= 's';
}

if (!empty($search_date_from)) {
    $where_clauses[] = "DATE(a.created_at) >= ?";
    $params[] = $search_date_from;
    $param_types .= 's';
}

if (!empty($search_date_to)) {
    $where_clauses[] = "DATE(a.created_at) <= ?";
    $params[] = $search_date_to;
    $param_types .= 's';
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Handle status updates
if (isset($_POST['update_status'])) {
    $admin_id = intval($_POST['admin_id']);
    $new_status = sanitize_input($conn, $_POST['status']);
    
    // Check if we're changing to active and referral_code is NULL
    $check_referral_sql = "SELECT referral_code FROM admins WHERE id = ?";
    $check_stmt = $conn->prepare($check_referral_sql);
    $check_stmt->bind_param("i", $admin_id);
    $check_stmt->execute();
    $check_stmt->bind_result($current_referral_code);
    $check_stmt->fetch();
    $check_stmt->close();
    
    $referral_code = $current_referral_code;
    
    // If changing to active and no referral code exists, generate one
    if ($new_status === 'active' && empty($current_referral_code)) {
        $referral_code = generateReferralCode(8);
        
        $update_sql = "UPDATE admins SET status = ?, referral_code = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ssi", $new_status, $referral_code, $admin_id);
    } else {
        $update_sql = "UPDATE admins SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("si", $new_status, $admin_id);
    }
    
    if ($stmt->execute()) {
        $success_message = "Admin status updated successfully!";
        if (!empty($referral_code)) {
            $success_message .= " Referral code generated: " . $referral_code;
        }
        
        // If status is being changed to 'active' and no broker limits exist, prepare for limit setup
        if ($new_status === 'active') {
            $check_limits_sql = "SELECT id FROM broker_limit WHERE admin_id = ?";
            $check_stmt = $conn->prepare($check_limits_sql);
            $check_stmt->bind_param("i", $admin_id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows === 0) {
                // No broker limits exist, set session flag to show limit modal
                $_SESSION['setup_limits_for'] = $admin_id;
            }
            $check_stmt->close();
        }
    } else {
        $error_message = "Error updating admin status: " . $stmt->error;
    }
    $stmt->close();
}

// Function to generate random alphanumeric referral code
function generateReferralCode($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }

    echo "<script> alert('Referral code generated: " . $randomString . "'); </script>";
    return $randomString;
}

// Handle broker limit updates/inserts
if (isset($_POST['save_limits'])) {
    $admin_id = intval($_POST['admin_id']);
    $deposit_limit = intval($_POST['deposit_limit']);
    $withdrawal_limit = intval($_POST['withdrawal_limit']);
    $bet_limit = intval($_POST['bet_limit']);
    $pnl_ratio = isset($_POST['pnl_ratio']) ? sanitize_input($conn, $_POST['pnl_ratio']) : NULL;
    $auto_forward = isset($_POST['auto_forward_enabled']) ? 1 : 0;
    
    // Check if broker limit already exists
    $check_sql = "SELECT id FROM broker_limit WHERE admin_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $admin_id);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        // Update existing limits
        $update_sql = "UPDATE broker_limit SET deposit_limit = ?, withdrawal_limit = ?, bet_limit = ?, pnl_ratio = ?, auto_forward_enabled = ? WHERE admin_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("iiisii", $deposit_limit, $withdrawal_limit, $bet_limit, $pnl_ratio, $auto_forward, $admin_id);
    } else {
        // Insert new limits
        $insert_sql = "INSERT INTO broker_limit (admin_id, deposit_limit, withdrawal_limit, bet_limit, pnl_ratio, auto_forward_enabled) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("iiisii", $admin_id, $deposit_limit, $withdrawal_limit, $bet_limit, $pnl_ratio, $auto_forward);
    }
    
    if ($stmt->execute()) {
        $success_message = "Broker limits saved successfully!";
        unset($_SESSION['setup_limits_for']);
    } else {
        $error_message = "Error saving broker limits: " . $stmt->error;
    }
    $stmt->close();
    $check_stmt->close();
}

// Handle admin updates
if (isset($_POST['update_admin'])) {
    $admin_id = intval($_POST['admin_id']);
    $username = sanitize_input($conn, $_POST['username']);
    $phone = sanitize_input($conn, $_POST['phone']);
    $email = sanitize_input($conn, $_POST['email']);
    $adhar = sanitize_input($conn, $_POST['adhar']);
    $pan = sanitize_input($conn, $_POST['pan']);
    $upiId = sanitize_input($conn, $_POST['upiId']);
    $address = sanitize_input($conn, $_POST['address']);
    
    $update_sql = "UPDATE admins SET username = ?, phone = ?, email = ?, adhar = ?, pan = ?, upiId = ?, address = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sisssssi", $username, $phone, $email, $adhar, $pan, $upiId, $address, $admin_id);
    
    if ($stmt->execute()) {
        $success_message = "Admin details updated successfully!";
    } else {
        $error_message = "Error updating admin details: " . $stmt->error;
    }
    $stmt->close();
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM admins a $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_admins = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_admins / $per_page);
$count_stmt->close();

// Get all admins with their broker limits (with pagination)
$admins_sql = "SELECT a.*, 
               bl.deposit_limit, bl.withdrawal_limit, bl.bet_limit, bl.pnl_ratio, bl.auto_forward_enabled,
               (SELECT COUNT(*) FROM users u WHERE u.referral_code = a.referral_code) as user_count,
               (SELECT COUNT(*) FROM bets b JOIN users u ON b.user_id = u.id WHERE u.referral_code = a.referral_code) as total_bets,
               (SELECT SUM(t.amount) FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.referral_code = a.referral_code AND t.type = 'deposit' AND t.status = 'completed') as total_deposits
               FROM admins a
               LEFT JOIN broker_limit bl ON a.id = bl.admin_id
               $where_sql
               ORDER BY a.created_at DESC
               LIMIT ?, ?";
               
$params[] = $offset;
$params[] = $per_page;
$param_types .= 'ii';

$admins_stmt = $conn->prepare($admins_sql);
if (!empty($params)) {
    $admins_stmt->bind_param($param_types, ...$params);
}
$admins_stmt->execute();
$admins_result = $admins_stmt->get_result();
$admins = [];
if ($admins_result && $admins_result->num_rows > 0) {
    while ($row = $admins_result->fetch_assoc()) {
        $admins[] = $row;
    }
}
$admins_stmt->close();

// Get admin details for view modal
if (isset($_GET['view_admin'])) {
    $view_admin_id = intval($_GET['view_admin']);
    $admin_details_sql = "SELECT a.*, 
                         bl.deposit_limit, bl.withdrawal_limit, bl.bet_limit, bl.pnl_ratio, bl.auto_forward_enabled,
                         (SELECT COUNT(*) FROM users u WHERE u.referral_code = a.referral_code) as user_count,
                         (SELECT COUNT(*) FROM bets b JOIN users u ON b.user_id = u.id WHERE u.referral_code = a.referral_code) as total_bets,
                         (SELECT SUM(t.amount) FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.referral_code = a.referral_code AND t.type = 'deposit' AND t.status = 'completed') as total_deposits
                         FROM admins a
                         LEFT JOIN broker_limit bl ON a.id = bl.admin_id
                         WHERE a.id = ?";
    $stmt = $conn->prepare($admin_details_sql);
    $stmt->bind_param("i", $view_admin_id);
    $stmt->execute();
    $admin_details_result = $stmt->get_result();
    $admin_details = $admin_details_result->fetch_assoc();
    $stmt->close();
}

// Get users under specific admin
if (isset($_GET['admin_users'])) {
    $admin_users_id = intval($_GET['admin_users']);
    $admin_users_sql = "SELECT a.username, a.referral_code FROM admins a WHERE a.id = ?";
    $stmt = $conn->prepare($admin_users_sql);
    $stmt->bind_param("i", $admin_users_id);
    $stmt->execute();
    $admin_info_result = $stmt->get_result();
    $admin_info = $admin_info_result->fetch_assoc();
    $stmt->close();
    
    $users_sql = "SELECT u.*, 
                 (SELECT COUNT(*) FROM bets b WHERE b.user_id = u.id) as bet_count,
                 (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed') as total_deposited,
                 (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'withdrawal' AND t.status = 'completed') as total_withdrawn
                 FROM users u 
                 WHERE u.referral_code = ?
                 ORDER BY u.created_at DESC";
    $stmt = $conn->prepare($users_sql);
    $stmt->bind_param("s", $admin_info['referral_code']);
    $stmt->execute();
    $users_result = $stmt->get_result();
    $admin_users = [];
    if ($users_result && $users_result->num_rows > 0) {
        while ($row = $users_result->fetch_assoc()) {
            $admin_users[] = $row;
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - Super Admin</title>
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
        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9;
            --accent: #00cec9;
            --dark: #1a1a2e;
            --darker: #16213e;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --suspended: #ce4c2bff;
            --super-admin: #ffc107;
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
        }

        body::-webkit-scrollbar{
            display:none;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.2rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--border-color);
        }

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
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th, .data-table td {
            padding: 1rem;
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

        .status {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-banned {
            background: rgba(250, 68, 68, 0.2);
            color: var(--danger);  
            border: 1px solid rgba(253, 83, 83, 0.3);
        }

        .status-suspend {
            background: rgba(214, 114, 48, 0.2);
            color: var(--suspended);
            border: 1px solid rgba(214, 84, 48, 0.3);
        }

        .status-inactive {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: black;
        }

        .btn-info {
            background: var(--accent);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }

        .modal-content {
            background: var(--card-bg);
            margin: 5% auto;
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            
            max-width: 100vw;
            border: 1px solid var(--border-color);
            max-height: 100vh;
            overflow-y: auto;
        }

        .modal-content::-webkit-scrollbar{
            display:none;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .document-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .document-preview:hover {
            transform: scale(1.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
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

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }

        /* Image Viewer Modal Styles */
        .image-viewer-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            backdrop-filter: blur(5px);
        }

        .image-viewer-content {
            position: relative;
            margin: auto;
            padding: 0;
            width: 90%;
            height: 90%;
            max-width: 1200px;
            max-height: 1200px;
            display: flex;
            align-items: center;
            justify-content: center;
            top: 50%;
            transform: translateY(-50%);
        }

        .image-container {
            position: relative;
            display: inline-block;
            max-width: 100%;
            max-height: 100%;
            cursor: move;
            touch-action: none;
        }

        .resizable-image {
            max-width: 100%;
            max-height: 100%;
            display: block;
            user-select: none;
            -webkit-user-drag: none;
        }

        .resize-handle {
            position: absolute;
            width: 16px;
            height: 16px;
            background: var(--primary);
            border: 2px solid white;
            border-radius: 50%;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .resize-handle.top-left {
            top: -8px;
            left: -8px;
            cursor: nwse-resize;
        }

        .resize-handle.top-right {
            top: -8px;
            right: -8px;
            cursor: nesw-resize;
        }

        .resize-handle.bottom-left {
            bottom: -8px;
            left: -88px;
            cursor: nesw-resize;
        }

        .resize-handle.bottom-right {
            bottom: -8px;
            right: -8px;
            cursor: nwse-resize;
        }

        .zoom-controls {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            background: var(--card-bg);
            padding: 10px;
            border-radius: 25px;
            border: 1px solid var(--border-color);
            z-index: 100;
        }

        .zoom-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 60, 126, 0.3);
        }

        .zoom-btn:hover {
            background: var(--secondary);
            transform: scale(1.1);
        }

        .zoom-btn.reset {
            background: var(--warning);
            color: black;
        }

        .close-viewer {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 100;
            background: rgba(0,0,0,0.5);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .close-viewer:hover {
            background: var(--danger);
            transform: rotate(90deg);
        }

        /* Document preview enhancements */
        .document-preview-container {
            position: relative;
            display: inline-block;
            margin: 10px;
        }

        .document-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .document-preview:hover {
            transform: scale(1.05);
            border-color: var(--primary);
            box-shadow: 0 6px 20px rgba(255, 60, 126, 0.4);
        }

        .document-label {
            display: block;
            text-align: center;
            margin-top: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Mobile touch improvements */
        @media (max-width: 768px) {
            .resize-handle {
                width: 20px;
                height: 20px;
            }
            
            .resize-handle.top-left {
                top: -10px;
                left: -10px;
            }
            
            .resize-handle.top-right {
                top: -10px;
                right: -10px;
            }
            
            .resize-handle.bottom-left {
                bottom: -10px;
                left: -10px;
            }
            
            .resize-handle.bottom-right {
                bottom: -10px;
                right: -10px;
            }
            
            .zoom-btn {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
            }
            
            .image-viewer-content {
                width: 95%;
                height: 95%;
            }
        }

        /* Loading state for images */
        .image-loading {
            opacity: 0.7;
            filter: blur(2px);
        }

        .image-loaded {
            opacity: 1;
            filter: blur(0);
            transition: opacity 0.3s ease, filter 0.3s ease;
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

        /* Search Form Styles */
        .search-form {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2.2rem;
            border: 1px solid var(--border-color);
        }
        
        .search-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .search-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            gap: 0.5rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            background: var(--card-bg);
            border: 1px solid var(--border-color);
        }
        
        .pagination a:hover {
            background: var(--primary);
        }
        
        .pagination .current {
            background: var(--primary);
            color: white;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        /* Full Screen Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 260px; /* Account for sidebar width */
            top: 0;
            width: calc(100% - 260px); /* Full width minus sidebar */
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        
        .modal-content {
            background: var(--card-bg);
            margin: 1% auto 2% auto;
            padding: 2rem;
            border-radius: 0;
            width: 100%;
            height: 96%;
            border: none;
            overflow-y: auto;
        }
        
        .modal-content::-webkit-scrollbar{
            display:none;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            background: var(--card-bg);
            z-index: 10;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }
        
        /* Responsive adjustments for modals */
        @media (max-width: 768px) {
            .modal {
                left: 0;
                width: 100%;
            }
            
            .modal-content {
                padding: 1rem;
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
                <a href="super_admin_dashboard.php" class="menu-item ">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="super_admin_manage_admins.php" class="menu-item active">
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
                <a href="edit_result.php" class="menu-item ">
                    <i class="fa-solid fa-puzzle-piece"></i>
                    <span>Edit Result</span>
                </a>
                <a href="super_admin_applications.php" class="menu-item">
                    <i class="fas fa-tasks"></i>
                    <span>All Applications</span>
                </a>
                <a href="super_admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Platform Reports</span>
                </a>
                <a href="profit_loss.php" class="menu-item ">
                    <i class="fa-solid fa-sack-dollar"></i>
                    <span>Profit & Loss</span>
                </a>
                <a href="adminlog.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>Admin Logs</span>
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
                    <h1>Manage Admins</h1>
                    <p>Super Admin Panel - Manage all platform administrators</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span id="currentTime"><?php echo date('F j, Y g:i A'); ?></span>
                    </div>
                    
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span class="admin-name">Super Admin: <?php echo htmlspecialchars($super_admin_username); ?></span>
                    </div>
                    
                    <a href="super_admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Search Form -->
            <div class="search-form">
                <form method="GET" action="">
                    <div class="search-row">
                        <div class="form-group">
                            <label class="form-label" for="search_username">Username</label>
                            <input type="text" class="form-control" id="search_username" name="search_username" 
                                   value="<?php echo htmlspecialchars($search_username); ?>" placeholder="Search by username">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="search_status">Status</label>
                            <select class="form-control" id="search_status" name="search_status">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo $search_status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="banned" <?php echo $search_status == 'banned' ? 'selected' : ''; ?>>Banned</option>
                                <option value="suspend" <?php echo $search_status == 'suspend' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="inactive" <?php echo $search_status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="search_date_from">From Date</label>
                            <input type="date" class="form-control" id="search_date_from" name="search_date_from" 
                                   value="<?php echo htmlspecialchars($search_date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="search_date_to">To Date</label>
                            <input type="date" class="form-control" id="search_date_to" name="search_date_to" 
                                   value="<?php echo htmlspecialchars($search_date_to); ?>">
                        </div>
                    </div>
                    
                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="super_admin_manage_admins.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Admins List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-user-shield"></i> All Administrators</h2>
                    <span class="badge">Total: <?php echo $total_admins; ?> admins (Page <?php echo $page; ?> of <?php echo $total_pages; ?>)</span>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Admin Info</th>
                                <th>Status</th>
                                <th>Users</th>
                                <th>Performance</th>
                                <th>Broker Limits</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($admins)): ?>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($admin['email']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                Ref: <?php echo htmlspecialchars($admin['referral_code']); ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-muted);">
                                                Joined: <?php echo date('M j, Y g:i A', strtotime($admin['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $admin['status']; ?>">
                                                <?php echo ucfirst($admin['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo $admin['user_count']; ?></strong> users
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <div>Bets: <?php echo $admin['total_bets'] ?? 0; ?></div>
                                                <div>Deposits: ₹<?php echo number_format($admin['total_deposits'] ?? 0, 2); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($admin['deposit_limit']): ?>
                                                <div style="font-size: 0.8rem;">
                                                    <div>Deposit: ₹<?php echo number_format($admin['deposit_limit']); ?></div>
                                                    <div>Withdrawal: ₹<?php echo number_format($admin['withdrawal_limit']); ?></div>
                                                    <div>Bet: ₹<?php echo number_format($admin['bet_limit']); ?></div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--warning); font-size: 0.8rem;">Not Set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <!-- Status Update Form -->
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                    <select name="status" onchange="this.form.submit()" style="background: var(--card-bg); color: var(--text-light); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.3rem;">
                                                        <option value="active" <?php echo $admin['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="banned" <?php echo $admin['status'] == 'banned' ? 'selected' : ''; ?>>Banned</option>
                                                        <option value="suspend" <?php echo $admin['status'] == 'suspend' ? 'selected' : ''; ?>>Suspend</option>
                                                        <option value="inactive" <?php echo $admin['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                    </select>
                                                    <input type="hidden" name="update_status" value="1">
                                                </form>

                                                <button class="btn btn-info btn-sm" onclick="openViewModal(<?php echo $admin['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>

                                                <button class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo $admin['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>

                                                <a href="?<?php 
                                                    echo http_build_query(array_merge($_GET, ['admin_users' => $admin['id']]));
                                                ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-users"></i> Users
                                                </a>

                                                <?php if ($admin['deposit_limit']): ?>
                                                    <button class="btn btn-success btn-sm" onclick="openLimitsModal(<?php echo $admin['id']; ?>)">
                                                        <i class="fas fa-sliders-h"></i> Limits
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                        <i class="fas fa-user-shield"></i> No administrators found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a>
                    <?php else: ?>
                        <span class="disabled">First</span>
                        <span class="disabled">Prev</span>
                    <?php endif; ?>

                    <?php
                    // Show page numbers
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
                    <?php else: ?>
                        <span class="disabled">Next</span>
                        <span class="disabled">Last</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- View Users Modal -->
            <?php if (isset($_GET['admin_users']) && isset($admin_users)): ?>
                <div class="modal" id="usersModal" style="display: block;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>
                                <i class="fas fa-users"></i> 
                                Users under <?php echo htmlspecialchars($admin_info['username']); ?>
                                <span class="badge"><?php echo count($admin_users); ?> users</span>
                            </h3>
                            <button class="close-modal" onclick="closeModal('usersModal')">&times;</button>
                        </div>
                        
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>User Info</th>
                                        <th>Balance</th>
                                        <th>Bets</th>
                                        <th>Deposits</th>
                                        <th>Withdrawals</th>
                                        <th>Status</th>
                                        <th>Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_users as $user): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                </div>
                                            </td>
                                            <td>₹<?php echo number_format($user['balance'], 2); ?></td>
                                            <td><?php echo $user['bet_count']; ?></td>
                                            <td>₹<?php echo number_format($user['total_deposited'] ?? 0, 2); ?></td>
                                            <td>₹<?php echo number_format($user['total_withdrawn'] ?? 0, 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                            <button class="btn btn-outline" onclick="closeModal('usersModal')">Close</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Admin Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-shield"></i> Admin Details</h3>
                <button class="close-modal" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="viewModalContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Edit Admin Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Admin Details</h3>
                <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form id="editAdminForm" method="POST">
                <input type="hidden" name="admin_id" id="edit_admin_id">
                <input type="hidden" name="update_admin" value="1">
                <div id="editModalContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Broker Limits Modal -->
    <div class="modal" id="limitsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sliders-h"></i> Broker Limits</h3>
                <button class="close-modal" onclick="closeModal('limitsModal')">&times;</button>
            </div>
            <form id="limitsForm" method="POST">
                <input type="hidden" name="admin_id" id="limits_admin_id">
                <input type="hidden" name="save_limits" value="1">
                <div id="limitsModalContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('limitsModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Limits</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image Viewer Modal -->
    <div class="image-viewer-modal" id="imageViewer">
        <span class="close-viewer" onclick="closeImageViewer()">&times;</span>
        <div class="image-viewer-content">
            <div class="image-container">
                <img id="viewerImage" class="resizable-image" src="" alt="">
                <div class="resize-handle top-left"></div>
                <div class="resize-handle top-right"></div>
                <div class="resize-handle bottom-left"></div>
                <div class="resize-handle bottom-right"></div>
            </div>
        </div>
        <div class="zoom-controls">
            <button class="zoom-btn" onclick="zoomOut()" title="Zoom Out">-</button>
            <button class="zoom-btn reset" onclick="resetZoom()" title="Reset Zoom">⟲</button>
            <button class="zoom-btn" onclick="zoomIn()" title="Zoom In">+</button>
        </div>
    </div>


    <script>
        function openViewModal(adminId) {
            fetch(`get_admin_details.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('viewModalContent').innerHTML = data;
                    document.getElementById('viewModal').style.display = 'block';
                });
        }

        function openEditModal(adminId) {
            document.getElementById('edit_admin_id').value = adminId;
            fetch(`get_admin_edit.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editModalContent').innerHTML = data;
                    document.getElementById('editModal').style.display = 'block';
                });
        }

        function openLimitsModal(adminId) {
            document.getElementById('limits_admin_id').value = adminId;
            fetch(`get_broker_limits.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('limitsModalContent').innerHTML = data;
                    document.getElementById('limitsModal').style.display = 'block';
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Remove URL parameters
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        

        function downloadDocument(filePath, fileName) {
            const link = document.createElement('a');
            link.href = filePath;
            link.download = fileName;
            link.click();
        }

        // Auto-show limits modal if needed
        <?php if (isset($_SESSION['setup_limits_for'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openLimitsModal(<?php echo $_SESSION['setup_limits_for']; ?>);
            });
        <?php unset($_SESSION['setup_limits_for']); endif; ?>

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            }
        }

        // Image Viewer Functionality
        let currentImage = null;
        let isDragging = false;
        let isResizing = false;
        let currentResizeHandle = null;
        let startX, startY, startWidth, startHeight, startLeft, startTop;

        function openImageViewer(imageSrc, imageAlt) {
            const viewer = document.getElementById('imageViewer');
            const image = document.getElementById('viewerImage');
            const container = document.querySelector('.image-container');
            
            image.src = imageSrc;
            image.alt = imageAlt;
            currentImage = image;
            
            // Reset transformations
            container.style.transform = 'translate(0px, 0px) scale(1)';
            container.style.width = 'auto';
            container.style.height = 'auto';
            
            viewer.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeImageViewer() {
            document.getElementById('imageViewer').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentImage = null;
        }

        function zoomIn() {
            const container = document.querySelector('.image-container');
            const currentScale = parseFloat(container.style.transform.split('scale(')[1]) || 1;
            const newScale = Math.min(currentScale * 1.2, 5);
            container.style.transform = container.style.transform.replace(/scale\([^)]*\)/, `scale(${newScale})`);
        }

        function zoomOut() {
            const container = document.querySelector('.image-container');
            const currentScale = parseFloat(container.style.transform.split('scale(')[1]) || 1;
            const newScale = Math.max(currentScale / 1.2, 0.1);
            container.style.transform = container.style.transform.replace(/scale\([^)]*\)/, `scale(${newScale})`);
        }

        function resetZoom() {
            const container = document.querySelector('.image-container');
            container.style.transform = 'translate(0px, 0px) scale(1)';
            container.style.width = 'auto';
            container.style.height = 'auto';
        }

        // Mouse/Touch events for dragging
        function startDrag(e) {
            if (isResizing) return;
            
            isDragging = true;
            const container = document.querySelector('.image-container');
            const rect = container.getBoundingClientRect();
            
            startX = (e.clientX || e.touches[0].clientX) - rect.left;
            startY = (e.clientY || e.touches[0].clientY) - rect.top;
            
            container.style.cursor = 'grabbing';
        }

        function doDrag(e) {
            if (!isDragging || isResizing) return;
            
            e.preventDefault();
            const container = document.querySelector('.image-container');
            const x = (e.clientX || e.touches[0].clientX) - startX;
            const y = (e.clientY || e.touches[0].clientY) - startY;
            
            container.style.left = x + 'px';
            container.style.top = y + 'px';
        }

        function stopDrag() {
            isDragging = false;
            document.querySelector('.image-container').style.cursor = 'grab';
        }

        // Resize functionality
        function startResize(e, handle) {
            e.stopPropagation();
            isResizing = true;
            currentResizeHandle = handle;
            
            const container = document.querySelector('.image-container');
            const rect = container.getBoundingClientRect();
            
            startX = e.clientX || e.touches[0].clientX;
            startY = e.clientY || e.touches[0].clientY;
            startWidth = rect.width;
            startHeight = rect.height;
            startLeft = rect.left;
            startTop = rect.top;
        }

        function doResize(e) {
            if (!isResizing) return;
            
            e.preventDefault();
            const container = document.querySelector('.image-container');
            const currentX = e.clientX || e.touches[0].clientX;
            const currentY = e.clientY || e.touches[0].clientY;
            
            const deltaX = currentX - startX;
            const deltaY = currentY - startY;
            
            let newWidth = startWidth;
            let newHeight = startHeight;
            
            switch (currentResizeHandle) {
                case 'bottom-right':
                    newWidth = Math.max(50, startWidth + deltaX);
                    newHeight = Math.max(50, startHeight + deltaY);
                    break;
                case 'bottom-left':
                    newWidth = Math.max(50, startWidth - deltaX);
                    newHeight = Math.max(50, startHeight + deltaY);
                    container.style.left = (startLeft + deltaX) + 'px';
                    break;
                case 'top-right':
                    newWidth = Math.max(50, startWidth + deltaX);
                    newHeight = Math.max(50, startHeight - deltaY);
                    container.style.top = (startTop + deltaY) + 'px';
                    break;
                case 'top-left':
                    newWidth = Math.max(50, startWidth - deltaX);
                    newHeight = Math.max(50, startHeight - deltaY);
                    container.style.left = (startLeft + deltaX) + 'px';
                    container.style.top = (startTop + deltaY) + 'px';
                    break;
            }
            
            container.style.width = newWidth + 'px';
            container.style.height = newHeight + 'px';
        }

        function stopResize() {
            isResizing = false;
            currentResizeHandle = null;
        }

        // Event listeners for image viewer
        document.addEventListener('DOMContentLoaded', function() {
            const viewer = document.getElementById('imageViewer');
            const container = document.querySelector('.image-container');
            
            if (container) {
                // Mouse events
                container.addEventListener('mousedown', startDrag);
                document.addEventListener('mousemove', doDrag);
                document.addEventListener('mouseup', stopDrag);
                
                // Touch events for mobile
                container.addEventListener('touchstart', startDrag);
                document.addEventListener('touchmove', doDrag);
                document.addEventListener('touchend', stopDrag);
                
                // Resize handles
                const resizeHandles = document.querySelectorAll('.resize-handle');
                resizeHandles.forEach(handle => {
                    handle.addEventListener('mousedown', (e) => startResize(e, handle.classList[1]));
                    handle.addEventListener('touchstart', (e) => startResize(e, handle.classList[1]));
                });
                
                document.addEventListener('mousemove', doResize);
                document.addEventListener('touchmove', doResize);
                document.addEventListener('mouseup', stopResize);
                document.addEventListener('touchend', stopResize);
            }
            
            // Close viewer when clicking outside image
            viewer.addEventListener('click', function(e) {
                if (e.target === viewer) {
                    closeImageViewer();
                }
            });
            
            // Keyboard controls
            document.addEventListener('keydown', function(e) {
                if (viewer.style.display === 'block') {
                    switch(e.key) {
                        case 'Escape':
                            closeImageViewer();
                            break;
                        case '+':
                        case '=':
                            zoomIn();
                            break;
                        case '-':
                            zoomOut();
                            break;
                        case '0':
                            resetZoom();
                            break;
                    }
                }
            });
        });

        // Update the openViewModal function to include image viewer
        function openViewModal(adminId) {
            fetch(`get_admin_details.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('viewModalContent').innerHTML = data;
                    document.getElementById('viewModal').style.display = 'block';
                    
                    // Initialize image viewers after modal content is loaded
                    initializeImageViewers();
                });
        }

        function initializeImageViewers() {
            // Add click events to all document preview images
            const previewImages = document.querySelectorAll('.document-preview');
            previewImages.forEach(img => {
                img.addEventListener('click', function() {
                    openImageViewer(this.src, this.alt);
                });
            });
        }

        // Update modal functions to handle full-screen modals
        function openViewModal(adminId) {
            fetch(`get_admin_details.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('viewModalContent').innerHTML = data;
                    document.getElementById('viewModal').style.display = 'block';
                    initializeImageViewers();
                });
        }

        function openEditModal(adminId) {
            document.getElementById('edit_admin_id').value = adminId;
            fetch(`get_admin_edit.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editModalContent').innerHTML = data;
                    document.getElementById('editModal').style.display = 'block';
                });
        }

        function openLimitsModal(adminId) {
            document.getElementById('limits_admin_id').value = adminId;
            fetch(`get_broker_limits.php?admin_id=${adminId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('limitsModalContent').innerHTML = data;
                    document.getElementById('limitsModal').style.display = 'block';
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Remove URL parameters but keep search filters
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.delete('view_admin');
            urlParams.delete('admin_users');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, document.title, newUrl);
        }

        // Auto-show limits modal if needed
        <?php if (isset($_SESSION['setup_limits_for'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                openLimitsModal(<?php echo $_SESSION['setup_limits_for']; ?>);
            });
        <?php unset($_SESSION['setup_limits_for']); endif; ?>

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    const urlParams = new URLSearchParams(window.location.search);
                    urlParams.delete('view_admin');
                    urlParams.delete('admin_users');
                    const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                    window.history.replaceState({}, document.title, newUrl);
                }
            }
        }
    </script>
</body>
</html>