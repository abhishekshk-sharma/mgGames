<?php
// adjust_user_balance.php
require_once '../config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is super admin
if (!isset($_SESSION['super_admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);
$adjustment_type = sanitize_input($conn, $_POST['adjustment_type'] ?? '');
$amount = floatval($_POST['amount'] ?? 0);
$reason = sanitize_input($conn, $_POST['reason'] ?? '');

if (!$user_id || !$amount || !$reason) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
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
        throw new Exception('Failed to update user balance: ' . $stmt->error);
    }
    
    // Record transaction
    $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())";
    $trans_stmt = $conn->prepare($transaction_sql);
    $trans_stmt->bind_param("isdids", $user_id, $transaction_type, $amount, $current_balance, $new_balance, $reason);
    
    if (!$trans_stmt->execute()) {
        throw new Exception('Failed to record transaction: ' . $trans_stmt->error);
    }
    
    // Log admin action
    $admin_log_sql = "INSERT INTO admin_logs (admin_id, title, description, created_at) 
                     VALUES (?, 'Balance Adjustment', ?, NOW())";
    $log_stmt = $conn->prepare($admin_log_sql);
    $log_description = "Adjusted balance for user {$username} (ID: {$user_id}): " . 
                     ($adjustment_type === 'add' ? '+' : '-') . "₹{$amount}. Reason: {$reason}";
    $log_stmt->bind_param("is", $_SESSION['super_admin_id'], $log_description);
    $log_stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Balance adjusted successfully',
        'new_balance' => $new_balance
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Error in adjust_user_balance.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>