<?php
// super_admin_dashboard.php
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

// Get stats for dashboard
$admins_count = 0;
$active_admins = 0;
$total_users = 0;
$active_users = 0;
$total_deposits = 0;
$total_withdrawals = 0;
$pending_withdrawals = 0;
$total_games = 0;
$total_revenue = 0;

// Count total admins
$sql = "SELECT COUNT(*) as count FROM admins WHERE status = 'active'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $admins_count = $row['count'];
}

// Count active admins (admins with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT a.id) as count 
        FROM admins a 
        WHERE a.status = 'active' 
        AND EXISTS (
            SELECT 1 FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            WHERE u.referral_code = a.referral_code 
            AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        )";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $active_admins = $row['count'];
}

// Count total users
$sql = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_users = $row['count'];
}

// Count active users (users with recent activity - last 30 days)
$sql = "SELECT COUNT(DISTINCT user_id) as count FROM transactions 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$result = $conn->query($sql);
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

// Get total deposits across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'deposit' AND t.status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_deposits = $row['total'] ? $row['total'] : 0;
}

// Get total withdrawals across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'withdrawal' AND t.status = 'completed'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $total_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Get pending withdrawals across all admins
$sql = "SELECT SUM(t.amount) as total FROM transactions t 
        WHERE t.type = 'withdrawal' AND t.status = 'pending'";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    $pending_withdrawals = $row['total'] ? $row['total'] : 0;
}

// Calculate total revenue (deposits - withdrawals)
$total_revenue = $total_deposits - $total_withdrawals;

// Get admin performance data
function get_admin_performance($conn) {
    $sql = "SELECT 
            a.id,
            a.username,
            a.referral_code,
            a.status,
            COUNT(DISTINCT u.id) as total_users,
            COUNT(DISTINCT CASE WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN u.id END) as new_users,
            SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN t.type = 'withdrawal' AND t.status = 'completed' THEN t.amount ELSE 0 END) as total_withdrawals,
            COUNT(DISTINCT b.id) as total_bets
            FROM admins a
            LEFT JOIN users u ON u.referral_code = a.referral_code
            LEFT JOIN transactions t ON t.user_id = u.id
            LEFT JOIN bets b ON b.user_id = u.id
            WHERE a.status = 'active'
            GROUP BY a.id, a.username, a.referral_code
            ORDER BY total_deposits DESC
            LIMIT 10";
    
    $result = $conn->query($sql);
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    return $admins;
}

// Get recent transactions with admin info
function get_recent_transactions($conn, $limit = 10) {
    $sql = "SELECT 
            t.id, 
            u.username as user_name,
            a.username as admin_name,
            t.type, 
            t.amount, 
            t.status, 
            t.created_at 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY t.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

// Get recent withdrawals with admin info
function get_recent_withdrawals($conn, $limit = 10) {
    $sql = "SELECT 
            w.id, 
            u.username as user_name,
            a.username as admin_name,
            w.amount, 
            w.status, 
            w.created_at 
            FROM withdrawals w 
            JOIN users u ON w.user_id = u.id 
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY w.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    return $withdrawals;
}

// Get recent user registrations with admin info
function get_recent_registrations($conn, $limit = 10) {
    $sql = "SELECT 
            u.id,
            u.username,
            u.email,
            a.username as admin_name,
            u.created_at
            FROM users u
            JOIN admins a ON u.referral_code = a.referral_code
            ORDER BY u.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    $result = $stmt->get_result();
    
    $registrations = [];
    while ($row = $result->fetch_assoc()) {
        $registrations[] = $row;
    }
    return $registrations;
}

// Get revenue data for charts
function get_revenue_data($conn, $period = 'week') {
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
            WHERE DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
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

// Get admin distribution data
function get_admin_distribution_data($conn) {
    $sql = "SELECT 
            a.username,
            COUNT(DISTINCT u.id) as user_count,
            SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END) as deposit_amount
            FROM admins a
            LEFT JOIN users u ON u.referral_code = a.referral_code
            LEFT JOIN transactions t ON t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed'
            WHERE a.status = 'active'
            GROUP BY a.id, a.username
            ORDER BY deposit_amount DESC
            LIMIT 10";
    
    $result = $conn->query($sql);
    $admins = [];
    $user_counts = [];
    $deposit_amounts = [];
    
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row['username'];
        $user_counts[] = $row['user_count'];
        $deposit_amounts[] = floatval($row['deposit_amount']);
    }
    
    return [
        'admins' => $admins,
        'user_counts' => $user_counts,
        'deposit_amounts' => $deposit_amounts
    ];
}

