<?php
// super_admin_all_users.php
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

// Get filter parameters with localStorage fallback
$filter_admin_id = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : (
    isset($_COOKIE['super_admin_user_filter_admin_id']) ? intval($_COOKIE['super_admin_user_filter_admin_id']) : ''
);
$filter_admin_username = isset($_GET['admin_username']) ? sanitize_input($conn, $_GET['admin_username']) : (
    isset($_COOKIE['super_admin_user_filter_admin_username']) ? $_COOKIE['super_admin_user_filter_admin_username'] : ''
);
$search_term = isset($_GET['search']) ? sanitize_input($conn, $_GET['search']) : (
    isset($_COOKIE['super_admin_user_filter_search']) ? $_COOKIE['super_admin_user_filter_search'] : ''
);
$status_filter = isset($_GET['status']) ? sanitize_input($conn, $_GET['status']) : (
    isset($_COOKIE['super_admin_user_filter_status']) ? $_COOKIE['super_admin_user_filter_status'] : ''
);

// Save filters to cookies for persistence
if (isset($_GET['admin_id']) || isset($_GET['admin_username']) || isset($_GET['search']) || isset($_GET['status'])) {
    setcookie('super_admin_user_filter_admin_id', $filter_admin_id, time() + (86400 * 30), "/");
    setcookie('super_admin_user_filter_admin_username', $filter_admin_username, time() + (86400 * 30), "/");
    setcookie('super_admin_user_filter_search', $search_term, time() + (86400 * 30), "/");
    setcookie('super_admin_user_filter_status', $status_filter, time() + (86400 * 30), "/");
}

// Pagination parameters
$records_per_page = isset($_GET['records']) ? intval($_GET['records']) : (
    isset($_COOKIE['super_admin_user_records_per_page']) ? intval($_COOKIE['super_admin_user_records_per_page']) : 20
);
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Save records per page to cookie
if (isset($_GET['records'])) {
    setcookie('super_admin_user_records_per_page', $records_per_page, time() + (86400 * 30), "/");
}

