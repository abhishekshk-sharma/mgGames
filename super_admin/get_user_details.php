<?php
// get_user_details.php
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

try {
    // Get basic user information
    $user_sql = "SELECT u.*, a.username as admin_username,
                (SELECT COUNT(*) FROM bets b WHERE b.user_id = u.id) as total_bets,
                (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed') as total_deposits,
                (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'withdrawal' AND t.status = 'completed') as total_withdrawals,
                (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'winning' AND t.status = 'completed') as total_winnings,
                (SELECT SUM(amount) FROM bets b WHERE b.user_id = u.id) as total_bets_amount,
                (SELECT COUNT(DISTINCT game_session_id) FROM bets WHERE user_id = u.id) as total_games,
                (SELECT COUNT(*) FROM bets WHERE user_id = u.id AND status = 'won') as won_bets,
                (SELECT MAX(placed_at) FROM bets WHERE user_id = u.id) as last_bet_date,
                (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_active = 1) as active_sessions
                FROM users u
                LEFT JOIN admins a ON u.referral_code = a.referral_code
                WHERE u.id = ?";
    
    $stmt = $conn->prepare($user_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    $user = $result->fetch_assoc();
    
    // Calculate winning rate
    $total_bets = $user['total_bets'] ?: 0;
    $won_bets = $user['won_bets'] ?: 0;
    $user['winning_rate'] = $total_bets > 0 ? round(($won_bets / $total_bets) * 100, 2) . '%' : '0%';
    
    // Get favorite game
    $favorite_game_sql = "SELECT game_name, COUNT(*) as game_count 
                         FROM bets 
                         WHERE user_id = ? 
                         GROUP BY game_name 
                         ORDER BY game_count DESC 
                         LIMIT 1";
    $stmt = $conn->prepare($favorite_game_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $favorite_result = $stmt->get_result();
    $user['favorite_game'] = $favorite_result->num_rows > 0 ? $favorite_result->fetch_assoc()['game_name'] : '-';
    
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_user_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>