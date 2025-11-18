<?php
// super_admin_withdrawals.php
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

// Handle withdrawal actions
$message = '';
$message_type = '';

// Approve withdrawal
if (isset($_POST['approve_withdrawal'])) {
    $withdrawal_id = $_POST['withdrawal_id'];
    
    // Get withdrawal details
    $sql = "SELECT * FROM withdrawals WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $withdrawal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $withdrawal = $result->fetch_assoc();
        
        // Update withdrawal status
        $update_sql = "UPDATE withdrawals SET status = 'completed' WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $withdrawal_id);
        
        if ($stmt->execute()) {
            // Update transaction record
            $transaction_sql = "UPDATE transactions SET status = 'completed' WHERE wd_id = ? AND type = 'withdrawal'";
            $stmt = $conn->prepare($transaction_sql);
            $stmt->bind_param("i", $withdrawal_id);
            $stmt->execute();

            $message = "Withdrawal approved successfully!";
            $message_type = "success";
        } else {
            $message = "Error approving withdrawal: " . $conn->error;
            $message_type = "error";
        }
    } else {
        $message = "Withdrawal not found!";
        $message_type = "error";
    }
}

// Reject withdrawal
if (isset($_POST['reject_withdrawal'])) {
    $withdrawal_id = $_POST['withdrawal_id'];
    $reason = $conn->real_escape_string($_POST['reject_reason']);
    
    // Get withdrawal details to refund user balance
    $sql = "SELECT * FROM transactions WHERE wd_id = ? AND type = 'withdrawal'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $withdrawal_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $withdrawal = $result->fetch_assoc();
        $user_id = $withdrawal['user_id'];
        $amount = $withdrawal['amount'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update withdrawal status to rejected
            $update_sql = "UPDATE withdrawals SET status = 'rejected', admin_notes = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("si", $reason, $withdrawal_id);
            $stmt->execute();
            
            // Update transaction status
            $transaction_sql = "UPDATE transactions SET status = 'rejected', description = CONCAT('Withdrawal rejected: ', ?) WHERE wd_id = ?";
            $stmt = $conn->prepare($transaction_sql);
            $stmt->bind_param("si", $reason, $withdrawal_id);
            $stmt->execute();
            
            // Refund amount to user balance
            $user_sql = "UPDATE users SET balance = balance + ? WHERE id = ?";
            $stmt = $conn->prepare($user_sql);
            $stmt->bind_param("di", $amount, $user_id);
            $stmt->execute();
            
            $conn->commit();
            $message = "Withdrawal rejected and amount refunded!";
            $message_type = "success";
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error rejecting withdrawal: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "Withdrawal not found!";
        $message_type = "error";
    }
}

// Pagination and filtering setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_admin = isset($_GET['search_admin']) ? trim($_GET['search_admin']) : '';
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for withdrawals count
$count_sql = "SELECT COUNT(w.id) as total 
              FROM withdrawals w 
              JOIN users u ON w.user_id = u.id 
              JOIN admins a ON u.referral_code = a.referral_code 
              WHERE 1=1";
$params = [];
$types = '';