// Build SQL for users with filters
$users_sql = "SELECT u.*, a.username as admin_username, a.id as admin_id,
             (SELECT COUNT(*) FROM bets b WHERE b.user_id = u.id) as total_bets,
             (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed') as total_deposits,
             (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'withdrawal' AND t.status = 'completed') as total_withdrawals,
             (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id AND t.type = 'bet') as transaction_count
             FROM users u
             JOIN admins a ON u.referral_code = a.referral_code
             WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total
             FROM users u
             JOIN admins a ON u.referral_code = a.referral_code
             WHERE 1=1";

$params = [];
$param_types = '';

// Apply filters
if ($filter_admin_id) {
    $users_sql .= " AND a.id = ?";
    $count_sql .= " AND a.id = ?";
    $params[] = $filter_admin_id;
    $param_types .= 'i';
}

if ($filter_admin_username) {
    $users_sql .= " AND a.username LIKE ?";
    $count_sql .= " AND a.username LIKE ?";
    $params[] = '%' . $filter_admin_username . '%';
    $param_types .= 's';
}

if ($search_term) {
    $users_sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $count_sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_like = '%' . $search_term . '%';
    $params = array_merge($params, [$search_like, $search_like, $search_like]);
    $param_types .= str_repeat('s', 3);
}

if ($status_filter) {
    $users_sql .= " AND u.status = ?";
    $count_sql .= " AND u.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Get total count for pagination
$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($param_types, ...$params);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add ordering and pagination to main query
$users_sql .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$users_params = $params;
$users_param_types = $param_types;
$users_params[] = $records_per_page;
$users_params[] = $offset;
$users_param_types .= 'ii';

// Get filtered users
$stmt_users = $conn->prepare($users_sql);
if (!empty($users_params)) {
    $stmt_users->bind_param($users_param_types, ...$users_params);
}
$stmt_users->execute();
$users_result = $stmt_users->get_result();
$users = [];

if ($users_result && $users_result->num_rows > 0) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Get all admins for filter dropdown
$admins_sql = "SELECT id, username FROM admins WHERE status = 'active' ORDER BY username";
$admins_result = $conn->query($admins_sql);
$admins = [];
if ($admins_result && $admins_result->num_rows > 0) {
    while ($row = $admins_result->fetch_assoc()) {
        $admins[] = $row;
    }
}

// Handle user status updates
if (isset($_POST['update_user_status'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = sanitize_input($conn, $_POST['status']);
    
    $update_sql = "UPDATE users SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("si", $new_status, $user_id);
    
    if ($stmt->execute()) {
        $success_message = "User status updated successfully!";
    } else {
        $error_message = "Error updating user status: " . $stmt->error;
    }
    $stmt->close();
}

// Handle balance adjustments
if (isset($_POST['adjust_balance'])) {
    $user_id = intval($_POST['user_id']);
    $adjustment_type = sanitize_input($conn, $_POST['adjustment_type']);
    $amount = floatval($_POST['amount']);
    $reason = sanitize_input($conn, $_POST['reason']);
    
    // Get current balance
    $balance_sql = "SELECT balance FROM users WHERE id = ?";
    $stmt = $conn->prepare($balance_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $balance_result = $stmt->get_result();
    $current_balance = $balance_result->fetch_assoc()['balance'];
    $stmt->close();
    
    // Calculate new balance
    if ($adjustment_type === 'add') {
        $new_balance = $current_balance + $amount;
        $transaction_type = 'adjustment';
    } else {
        $new_balance = $current_balance - $amount;
        $transaction_type = 'adjustment';
    }
    
    // Update user balance
    $update_sql = "UPDATE users SET balance = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("di", $new_balance, $user_id);
    
    if ($stmt->execute()) {
        // Record transaction
        $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')";
        $trans_stmt = $conn->prepare($transaction_sql);
        $trans_stmt->bind_param("isdids", $user_id, $transaction_type, $amount, $current_balance, $new_balance, $reason);
        $trans_stmt->execute();
        $trans_stmt->close();
        
        $success_message = "Balance adjusted successfully!";
    } else {
        $error_message = "Error adjusting balance: " . $stmt->error;
    }
    $stmt->close();
}

// Build URL with parameters
function buildUrl($params = []) {
    global $filter_admin_id, $filter_admin_username, $search_term, $status_filter, $records_per_page, $page;
    
    $base_params = [
        'admin_id' => $filter_admin_id,
        'admin_username' => $filter_admin_username,
        'search' => $search_term,
        'status' => $status_filter,
        'records' => $records_per_page,
        'page' => $page
    ];
    
    return '?' . http_build_query(array_merge($base_params, $params));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Users - Super Admin</title>
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
            left: -260px;
            top: 0;
            overflow-x: scroll;
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
            width: 100%;
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

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.2rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
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

        .status-active {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-suspended {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-banned {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 2rem;
            max-width: 90%;
            width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.2rem;
            border: 1px solid var(--border-color);
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Confirmation modal */
        .confirmation-modal .modal-content {
            max-width: 500px;
        }

        .confirmation-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .tbody{
            position:relative;
            overflow-x:scroll;
        }
        .tbody::-webkit-scrollbar{
            display: none;
        }

        /* ========== RESPONSIVE DESIGN ========== */
        
        /* Large screens (993px and above) */
        @media (min-width: 993px) {
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
        }

        /* Medium screens (769px - 992px) */
        @media (max-width: 992px) and (min-width: 769px) {
            .sidebar {
                width: 80px;
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
        }

        /* Small screens (768px and below) */
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
        }

        /* Extra small devices (576px and below) */
        @media (max-width: 576px) {
            .main-content {
                padding: 1rem 0.8rem;
            }
            .welcome h1 {
                font-size: 1.5rem;
            }
            .welcome p {
                font-size: 0.9rem;
            }
            .filter-section {
                padding: 1rem;
            }
            .filter-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .data-table {
                font-size: 0.85rem;
            }
            .data-table th, .data-table td {
                padding: 0.8rem 0.5rem;
            }
            .action-buttons {
                flex-direction: column;
                gap: 0.3rem;
            }
            .btn-sm {
                padding: 0.4rem 0.6rem;
                font-size: 0.8rem;
            }
        }

        /* Ultra small devices (400px and below) */
        @media (max-width: 400px) {
            .main-content {
                padding: 0.8rem 0.5rem;
            }
            .header {
                margin-top: 3.5rem;
                gap: 0.8rem;
            }
            .welcome h1 {
                font-size: 1.3rem;
            }
            .welcome p {
                font-size: 0.85rem;
            }
            .filter-section {
                padding: 0.8rem;
            }
            .form-control {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }
            .btn {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            .data-table {
                font-size: 0.8rem;
            }
            .data-table th, .data-table td {
                padding: 0.6rem 0.4rem;
            }
            .status {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }
            .action-buttons .btn-sm {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }
            .modal-content {
                padding: 1rem;
                width: 95%;
            }
            .user-info-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
            .info-card {
                padding: 0.8rem;
            }
            .tabs {
                flex-direction: column;
            }
            .tab {
                padding: 0.8rem;
                text-align: center;
            }
            .confirmation-buttons {
                flex-direction: column;
            }
            .confirmation-buttons .btn {
                width: 100%;
            }
            .menu-toggle {
                top: 0.8rem;
                left: 0.8rem;
                padding: 0.4rem;
                font-size: 1.3rem;
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
            min-width: 1200px;
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

        .status-suspended {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-banned {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        .admin-badge {
            background: rgba(255, 193, 7, 0.2);
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
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

        .filter-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding: 1rem 0;
            border-top: 1px solid var(--border-color);
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-light);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-block;
        }

        .page-btn:hover:not(.disabled) {
            background: rgba(255, 255, 255, 0.1);
        }

        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-numbers {
            display: flex;
            gap: 0.5rem;
        }

        .page-number {
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s ease;
            display: inline-block;
        }

        .page-number.active {
            background: var(--primary);
            color: white;
        }

        .page-number:not(.active):hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .records-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .active-filters {
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(11, 180, 201, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(11, 180, 201, 0.2);
        }

        .active-filter-tag {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            font-size: 0.8rem;
        }

        .active-filter-tag .remove {
            margin-left: 0.5rem;
            cursor: pointer;
            color: var(--danger);
            text-decoration: none;
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
            max-width: 500px;
            border: 1px solid var(--border-color);
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .pagination {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .pagination-controls {
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
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
                <a href="super_admin_manage_admins.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>Manage Admins</span>
                </a>
                <a href="super_admin_all_users.php" class="menu-item active">
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
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>All Users</h1>
                    <p>Super Admin Panel - Manage all platform users across all admins</p>
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

            <!-- Filters Section -->
            <div class="filter-section">
                <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-filter"></i> Filter Users
                </h3>
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="form-group">
                        <label class="form-label">Admin Username</label>
                        <input type="text" name="admin_username" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_admin_username); ?>" 
                               placeholder="Search by admin username">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Admin ID</label>
                        <input type="number" name="admin_id" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_admin_id); ?>" 
                               placeholder="Filter by admin ID">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Search Users</label>
                        <input type="text" name="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search_term); ?>" 
                               placeholder="Search username, email, phone">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="" style='background-color: var(--dark);'>All Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : '';  ?> style='background-color: var(--dark);'>Active</option>
                            <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?> style='background-color: var(--dark);'>Suspended</option>
                            <option value="banned" <?php echo $status_filter == 'banned' ? 'selected' : ''; ?> style='background-color: var(--dark);'>Banned</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" class="btn btn-outline" style="width: 100%;" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Active Filters -->
            <?php if ($filter_admin_id || $filter_admin_username || $search_term || $status_filter): ?>
            <div class="active-filters">
                <h4><i class="fas fa-filter"></i> Active Filters</h4>
                <?php if ($filter_admin_id): ?>
                    <span class="active-filter-tag">
                        Admin ID: <?php echo $filter_admin_id; ?>
                        <a href="<?php echo buildUrl(['admin_id' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($filter_admin_username): ?>
                    <span class="active-filter-tag">
                        Admin Username: "<?php echo htmlspecialchars($filter_admin_username); ?>"
                        <a href="<?php echo buildUrl(['admin_username' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($search_term): ?>
                    <span class="active-filter-tag">
                        Search: "<?php echo htmlspecialchars($search_term); ?>"
                        <a href="<?php echo buildUrl(['search' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($status_filter): ?>
                    <span class="active-filter-tag">
                        Status: <?php echo ucfirst($status_filter); ?>
                        <a href="<?php echo buildUrl(['status' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <a href="<?php echo buildUrl(['admin_id' => '', 'admin_username' => '', 'search' => '', 'status' => '', 'page' => 1]); ?>" class="btn btn-outline btn-sm" style="margin-left: 1rem;">
                    <i class="fas fa-times"></i> Clear All
                </a>
            </div>
            <?php endif; ?>

            <!-- Users List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-users"></i> All Users</h2>
                    <span class="badge">Total: <?php echo $total_records; ?> users</span>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User Info</th>
                                <th>Admin</th>
                                <th>Balance</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($user['email']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($user['phone']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="admin-badge">
                                                <?php echo htmlspecialchars($user['admin_username']); ?>
                                            </span>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                                                ID: <?php echo $user['admin_id']; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>₹<?php echo number_format($user['balance'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.8rem;">
                                                <div>Bets: <?php echo $user['total_bets']; ?></div>
                                                <div>Deposits: ₹<?php echo number_format($user['total_deposits'] ?? 0, 2); ?></div>
                                                <div>Withdrawals: ₹<?php echo number_format($user['total_withdrawals'] ?? 0, 2); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <!-- Status Update Form -->
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <select name="status" onchange="this.form.submit()" style="background: var(--card-bg); color: var(--text-light); border: 1px solid var(--border-color); border-radius: 4px; padding: 0.3rem; font-size: 0.8rem;">
                                                        <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                        <option value="suspended" <?php echo $user['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                                        <option value="banned" <?php echo $user['status'] == 'banned' ? 'selected' : ''; ?>>Banned</option>
                                                    </select>
                                                    <input type="hidden" name="update_user_status" value="1">
                                                </form>

                                                <button class="btn btn-warning btn-sm" onclick="openAdjustBalanceModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', <?php echo $user['balance']; ?>)">
                                                    <i class="fas fa-coins"></i> Adjust
                                                </button>

                                                <a href="user_details.php?user_id=<?php echo $user['id']; ?>" class="btn btn-info btn-sm" target="_blank">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                        <i class="fas fa-users"></i> No users found matching your filters
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> - 
                        <?php echo min($page * $records_per_page, $total_records); ?> of <?php echo number_format($total_records); ?> users
                    </div>
                    
                    <div class="pagination-controls">
                        <!-- Records per page -->
                        <select class="records-select" onchange="updateRecordsPerPage(this.value)">
                            <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?> style='background-color: var(--dark);'>10 per page</option>
                            <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?> style='background-color: var(--dark);'>20 per page</option>
                            <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?> style='background-color: var(--dark);'>50 per page</option>
                            <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?> style='background-color: var(--dark);'>100 per page</option>
                        </select>
                        
                        <!-- Previous button -->
                        <a href="<?php echo $page > 1 ? buildUrl(['page' => $page - 1]) : '#'; ?>" 
                           class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        
                        <!-- Page numbers -->
                        <div class="page-numbers">
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="<?php echo buildUrl(['page' => $i]); ?>" 
                                   class="page-number <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                        
                        <!-- Next button -->
                        <a href="<?php echo $page < $total_pages ? buildUrl(['page' => $page + 1]) : '#'; ?>" 
                           class="page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjust Balance Modal -->
    <div class="modal" id="adjustBalanceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-coins"></i> Adjust User Balance</h3>
                <button class="close-modal" onclick="closeModal('adjustBalanceModal')">&times;</button>
            </div>
            <form id="adjustBalanceForm" method="POST">
                <input type="hidden" name="user_id" id="adjust_user_id">
                <input type="hidden" name="adjust_balance" value="1">
                
                <div class="form-group">
                    <label class="form-label">User</label>
                    <input type="text" class="form-control" id="adjust_user_name" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Current Balance</label>
                    <input type="text" class="form-control" id="current_balance" readonly>
                </div>
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">Action</label>
                        <select name="adjustment_type" class="form-control" required>
                            <option value="add" style='background-color: var(--dark);'>Add Balance</option>
                            <option value="subtract" style='background-color: var(--dark);'>Subtract Balance</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="Enter reason for balance adjustment" required></textarea>
                </div>
                
                <div class="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('adjustBalanceModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Adjust Balance</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Save filters to localStorage
        function saveFiltersToLocalStorage() {
            const formData = new FormData(document.getElementById('filterForm'));
            const filters = {
                admin_username: formData.get('admin_username') || '',
                admin_id: formData.get('admin_id') || '',
                search: formData.get('search') || '',
                status: formData.get('status') || ''
            };
            localStorage.setItem('super_admin_user_filters', JSON.stringify(filters));
        }

        // Load filters from localStorage
        function loadFiltersFromLocalStorage() {
            const savedFilters = localStorage.getItem('super_admin_user_filters');
            if (savedFilters) {
                const filters = JSON.parse(savedFilters);
                document.querySelector('input[name="admin_username"]').value = filters.admin_username || '';
                document.querySelector('input[name="admin_id"]').value = filters.admin_id || '';
                document.querySelector('input[name="search"]').value = filters.search || '';
                document.querySelector('select[name="status"]').value = filters.status || '';
            }
        }

        // Clear all filters
        function clearFilters() {
            localStorage.removeItem('super_admin_user_filters');
            window.location.href = '<?php echo buildUrl(['admin_id' => '', 'admin_username' => '', 'search' => '', 'status' => '', 'page' => 1]); ?>';
        }

        // Update records per page
        function updateRecordsPerPage(records) {
            const url = new URL(window.location.href);
            url.searchParams.set('records', records);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        // Modal functions
        function openAdjustBalanceModal(userId, userName, currentBalance) {
            document.getElementById('adjust_user_id').value = userId;
            document.getElementById('adjust_user_name').value = userName;
            document.getElementById('current_balance').value = '₹' + currentBalance.toLocaleString('en-IN', {minimumFractionDigits: 2});
            document.getElementById('adjustBalanceModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }

        // Save filters when form changes
        document.getElementById('filterForm').addEventListener('input', saveFiltersToLocalStorage);

        // Load saved filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadFiltersFromLocalStorage();
            
            // Save current page state
            const currentState = {
                page: <?php echo $page; ?>,
                records: <?php echo $records_per_page; ?>
            };
            localStorage.setItem('super_admin_user_pagination', JSON.stringify(currentState));
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus on search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                const modals = document.getElementsByClassName('modal');
                for (let modal of modals) {
                    if (modal.style.display === 'block') {
                        modal.style.display = 'none';
                    }
                }
            }
        });

        // Auto-submit status changes with confirmation
        document.querySelectorAll('select[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                const userName = this.closest('tr').querySelector('strong').textContent;
                const newStatus = this.value;
                
                if (confirm(`Are you sure you want to change ${userName}'s status to ${newStatus}?`)) {
                    this.form.submit();
                } else {
                    this.form.reset();
                }
            });
        });
    </script>
</body>
</html>