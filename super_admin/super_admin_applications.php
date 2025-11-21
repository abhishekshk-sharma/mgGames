<?php
// super_admin_applications.php
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



// Handle application actions
$message = '';
$message_type = '';

// Approve application
if (isset($_POST['approve_application'])) {


    
    $request_id = $_POST['request_id'];
    
    $sql = "UPDATE admin_requests SET status = 'approved' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);
    
    if ($stmt->execute()) {
        $message = "Application approved successfully!";
        $message_type = "success";
    } else {
        $message = "Error approving application: " . $conn->error;
        $message_type = "error";
    }

    $conn->autocommit(false);

    try{
        //Fetch application details
        $stmt = $conn->prepare("SELECT * FROM admin_requests WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $app_result = $stmt->get_result();

        if($app_result && $app_result->num_rows > 0){
            $application = $app_result->fetch_assoc();
            $admin_id = $application['admin_id'];
            $user_id = $application['user_id'];
            $title = $application['title'];

            //checking the title

            if($title === 'Balance Update Request'){
                $amount = $application['amount'];
                //Update user's balance
                $stmt = $conn->prepare("UPDATE users SET balance =  ? WHERE id = ?");
                $stmt->bind_param("di", $amount, $user_id);
                

                if($stmt->execute()){
                    $message = "Balance updated successfully!";
                    $message_type = "success";
                }
            }
            else if($title === 'User Deletion'){
                //Delete user account
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);

                if($stmt->execute()){
                    $message = "User deleted successfully!";
                    $message_type = "success";
                }

            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error approving application: " . $e->getMessage();
        $message_type = "error";
    }
}

// Reject application
if (isset($_POST['reject_application'])) {
    $request_id = $_POST['request_id'];
    
    $sql = "UPDATE admin_requests SET status = 'rejected' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);
    
    if ($stmt->execute()) {
        $message = "Application rejected successfully!";
        $message_type = "success";
    } else {
        $message = "Error rejecting application: " . $conn->error;
        $message_type = "error";
    }
}

// Pagination and filtering setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_admin = isset($_GET['search_admin']) ? trim($_GET['search_admin']) : '';
$search_user = isset($_GET['search_user']) ? trim($_GET['search_user']) : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for applications count
$count_sql = "SELECT COUNT(ar.id) as total 
              FROM admin_requests ar 
              LEFT JOIN admins a ON ar.admin_id = a.id 
              LEFT JOIN users u ON ar.user_id = u.id 
              WHERE 1=1";
$params = [];
$types = '';

if ($search_admin) {
    $count_sql .= " AND (a.username LIKE ? OR a.id = ?)";
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($search_user) {
    $count_sql .= " AND (u.username LIKE ? OR u.id = ?)";
    $params[] = "%$search_user%";
    $params[] = $search_user;
    $types .= 'ss';
}

if ($filter_status) {
    $count_sql .= " AND ar.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

// Date filtering
if ($date_filter === 'current_month') {
    $count_sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE)";
} elseif ($date_filter === 'last_month') {
    $count_sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH)";
} elseif ($date_filter === 'custom' && $start_date && $end_date) {
    $count_sql .= " AND DATE(ar.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Build query for applications with pagination
$sql = "SELECT ar.*, a.username as admin_username, u.username as user_username,
               u.email as user_email, u.phone as user_phone
        FROM admin_requests ar 
        LEFT JOIN admins a ON ar.admin_id = a.id 
        LEFT JOIN users u ON ar.user_id = u.id 
        WHERE 1=1";

if ($search_admin) {
    $sql .= " AND (a.username LIKE ? OR a.id = ?)";
}

if ($search_user) {
    $sql .= " AND (u.username LIKE ? OR u.id = ?)";
}

if ($filter_status) {
    $sql .= " AND ar.status = ?";
}

// Date filtering for main query
if ($date_filter === 'current_month') {
    $sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE)";
} elseif ($date_filter === 'last_month') {
    $sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH)";
} elseif ($date_filter === 'custom' && $start_date && $end_date) {
    $sql .= " AND DATE(ar.created_at) BETWEEN ? AND ?";
}

$sql .= " ORDER BY ar.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [];
$types = '';

if ($search_admin) {
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($search_user) {
    $params[] = "%$search_user%";
    $params[] = $search_user;
    $types .= 'ss';
}

if ($filter_status) {
    $params[] = $filter_status;
    $types .= 's';
}

if ($date_filter === 'custom' && $start_date && $end_date) {
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$applications = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
}

// Get stats for dashboard
$pending_applications = 0;
$approved_applications = 0;
$rejected_applications = 0;

$stats_sql = "SELECT 
    COUNT(CASE WHEN ar.status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN ar.status = 'approved' THEN 1 END) as approved_count,
    COUNT(CASE WHEN ar.status = 'rejected' THEN 1 END) as rejected_count
    FROM admin_requests ar 
    LEFT JOIN admins a ON ar.admin_id = a.id 
    LEFT JOIN users u ON ar.user_id = u.id 
    WHERE 1=1";
    
$stats_params = [];
$stats_types = '';

if ($search_admin) {
    $stats_sql .= " AND (a.username LIKE ? OR a.id = ?)";
    $stats_params[] = "%$search_admin%";
    $stats_params[] = $search_admin;
    $stats_types .= 'ss';
}

if ($search_user) {
    $stats_sql .= " AND (u.username LIKE ? OR u.id = ?)";
    $stats_params[] = "%$search_user%";
    $stats_params[] = $search_user;
    $stats_types .= 'ss';
}

// Date filtering for stats
if ($date_filter === 'current_month') {
    $stats_sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE)";
} elseif ($date_filter === 'last_month') {
    $stats_sql .= " AND YEAR(ar.created_at) = YEAR(CURRENT_DATE - INTERVAL 1 MONTH) AND MONTH(ar.created_at) = MONTH(CURRENT_DATE - INTERVAL 1 MONTH)";
} elseif ($date_filter === 'custom' && $start_date && $end_date) {
    $stats_sql .= " AND DATE(ar.created_at) BETWEEN ? AND ?";
    $stats_params[] = $start_date;
    $stats_params[] = $end_date;
    $stats_types .= 'ss';
}

$stmt_stats = $conn->prepare($stats_sql);
if (!empty($stats_params)) {
    $stmt_stats->bind_param($stats_types, ...$stats_params);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
if ($stats_result && $stats_result->num_rows > 0) {
    $stats = $stats_result->fetch_assoc();
    $pending_applications = $stats['pending_count'];
    $approved_applications = $stats['approved_count'];
    $rejected_applications = $stats['rejected_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications - Super Admin</title>
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
            transform: translateX(0);
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

        .pending-icon {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }

        .approved-icon {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }

        .rejected-icon {
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

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
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

        .status-pending {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-approved {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-rejected {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
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

        .user-info {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        /* Filter and Controls */
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .limit-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-control {
            padding: 0.6rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 0.9rem;
            transition: all 0.3s ease;
            min-width: 150px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .form-control option{
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .date-inputs {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .date-inputs .form-control {
            min-width: 140px;
        }

        .btn {
            padding: 0.6rem 1.2rem;
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

        .btn-success {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .btn-success:hover {
            background: rgba(0, 184, 148, 0.3);
        }

        .btn-danger {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        .btn-danger:hover {
            background: rgba(214, 48, 49, 0.3);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            white-space: nowrap;
        }

        .current-time i {
            color: var(--secondary);
        }

        /* Table container for horizontal scrolling */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -1.8rem;
            padding: 0 1.8rem;
        }

        /* Card view for mobile */
        .applications-cards {
            display: none;
            flex-direction: column;
            gap: 1rem;
        }

        .application-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid var(--border-color);
        }

        .application-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .application-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .application-label {
            color: var(--text-muted);
            font-weight: 500;
            min-width: 120px;
        }

        .application-value {
            text-align: right;
            flex: 1;
        }

        .application-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(0, 184, 148, 0.2);
            border-color: rgba(0, 184, 148, 0.3);
            color: var(--success);
        }

        .alert-error {
            background: rgba(214, 48, 49, 0.2);
            border-color: rgba(214, 48, 49, 0.3);
            color: var(--danger);
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1.5rem;
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
            
            .controls-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-form {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 992px) and (min-width: 769px){
            .sidebar{
                width: 80px;
            }
            .menu-item span {
                display: none;
            }
            .menu-toggle {
                display: none;
            }
        }

        /* MOBILE STYLES */
        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
                transform: translateX(-100%);
                left: -260px;
            }
            
            .sidebar.active {
                transform: translateX(0px);
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
                width: 100%;
            }

            .menu-toggle {
                display: block;
            }

            .menu-item {
                justify-content: start;
                padding: 1rem 1.8rem;
            }
            
            .menu-item i {
                margin-right: 12px;
            }

            .menu-item span {
                display: inline-block;
            }

            .header {
                margin-top: 3rem;
            }

            .header-actions {
                flex-direction: column;
                gap: 1rem;
                width: 100%;
            }
            
            .admin-badge, .current-time {
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
            
            .applications-cards {
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
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .form-control {
                min-width: 100%;
            }
            
            .date-inputs {
                width: 100%;
                justify-content: space-between;
            }
            
            .date-inputs .form-control {
                flex: 1;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 260px;
                transform: translateX(-100%);
                left: -260px;
            }
            
            .sidebar.active {
                transform: translateX(0px);
                left: 0;
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
            
            .table-container {
                margin: 0 -0.8rem;
                padding: 0 0.8rem;
            }
            
            .application-card {
                padding: 0.8rem;
            }
            
            .application-label {
                min-width: 100px;
                font-size: 0.9rem;
            }
            
            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
            }
            
            .btn-sm {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .stat-card-value {
                font-size: 2rem;
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
            
            .stat-card {
                padding: 1.2rem;
            }
            
            .stat-card-value {
                font-size: 1.8rem;
            }
            
            .application-actions {
                flex-direction: column;
            }
            
            .application-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games </h2>
            </div>
            <div class="sidebar-menu">
                <a href="super_admin_dashboard.php" class="menu-item ">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="super_admin_manage_admins.php" class="menu-item ">
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
                <a href="super_admin_applications.php" class="menu-item active">
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
                    <h1>Applications Management</h1>
                    <p>Manage all admin requests across the platform</p>
                    
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

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Pending Applications</div>
                        <div class="stat-card-icon pending-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $pending_applications; ?></div>
                    <div class="stat-card-desc">Awaiting action</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Approved Applications</div>
                        <div class="stat-card-icon approved-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $approved_applications; ?></div>
                    <div class="stat-card-desc">Successfully approved</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Rejected Applications</div>
                        <div class="stat-card-icon rejected-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $rejected_applications; ?></div>
                    <div class="stat-card-desc">Applications rejected</div>
                </div>
            </div>

            <!-- Applications Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-tasks"></i> All Applications</h2>
                    <div class="view-all">Total: <?php echo $total_records; ?></div>
                </div>

                <!-- Controls Row -->
                <div class="controls-row">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <input type="text" name="search_admin" class="form-control" 
                                   placeholder="Search admin (username or ID)" 
                                   value="<?php echo htmlspecialchars($search_admin); ?>">
                        </div>
                        
                        <div class="form-group">
                            <input type="text" name="search_user" class="form-control" 
                                   placeholder="Search user (username or ID)" 
                                   value="<?php echo htmlspecialchars($search_user); ?>">
                        </div>
                        
                        <div class="form-group">
                            <select name="filter_status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <select name="date_filter" class="form-control" id="dateFilter">
                                <option value="">All Time</option>
                                <option value="current_month" <?php echo $date_filter == 'current_month' ? 'selected' : ''; ?>>Current Month</option>
                                <option value="last_month" <?php echo $date_filter == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                <option value="custom" <?php echo $date_filter == 'custom' ? 'selected' : ''; ?>>Custom Date Range</option>
                            </select>
                        </div>
                        
                        <div class="date-inputs" id="customDateRange" style="<?php echo $date_filter == 'custom' ? '' : 'display: none;'; ?>">
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" placeholder="Start Date">
                            <span>to</span>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" placeholder="End Date">
                        </div>
                        
                        <div class="limit-selector">
                            <div class="form-group">
                                <label class="form-label">Records per page</label>
                                <select name="limit" class="form-control">
                                    <?php foreach ($allowed_limits as $allowed_limit): ?>
                                        <option value="<?php echo $allowed_limit; ?>" <?php echo $limit == $allowed_limit ? 'selected' : ''; ?>>
                                            <?php echo $allowed_limit; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        
                        <a href="super_admin_applications.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </form>
                </div>
                
                <?php if (!empty($applications)): ?>
                    <!-- Desktop Table View -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Admin</th>
                                    <th>User</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td><?php echo $app['id']; ?></td>
                                        <td>
                                            <span class="admin-info">
                                                <?php echo $app['admin_username'] ? $app['admin_username'] . ' (ID: ' . $app['admin_id'] . ')' : 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="user-info">
                                                <?php echo $app['user_username'] ? $app['user_username'] . ' (ID: ' . $app['user_id'] . ')' : 'N/A'; ?>
                                            </span>
                                            <?php if ($app['user_email']): ?>
                                                <div class="text-muted" style="font-size: 0.8rem;"><?php echo $app['user_email']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['title']); ?></td>
                                        <td><?php echo htmlspecialchars($app['description']); ?></td>
                                        <td>
                                            <?php if ($app['amount'] !== null): ?>
                                                $<?php echo number_format($app['amount'], 2); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $app['status']; ?>">
                                                <?php echo ucfirst($app['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($app['created_at'])); ?></td>
                                        <td>
                                            <?php if ($app['status'] == 'pending'): ?>
                                                <div class="action-buttons">
                                                    <form method="POST" action="super_admin_applications.php" style="display: inline;">
                                                        <input type="hidden" name="request_id" value="<?php echo $app['id']; ?>">

                                                        <input type="hidden" name="approve_application" value="1"> 
                                                        
                                                        <button type="submit" class="btn btn-success btn-sm approve_btn">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="request_id" value="<?php echo $app['id']; ?>">
                                                        <input type="hidden" name="reject_application" value="1">
                                                        <button type="submit" class="btn btn-danger btn-sm reject_btn">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No actions</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="applications-cards">
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card">
                                <div class="application-row">
                                    <span class="application-label">ID:</span>
                                    <span class="application-value"><?php echo $app['id']; ?></span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">Admin:</span>
                                    <span class="application-value">
                                        <span class="admin-info">
                                            <?php echo $app['admin_username'] ? $app['admin_username'] . ' (ID: ' . $app['admin_id'] . ')' : 'N/A'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">User:</span>
                                    <span class="application-value">
                                        <span class="user-info">
                                            <?php echo $app['user_username'] ? $app['user_username'] . ' (ID: ' . $app['user_id'] . ')' : 'N/A'; ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if ($app['user_email']): ?>
                                <div class="application-row">
                                    <span class="application-label">User Email:</span>
                                    <span class="application-value"><?php echo $app['user_email']; ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="application-row">
                                    <span class="application-label">Title:</span>
                                    <span class="application-value"><?php echo htmlspecialchars($app['title']); ?></span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">Description:</span>
                                    <span class="application-value"><?php echo htmlspecialchars($app['description']); ?></span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">Amount:</span>
                                    <span class="application-value">
                                        <?php if ($app['amount'] !== null): ?>
                                            $<?php echo number_format($app['amount'], 2); ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">Status:</span>
                                    <span class="application-value">
                                        <span class="status status-<?php echo $app['status']; ?>">
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="application-row">
                                    <span class="application-label">Date:</span>
                                    <span class="application-value"><?php echo date('M j, Y g:i A', strtotime($app['created_at'])); ?></span>
                                </div>
                                <?php if ($app['status'] == 'pending'): ?>
                                    <div class="application-actions">
                                        <form method="POST" action="super_admin_applications.php" style="width: 100%;">
                                            <input type="hidden" name="request_id" value="<?php echo $app['id']; ?>">
                                            <input type="hidden" name="approve_application" value="1"> 
                                            <button type="submit" class="btn btn-success approve_btn" >
                                                <i class="fas fa-check"></i> Approve Application
                                            </button>
                                        </form>
                                        <form method="POST" style="width: 100%;">
                                            <input type="hidden" name="request_id" value="<?php echo $app['id']; ?>">
                                            <input type="hidden" name="reject_application" value="1"> 
                                            <button type="submit" class="btn btn-danger reject_btn" >
                                                <i class="fas fa-times"></i> Reject Application
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                        <p>No applications found.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>&search_user=<?php echo urlencode($search_user); ?>&date_filter=<?php echo $date_filter; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>&search_user=<?php echo urlencode($search_user); ?>&date_filter=<?php echo $date_filter; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>&search_user=<?php echo urlencode($search_user); ?>&date_filter=<?php echo $date_filter; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>&search_user=<?php echo urlencode($search_user); ?>&date_filter=<?php echo $date_filter; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>&search_user=<?php echo urlencode($search_user); ?>&date_filter=<?php echo $date_filter; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
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

    <!-- jQuery (latest stable version from Google CDN) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <script>
        // Mobile Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        menuToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);

        // Close sidebar when clicking on a menu item on mobile
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // Date filter functionality
        const dateFilter = document.getElementById('dateFilter');
        const customDateRange = document.getElementById('customDateRange');

        dateFilter.addEventListener('change', function() {
            if (this.value === 'custom') {
                customDateRange.style.display = 'flex';
            } else {
                customDateRange.style.display = 'none';
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }

        // Update time initially and every minute
        updateCurrentTime();
        setInterval(updateCurrentTime, 60000);


        // SweetAlert for approve buttons
        $(document).ready(function() {
            $(document).on('click', '.approve_btn', function(e) {
                e.preventDefault(); // Prevent the default form submission

                const form = $(this).closest('form'); // Get the closest form element

                Swal.fire({
                    title: 'Are you sure?',
                    text: "You are about to approve this application.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, approve it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        
                        form[0].submit(); // Submit the form if confirmed
                    }
                });
            });

            // SweetAlert for reject buttons
            $(document).on('click', '.reject_btn', function(e) {
                e.preventDefault(); // Prevent the default form submission

                const form = $(this).closest('form'); // Get the closest form element

                Swal.fire({
                    title: 'Are you sure?',
                    text: "You are about to reject this application.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, reject it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form[0].submit(); // Submit the form if confirmed
                    }
                });
            });
        });
    </script>
</body>
</html>