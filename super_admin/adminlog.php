<?php
// adminlog.php
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

// Pagination and filtering setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search_admin = isset($_GET['search_admin']) ? trim($_GET['search_admin']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for admin logs count
$count_sql = "SELECT COUNT(al.id) as total 
              FROM admin_logs al 
              LEFT JOIN admins a ON al.admin_id = a.id 
              WHERE 1=1";
$params = [];
$types = '';

if ($search_admin) {
    $count_sql .= " AND (a.username LIKE ? OR a.id = ?)";
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($start_date && $end_date) {
    $count_sql .= " AND DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
} elseif ($start_date) {
    $count_sql .= " AND DATE(al.created_at) >= ?";
    $params[] = $start_date;
    $types .= 's';
} elseif ($end_date) {
    $count_sql .= " AND DATE(al.created_at) <= ?";
    $params[] = $end_date;
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

// Build query for admin logs with pagination
$sql = "SELECT al.*, a.username as admin_username
        FROM admin_logs al 
        LEFT JOIN admins a ON al.admin_id = a.id 
        WHERE 1=1";

if ($search_admin) {
    $sql .= " AND (a.username LIKE ? OR a.id = ?)";
}

if ($start_date && $end_date) {
    $sql .= " AND DATE(al.created_at) BETWEEN ? AND ?";
} elseif ($start_date) {
    $sql .= " AND DATE(al.created_at) >= ?";
} elseif ($end_date) {
    $sql .= " AND DATE(al.created_at) <= ?";
}

$sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [];
$types = '';

if ($search_admin) {
    $params[] = "%$search_admin%";
    $params[] = $search_admin;
    $types .= 'ss';
}

if ($start_date && $end_date) {
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
} elseif ($start_date) {
    $params[] = $start_date;
    $types .= 's';
} elseif ($end_date) {
    $params[] = $end_date;
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
$admin_logs = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $admin_logs[] = $row;
    }
}

// Get stats for dashboard
$total_logs = 0;
$unique_admins = 0;
$today_logs = 0;

$stats_sql = "SELECT 
    COUNT(al.id) as total_logs,
    COUNT(DISTINCT al.admin_id) as unique_admins,
    COUNT(CASE WHEN DATE(al.created_at) = CURDATE() THEN 1 END) as today_logs
    FROM admin_logs al 
    LEFT JOIN admins a ON al.admin_id = a.id 
    WHERE 1=1";
    
$stats_params = [];
$stats_types = '';

if ($search_admin) {
    $stats_sql .= " AND (a.username LIKE ? OR a.id = ?)";
    $stats_params[] = "%$search_admin%";
    $stats_params[] = $search_admin;
    $stats_types .= 'ss';
}

if ($start_date && $end_date) {
    $stats_sql .= " AND DATE(al.created_at) BETWEEN ? AND ?";
    $stats_params[] = $start_date;
    $stats_params[] = $end_date;
    $stats_types .= 'ss';
} elseif ($start_date) {
    $stats_sql .= " AND DATE(al.created_at) >= ?";
    $stats_params[] = $start_date;
    $stats_types .= 's';
} elseif ($end_date) {
    $stats_sql .= " AND DATE(al.created_at) <= ?";
    $stats_params[] = $end_date;
    $stats_types .= 's';
}

$stmt_stats = $conn->prepare($stats_sql);
if (!empty($stats_params)) {
    $stmt_stats->bind_param($stats_types, ...$stats_params);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
if ($stats_result && $stats_result->num_rows > 0) {
    $stats = $stats_result->fetch_assoc();
    $total_logs = $stats['total_logs'];
    $unique_admins = $stats['unique_admins'];
    $today_logs = $stats['today_logs'];
}

include 'includes/header.php';
?>


        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Admin Activity Logs</h1>
                    <p>Monitor all admin activities across the platform</p>
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Total Logs</div>
                        <div class="stat-card-icon total-icon">
                            <i class="fas fa-list-alt"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $total_logs; ?></div>
                    <div class="stat-card-desc">All admin activities</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Unique Admins</div>
                        <div class="stat-card-icon admin-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $unique_admins; ?></div>
                    <div class="stat-card-desc">Active administrators</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-card-title">Today's Logs</div>
                        <div class="stat-card-icon today-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                    <div class="stat-card-value"><?php echo $today_logs; ?></div>
                    <div class="stat-card-desc">Activities today</div>
                </div>
            </div>

            <!-- Admin Logs Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-history"></i> Admin Activity Logs</h2>
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
                        
                        <div class="date-inputs">
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" placeholder="From Date">
                            <span>to</span>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" placeholder="To Date">
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
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        
                        <a href="adminlog.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </form>
                </div>
                
                <?php if (!empty($admin_logs)): ?>
                    <!-- Desktop Table View -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Admin</th>
                                    <th>Activity</th>
                                    <th>Description</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin_logs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['id']; ?></td>
                                        <td>
                                            <span class="admin-info">
                                                <?php echo $log['admin_username'] ? $log['admin_username'] . ' (ID: ' . $log['admin_id'] . ')' : 'Admin ID: ' . $log['admin_id']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="log-title"><?php echo htmlspecialchars($log['title']); ?></span>
                                        </td>
                                        <td>
                                            <span class="log-description"><?php echo htmlspecialchars($log['description']); ?></span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="logs-cards">
                        <?php foreach ($admin_logs as $log): ?>
                            <div class="log-card">
                                <div class="log-row">
                                    <span class="log-label">ID:</span>
                                    <span class="log-value"><?php echo $log['id']; ?></span>
                                </div>
                                <div class="log-row">
                                    <span class="log-label">Admin:</span>
                                    <span class="log-value">
                                        <span class="admin-info">
                                            <?php echo $log['admin_username'] ? $log['admin_username'] . ' (ID: ' . $log['admin_id'] . ')' : 'Admin ID: ' . $log['admin_id']; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="log-row">
                                    <span class="log-label">Activity:</span>
                                    <span class="log-value">
                                        <span class="log-title"><?php echo htmlspecialchars($log['title']); ?></span>
                                    </span>
                                </div>
                                <div class="log-row">
                                    <span class="log-label">Description:</span>
                                    <span class="log-value">
                                        <span class="log-description"><?php echo htmlspecialchars($log['description']); ?></span>
                                    </span>
                                </div>
                                <div class="log-row">
                                    <span class="log-label">Date & Time:</span>
                                    <span class="log-value"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                        <p>No admin logs found.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&search_admin=<?php echo urlencode($search_admin); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&search_admin=<?php echo urlencode($search_admin); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&search_admin=<?php echo urlencode($search_admin); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&search_admin=<?php echo urlencode($search_admin); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&search_admin=<?php echo urlencode($search_admin); ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
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
    </script>
</body>
</html>