// Get initial data for charts
$revenue_data = get_revenue_data($conn, 'week');
$admin_distribution_data = get_admin_distribution_data($conn);

// Get recent data
$recent_transactions = get_recent_transactions($conn, 5);
$recent_withdrawals = get_recent_withdrawals($conn, 5);
$recent_registrations = get_recent_registrations($conn, 5);
$top_admins = get_admin_performance($conn);

$title = "Super Admin Dashboard - RB Games";

include 'includes/header.php';
?>



        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>Super Admin Dashboard</h1>
                    <p>Welcome back, <span class="admin-name">Super Admin <?php echo $super_admin_username; ?></span>. Platform overview and analytics.</p>
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

            <!-- Platform Overview Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Admins</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $admins_count; ?></div>
                    <div class="stat-card-desc">Active platform admins</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Users</div>
                        <div class="stat-card-icon active-users-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_users; ?></div>
                    <div class="stat-card-desc">Registered users</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Revenue</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-card-value <?php echo $total_revenue >= 0 ? 'revenue-positive' : 'revenue-negative'; ?>">
                        $<?php echo number_format($total_revenue, 2); ?>
                    </div>
                    <div class="stat-card-desc">Platform net revenue</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Games</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_games; ?></div>
                    <div class="stat-card-desc">Active games</div>
                </div>
            </div>

            <!-- Financial Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Deposits</div>
                        <div class="stat-card-icon deposits-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_deposits, 2); ?></div>
                    <div class="stat-card-desc">All time deposits</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Withdrawals</div>
                        <div class="stat-card-icon withdrawals-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">All time withdrawals</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Pending Withdrawals</div>
                        <div class="stat-card-icon pending-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($pending_withdrawals, 2); ?></div>
                    <div class="stat-card-desc">Awaiting approval</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Active Users</div>
                        <div class="stat-card-icon users-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $active_users; ?></div>
                    <div class="stat-card-desc">Last 30 days activity</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Platform Revenue Overview</h3>
                        <div class="chart-actions">
                            <button class="chart-action-btn active" data-period="week">Week</button>
                            <button class="chart-action-btn" data-period="this_month">This Month</button>
                            <button class="chart-action-btn" data-period="last_month">Last Month</button>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Top Admins by Deposits</h3>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="adminDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Performing Admins -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-trophy"></i> Top Performing Admins</h2>
                    <a href="super_admin_manage_admins.php" class="view-all">View All Admins</a>
                </div>
                
                <div class="recent_data">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Admin</th>
                                <th>Users</th>
                                <th>New Users</th>
                                <th>Total Deposits</th>
                                <th>Total Withdrawals</th>
                                <th>Total Bets</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_admins)): ?>
                                <?php foreach ($top_admins as $admin): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                            <div class="admin-badge"><?php echo ucfirst($admin['status']); ?></div>
                                        </td>
                                        <td><?php echo $admin['total_users']; ?></td>
                                        <td><?php echo $admin['new_users']; ?></td>
                                        <td>$<?php echo number_format($admin['total_deposits'], 2); ?></td>
                                        <td>$<?php echo number_format($admin['total_withdrawals'], 2); ?></td>
                                        <td><?php echo $admin['total_bets']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No admin data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-data-grid">
                <!-- Recent Transactions -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                        <a href="super_admin_transactions.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Admin</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_transactions)): ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['user_name']); ?></td>
                                            <td>
                                                <span class="admin-badge"><?php echo htmlspecialchars($transaction['admin_name']); ?></span>
                                            </td>
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
                                        <td colspan="5" style="text-align: center;">No recent transactions</td>
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
                        <a href="super_admin_withdrawals.php" class="view-all">View All</a>
                    </div>
                    
                    <div class="recent_data">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Admin</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_withdrawals)): ?>
                                    <?php foreach ($recent_withdrawals as $withdrawal): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($withdrawal['user_name']); ?></td>
                                            <td>
                                                <span class="admin-badge"><?php echo htmlspecialchars($withdrawal['admin_name']); ?></span>
                                            </td>
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
                                        <td colspan="4" style="text-align: center;">No recent withdrawals</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="super_admin_manage_admins.php" class="action-btn">
                        <i class="fas fa-user-shield"></i>
                        <span>Manage Admins</span>
                    </a>
                    <a href="super_admin_all_users.php" class="action-btn">
                        <i class="fas fa-users"></i>
                        <span>View All Users</span>
                    </a>
                    <a href="super_admin_transactions.php" class="action-btn">
                        <i class="fas fa-search-dollar"></i>
                        <span>All Transactions</span>
                    </a>
                    <a href="super_admin_withdrawals.php" class="action-btn">
                        <i class="fas fa-hand-holding-usd"></i>
                        <span>Process Withdrawals</span>
                    </a>
                    <a href="super_admin_reports.php" class="action-btn">
                        <i class="fas fa-chart-pie"></i>
                        <span>Platform Reports</span>
                    </a>
                    <a href="super_admin_settings.php" class="action-btn">
                        <i class="fas fa-cog"></i>
                        <span>Platform Settings</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

