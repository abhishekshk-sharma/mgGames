<?php
// admin_dashboard.php
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

// Get admin referral code
$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_result = $stmt->get_result();
$admin_data = $referral_result->fetch_assoc();
$referral_code = $admin_data['referral_code'];

// Get broker limit details
$broker_limit = [];
$stmt = $conn->prepare("SELECT * FROM broker_limit WHERE admin_id = ?");
$stmt->execute([$admin_id]);
$broker_result = $stmt->get_result();
if ($broker_result->num_rows > 0) {
    $broker_limit = $broker_result->fetch_assoc();
}

// Get stats for dashboard
$users_count = 0;
$active_users = 0;
$total_deposits = 0;
$total_withdrawals = 0;
$pending_withdrawals = 0;
$total_games = 0;

// Count total users
$sql = "SELECT COUNT(*) as count FROM users WHERE referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $users_count = $row['count'];
}

// Count active users (users with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT user_id) as count FROM transactions 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
        AND user_id IN (SELECT id FROM users WHERE referral_code = ?)";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $active_users = $row['count'];
}

// Count total games
$sql = "SELECT COUNT(*) as count FROM games WHERE status = 'active'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_games = $row['count'];
}

// Get total deposits
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id  
        WHERE t.type = 'deposit' AND t.status = 'completed' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $total_deposits = $row['total'] ? $row['total'] : 0;
}

// Get total withdrawals
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.type = 'withdrawal' AND t.status = 'completed' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $total_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get pending withdrawals
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.type = 'withdrawal' AND t.status = 'pending' 
        AND u.referral_code = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$referral_code]);
$result = $stmt->get_result();
if ($result) {
    $row = $result->fetch_assoc();
    $pending_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get recent transactions function
function get_recent_transactions($conn, $referral_code, $limit = 5) {
    $sql = "SELECT t.id, u.username, t.type, t.amount, t.status, t.created_at 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            WHERE u.referral_code = ?
            ORDER BY t.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $limit]);
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

// Get recent withdrawals function
function get_recent_withdrawals($conn, $referral_code, $limit = 5) {
    $sql = "SELECT w.id, u.username, w.amount, w.status, w.created_at 
            FROM withdrawals w 
            JOIN users u ON w.user_id = u.id 
            WHERE u.referral_code = ?
            ORDER BY w.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $limit]);
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    return $withdrawals;
}

// Get revenue data for charts with different time periods
function get_revenue_data($conn, $referral_code, $period = 'week') {
    $start_date = '';
    $end_date = date('Y-m-d');
    $date_format = 'M j';
    
    switch ($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $date_format = 'D';
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $date_format = 'M j';
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            $date_format = 'M j';
            break;
        case 'custom':
            // For custom, we'll handle via AJAX
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $date_format = 'M j';
            break;
    }
    
    // Generate dates array
    $dates = [];
    $current = strtotime($start_date);
    $last = strtotime($end_date);
    
    while ($current <= $last) {
        $dates[] = date($date_format, $current);
        $current = strtotime('+1 day', $current);
    }
    
    $sql = "SELECT 
            DATE(t.created_at) as date,
            SUM(CASE WHEN type = 'deposit' AND t.status = 'completed' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type = 'withdrawal' AND t.status = 'completed' THEN amount ELSE 0 END) as withdrawals,
            SUM(CASE WHEN type = 'winning' THEN amount ELSE 0 END) as winnings
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE u.referral_code = ? 
            AND DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code, $start_date, $end_date]);
    $result = $stmt->get_result();
    
    // Initialize arrays with zeros
    $deposits = array_fill(0, count($dates), 0);
    $withdrawals = array_fill(0, count($dates), 0);
    $winnings = array_fill(0, count($dates), 0);
    
    while ($row = $result->fetch_assoc()) {
        $date_formatted = date($date_format, strtotime($row['date']));
        $day_index = array_search($date_formatted, $dates);
        if ($day_index !== false) {
            $deposits[$day_index] = floatval($row['deposits']);
            $withdrawals[$day_index] = floatval($row['withdrawals']);
            $winnings[$day_index] = floatval($row['winnings']);
        }
    }
    
    return [
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'dates' => $dates,
        'deposits' => $deposits,
        'withdrawals' => $withdrawals,
        'winnings' => $winnings
    ];
}

