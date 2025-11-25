<?php
session_start();
require_once '../config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit;
}

$user_id = $_GET['id'] ?? 0;

// Fetch user details
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

if (!$user) {
    header('Location: users.php');
    exit();
}

// Get admin details for referral code
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];
$admin_stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$referral_result = $admin_stmt->get_result();
$referral_code = $referral_result->fetch_assoc();

// Pagination variables
$per_page = 10;
$bets_page = isset($_GET['bets_page']) ? max(1, intval($_GET['bets_page'])) : 1;
$deposits_page = isset($_GET['deposits_page']) ? max(1, intval($_GET['deposits_page'])) : 1;
$withdrawals_page = isset($_GET['withdrawals_page']) ? max(1, intval($_GET['withdrawals_page'])) : 1;
$transactions_page = isset($_GET['transactions_page']) ? max(1, intval($_GET['transactions_page'])) : 1;

// Get active tab from URL or default to bets
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bets';

// Calculate total deposits
$deposit_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_deposits FROM deposits WHERE user_id = ? AND status = 'approved'");
$deposit_stmt->bind_param("i", $user_id);
$deposit_stmt->execute();
$deposit_result = $deposit_stmt->get_result();
$total_deposits = $deposit_result->fetch_assoc()['total_deposits'];

// Calculate total withdrawals
$withdrawal_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_withdrawals FROM withdrawals WHERE user_id = ? AND status = 'completed'");
$withdrawal_stmt->bind_param("i", $user_id);
$withdrawal_stmt->execute();
$withdrawal_result = $withdrawal_stmt->get_result();
$total_withdrawals = $withdrawal_result->fetch_assoc()['total_withdrawals'];

// Calculate total bets
$bet_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_bets FROM bets WHERE user_id = ?");
$bet_stmt->bind_param("i", $user_id);
$bet_stmt->execute();
$bet_result = $bet_stmt->get_result();
$total_bets = $bet_result->fetch_assoc()['total_bets'];

// Fetch bets with pagination
function getUserBetsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT b.*, g.name as game_name, gt.name as game_type_name 
            FROM bets b 
            LEFT JOIN games g ON b.game_session_id = g.id 
            LEFT JOIN game_types gt ON b.game_type_id = gt.id 
            WHERE b.user_id = ? 
            ORDER BY b.placed_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bets = [];
    while ($row = $result->fetch_assoc()) {
        $bets[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM bets WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $bets,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch deposits with pagination
function getUserDepositsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM deposits 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deposits = [];
    while ($row = $result->fetch_assoc()) {
        $deposits[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM deposits WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $deposits,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch withdrawals with pagination
function getUserWithdrawalsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM withdrawals 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM withdrawals WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $withdrawals,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch transactions with pagination
function getUserTransactionsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM transactions WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $transactions,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Get paginated data
$user_bets = getUserBetsPaginated($conn, $user_id, $bets_page, $per_page);
$user_deposits = getUserDepositsPaginated($conn, $user_id, $deposits_page, $per_page);
$user_withdrawals = getUserWithdrawalsPaginated($conn, $user_id, $withdrawals_page, $per_page);
$user_transactions = getUserTransactionsPaginated($conn, $user_id, $transactions_page, $per_page);

// Handle balance update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_balance'])) {
    $new_balance = $_POST['new_balance'];
    $reason = sanitize_input($conn, $_POST['reason']);


    $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'Balance Update Request'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {

        $update_stmt = $conn->prepare("UPDATE `admin_requests` SET  `amount` = ? WHERE user_id = ? AND status = 'pending' AND title = 'Balance Update Request'");
        $update_stmt->bind_param("di", $new_balance, $user_id   );
        $update_stmt->execute();


        $_SESSION['success_message'] = "Request to update user balance updated successfully!";
        header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
        exit();
    }


    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $previous_amount = $user['balance'];



    
    $title = 'Balance Update Request';
    $description = "Admin $admin_username requested a balance update for user {$user['username']} from $previous_amount to $new_balance (".($new_balance - $previous_amount)." Rs). Reason: $reason";  
    // Update user balance
    $update_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `amount`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,?,'pending',NOW())");
    $update_stmt->bind_param("iidss", $admin_id, $user_id, $new_balance, $title, $description);
    $update_stmt->execute();
    
    // Log the transaction
    // $transaction_stmt = $conn->prepare("
    //     INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) 
    //     VALUES (?, 'adjustment', ?, ?, ?, ?, 'completed')
    // ");
    // $amount_diff = $new_balance - $user['balance'];
    // $transaction_stmt->bind_param("iddds", $user_id, $amount_diff, $user['balance'], $new_balance, $reason);
    // $transaction_stmt->execute();
    
    $_SESSION['success_message'] = " Request to update user balance submitted successfully!";
    header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
    exit();
}

// Handle user status change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_status'])) {
    $new_status = sanitize_input($conn, $_POST['status']);
    
    $status_stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $status_stmt->bind_param("si", $new_status, $user_id);
    $status_stmt->execute();
    
    $_SESSION['success_message'] = "User status updated successfully!";
    header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
    exit();
}

// Handle delete user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {

    $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'User Deletion'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $_SESSION['error_message'] = "There is already a pending user deletion request for this user.";
        header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
        exit();
    }

    $title = "User Deletion";
    $description = "User account deleted by admin: " . $admin_username;
    $delete_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,'pending',NOW())");
    $delete_stmt->bind_param("iiss", $admin_id, $user_id, $title, $description);
    $delete_stmt->execute();
    
    $_SESSION['success_message'] = "Request to delete user submitted successfully!";
    header("Location: users.php");
    exit();
}

