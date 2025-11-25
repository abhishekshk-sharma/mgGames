<?php
// super_admin_transactions.php
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

// Pagination setup
$limit = 20; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Initialize variables for filtering and searching
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$search_admin = isset($_GET['search_admin']) ? trim($_GET['search_admin']) : '';

// Base query for transactions with admin info
$where_conditions = [];
$params = [];
$types = '';

// Build query conditions
if ($search_admin) {
    // Search by admin username or ID
    $where_conditions[] = "(a.username LIKE ? OR a.id = ?)";
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($filter_type) {
    $where_conditions[] = "t.type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

if ($filter_status) {
    $where_conditions[] = "t.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

// Build WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get total count of transactions
$sql_count = "SELECT COUNT(t.id) as total 
              FROM transactions t 
              JOIN users u ON t.user_id = u.id 
              JOIN admins a ON u.referral_code = a.referral_code 
              $where_clause";
$stmt_count = $conn->prepare($sql_count);
if ($stmt_count) {
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $total_records = $result_count->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
}

// Get transactions with pagination
$transactions = [];
$sql = "SELECT t.*, u.username as user_username, a.username as admin_username, a.id as admin_id
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        JOIN admins a ON u.referral_code = a.referral_code 
        $where_clause
        ORDER BY t.created_at DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Super Admin</title>
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
        z-index: 100;
        display: flex;
        flex-direction: column;
        position: fixed;
        height: 100vh;
        transition: all 0.3s ease;
        overflow-y: auto;
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

    /* Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
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

    .status-cancelled {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 1px solid rgba(108, 117, 125, 0.3);
    }

    /* Filter Form */
    .filter-form {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .form-group {
        margin-bottom: 0;
        flex: 1;
        min-width: 180px;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-light);
        font-weight: 500;
    }

    .form-control {
        padding: 0.8rem 1rem;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        color: var(--text-light);
        font-size: 1rem;
        transition: all 0.3s ease;
        width: 100%;
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
        padding: 0.8rem 1.5rem;
        border: none;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        white-space: nowrap;
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

    /* Table container for horizontal scrolling */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -1.8rem;
        padding: 0 1.8rem;
    }

    /* Card view for mobile */
    .transactions-cards {
        display: none;
        flex-direction: column;
        gap: 1rem;
    }

    .transaction-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        border: 1px solid var(--border-color);
    }

    .transaction-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .transaction-row:last-child {
        margin-bottom: 0;
        border-bottom: none;
    }

    .transaction-label {
        color: var(--text-muted);
        font-weight: 500;
        min-width: 120px;
    }

    .transaction-value {
        text-align: right;
        flex: 1;
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
    }

    @media (max-width: 992px) and (min-width: 769px){
        .sidebar{
            width: 10%;
        }
        .menu-item span {
            display: none;
        }
    }

    @media (max-width: 768px) {
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

        .menu-item {
            justify-content: start;
            padding: 1rem;
        }
        
        .menu-item i {
            margin-right: 12px;
        }

        .header{
            margin-top: 3rem;
        }
        .menu-toggle {
            display: block;
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
        
        .filter-form {
            flex-direction: column;
        }
        
        .form-group {
            min-width: 100%;
        }
        
        .table-container {
            margin: 0 -1rem;
            padding: 0 1rem;
        }
        
        .data-table {
            display: none;
        }
        
        .transactions-cards {
            display: flex;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .view-all {
            align-self: flex-end;
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
        
        .transaction-card {
            padding: 0.8rem;
        }
        
        .transaction-label {
            min-width: 100px;
            font-size: 0.9rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 0.8rem;
            min-width: 35px;
            font-size: 0.9rem;
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
            padding: 0.7rem 1.2rem;
            font-size: 0.9rem;
        }
        
        .transaction-card {
            padding: 0.7rem;
        }
        
        .transaction-row {
            flex-direction: column;
            gap: 0.3rem;
        }
        
        .transaction-label, .transaction-value {
            width: 100%;
            text-align: left;
        }
        
        .admin-badge, .current-time, .logout-btn {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        
        .pagination {
            gap: 0.3rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.4rem 0.7rem;
            min-width: 32px;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 400px) {
        .main-content {
            padding: 0.4rem;
        }
        
        .dashboard-section {
            padding: 0.6rem;
            margin-bottom: 1.5rem;
        }
        
        .header {
            margin-bottom: 1rem;
        }
        
        .welcome h1 {
            font-size: 1.2rem;
        }
        
        .section-title {
            font-size: 1rem;
        }
        
        .btn {
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
        }
        
        .form-control {
            padding: 0.7rem 0.8rem;
            font-size: 0.9rem;
        }
        
        .transaction-card {
            padding: 0.6rem;
        }
        
        .admin-badge, .current-time, .logout-btn {
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.35rem 0.6rem;
            min-width: 30px;
            font-size: 0.75rem;
        }
        
        .status {
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
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
                <a href="super_admin_transactions.php" class="menu-item active">
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
                    <h1>Transactions</h1>
                    <p>View and manage all user transactions across all admins</p>
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

            <!-- Filter Section -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-filter"></i> Filter Transactions</h2>
                </div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Search Admin</label>
                        <input type="text" name="search_admin" class="form-control" placeholder="Admin username or ID" value="<?php echo htmlspecialchars($search_admin); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transaction Type</label>
                        <select name="filter_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="deposit" <?php echo $filter_type == 'deposit' ? 'selected' : ''; ?>>Deposit</option>
                            <option value="withdrawal" <?php echo $filter_type == 'withdrawal' ? 'selected' : ''; ?>>Withdrawal</option>
                            <option value="bet" <?php echo $filter_type == 'bet' ? 'selected' : ''; ?>>Bet</option>
                            <option value="winning" <?php echo $filter_type == 'winning' ? 'selected' : ''; ?>>Winning</option>
                            <option value="bonus" <?php echo $filter_type == 'bonus' ? 'selected' : ''; ?>>Bonus</option>
                            <option value="refund" <?php echo $filter_type == 'refund' ? 'selected' : ''; ?>>Refund</option>
                            <option value="adjustment" <?php echo $filter_type == 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo $filter_status == 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group" style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="super_admin_transactions.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Transactions Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-exchange-alt"></i> All Transactions</h2>
                    <div class="view-all">Total: <?php echo $total_records; ?></div>
                </div>
                
                <?php if (!empty($transactions)): ?>
                    <!-- Desktop Table View -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Admin</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance Before</th>
                                    <th>Balance After</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo $transaction['id']; ?></td>
                                        <td><?php echo $transaction['user_username']; ?></td>
                                        <td><?php echo $transaction['admin_username'] . ' (ID: ' . $transaction['admin_id'] . ')'; ?></td>
                                        <td><?php echo ucfirst($transaction['type']); ?></td>
                                        <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                                        <td>$<?php echo number_format($transaction['balance_before'], 2); ?></td>
                                        <td>$<?php echo number_format($transaction['balance_after'], 2); ?></td>
                                        <td>
                                            <span class="status status-<?php echo $transaction['status']; ?>">
                                                <?php echo ucfirst($transaction['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="transactions-cards">
                        <?php foreach ($transactions as $transaction): ?>
                            <div class="transaction-card">
                                <div class="transaction-row">
                                    <span class="transaction-label">ID:</span>
                                    <span class="transaction-value"><?php echo $transaction['id']; ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">User:</span>
                                    <span class="transaction-value"><?php echo $transaction['user_username']; ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Admin:</span>
                                    <span class="transaction-value"><?php echo $transaction['admin_username'] . ' (ID: ' . $transaction['admin_id'] . ')'; ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Type:</span>
                                    <span class="transaction-value"><?php echo ucfirst($transaction['type']); ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Amount:</span>
                                    <span class="transaction-value">$<?php echo number_format($transaction['amount'], 2); ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Balance Before:</span>
                                    <span class="transaction-value">$<?php echo number_format($transaction['balance_before'], 2); ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Balance After:</span>
                                    <span class="transaction-value">$<?php echo number_format($transaction['balance_after'], 2); ?></span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Status:</span>
                                    <span class="transaction-value">
                                        <span class="status status-<?php echo $transaction['status']; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="transaction-row">
                                    <span class="transaction-label">Date:</span>
                                    <span class="transaction-value"><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                        <p>No transactions found.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo $search_admin ? '&search_admin=' . urlencode($search_admin) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search_admin ? '&search_admin=' . urlencode($search_admin) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
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
                            <a href="?page=<?php echo $i; ?><?php echo $search_admin ? '&search_admin=' . urlencode($search_admin) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search_admin ? '&search_admin=' . urlencode($search_admin) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?><?php echo $search_admin ? '&search_admin=' . urlencode($search_admin) : ''; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
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
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 576 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
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