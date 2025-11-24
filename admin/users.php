
<?php

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

// Handle AJAX requests for pagination FIRST
if (isset($_GET['ajax']) && isset($_GET['view_user'])) {
    $user_id = intval($_GET['view_user']);
    
    // Get admin details for the referral code
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();
    
    // Determine which tab content to return
    if (isset($_GET['bets_page'])) {
        $bets_page = max(1, intval($_GET['bets_page']));
        $per_page = 10;
        $user_bets = getUserBets($conn, $user_id, $bets_page, $per_page, $referral_code);
        displayBetsTab($user_bets, $bets_page, $per_page);
    }
    
    if (isset($_GET['transactions_page'])) {
        $transactions_page = max(1, intval($_GET['transactions_page']));
        $per_page = 10;
        $user_transactions = getUserTransactions($conn, $user_id, $transactions_page, $per_page, $referral_code);
        displayTransactionsTab($user_transactions, $transactions_page, $per_page);
    }
    exit;
}

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code  = $stmt->get_result();
$referral_code = $referral_code->fetch_assoc();

global $referral_code;
// Initialize variables
$search = '';
$date_from = '';
$date_to = '';
$users = [];
$user_details = null;
$user_bets = [];
$user_transactions = [];

// Pagination variables for user details
$bets_page = isset($_GET['bets_page']) ? max(1, intval($_GET['bets_page'])) : 1;
$transactions_page = isset($_GET['transactions_page']) ? max(1, intval($_GET['transactions_page'])) : 1;
$per_page = 10; // Number of items per page

// Handle search and filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    
    // Handle view user details
    if (isset($_GET['view_user'])) {
        $user_id = intval($_GET['view_user']);
        $user_details = getUserDetails($conn, $user_id);
        if ($user_details) {
            $user_bets = getUserBets($conn, $user_id, $bets_page, $per_page, $referral_code);
            $user_transactions = getUserTransactions($conn, $user_id, $transactions_page, $per_page, $referral_code);
        }
    }
    
    // Handle ban user
    if (isset($_GET['ban_user'])) {
        $user_id = intval($_GET['ban_user']);
        banUser($conn, $user_id);
        header("Location: users.php");
        exit;
    }
    
    // Handle unban user
    if (isset($_GET['unban_user'])) {
        $user_id = intval($_GET['unban_user']);
        unbanUser($conn, $user_id);
        header("Location: users.php");
        exit;
    }
    
    // Handle delete user (with confirmation)
    if (isset($_GET['delete_user'])) {
        $user_id = intval($_GET['delete_user']);

        $stmt = $conn->prepare("SELECT * FROM admin_requests WHERE user_id = ? AND title = 'User Deletion' AND status = 'pending'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $pre_result = $stmt->get_result();
        $result = $pre_result->num_rows;
        if ($result > 0) {
            $_SESSION['error_message'] = "A deletion request for this user is already pending.";
            header("Location: users.php");
            exit;
        }
        else{
            

            if (deleteUser($conn, $user_id)) {
                $_SESSION['success_message'] = "Request to delete user submitted successfully!";
                header("Location: users.php");
            } else {
                $_SESSION['error_message'] = "Error deleting user!";
            }
            header("Location: users.php");
        }
        exit;
    }
}

// Get users with filters
$users = getUsers($conn, $search, $date_from, $date_to);

