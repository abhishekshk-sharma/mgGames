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
        $_SESSION['success_message'] = "User status updated successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . buildUrl());
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating user status: " . $stmt->error;
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
        
        $_SESSION['success_message'] = "Balance adjusted successfully!";
        header("Location: " . $_SERVER['PHP_SELF'] . buildUrl());
        exit;
    } else {
        $_SESSION['error_message'] = "Error adjusting balance: " . $stmt->error;
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

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
            <?php 
                unset($_SESSION['success_message']);
            endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
            <?php 
                unset($_SESSION['error_message']);
            endif; ?>

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

                                                <a href="user_details.php?user_id=<?php echo $user['id']; ?>" class="btn btn-info btn-sm">
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