// Function to generate pagination HTML with active tab preservation
function generatePagination($total_pages, $current_page, $page_param, $user_id, $active_tab) {
    if ($total_pages <= 1) return '';
    
    $pagination = '<div class="pagination">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $prev_page . '" class="pagination-btn">« Previous</a>';
    } else {
        $pagination .= '<span class="pagination-btn disabled">« Previous</span>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=1" class="pagination-btn">1</a>';
        if ($start_page > 2) {
            $pagination .= '<span class="pagination-ellipsis">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="pagination-btn active">' . $i . '</span>';
        } else {
            $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $i . '" class="pagination-btn">' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $pagination .= '<span class="pagination-ellipsis">...</span>';
        }
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $total_pages . '" class="pagination-btn">' . $total_pages . '</a>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $next_page . '" class="pagination-btn">Next »</a>';
    } else {
        $pagination .= '<span class="pagination-btn disabled">Next »</span>';
    }
    
    $pagination .= '</div>';
    
    return $pagination;
}

// Function to generate tab URL with current parameters
function generateTabUrl($tab, $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) {
    $url = "viewuser.php?id=" . $user_id . "&tab=" . $tab;
    
    // Preserve the current page for each tab type
    switch($tab) {
        case 'bets':
            $url .= "&bets_page=" . $bets_page;
            break;
        case 'deposits':
            $url .= "&deposits_page=" . $deposits_page;
            break;
        case 'withdrawals':
            $url .= "&withdrawals_page=" . $withdrawals_page;
            break;
        case 'transactions':
            $url .= "&transactions_page=" . $transactions_page;
            break;
    }
    
    return $url;
}

$pagefilename = "viewuser";

