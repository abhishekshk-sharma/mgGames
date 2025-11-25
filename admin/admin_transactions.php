<?php
// admin_transactions.php
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

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

// Pagination setup
$limit = 20; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code  = $stmt->get_result();
$referral_code = $referral_code->fetch_assoc();

// Get total count of transactions
$sql_count = "SELECT COUNT(u.id) as total FROM transactions t JOIN users u ON u.id = t.user_id WHERE u.referral_code = '".$referral_code['referral_code']."'";
$result_count = $conn->query($sql_count);
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Get transactions with pagination
$transactions = [];
$sql = "SELECT t.*, u.username 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE u.referral_code = '".$referral_code['referral_code']."'
        ORDER BY t.created_at DESC 
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
}

// Filter functionality
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

if ($filter_type || $filter_status) {
    $where_conditions = [];
    $params = [];
    
    if ($filter_type) {
        $where_conditions[] = "t.type = ?";
        $params[] = $filter_type;
    }
    
    if ($filter_status) {
        $where_conditions[] = "t.status = ?";
        $params[] = $filter_status;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Update count with filters
    $sql_count = "SELECT COUNT(u.id) as total FROM transactions t
    JOIN users u ON t.user_id = u.id 
    $where_clause AND u.referral_code = '".$referral_code['referral_code']."'";
    $stmt_count = $conn->prepare($sql_count);
    if ($stmt_count) {
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt_count->bind_param($types, ...$params);
        }
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        $total_records = $result_count->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
    }
    
    // Update transactions query with filters
    $sql = "SELECT t.*, u.username 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            $where_clause AND u.referral_code = '".$referral_code['referral_code']."'
            ORDER BY t.created_at DESC 
            LIMIT $limit OFFSET $offset";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
}

$pagefilename = "transactions";

include "includes/header.php";
?>




        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Transactions</h1>
                    <p>View and manage all user transactions</p>
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
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-filter"></i> Filter Transactions</h2>
                </div>
                <form method="GET" class="filter-form">
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
                        </select>
                    </div>
                    <div class="form-group" style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="admin_transactions.php" class="btn btn-secondary">
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
                                        <td><?php echo $transaction['username']; ?></td>
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
                                    <span class="transaction-value"><?php echo $transaction['username']; ?></span>
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
                            <a href="?page=1<?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
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
                            <a href="?page=<?php echo $i; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?><?php echo $filter_type ? '&filter_type=' . $filter_type : ''; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?>">
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