// Function to display bets tab content
function displayBetsTab($user_bets, $current_page, $per_page) {
    ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Game</th>
                <th>Amount</th>
                <th>Potential Win</th>
                <th>Status</th>
                <th>Placed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($user_bets['data'])): ?>
                <?php foreach ($user_bets['data'] as $bet): ?>
                    <tr>
                        <td><?php echo $bet['id']; ?></td>
                        <td><?php echo htmlspecialchars($bet['game_name'] ?? 'N/A'); ?></td>
                        <td>$<?php echo number_format($bet['amount'], 2); ?></td>
                        <td>$<?php echo number_format($bet['potential_win'], 2); ?></td>
                        <td>
                            <span class="status status-<?php echo $bet['status']; ?>">
                                <?php echo ucfirst($bet['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 1.5rem;">
                        <i class="fas fa-info-circle" style="color: var(--text-muted);"></i>
                        No bets found for this user.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($user_bets['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <button data-page="<?php echo $current_page - 1; ?>">&laquo; Previous</button>
        <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $user_bets['total_pages']; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <button data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $user_bets['total_pages']): ?>
            <button data-page="<?php echo $current_page + 1; ?>">Next &raquo;</button>
        <?php else: ?>
            <span class="disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <div class="pagination-info">
        Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to <?php echo min($current_page * $per_page, $user_bets['total']); ?> of <?php echo $user_bets['total']; ?> bets
    </div>
    <?php endif;
}

// Function to display transactions tab content
function displayTransactionsTab($user_transactions, $current_page, $per_page) {
    ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($user_transactions['data'])): ?>
                <?php foreach ($user_transactions['data'] as $transaction): ?>
                    <tr>
                        <td><?php echo $transaction['id']; ?></td>
                        <td>
                            <span class="status <?php echo $transaction['type'] === 'deposit' ? 'status-active' : 'status-warning'; ?>">
                                <?php echo ucfirst($transaction['type']); ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                        <td>
                            <span class="status status-<?php echo $transaction['status']; ?>">
                                <?php echo ucfirst($transaction['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 1.5rem;">
                        <i class="fas fa-info-circle" style="color: var(--text-muted);"></i>
                        No transactions found for this user.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($user_transactions['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <button data-page="<?php echo $current_page - 1; ?>">&laquo; Previous</button>
        <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $user_transactions['total_pages']; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <button data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $user_transactions['total_pages']): ?>
            <button data-page="<?php echo $current_page + 1; ?>">Next &raquo;</button>
        <?php else: ?>
            <span class="disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <div class="pagination-info">
        Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to <?php echo min($current_page * $per_page, $user_transactions['total']); ?> of <?php echo $user_transactions['total']; ?> transactions
    </div>
    <?php endif;
}

// Function to get users with filters
function getUsers($conn, $search = '', $date_from = '', $date_to = '') {
    // Get admin details
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();

    $sql = "SELECT * FROM users WHERE referral_code = '".$referral_code['referral_code']."'";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (username LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($date_from)) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " AND referral_code = '".$referral_code['referral_code']."' ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return $users;
}

// Function to get user details
function getUserDetails($conn, $user_id) {
    // Get admin details
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();

    $sql = "SELECT * FROM users WHERE id = ? AND referral_code = '".$referral_code['referral_code']."'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get user bets with pagination
function getUserBets($conn, $user_id, $page = 1, $per_page = 10, $referral_code = null) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT b.*, g.name as game_name, gt.name as game_type_name 
            FROM bets b 
            JOIN users u ON b.user_id = u.id
            LEFT JOIN games g ON b.game_session_id = g.id 
            LEFT JOIN game_types gt ON b.game_type_id = gt.id 
            WHERE b.user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'
            ORDER BY b.placed_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $conn->error);
        return ['data' => [], 'total' => 0, 'total_pages' => 0];
    }
    
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    
    if (!$stmt->execute()) {
        error_log("SQL Execute Error: " . $stmt->error);
        return ['data' => [], 'total' => 0, 'total_pages' => 0];
    }
    
    $result = $stmt->get_result();
    
    $bets = [];
    while ($row = $result->fetch_assoc()) {
        $bets[] = $row;
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM bets b 
                  JOIN users u ON b.user_id = u.id
                  WHERE b.user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_bets = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_bets / $per_page);
    
    $stmt->close();
    $count_stmt->close();
    
    return [
        'data' => $bets,
        'total' => $total_bets,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'per_page' => $per_page
    ];
}

// Function to get user transactions with pagination
function getUserTransactions($conn, $user_id, $page = 1, $per_page = 10, $referral_code = null) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'
            ORDER BY t.created_at DESC 
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM transactions t
                  JOIN users u ON t.user_id = u.id
                  WHERE user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_transactions = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_transactions / $per_page);
    
    $stmt->close();
    $count_stmt->close();
    
    return [
        'data' => $transactions,
        'total' => $total_transactions,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'per_page' => $per_page
    ];
}

// Function to ban user
function banUser($conn, $user_id) {
    $sql = "UPDATE users SET status = 'banned' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    return $stmt->execute();
}

// Function to unban user
function unbanUser($conn, $user_id) {
    $sql = "UPDATE users SET status = 'active' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    return $stmt->execute();
}

// Function to delete user
function deleteUser($conn, $user_id) {

    $user_id = intval($user_id);
    // Start transaction
    $conn->begin_transaction();
    
    try {

        $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'User Deletion'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['error_message'] = "There is already a pending user deletion request for this user.";
            header("Location: users.php");
            exit();
        }

        $admin_id = isset($_SESSION['admin_id'])? intval($_SESSION['admin_id']) : 0;
        $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $referral_result = $stmt->get_result();
        $get_admin_name = $referral_result->fetch_assoc();
        $referral_result->free();   // <-- important
        $stmt->close();


        // Delete user related data first
        $title = "User Deletion";
        $description = "User account deleted by admin: " . $get_admin_name['username'];
        $delete_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,'pending',NOW())");
        $delete_stmt->bind_param("iiss", $admin_id, $user_id, $title, $description);
        $delete_stmt->execute();
        
       
        
        $conn->commit();
        $_SESSION['success_message'] = "Request to delete user submitted successfully!";
        
        
        
        return true;
    } catch (Exception $e) {
        
        try { $conn->rollback(); } catch (Exception $rollbackError) {}
        return false;
    }
}