if ($search_admin) {
    $count_sql .= " AND (a.username LIKE ? OR a.id = ?)";
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($filter_status) {
    $count_sql .= " AND w.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Build query for withdrawals with pagination
$sql = "SELECT w.*, u.username, u.email, u.phone, u.balance as user_balance, 
               a.username as admin_username, a.id as admin_id
        FROM withdrawals w 
        JOIN users u ON w.user_id = u.id 
        JOIN admins a ON u.referral_code = a.referral_code 
        WHERE 1=1";

if ($search_admin) {
    $sql .= " AND (a.username LIKE ? OR a.id = ?)";
}

if ($filter_status) {
    $sql .= " AND w.status = ?";
}

$sql .= " ORDER BY w.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [];
$types = '';

if ($search_admin) {
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($filter_status) {
    $params[] = $filter_status;
    $types .= 's';
}

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$withdrawals = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
}

// Get stats for dashboard
$pending_withdrawals = 0;
$total_withdrawal_amount = 0;

$stats_sql = "SELECT 
    COUNT(w.id) as pending_count,
    SUM(w.amount) as total_amount 
    FROM withdrawals w 
    JOIN users u ON w.user_id = u.id 
    JOIN admins a ON u.referral_code = a.referral_code 
    WHERE w.status = 'pending'";
    
if ($search_admin) {
    $stats_sql .= " AND (a.username LIKE ? OR a.id = ?)";
}

$stmt_stats = $conn->prepare($stats_sql);
if ($search_admin) {
    $stmt_stats->bind_param("ss", $search_admin, $search_admin);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
if ($stats_result && $stats_result->num_rows > 0) {
    $stats = $stats_result->fetch_assoc();
    $pending_withdrawals = $stats['pending_count'];
    $total_withdrawal_amount = $stats['total_amount'] ? $stats['total_amount'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawals - Super Admin</title>
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

    .amount-icon {
        background: rgba(255, 60, 126, 0.2);
        color: var(--primary);
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
        min-width: 1000px;
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

    .status-completed {
        background: rgba(0, 184, 148, 0.2);
        color: var(--success);
        border: 1px solid rgba(0, 184, 148, 0.3);
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

    .status-cancelled {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 1px solid rgba(108, 117, 125, 0.3);
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
        min-width: 120px;
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
    .withdrawals-cards {
        display: none;
        flex-direction: column;
        gap: 1rem;
    }

    .withdrawal-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        border: 1px solid var(--border-color);
    }

    .withdrawal-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .withdrawal-row:last-child {
        margin-bottom: 0;
        border-bottom: none;
    }

    .withdrawal-label {
        color: var(--text-muted);
        font-weight: 500;
        min-width: 120px;
    }

    .withdrawal-value {
        text-align: right;
        flex: 1;
    }

    .withdrawal-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
        flex-wrap: wrap;
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

    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        padding: 1rem;
    }

    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 2rem;
        max-width: 500px;
        width: 100%;
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

    .payment-method {
        padding: 0.3rem 0.8rem;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 500;
        display: inline-block;
        background: rgba(11, 180, 201, 0.2);
        color: var(--secondary);
        border: 1px solid rgba(11, 180, 201, 0.3);
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
        
        .withdrawals-cards {
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
        
        .withdrawal-card {
            padding: 0.8rem;
        }
        
        .withdrawal-label {
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
        
        .withdrawal-actions {
            flex-direction: column;
        }
        
        .withdrawal-actions .btn {
            width: 100%;
            justify-content: center;
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

        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games</h2>
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
                <a href="super_admin_withdrawals.php" class="menu-item active">
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
                <a href="profit_loss.php" class="menu-item">
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
                    <h1>Withdrawal Management</h1>
                    <p>Manage and process user withdrawal requests across all admins</p>
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
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Pending Withdrawals</div>
                        <div class="stat-card-icon pending-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $pending_withdrawals; ?></div>
                    <div class="stat-card-desc">Awaiting approval</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Amount</div>
                        <div class="stat-card-icon amount-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">₹<?php echo number_format($total_withdrawal_amount, 2); ?></div>
                    <div class="stat-card-desc">Pending withdrawal amount</div>
                </div>
            </div>

            <!-- Withdrawals Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-list"></i>
                        Withdrawal Requests
                    </h2>
                    
                    <div class="controls-row">
                        <form method="GET" class="filter-form">
                            <div class="form-group">
                                <input type="text" name="search_admin" class="form-control" 
                                       placeholder="Search admin (username or ID)" 
                                       value="<?php echo htmlspecialchars($search_admin); ?>">
                            </div>
                            
                            <div class="form-group">
                                <select name="filter_status" class="form-control" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <div class="limit-selector">
                                <span class="form-label">Show:</span>
                                <select name="limit" class="form-control" onchange="this.form.submit()">
                                    <?php foreach ($allowed_limits as $allowed_limit): ?>
                                        <option value="<?php echo $allowed_limit; ?>" <?php echo $limit == $allowed_limit ? 'selected' : ''; ?>>
                                            <?php echo $allowed_limit; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            
                            <a href="super_admin_withdrawals.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </form>
                    </div>
                </div>

                <!-- Table View -->
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Admin</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Account Details</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($withdrawals)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem; display: block;"></i>
                                        <p style="color: var(--text-muted);">No withdrawal requests found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($withdrawals as $withdrawal): ?>
                                    <tr>
                                        <td>#<?php echo $withdrawal['id']; ?></td>
                                        <td>
                                            <div><strong><?php echo htmlspecialchars($withdrawal['username']); ?></strong></div>
                                            <div style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($withdrawal['email']); ?></div>
                                        </td>
                                        <td>
                                            <span class="admin-info"><?php echo htmlspecialchars($withdrawal['admin_username']); ?> (ID: <?php echo $withdrawal['admin_id']; ?>)</span>
                                        </td>
                                        <td>
                                            <strong style="color: var(--primary);">₹<?php echo number_format($withdrawal['amount'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <span class="payment-method"><?php echo htmlspecialchars($withdrawal['payment_method']); ?></span>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.85rem;">
                                                <?php 
                                                $account_details = json_decode($withdrawal['account_details'], true);
                                                if (is_array($account_details)) {
                                                    foreach ($account_details as $key => $value) {
                                                        echo "<div><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</div>";
                                                    }
                                                } else {
                                                    echo htmlspecialchars($withdrawal['account_details']);
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $withdrawal['status']; ?>">
                                                <?php echo ucfirst($withdrawal['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y g:i A', strtotime($withdrawal['created_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($withdrawal['status'] == 'pending'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="withdrawal_id" value="<?php echo $withdrawal['id']; ?>">
                                                        <button type="submit" name="approve_withdrawal" class="btn btn-success btn-sm" 
                                                                onclick="return confirm('Are you sure you want to approve this withdrawal?')">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    
                                                    <button type="button" class="btn btn-danger btn-sm reject-btn" 
                                                            data-withdrawal-id="<?php echo $withdrawal['id']; ?>">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No actions</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Card View for Mobile -->
                    <div class="withdrawals-cards">
                        <?php if (empty($withdrawals)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                <p>No withdrawal requests found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($withdrawals as $withdrawal): ?>
                                <div class="withdrawal-card">
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">ID</div>
                                        <div class="withdrawal-value">#<?php echo $withdrawal['id']; ?></div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">User</div>
                                        <div class="withdrawal-value">
                                            <div><strong><?php echo htmlspecialchars($withdrawal['username']); ?></strong></div>
                                            <div style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($withdrawal['email']); ?></div>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Admin</div>
                                        <div class="withdrawal-value">
                                            <span class="admin-info"><?php echo htmlspecialchars($withdrawal['admin_username']); ?> (ID: <?php echo $withdrawal['admin_id']; ?>)</span>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Amount</div>
                                        <div class="withdrawal-value">
                                            <strong style="color: var(--primary);">₹<?php echo number_format($withdrawal['amount'], 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Payment Method</div>
                                        <div class="withdrawal-value">
                                            <span class="payment-method"><?php echo htmlspecialchars($withdrawal['payment_method']); ?></span>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Account Details</div>
                                        <div class="withdrawal-value">
                                            <?php 
                                            $account_details = json_decode($withdrawal['account_details'], true);
                                            if (is_array($account_details)) {
                                                foreach ($account_details as $key => $value) {
                                                    echo "<div><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</div>";
                                                }
                                            } else {
                                                echo htmlspecialchars($withdrawal['account_details']);
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Status</div>
                                        <div class="withdrawal-value">
                                            <span class="status status-<?php echo $withdrawal['status']; ?>">
                                                <?php echo ucfirst($withdrawal['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="withdrawal-row">
                                        <div class="withdrawal-label">Date</div>
                                        <div class="withdrawal-value">
                                            <?php echo date('M j, Y g:i A', strtotime($withdrawal['created_at'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($withdrawal['status'] == 'pending'): ?>
                                        <div class="withdrawal-actions">
                                            <form method="POST" style="flex: 1;">
                                                <input type="hidden" name="withdrawal_id" value="<?php echo $withdrawal['id']; ?>">
                                                <button type="submit" name="approve_withdrawal" class="btn btn-success" 
                                                        onclick="return confirm('Are you sure you want to approve this withdrawal?')" style="width: 100%;">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="btn btn-danger reject-btn" 
                                                    data-withdrawal-id="<?php echo $withdrawal['id']; ?>" style="flex: 1;">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&filter_status=<?php echo $filter_status; ?>&search_admin=<?php echo urlencode($search_admin); ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Withdrawal</h3>
                <button type="button" class="modal-close" id="closeRejectModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="withdrawal_id" id="rejectWithdrawalId">
                <div class="form-group">
                    <label for="reject_reason" class="form-label">Reason for Rejection</label>
                    <textarea name="reject_reason" id="reject_reason" class="form-control" rows="4" 
                              placeholder="Please provide a reason for rejecting this withdrawal..." required></textarea>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-secondary" id="cancelReject" style="flex: 1;">Cancel</button>
                    <button type="submit" name="reject_withdrawal" class="btn btn-danger" style="flex: 1;">
                        Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        // Reject Modal Functionality
        const rejectButtons = document.querySelectorAll('.reject-btn');
        const rejectModal = document.getElementById('rejectModal');
        const closeRejectModal = document.getElementById('closeRejectModal');
        const cancelReject = document.getElementById('cancelReject');
        const rejectForm = document.getElementById('rejectForm');
        const rejectWithdrawalId = document.getElementById('rejectWithdrawalId');

        rejectButtons.forEach(button => {
            button.addEventListener('click', function() {
                const withdrawalId = this.getAttribute('data-withdrawal-id');
                rejectWithdrawalId.value = withdrawalId;
                rejectModal.classList.add('active');
            });
        });

        function closeRejectModalFunc() {
            rejectModal.classList.remove('active');
        }

        closeRejectModal.addEventListener('click', closeRejectModalFunc);
        cancelReject.addEventListener('click', closeRejectModalFunc);

        // Close modal when clicking outside
        rejectModal.addEventListener('click', function(e) {
            if (e.target === rejectModal) {
                closeRejectModalFunc();
            }
        });

        // Update current time
        function updateTime() {
            const now = new Date();
            const options = { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
                hour12: true 
            };
            document.getElementById('currentTime').textContent = now.toLocaleDateString('en-US', options);
        }

        // Update time every minute
        setInterval(updateTime, 60000);
        updateTime(); // Initial call

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>