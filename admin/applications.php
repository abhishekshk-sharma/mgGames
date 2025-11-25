<?php
// applications.php
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

// Get total count of admin requests
$sql_count = "SELECT COUNT(*) as total FROM admin_requests";
$result_count = $conn->query($sql_count);
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Get admin requests with pagination
$requests = [];
$sql = "SELECT ar.*, a.username as admin_username, u.username as user_username 
        FROM admin_requests ar 
        LEFT JOIN admins a ON ar.admin_id = a.id 
        LEFT JOIN users u ON ar.user_id = u.id 
        WHERE a.id = $admin_id
        ORDER BY ar.created_at DESC 
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Filter functionality
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_username = isset($_GET['filter_username']) ? $_GET['filter_username'] : '';

if ($filter_status || $filter_username) {
    $where_conditions = [];
    $params = [];
    $types = '';
    
    if ($filter_status) {
        $where_conditions[] = "ar.status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
    
    if ($filter_username) {
        $where_conditions[] = "u.username LIKE ?";
        $params[] = "%$filter_username%";
        $types .= 's';
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Update count with filters
    $sql_count = "SELECT COUNT(*) as total FROM admin_requests ar 
                  LEFT JOIN users u ON ar.user_id = u.id 
                  $where_clause";
    $stmt_count = $conn->prepare($sql_count);
    if ($stmt_count) {
        if ($params) {
            $stmt_count->bind_param($types, ...$params);
        }
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        $total_records = $result_count->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $limit);
    }
    
    // Update requests query with filters
    $sql = "SELECT ar.*, a.username as admin_username, u.username as user_username 
            FROM admin_requests ar 
            LEFT JOIN admins a ON ar.admin_id = a.id 
            LEFT JOIN users u ON ar.user_id = u.id 
            $where_clause
            ORDER BY ar.created_at DESC 
            LIMIT $limit OFFSET $offset";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
    }
}

$pagefilename = "applications";

include "includes/header.php";
?>


        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Applications</h1>
                    <p>View and manage all admin requests</p>
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
                    <h2 class="section-title"><i class="fas fa-filter"></i> Filter Applications</h2>
                </div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="filter_username" class="form-control" placeholder="Search by username..." value="<?php echo htmlspecialchars($filter_username); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group" style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="applications.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-tasks"></i> All Applications</h2>
                    <div class="view-all">Total: <?php echo $total_records; ?></div>
                </div>
                
                <?php if (!empty($requests)): ?>
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
                                    
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><?php echo $request['id']; ?></td>
                                        <td><?php echo $request['admin_username'] ?? 'N/A'; ?></td>
                                        <td><?php echo $request['user_username'] ?? 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($request['title']); ?></td>
                                        <td><?php echo htmlspecialchars($request['description']); ?></td>
                                        <td>
                                            <?php if ($request['amount'] !== null): ?>
                                                $<?php echo number_format($request['amount'], 2); ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></td>
                                        
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="requests-cards">
                        <?php foreach ($requests as $request): ?>
                            <div class="request-card">
                                <div class="request-row">
                                    <span class="request-label">ID:</span>
                                    <span class="request-value"><?php echo $request['id']; ?></span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Admin:</span>
                                    <span class="request-value"><?php echo $request['admin_username'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">User:</span>
                                    <span class="request-value"><?php echo $request['user_username'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Title:</span>
                                    <span class="request-value"><?php echo htmlspecialchars($request['title']); ?></span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Description:</span>
                                    <span class="request-value"><?php echo htmlspecialchars($request['description']); ?></span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Amount:</span>
                                    <span class="request-value">
                                        <?php if ($request['amount'] !== null): ?>
                                            $<?php echo number_format($request['amount'], 2); ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Status:</span>
                                    <span class="request-value">
                                        <span class="status status-<?php echo $request['status']; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="request-row">
                                    <span class="request-label">Date:</span>
                                    <span class="request-value"><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></span>
                                </div>
                               
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
                            <a href="?page=1<?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?><?php echo $filter_title ? '&filter_title=' . $filter_title : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?><?php echo $filter_title ? '&filter_title=' . $filter_title : ''; ?>">
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
                            <a href="?page=<?php echo $i; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?><?php echo $filter_title ? '&filter_title=' . $filter_title : ''; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?><?php echo $filter_title ? '&filter_title=' . $filter_title : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?><?php echo $filter_status ? '&filter_status=' . $filter_status : ''; ?><?php echo $filter_title ? '&filter_title=' . $filter_title : ''; ?>">
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

        // Function to update request status
        function updateRequestStatus(requestId, status) {
            if (confirm(`Are you sure you want to ${status} this request?`)) {
                // Create a form to submit the request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'update_request_status.php';
                
                const requestIdInput = document.createElement('input');
                requestIdInput.type = 'hidden';
                requestIdInput.name = 'request_id';
                requestIdInput.value = requestId;
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = status;
                
                form.appendChild(requestIdInput);
                form.appendChild(statusInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>

</body>
</html>