// Get user activity data
function get_user_activity_data($conn, $referral_code) {
    // Active users (played in last 7 days)
    $sql = "SELECT COUNT(DISTINCT user_id) as active_users 
            FROM bets 
            WHERE user_id IN (SELECT id FROM users WHERE referral_code = ?)
            AND placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $active_users = $result->fetch_assoc()['active_users'] ?? 0;
    
    // New users (registered in last 7 days)
    $sql = "SELECT COUNT(*) as new_users 
            FROM users 
            WHERE referral_code = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $new_users = $result->fetch_assoc()['new_users'] ?? 0;
    
    // Total users
    $sql = "SELECT COUNT(*) as total_users 
            FROM users 
            WHERE referral_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referral_code]);
    $result = $stmt->get_result();
    $total_users = $result->fetch_assoc()['total_users'] ?? 0;
    
    $returning_users = max(0, $total_users - $new_users);
    
    return [
        'active' => $active_users,
        'new' => $new_users,
        'returning' => $returning_users
    ];
}

// Get initial data for charts (default: week)
$revenue_data = get_revenue_data($conn, $referral_code, 'week');
$user_activity_data = get_user_activity_data($conn, $referral_code);

// Get recent data
$recent_transactions = get_recent_transactions($conn, $referral_code, 5);
$recent_withdrawals = get_recent_withdrawals($conn, $referral_code, 5);
$title = "Admin Dashboard - RB Games.";

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
                <a href="dashboard.php" class="menu-item active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="all_users_history.php" class="menu-item">
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
                <a href="admin_deposits.php" class="menu-item">
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
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <span class="admin-name"><?php echo $admin_username; ?></span>. Here's what's happening with your platform today.</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span><?php echo date('l, F j, Y'); ?></span>
                    </div>
                    <a href="admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
      


            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Users</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $users_count; ?></div>
                    <div class="stat-card-desc">Registered users</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Games</div>
                        <div class="stat-card-icon active-users-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_games; ?></div>
                    <div class="stat-card-desc">Available games</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Deposits</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_deposits, 2); ?></div>
                    <div class="stat-card-desc">All time</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Withdrawals</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-money-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">All time</div>
                </div>
            </div>

            <!-- Broker Limit Card -->
            <?php if (!empty($broker_limit)): ?>
            <div class="broker-limit-card">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-line"></i> Broker Limits</h2>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Deposit Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['deposit_limit'], 2); ?></div>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Withdrawal Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['withdrawal_limit'], 2); ?></div>
                </div>
                <div class="limit-item">
                    <div class="limit-label">Bet Limit</div>
                    <div class="limit-value">$<?php echo number_format($broker_limit['bet_limit'], 2); ?></div>
                </div>
                <?php if ($broker_limit['pnl_ratio']): ?>
                <div class="limit-item">
                    <div class="limit-label">P&L Ratio</div>
                    <div class="limit-value"><?php echo $broker_limit['pnl_ratio']; ?>%</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <!-- Your stats cards remain the same -->
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Revenue Overview</h3>
                        <div class="chart-actions">
                            <button class="chart-action-btn active" data-period="week">Week</button>
                            <button class="chart-action-btn" data-period="this_month">This Month</button>
                            <button class="chart-action-btn" data-period="last_month">Last Month</button>
                            <button class="chart-action-btn" data-period="custom">Custom</button>
                        </div>
                    </div>
                    
                    <!-- Custom Date Range Input -->
                    <div class="custom-date-range" id="customDateRange">
                        <div class="date-input-group">
                            <label for="startDate">From</label>
                            <input type="date" id="startDate" class="date-input" value="<?php echo date('Y-m-d', strtotime('-6 days')); ?>">
                        </div>
                        <div class="date-input-group">
                            <label for="endDate">To</label>
                            <input type="date" id="endDate" class="date-input" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button class="apply-custom-date" id="applyCustomDate">Apply</button>
                    </div>
                    
                    <div class="chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">User Activity</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="userActivityChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="users.php" class="action-btn">
                        <i class="fas fa-users"></i>
                        <span>Manage Users</span>
                    </a>
                    <a href="admin_transactions.php" class="action-btn">
                        <i class="fas fa-search-dollar"></i>
                        <span>View Transactions</span>
                    </a>
                    <a href="admin_withdrawals.php" class="action-btn">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Process Withdrawals</span>
                    </a>
                    <a href="admin_reports.php" class="action-btn">
                        <i class="fas fa-chart-pie"></i>
                        <span>Generate Reports</span>
                    </a>
                    
                    <a href="admin_deposits.php" class="action-btn">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Approve Deposits</span>
                    </a>
                </div>
            </div>

            <!-- Recent Data -->
            <div class="recent-data-grid">
                <!-- Recent Transactions -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                        <a href="admin_transactions.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_transactions)): ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['username']); ?></td>
                                            <td><?php echo ucfirst($transaction['type']); ?></td>
                                            <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $transaction['status']; ?>">
                                                    <?php echo ucfirst($transaction['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center;">No recent transactions</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Withdrawals -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-credit-card"></i> Recent Withdrawals</h2>
                        <a href="admin_withdrawals.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_withdrawals)): ?>
                                    <?php foreach ($recent_withdrawals as $withdrawal): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($withdrawal['username']); ?></td>
                                            <td>$<?php echo number_format($withdrawal['amount'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $withdrawal['status']; ?>">
                                                    <?php echo ucfirst($withdrawal['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center;">No recent withdrawals</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Replace the entire JavaScript section in your dashboard.php with this: -->

<script>
// Global chart variables
let revenueChart = null;
let userActivityChart = null;
let currentPeriod = 'week';
let resizeTimeout = null;

// Mobile menu functionality
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

menuToggle.addEventListener('click', function() {
    sidebar.classList.toggle('active');
    sidebarOverlay.classList.toggle('active');
    // Resize charts after sidebar animation completes
    setTimeout(resizeCharts, 300);
});

sidebarOverlay.addEventListener('click', function() {
    sidebar.classList.remove('active');
    sidebarOverlay.classList.remove('active');
    // Resize charts after sidebar animation completes
    setTimeout(resizeCharts, 300);
});

const menuItems = document.querySelectorAll('.menu-item');
menuItems.forEach(item => {
    item.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            setTimeout(resizeCharts, 300);
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
    
    // Debounced chart resizing
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(resizeCharts, 250);
}

handleResize();
window.addEventListener('resize', handleResize);

// Initialize charts after DOM is loaded and Chart.js is available
document.addEventListener('DOMContentLoaded', function() {
    // Wait a bit to ensure Chart.js is loaded and DOM is fully rendered
    setTimeout(() => {
        if (typeof Chart !== 'undefined') {
            initializeCharts();
            setupChartControls();
        } else {
            console.error('Chart.js is not loaded. Please check the script inclusion.');
            showChartError();
        }
    }, 500);
});

// Initialize all charts
function initializeCharts() {
    initializeRevenueChart();
    initializeUserActivityChart();
    
    // Resize charts after initialization to ensure proper sizing
    setTimeout(resizeCharts, 100);
}

// Resize all charts function
function resizeCharts() {
    if (revenueChart) {
        revenueChart.resize();
    }
    if (userActivityChart) {
        userActivityChart.resize();
    }
}

// Show error message if charts fail to load
function showChartError() {
    const revenueChartContainer = document.getElementById('revenueChart');
    const userActivityChartContainer = document.getElementById('userActivityChart');
    
    if (revenueChartContainer) {
        revenueChartContainer.innerHTML = `
            <div class="chart-no-data">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Chart failed to load. Please refresh the page.</p>
            </div>
        `;
    }
    
    if (userActivityChartContainer) {
        userActivityChartContainer.innerHTML = `
            <div class="chart-no-data">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Chart failed to load. Please refresh the page.</p>
            </div>
        `;
    }
}

// Initialize Revenue Chart with responsive options
function initializeRevenueChart() {
    const revenueCtx = document.getElementById('revenueChart');
    if (!revenueCtx) return;
    
    // Ensure canvas has proper dimensions
    revenueCtx.style.width = '100%';
    revenueCtx.style.height = '100%';
    
    revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($revenue_data['dates']); ?>,
            datasets: [
                {
                    label: 'Deposits',
                    data: <?php echo json_encode($revenue_data['deposits']); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                },
                {
                    label: 'Withdrawals',
                    data: <?php echo json_encode($revenue_data['withdrawals']); ?>,
                    borderColor: '#e74c3c',
                    backgroundColor: 'rgba(231, 76, 60, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#e74c3c',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                },
                {
                    label: 'Winnings',
                    data: <?php echo json_encode($revenue_data['winnings']); ?>,
                    borderColor: '#f39c12',
                    backgroundColor: 'rgba(243, 156, 18, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#f39c12',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 0,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#2d3748',
                        padding: 15,
                        usePointStyle: true,
                        font: {
                            size: 12,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#2d3748',
                    bodyColor: '#4a5568',
                    borderColor: '#e2e8f0',
                    borderWidth: 1,
                    cornerRadius: 6,
                    displayColors: true,
                    boxPadding: 5
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#4a5568',
                        callback: function(value) {
                            return '$' + value;
                        },
                        font: {
                            size: 11
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#4a5568',
                        font: {
                            size: 11
                        }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            animations: {
                tension: {
                    duration: 1000,
                    easing: 'linear'
                }
            }
        }
    });
}

// Initialize User Activity Chart with responsive options
function initializeUserActivityChart() {
    const userActivityCtx = document.getElementById('userActivityChart');
    if (!userActivityCtx) return;
    
    // Ensure canvas has proper dimensions
    userActivityCtx.style.width = '100%';
    userActivityCtx.style.height = '100%';
    
    userActivityChart = new Chart(userActivityCtx, {
        type: 'doughnut',
        data: {
            labels: ['Active Users', 'New Users', 'Returning Users'],
            datasets: [{
                data: [
                    <?php echo $user_activity_data['active']; ?>,
                    <?php echo $user_activity_data['new']; ?>,
                    <?php echo $user_activity_data['returning']; ?>
                ],
                backgroundColor: [
                    '#3498db',
                    '#2ecc71',
                    '#9b59b6'
                ],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverBackgroundColor: [
                    '#2980b9',
                    '#27ae60',
                    '#8e44ad'
                ],
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 0,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#2d3748',
                        padding: 15,
                        usePointStyle: true,
                        font: {
                            size: 11,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#2d3748',
                    bodyColor: '#4a5568',
                    borderColor: '#e2e8f0',
                    borderWidth: 1,
                    cornerRadius: 6,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
}

// Setup chart controls
function setupChartControls() {
    // Chart period switching
    document.querySelectorAll('.chart-action-btn').forEach(button => {
        button.addEventListener('click', function() {
            const period = this.dataset.period;
            
            document.querySelectorAll('.chart-action-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            this.classList.add('active');
            
            // Show/hide custom date range
            const customDateRange = document.getElementById('customDateRange');
            if (period === 'custom') {
                customDateRange.classList.add('active');
                // Resize charts after showing date range
                setTimeout(resizeCharts, 100);
            } else {
                customDateRange.classList.remove('active');
                updateChartData(period);
            }
            
            currentPeriod = period;
        });
    });

    // Apply custom date range
    const applyCustomDateBtn = document.getElementById('applyCustomDate');
    if (applyCustomDateBtn) {
        applyCustomDateBtn.addEventListener('click', function() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date');
                return;
            }
            
            updateChartData('custom', startDate, endDate);
        });
    }
}

// Update chart data via AJAX
function updateChartData(period, startDate = '', endDate = '') {
    console.log('Updating chart data for period:', period, 'Start:', startDate, 'End:', endDate);
    
    // Show loading state
    const chartTitle = document.querySelector('.chart-container .chart-title');
    const originalTitle = chartTitle.textContent;
    chartTitle.textContent = 'Loading...';
    
    // Create request data
    const requestData = {
        period: period,
        referral_code: '<?php echo $referral_code; ?>'
    };
    
    if (period === 'custom' && startDate && endDate) {
        requestData.start_date = startDate;
        requestData.end_date = endDate;
    }
    
    console.log('Sending request data:', requestData);
    
    // Send AJAX request
    fetch('get_chart_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(requestData)
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        console.log('Received data:', data);
        if (data.success) {
            // Update chart data
            revenueChart.data.labels = data.dates;
            revenueChart.data.datasets[0].data = data.deposits;
            revenueChart.data.datasets[1].data = data.withdrawals;
            revenueChart.data.datasets[2].data = data.winnings;
            revenueChart.update('none'); // Use 'none' to prevent animation issues
            
            // Update chart title with period info
            let periodText = '';
            switch(period) {
                case 'week':
                    periodText = ' (Last 7 Days)';
                    break;
                case 'this_month':
                    periodText = ' (This Month)';
                    break;
                case 'last_month':
                    periodText = ' (Last Month)';
                    break;
                case 'custom':
                    periodText = ` (${formatDate(data.start_date)} to ${formatDate(data.end_date)})`;
                    break;
            }
            chartTitle.textContent = 'Revenue Overview' + periodText;
            
            // Resize chart after data update
            setTimeout(resizeCharts, 50);
            
            console.log('Chart updated successfully');
        } else {
            throw new Error(data.message || 'Unknown error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error loading chart data: ' + error.message);
        chartTitle.textContent = originalTitle;
    });
}

// Format date for display
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

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

// Add resize observer for container changes
if (typeof ResizeObserver !== 'undefined') {
    const chartContainers = document.querySelectorAll('.chart-wrapper');
    chartContainers.forEach(container => {
        const resizeObserver = new ResizeObserver(() => {
            resizeCharts();
        });
        resizeObserver.observe(container);
    });
}
</script>
</body>
</html>