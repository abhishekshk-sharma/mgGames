<?php
// update_user_info.php
require_once '../config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is super admin
if (!isset($_SESSION['super_admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_details.php?user_id=" . $_POST['user_id'] . "&error=1&message=Invalid request method");
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);
$username = sanitize_input($conn, $_POST['username'] ?? '');
$email = sanitize_input($conn, $_POST['email'] ?? '');
$phone = sanitize_input($conn, $_POST['phone'] ?? '');
$status = sanitize_input($conn, $_POST['status'] ?? '');

if (!$user_id) {
    header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=User ID is required");
    exit;
}

try {
    // Check if username already exists (excluding current user)
    $check_username_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
    $stmt = $conn->prepare($check_username_sql);
    $stmt->bind_param("si", $username, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=Username already exists");
        exit;
    }
    
    // Check if email already exists (excluding current user)
    $check_email_sql = "SELECT id FROM users WHERE email = ? AND id != ?";
    $stmt = $conn->prepare($check_email_sql);
    $stmt->bind_param("si", $email, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=Email already exists");
        exit;
    }
    
    // Update user information
    $update_sql = "UPDATE users SET username = ?, email = ?, phone = ?, status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ssssi", $username, $email, $phone, $status, $user_id);
    
    if ($stmt->execute()) {
        header("Location: user_details.php?user_id=" . $user_id . "&success=1&message=User information updated successfully");
    } else {
        header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=Failed to update user information");
    }
    
} catch (Exception $e) {
    header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=Server error");
}
?>