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

include 'includes/header.php';
?>



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