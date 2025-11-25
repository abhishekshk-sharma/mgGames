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

include 'includes/header.php';
?>



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