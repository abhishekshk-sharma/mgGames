<?php
// get_user_transactions.php
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

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$user_id = intval($_GET['user_id']);
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

try {
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM transactions WHERE user_id = ?";
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    
    // Get transactions
    $transactions_sql = "SELECT * FROM transactions 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($transactions_sql);
    $stmt->bind_param("iii", $user_id, $records_per_page, $offset);
    $stmt->execute();
    $transactions_result = $stmt->get_result();
    $transactions = [];
    
    while ($row = $transactions_result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $total_pages,
            'total' => $total_records,
            'start' => $offset + 1,
            'end' => min($offset + $records_per_page, $total_records)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_user_transactions.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>