<?php

require_once '../config.php';
// Start session (if not already started)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


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

// Unset all session variables
$_SESSION = array();

session_unset();

// Destroy the session
session_destroy();

// Log logout action
            try {
                $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, title, description, created_at) VALUES (?, 'Admin Logout', 'Admin logged out of the system', NOW())");
                $stmt->execute([$admin_id]);
            } catch (Exception $e) {
                // Silently fail if logging doesn't work
                error_log("Failed to log dashboard access: " . $e->getMessage());
            }

// Redirect to login page
header("location: login.php");
exit;
?>