include "includes/header.php";
?>

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
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item active">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                
                
                <a href="todays_active_games.php" class="menu-item ">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item ">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="all_users_history.php" class="menu-item ">
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
                <a href="admin_deposits.php" class="menu-item ">
                    <i class="fas fa-money-bill"></i>
                    <span>Deposits</span>
                </a>
                <a href="applications.php" class="menu-item">
                    <i class="fas fa-tasks"></i>
                    <span>Applications</span>
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
                    <h1>User Management</h1>
                    <p>Manage users, view details, and perform actions</p>
                </div>
                <div class="header-actions">
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo $admin_username; ?></span>
                    </div>
                    <a href="admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Search Users</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by username, email, or phone" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="users.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Users List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-users"></i> Users List (<?php echo count($users); ?>)</h2>
                </div>
                
                <?php if (!empty($users)): ?>
                <div class="tbody">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                    <td>$<?php echo number_format($user['balance'], 2); ?></td>
                                    <td>
                                        <span class="status status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-primary btn-sm view-user" data-user-id="<?php echo $user['id']; ?>" onclick="window.location.href = 'viewuser.php?id=<?= $user['id'] ?>'">
                                                <i class="fas fa-eye"></i> View
                                            </button>

                                            <?php if($referral_code['status'] == 'suspend'):?>
                            
                                            <?php else:?>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <a href="users.php?ban_user=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-ban"></i> Ban
                                                    </a>
                                                <?php else: ?>
                                                    <a href="users.php?unban_user=<?php echo $user['id']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i> Unban
                                                    </a>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm delete-user" data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-users" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <p style="color: var(--text-muted);">No users found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

   <!-- User Details Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>User Details</h3>
                <button class="modal-close" id="closeUserModal">&times;</button>
            </div>
            <div id="userModalContent">
                <!-- Content will be loaded here -->
                <?php if ($user_details): ?>
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['username']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['email']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['phone'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Balance</div>
                        <div class="info-value">$<?php echo number_format($user_details['balance'], 2); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status status-<?php echo $user_details['status']; ?>">
                                <?php echo ucfirst($user_details['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Joined Date</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user_details['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['referral_code'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?php echo $user_details['last_login'] ? date('M j, Y g:i A', strtotime($user_details['last_login'])) : 'Never'; ?></div>
                    </div>
                </div>
                
                <div class="tabs">
                    <button class="tab active" data-tab="bets">Bet History (<?php echo $user_bets['total']; ?>)</button>
                    <button class="tab" data-tab="transactions">Transaction History (<?php echo $user_transactions['total']; ?>)</button>
                </div>
                
                <div class="tab-content active" id="bets-tab">
                    <?php displayBetsTab($user_bets, $bets_page, $per_page); ?>
                </div>
                
                <div class="tab-content" id="transactions-tab">
                    <?php displayTransactionsTab($user_transactions, $transactions_page, $per_page); ?>
                </div>
                
                <div style="margin-top: 2rem; text-align: center;">
                    <?php if ($user_details['status'] === 'active'): ?>
                        <a href="users.php?ban_user=<?php echo $user_details['id']; ?>" class="btn btn-warning">
                            <i class="fas fa-ban"></i> Ban User
                        </a>
                    <?php else: ?>
                        <a href="users.php?unban_user=<?php echo $user_details['id']; ?>" class="btn btn-success">
                            <i class="fas fa-check"></i> Unban User
                        </a>
                    <?php endif; ?>
                    <a href="users.php?delete_user=<?php echo $user_details['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete User
                    </a>
                    <button class="btn btn-secondary" id="closeModalBtn">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay confirmation-modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close" id="closeDeleteModal">&times;</button>
            </div>
            <div id="deleteModalContent">
                <p>Are you sure you want to delete user <strong id="deleteUsername"></strong>? This action cannot be undone.</p>
                <div class="confirmation-buttons">
                    <button class="btn btn-secondary" id="cancelDelete">Cancel</button>
                    <a href="#" class="btn btn-danger" id="confirmDelete">Delete User</a>
                </div>
            </div>
        </div>
    </div>

<!-- SweetAlert2 latest from jsDelivr -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


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

    // Global variables
    let currentUserId = null;

    // Modal elements
    const userModal = document.getElementById('userModal');
    const closeUserModal = document.getElementById('closeUserModal');
    const userModalContent = document.getElementById('userModalContent');
    const deleteModal = document.getElementById('deleteModal');
    const closeDeleteModal = document.getElementById('closeDeleteModal');
    const cancelDelete = document.getElementById('cancelDelete');
    const deleteUsername = document.getElementById('deleteUsername');
    const confirmDelete = document.getElementById('confirmDelete');

    // View user buttons
    // document.querySelectorAll('.view-user').forEach(button => {
    //     button.addEventListener('click', function() {
    //         const userId = this.getAttribute('data-user-id');
    //         currentUserId = userId;
    //         window.location.href = `users.php?view_user=${userId}`;
    //     });
    // });

    // Close modal handlers
    closeUserModal.addEventListener('click', () => {
        userModal.classList.remove('active');
        currentUserId = null;
    });

    closeDeleteModal.addEventListener('click', () => deleteModal.classList.remove('active'));
    cancelDelete.addEventListener('click', () => deleteModal.classList.remove('active'));

    [userModal, deleteModal].forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                if (modal === userModal) {
                    currentUserId = null;
                }
            }
        });
    });

    // Delete user buttons
    document.querySelectorAll('.delete-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const username = this.getAttribute('data-username');
            
            deleteUsername.textContent = username;
            confirmDelete.href = `users.php?delete_user=${userId}`;
            deleteModal.classList.add('active');
        });
    });

    // Function to handle pagination clicks
    function handlePaginationClick(event, tabType) {
        event.preventDefault();
        
        const button = event.target;
        const page = button.getAttribute('data-page');
        
        if (!page || !currentUserId) return;
        
        // Show loading state
        const tabContent = document.getElementById(`${tabType}-tab`);
        const oldContent = tabContent.innerHTML;
        tabContent.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <div class="loading"></div>
                <p>Loading ${tabType}...</p>
            </div>
        `;
        
        // Load the page via AJAX
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `users.php?view_user=${currentUserId}&${tabType}_page=${page}&ajax=1`, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                tabContent.innerHTML = xhr.responseText;
                // Re-attach event listeners to the new pagination buttons
                attachPaginationListeners(tabType);
            } else {
                tabContent.innerHTML = oldContent;
                alert('Error loading page content');
            }
        };
        xhr.onerror = function() {
            tabContent.innerHTML = oldContent;
            alert('Error loading page content');
        };
        xhr.send();
    }

    // Function to attach event listeners to pagination buttons
    function attachPaginationListeners(tabType) {
        const paginationContainer = document.querySelector(`#${tabType}-tab .pagination`);
        if (paginationContainer) {
            const buttons = paginationContainer.querySelectorAll('button[data-page]');
            buttons.forEach(button => {
                // Remove existing event listeners and add new ones
                button.replaceWith(button.cloneNode(true));
            });
            
            // Re-select the new buttons and attach listeners
            const newButtons = paginationContainer.querySelectorAll('button[data-page]');
            newButtons.forEach(button => {
                button.addEventListener('click', (e) => handlePaginationClick(e, tabType));
            });
        }
    }

    // Tab switching functionality
    function initializeTabs() {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Update active tab
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Update active content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${targetTab}-tab`).classList.add('active');
            });
        });
    }

    // Close modal button
    function initializeCloseButton() {
        const closeModalBtn = document.getElementById('closeModalBtn');
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => {
                userModal.classList.remove('active');
                window.history.replaceState({}, document.title, 'users.php');
                currentUserId = null;
            });
        }
    }

    // Initialize everything when modal is shown
    <?php if ($user_details): ?>
        currentUserId = <?php echo $user_details['id']; ?>;
        
        // Show modal on page load if user details are present
        window.addEventListener('load', function() {
            userModal.classList.add('active');
            
            // Initialize tabs and pagination
            setTimeout(() => {
                initializeTabs();
                initializeCloseButton();
                attachPaginationListeners('bets');
                attachPaginationListeners('transactions');
            }, 100);
        });
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        setTimeout(() => {
            Swal.fire({'title': "Success"  ,
                'text':'<?php echo $_SESSION['success_message']; ?>',
                'icon':'success',
                'confirmButtonText':'OK'});
        }, 100);
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        setTimeout(() => {
            Swal.fire({'title': "Error"  ,
                'text':'<?php echo $_SESSION['error_message']; ?>',
                'icon':'error',
                'confirmButtonText':'OK'});
        }, 100);
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
</script>


</body>
</html>