include "includes/header.php";
?>


        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>User Details: <?= htmlspecialchars($user['username']) ?></h1>
                    <p>Manage user account, view transactions, and perform actions</p>
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

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?= $_SESSION['error_message'] ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Financial Summary -->
            <div class="financial-summary">
                <div class="summary-card balance">
                    <div class="summary-value">₹<?= number_format($user['balance'], 2) ?></div>
                    <div class="summary-label">Current Balance</div>
                </div>
                <div class="summary-card deposits">
                    <div class="summary-value">₹<?= number_format($total_deposits, 2) ?></div>
                    <div class="summary-label">Total Deposits</div>
                </div>
                <div class="summary-card withdrawals">
                    <div class="summary-value">₹<?= number_format($total_withdrawals, 2) ?></div>
                    <div class="summary-label">Total Withdrawals</div>
                </div>
                <div class="summary-card bets">
                    <div class="summary-value">₹<?= number_format($total_bets, 2) ?></div>
                    <div class="summary-label">Total Bets</div>
                </div>
            </div>

            <!-- User Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Information</h3>
                </div>
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">User ID</div>
                        <div class="info-value"><?= $user['id'] ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($user['username']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status status-<?= $user['status'] ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?= htmlspecialchars($user['referral_code'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Registered</div>
                        <div class="info-value"><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?></div>
                    </div>
                </div>
            </div>

            <!-- Balance Update Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Update Balance</h3>
                </div>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label class="form-label">Current Balance</label>
                            <input type="text" class="form-control" value="₹<?= number_format($user['balance'], 2) ?>" readonly>
                        </div>
                        <?php if($referral_code['status'] == 'suspend'):?>
                            
                        <?php else:?>
                                
                            <div class="form-group">
                                <label class="form-label">New Balance</label>
                                <input type="number" step="0.01" class="form-control" name="new_balance" value="<?= $user['balance'] ?>" 
                                <?php 
                                if($referral_code['status'] == 'suspend'){ 
                                    echo 'readonly'; 
                                }?> required >
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" name="reason" placeholder="Reason for balance adjustment" required>
                            </div>
                            <div class="form-group">
                                <button type="submit" name="update_balance" class="btn btn-warning">
                                    <i class="fas fa-sync-alt"></i>Request to Update Balance
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- User Management Actions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Management</h3>
                </div>
                <div class="action-buttons">
                    <?php if($referral_code['status'] == 'suspend'):?>
                            
                    <?php else:?>
                    <form method="POST" style="display: inline;">
                        <select name="status" class="form-control" style="width: auto; display: inline-block; margin-right: 1rem; background-color: var(--dark); color: var(--text-light);">
                            <option value="active" <?= $user['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="suspended" <?= $user['status'] == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="banned" <?= $user['status'] == 'banned' ? 'selected' : '' ?>>Banned</option>
                        </select>
                        <button type="submit" name="change_status" class="btn btn-primary">
                            <i class="fas fa-user-cog"></i> Change Status
                        </button>
                    </form>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');" style="display: inline;">
                        <button type="submit" name="delete_user" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete User
                        </button>
                    </form>

                    <?php endif; ?>
                    
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            </div>

            <!-- Tabs for Detailed Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Activity</h3>
                </div>
                
                <div class="tabs">
                    <a href="<?= generateTabUrl('bets', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'bets' ? 'active' : '' ?>" 
                       data-tab="bets">Bet History</a>
                    <a href="<?= generateTabUrl('deposits', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'deposits' ? 'active' : '' ?>" 
                       data-tab="deposits">Deposit History</a>
                    <a href="<?= generateTabUrl('withdrawals', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'withdrawals' ? 'active' : '' ?>" 
                       data-tab="withdrawals">Withdrawal History</a>
                    <a href="<?= generateTabUrl('transactions', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'transactions' ? 'active' : '' ?>" 
                       data-tab="transactions">All Transactions</a>
                </div>
                
                <!-- Bets Tab -->
                <div class="tab-content <?= $active_tab == 'bets' ? 'active' : '' ?>" id="bets-tab">
                    <div class="tab-header">
                        <div class="tab-title">Bet History</div>
                        <div class="total-count">Total: <?= $user_bets['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Game</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Status</th>
                                    <th>Placed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_bets['data'] as $bet): ?>
                                <tr>
                                    <td><?= $bet['id'] ?></td>
                                    <td><?= htmlspecialchars($bet['game_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($bet['game_type_name'] ?? 'N/A') ?></td>
                                    <td>₹<?= number_format($bet['amount'], 2) ?></td>
                                    <td>₹<?= number_format($bet['potential_win'], 2) ?></td>
                                    <td>
                                        <span class="status status-<?= $bet['status'] ?>">
                                            <?= ucfirst($bet['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($bet['placed_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_bets['data'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No bets found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_bets['total_pages'], $user_bets['current_page'], 'bets_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_bets['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_bets['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_bets['current_page'] * $per_page, $user_bets['total']) ?> of <?= $user_bets['total'] ?> bets
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Deposits Tab -->
                <div class="tab-content <?= $active_tab == 'deposits' ? 'active' : '' ?>" id="deposits-tab">
                    <div class="tab-header">
                        <div class="tab-title">Deposit History</div>
                        <div class="total-count">Total: <?= $user_deposits['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>UTR Number</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_deposits['data'] as $deposit): ?>
                                <tr>
                                    <td><?= $deposit['id'] ?></td>
                                    <td>₹<?= number_format($deposit['amount'], 2) ?></td>
                                    <td><?= ucfirst($deposit['payment_method']) ?></td>
                                    <td><?= htmlspecialchars($deposit['utr_number']) ?></td>
                                    <td>
                                        <span class="status status-<?= $deposit['status'] ?>">
                                            <?= ucfirst($deposit['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($deposit['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_deposits['data'])): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No deposits found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_deposits['total_pages'], $user_deposits['current_page'], 'deposits_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_deposits['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_deposits['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_deposits['current_page'] * $per_page, $user_deposits['total']) ?> of <?= $user_deposits['total'] ?> deposits
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Withdrawals Tab -->
                <div class="tab-content <?= $active_tab == 'withdrawals' ? 'active' : '' ?>" id="withdrawals-tab">
                    <div class="tab-header">
                        <div class="tab-title">Withdrawal History</div>
                        <div class="total-count">Total: <?= $user_withdrawals['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_withdrawals['data'] as $withdrawal): ?>
                                <tr>
                                    <td><?= $withdrawal['id'] ?></td>
                                    <td>₹<?= number_format($withdrawal['amount'], 2) ?></td>
                                    <td><?= ucfirst(str_replace('_', ' ', $withdrawal['payment_method'])) ?></td>
                                    <td>
                                        <span class="status status-<?= $withdrawal['status'] ?>">
                                            <?= ucfirst($withdrawal['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_withdrawals['data'])): ?>
                                <tr>
                                    <td colspan="5" class="text-center">No withdrawals found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_withdrawals['total_pages'], $user_withdrawals['current_page'], 'withdrawals_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_withdrawals['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_withdrawals['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_withdrawals['current_page'] * $per_page, $user_withdrawals['total']) ?> of <?= $user_withdrawals['total'] ?> withdrawals
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Transactions Tab -->
                <div class="tab-content <?= $active_tab == 'transactions' ? 'active' : '' ?>" id="transactions-tab">
                    <div class="tab-header">
                        <div class="tab-title">All Transactions</div>
                        <div class="total-count">Total: <?= $user_transactions['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance Before</th>
                                    <th>Balance After</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_transactions['data'] as $transaction): ?>
                                <tr>
                                    <td><?= $transaction['id'] ?></td>
                                    <td>
                                        <span class="status status-<?= $transaction['type'] === 'deposit' ? 'active' : 'pending' ?>">
                                            <?= ucfirst($transaction['type']) ?>
                                        </span>
                                    </td>
                                    <td>₹<?= number_format($transaction['amount'], 2) ?></td>
                                    <td>₹<?= number_format($transaction['balance_before'], 2) ?></td>
                                    <td>₹<?= number_format($transaction['balance_after'], 2) ?></td>
                                    <td>
                                        <span class="status status-<?= $transaction['status'] ?>">
                                            <?= ucfirst($transaction['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($transaction['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_transactions['data'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No transactions found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_transactions['total_pages'], $user_transactions['current_page'], 'transactions_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_transactions['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_transactions['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_transactions['current_page'] * $per_page, $user_transactions['total']) ?> of <?= $user_transactions['total'] ?> transactions
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // The page now uses server-side tab switching, so no JavaScript is needed
        // The tabs are now actual links that preserve the active tab state
        
        // Confirm before delete
        const deleteForms = document.querySelectorAll('form[onsubmit]');
        deleteForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>

</body>
</html>