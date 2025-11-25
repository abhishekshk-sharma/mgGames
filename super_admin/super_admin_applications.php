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