<?php
// adjust_user_balance.php
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
$adjustment_type = sanitize_input($conn, $_POST['adjustment_type'] ?? '');
$amount = floatval($_POST['amount'] ?? 0);
$reason = sanitize_input($conn, $_POST['reason'] ?? '');

if (!$user_id || !$amount || !$reason) {
    header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=All fields are required");
    exit;
}

if ($amount <= 0) {
    header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=Amount must be greater than 0");
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Get current balance
    $balance_sql = "SELECT balance, username FROM users WHERE id = ?";
    $stmt = $conn->prepare($balance_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $balance_result = $stmt->get_result();
    
    if ($balance_result->num_rows === 0) {
        throw new Exception('User not found');
    }
    
    $user_data = $balance_result->fetch_assoc();
    $current_balance = $user_data['balance'];
    $username = $user_data['username'];
    
    // Calculate new balance
    if ($adjustment_type === 'add') {
        $new_balance = $current_balance + $amount;
        $transaction_type = 'adjustment';
    } else {
        if ($current_balance < $amount) {
            throw new Exception('Insufficient balance for deduction');
        }
        $new_balance = $current_balance - $amount;
        $transaction_type = 'adjustment';
    }
    
    // Update user balance
    $update_sql = "UPDATE users SET balance = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("di", $new_balance, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update user balance');
    }
    
    // Record transaction
    $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())";
    $trans_stmt = $conn->prepare($transaction_sql);
    $trans_stmt->bind_param("isdids", $user_id, $transaction_type, $amount, $current_balance, $new_balance, $reason);
    
    if (!$trans_stmt->execute()) {
        throw new Exception('Failed to record transaction');
    }
    
    // Commit transaction
    $conn->commit();
    
    header("Location: user_details.php?user_id=" . $user_id . "&success=1&message=Balance adjusted successfully");
    
} catch (Exception $e) {
    $conn->rollback();
    header("Location: user_details.php?user_id=" . $user_id . "&error=1&message=" . urlencode($e->getMessage()));
}
?>