<script>
    // Mobile menu functionality
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

    // Initialize charts with proper styling
    document.addEventListener('DOMContentLoaded', function() {
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($revenue_data['dates']); ?>,
                datasets: [
                    {
                        label: 'Deposits',
                        data: <?php echo json_encode($revenue_data['deposits']); ?>,
                        borderColor: '#00b894',
                        backgroundColor: 'rgba(0, 184, 148, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#00b894',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'Withdrawals',
                        data: <?php echo json_encode($revenue_data['withdrawals']); ?>,
                        borderColor: '#d63031',
                        backgroundColor: 'rgba(214, 48, 49, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#d63031',
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
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: 'var(--light-text)',
                            font: {
                                family: 'Poppins',
                                size: 12,
                                weight: '500'
                            },
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'var(--light-card)',
                        titleColor: 'var(--light-text)',
                        bodyColor: 'var(--light-text-secondary)',
                        borderColor: 'var(--light-border)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: true,
                        padding: 12,
                        boxPadding: 6,
                        usePointStyle: true,
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: $${context.parsed.y.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(100, 116, 139, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: 'var(--light-text-secondary)',
                            font: {
                                family: 'Poppins',
                                size: 11
                            },
                            callback: function(value) {
                                return '$' + value;
                            }
                        },
                        border: {
                            display: false
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(100, 116, 139, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: 'var(--light-text-secondary)',
                            font: {
                                family: 'Poppins',
                                size: 11
                            },
                            maxRotation: 45
                        },
                        border: {
                            display: false
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

        // Admin Distribution Chart
        const adminCtx = document.getElementById('adminDistributionChart').getContext('2d');
        const adminChart = new Chart(adminCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($admin_distribution_data['admins']); ?>,
                datasets: [{
                    label: 'Deposit Amount',
                    data: <?php echo json_encode($admin_distribution_data['deposit_amounts']); ?>,
                    backgroundColor: [
                        'rgba(102, 126, 234, 0.8)',
                        'rgba(102, 126, 234, 0.7)',
                        'rgba(102, 126, 234, 0.6)',
                        'rgba(102, 126, 234, 0.5)',
                        'rgba(102, 126, 234, 0.4)'
                    ],
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'var(--light-card)',
                        titleColor: 'var(--light-text)',
                        bodyColor: 'var(--light-text-secondary)',
                        borderColor: 'var(--light-border)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        padding: 12,
                        boxPadding: 6,
                        callbacks: {
                            label: function(context) {
                                return `Deposits: $${context.parsed.y.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(100, 116, 139, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: 'var(--light-text-secondary)',
                            font: {
                                family: 'Poppins',
                                size: 11
                            },
                            callback: function(value) {
                                return '$' + value;
                            }
                        },
                        border: {
                            display: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: 'var(--light-text-secondary)',
                            font: {
                                family: 'Poppins',
                                size: 11
                            },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        border: {
                            display: false
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });

        // Chart period switching
        document.querySelectorAll('.chart-action-btn').forEach(button => {
            button.addEventListener('click', function() {
                const period = this.dataset.period;
                
                document.querySelectorAll('.chart-action-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // In a real implementation, you would fetch new data for the selected period
                console.log('Switching to period:', period);
                
                // Update chart data based on period
                // This would typically involve an AJAX call to fetch new data
                updateChartData(period);
            });
        });

        function updateChartData(period) {
            // This function would handle fetching new data and updating charts
            console.log('Updating chart data for period:', period);
            // Implement AJAX call here to fetch new data and update charts
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
    
    updateTime();
    setInterval(updateTime, 60000);
</script>
</body>
</html>