<?php

// profit_loss.php
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
// admin_logs_viewer.php
// Add this to your admin panel to view the logs
$stmt = $conn->prepare("
    SELECT al.*, a.username as admin_username 
    FROM admin_logs al 
    JOIN admins a ON al.admin_id = a.id 
    ORDER BY al.created_at DESC 
    LIMIT 100
");
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!-- Display logs in a table -->
<table class="data-table">
    <thead>
        <tr>
            <th>Admin</th>
            <th>Action</th>
            <th>Description</th>
            <th>Timestamp</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($logs as $log): ?>
        <tr>
            <td><?php echo htmlspecialchars($log['admin_username']); ?></td>
            <td><?php echo htmlspecialchars($log['title']); ?></td>
            <td><?php echo htmlspecialchars($log['description']); ?></td>
            <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>