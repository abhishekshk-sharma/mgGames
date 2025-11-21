
<?php

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

// Handle AJAX requests for pagination FIRST
if (isset($_GET['ajax']) && isset($_GET['view_user'])) {
    $user_id = intval($_GET['view_user']);
    
    // Get admin details for the referral code
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();
    
    // Determine which tab content to return
    if (isset($_GET['bets_page'])) {
        $bets_page = max(1, intval($_GET['bets_page']));
        $per_page = 10;
        $user_bets = getUserBets($conn, $user_id, $bets_page, $per_page, $referral_code);
        displayBetsTab($user_bets, $bets_page, $per_page);
    }
    
    if (isset($_GET['transactions_page'])) {
        $transactions_page = max(1, intval($_GET['transactions_page']));
        $per_page = 10;
        $user_transactions = getUserTransactions($conn, $user_id, $transactions_page, $per_page, $referral_code);
        displayTransactionsTab($user_transactions, $transactions_page, $per_page);
    }
    exit;
}

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code  = $stmt->get_result();
$referral_code = $referral_code->fetch_assoc();

global $referral_code;
// Initialize variables
$search = '';
$date_from = '';
$date_to = '';
$users = [];
$user_details = null;
$user_bets = [];
$user_transactions = [];

// Pagination variables for user details
$bets_page = isset($_GET['bets_page']) ? max(1, intval($_GET['bets_page'])) : 1;
$transactions_page = isset($_GET['transactions_page']) ? max(1, intval($_GET['transactions_page'])) : 1;
$per_page = 10; // Number of items per page

// Handle search and filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    
    // Handle view user details
    if (isset($_GET['view_user'])) {
        $user_id = intval($_GET['view_user']);
        $user_details = getUserDetails($conn, $user_id);
        if ($user_details) {
            $user_bets = getUserBets($conn, $user_id, $bets_page, $per_page, $referral_code);
            $user_transactions = getUserTransactions($conn, $user_id, $transactions_page, $per_page, $referral_code);
        }
    }
    
    // Handle ban user
    if (isset($_GET['ban_user'])) {
        $user_id = intval($_GET['ban_user']);
        banUser($conn, $user_id);
        header("Location: users.php");
        exit;
    }
    
    // Handle unban user
    if (isset($_GET['unban_user'])) {
        $user_id = intval($_GET['unban_user']);
        unbanUser($conn, $user_id);
        header("Location: users.php");
        exit;
    }
    
    // Handle delete user (with confirmation)
    if (isset($_GET['delete_user'])) {
        $user_id = intval($_GET['delete_user']);

        $stmt = $conn->prepare("SELECT * FROM admin_requests WHERE user_id = ? AND title = 'User Deletion' AND status = 'pending'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $pre_result = $stmt->get_result();
        $result = $pre_result->num_rows;
        if ($result > 0) {
            $_SESSION['error_message'] = "A deletion request for this user is already pending.";
            header("Location: users.php");
            exit;
        }
        else{
            

            if (deleteUser($conn, $user_id)) {
                $_SESSION['success_message'] = "Request to delete user submitted successfully!";
                header("Location: users.php");
            } else {
                $_SESSION['error_message'] = "Error deleting user!";
            }
            header("Location: users.php");
        }
        exit;
    }
}

// Get users with filters
$users = getUsers($conn, $search, $date_from, $date_to);

// Function to display bets tab content
function displayBetsTab($user_bets, $current_page, $per_page) {
    ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Game</th>
                <th>Amount</th>
                <th>Potential Win</th>
                <th>Status</th>
                <th>Placed At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($user_bets['data'])): ?>
                <?php foreach ($user_bets['data'] as $bet): ?>
                    <tr>
                        <td><?php echo $bet['id']; ?></td>
                        <td><?php echo htmlspecialchars($bet['game_name'] ?? 'N/A'); ?></td>
                        <td>$<?php echo number_format($bet['amount'], 2); ?></td>
                        <td>$<?php echo number_format($bet['potential_win'], 2); ?></td>
                        <td>
                            <span class="status status-<?php echo $bet['status']; ?>">
                                <?php echo ucfirst($bet['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 1.5rem;">
                        <i class="fas fa-info-circle" style="color: var(--text-muted);"></i>
                        No bets found for this user.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($user_bets['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <button data-page="<?php echo $current_page - 1; ?>">&laquo; Previous</button>
        <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $user_bets['total_pages']; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <button data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $user_bets['total_pages']): ?>
            <button data-page="<?php echo $current_page + 1; ?>">Next &raquo;</button>
        <?php else: ?>
            <span class="disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <div class="pagination-info">
        Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to <?php echo min($current_page * $per_page, $user_bets['total']); ?> of <?php echo $user_bets['total']; ?> bets
    </div>
    <?php endif;
}

// Function to display transactions tab content
function displayTransactionsTab($user_transactions, $current_page, $per_page) {
    ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($user_transactions['data'])): ?>
                <?php foreach ($user_transactions['data'] as $transaction): ?>
                    <tr>
                        <td><?php echo $transaction['id']; ?></td>
                        <td>
                            <span class="status <?php echo $transaction['type'] === 'deposit' ? 'status-active' : 'status-warning'; ?>">
                                <?php echo ucfirst($transaction['type']); ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($transaction['amount'], 2); ?></td>
                        <td>
                            <span class="status status-<?php echo $transaction['status']; ?>">
                                <?php echo ucfirst($transaction['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 1.5rem;">
                        <i class="fas fa-info-circle" style="color: var(--text-muted);"></i>
                        No transactions found for this user.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($user_transactions['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <button data-page="<?php echo $current_page - 1; ?>">&laquo; Previous</button>
        <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $user_transactions['total_pages']; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <button data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($current_page < $user_transactions['total_pages']): ?>
            <button data-page="<?php echo $current_page + 1; ?>">Next &raquo;</button>
        <?php else: ?>
            <span class="disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <div class="pagination-info">
        Showing <?php echo (($current_page - 1) * $per_page) + 1; ?> to <?php echo min($current_page * $per_page, $user_transactions['total']); ?> of <?php echo $user_transactions['total']; ?> transactions
    </div>
    <?php endif;
}

// Function to get users with filters
function getUsers($conn, $search = '', $date_from = '', $date_to = '') {
    // Get admin details
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();

    $sql = "SELECT * FROM users WHERE referral_code = '".$referral_code['referral_code']."'";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (username LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($date_from)) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " AND referral_code = '".$referral_code['referral_code']."' ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return $users;
}

// Function to get user details
function getUserDetails($conn, $user_id) {
    // Get admin details
    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $referral_result = $stmt->get_result();
    $referral_code = $referral_result->fetch_assoc();

    $sql = "SELECT * FROM users WHERE id = ? AND referral_code = '".$referral_code['referral_code']."'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get user bets with pagination
function getUserBets($conn, $user_id, $page = 1, $per_page = 10, $referral_code = null) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT b.*, g.name as game_name, gt.name as game_type_name 
            FROM bets b 
            JOIN users u ON b.user_id = u.id
            LEFT JOIN games g ON b.game_session_id = g.id 
            LEFT JOIN game_types gt ON b.game_type_id = gt.id 
            WHERE b.user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'
            ORDER BY b.placed_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $conn->error);
        return ['data' => [], 'total' => 0, 'total_pages' => 0];
    }
    
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    
    if (!$stmt->execute()) {
        error_log("SQL Execute Error: " . $stmt->error);
        return ['data' => [], 'total' => 0, 'total_pages' => 0];
    }
    
    $result = $stmt->get_result();
    
    $bets = [];
    while ($row = $result->fetch_assoc()) {
        $bets[] = $row;
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM bets b 
                  JOIN users u ON b.user_id = u.id
                  WHERE b.user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_bets = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_bets / $per_page);
    
    $stmt->close();
    $count_stmt->close();
    
    return [
        'data' => $bets,
        'total' => $total_bets,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'per_page' => $per_page
    ];
}

// Function to get user transactions with pagination
function getUserTransactions($conn, $user_id, $page = 1, $per_page = 10, $referral_code = null) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'
            ORDER BY t.created_at DESC 
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM transactions t
                  JOIN users u ON t.user_id = u.id
                  WHERE user_id = ? AND u.referral_code = '".$referral_code['referral_code']."'";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_transactions = $count_result->fetch_assoc()['total'];
    $total_pages = ceil($total_transactions / $per_page);
    
    $stmt->close();
    $count_stmt->close();
    
    return [
        'data' => $transactions,
        'total' => $total_transactions,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'per_page' => $per_page
    ];
}

// Function to ban user
function banUser($conn, $user_id) {
    $sql = "UPDATE users SET status = 'banned' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    return $stmt->execute();
}

// Function to unban user
function unbanUser($conn, $user_id) {
    $sql = "UPDATE users SET status = 'active' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    return $stmt->execute();
}

// Function to delete user
function deleteUser($conn, $user_id) {

    $user_id = intval($user_id);
    // Start transaction
    $conn->begin_transaction();
    
    try {

        $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'User Deletion'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['error_message'] = "There is already a pending user deletion request for this user.";
            header("Location: users.php");
            exit();
        }

        $admin_id = isset($_SESSION['admin_id'])? intval($_SESSION['admin_id']) : 0;
        $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $referral_result = $stmt->get_result();
        $get_admin_name = $referral_result->fetch_assoc();
        $referral_result->free();   // <-- important
        $stmt->close();


        // Delete user related data first
        $title = "User Deletion";
        $description = "User account deleted by admin: " . $get_admin_name['username'];
        $delete_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,'pending',NOW())");
        $delete_stmt->bind_param("iiss", $admin_id, $user_id, $title, $description);
        $delete_stmt->execute();
        
       
        
        $conn->commit();
        $_SESSION['success_message'] = "Request to delete user submitted successfully!";
        
        
        
        return true;
    } catch (Exception $e) {
        
        try { $conn->rollback(); } catch (Exception $rollbackError) {}
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - RB Games Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9;
            --accent: #00cec9;
            --dark: #1a1a2e;
            --darker: #16213e;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --text-light: #f5f6fa;
            --text-muted: rgba(255, 255, 255, 0.7);
            --card-bg: rgba(26, 26, 46, 0.8);
            --border-color: rgba(255, 60, 126, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--dark) 0%, var(--darker) 100%);
            color: var(--text-light);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Mobile menu toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            z-index: 1001;
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: var(--card-bg);
            border-radius: 6px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: var(--dark);
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            transition: all 0.3s ease;
            left: -260px;
            top: 0;
            overflow-x: scroll;
        }
        .sidebar::-webkit-scrollbar{
            display:none;
        }

        .sidebar.active {
            left: 0;
        }

        .sidebar-header {
            padding: 1.8rem 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            padding: 1.5rem 0;
            flex-grow: 1;
        }

        .menu-item {
            padding: 1rem 1.8rem;
            display: flex;
            align-items: center;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
            margin: 0.3rem 0.8rem;
            border-radius: 8px;
        }

        .menu-item:hover, .menu-item.active {
            background: linear-gradient(to right, rgba(255, 60, 126, 0.2), rgba(11, 180, 201, 0.2));
            border-left: 4px solid var(--primary);
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 1.2rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            width: 100%;
            overflow-y: auto;
            transition: all 0.3s ease;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.2rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .welcome h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .welcome p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .admin-badge {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 500;
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .admin-badge i {
            color: var(--primary);
        }

        .logout-btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.6rem 1.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            box-shadow: 0 4px 10px rgba(255, 60, 126, 0.3);
            text-decoration: none;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 60, 126, 0.4);
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.2rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 60, 126, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: var(--dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th, .data-table td {
            padding: 1.2rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-suspended {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-banned {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 2rem;
            max-width: 90%;
            width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.2rem;
            border: 1px solid var(--border-color);
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Confirmation modal */
        .confirmation-modal .modal-content {
            max-width: 500px;
        }

        .confirmation-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .tbody{
            position:relative;
            overflow-x:scroll;
        }
        .tbody::-webkit-scrollbar{
            display: none;
        }

        /* ========== RESPONSIVE DESIGN ========== */
        
        /* Large screens (993px and above) */
        @media (min-width: 993px) {
            .sidebar {
                width: 260px;
                left: 0;
                position: fixed;
            }
            .main-content {
                margin-left: 260px;
                width: calc(100% - 260px);
            }
            .menu-toggle {
                display: none;
            }
            .sidebar-overlay {
                display: none !important;
            }
        }

        /* Medium screens (769px - 992px) */
        @media (max-width: 992px) and (min-width: 769px) {
            .sidebar {
                width: 80px;
                left: 0;
            }
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
                padding: 1.5rem;
            }
            .menu-toggle {
                display: none;
            }
            .sidebar-header h2 {
                font-size: 1.2rem;
            }
            .menu-item span {
                display: none;
            }
            .menu-item {
                justify-content: center;
                padding: 1rem;
            }
            .menu-item i {
                margin-right: 0;
            }
        }

        /* Small screens (768px and below) */
        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
            .menu-toggle {
                display: block;
            }
            .header {
                margin-top: 4rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .header-actions {
                flex-direction: column;
                width: 100%;
                gap: 0.8rem;
            }
            .admin-badge, .logout-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Extra small devices (576px and below) */
        @media (max-width: 576px) {
            .main-content {
                padding: 1rem 0.8rem;
            }
            .welcome h1 {
                font-size: 1.5rem;
            }
            .welcome p {
                font-size: 0.9rem;
            }
            .filter-section {
                padding: 1rem;
            }
            .filter-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .data-table {
                font-size: 0.85rem;
            }
            .data-table th, .data-table td {
                padding: 0.8rem 0.5rem;
            }
            .action-buttons {
                flex-direction: column;
                gap: 0.3rem;
            }
            .btn-sm {
                padding: 0.4rem 0.6rem;
                font-size: 0.8rem;
            }
        }

        /* Ultra small devices (400px and below) */
        @media (max-width: 400px) {
            .main-content {
                padding: 0.8rem 0.5rem;
            }
            .header {
                margin-top: 3.5rem;
                gap: 0.8rem;
            }
            .welcome h1 {
                font-size: 1.3rem;
            }
            .welcome p {
                font-size: 0.85rem;
            }
            .filter-section {
                padding: 0.8rem;
            }
            .form-control {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }
            .btn {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            .data-table {
                font-size: 0.8rem;
            }
            .data-table th, .data-table td {
                padding: 0.6rem 0.4rem;
            }
            .status {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }
            .action-buttons .btn-sm {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }
            .modal-content {
                padding: 1rem;
                width: 95%;
            }
            .user-info-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
            .info-card {
                padding: 0.8rem;
            }
            .tabs {
                flex-direction: column;
            }
            .tab {
                padding: 0.8rem;
                text-align: center;
            }
            .confirmation-buttons {
                flex-direction: column;
            }
            .confirmation-buttons .btn {
                width: 100%;
            }
            .menu-toggle {
                top: 0.8rem;
                left: 0.8rem;
                padding: 0.4rem;
                font-size: 1.3rem;
            }
        }

        /* Utility classes */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .mt-1 { margin-top: 0.5rem; }
        .mt-2 { margin-top: 1rem; }
        .mt-3 { margin-top: 1.5rem; }
        .mb-1 { margin-bottom: 0.5rem; }
        .mb-2 { margin-bottom: 1rem; }
        .mb-3 { margin-bottom: 1.5rem; }
        .p-1 { padding: 0.5rem; }
        .p-2 { padding: 1rem; }
        .p-3 { padding: 1.5rem; }

        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <style>
        /* ... (all existing CSS remains the same) ... */
        
         /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 1.5rem;
            gap: 0.5rem;
        }
        
        .pagination a, .pagination span, .pagination button {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s ease;
            background: var(--card-bg);
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .pagination a:hover, .pagination button:hover {
            background: rgba(255, 60, 126, 0.2);
            border-color: var(--primary);
        }
        
        .pagination .current {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border-color: var(--primary);
        }
        
        .pagination .disabled {
            color: var(--text-muted);
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .pagination .disabled:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .loading-pagination {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Mobile Menu Toggle -->
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games</h2>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item active">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                
                
                <a href="todays_active_games.php" class="menu-item ">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item ">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="all_users_history.php" class="menu-item ">
                    <i class="fas fa-history"></i>
                    <span>All Users Bet History</span>
                </a>
                <a href="admin_transactions.php" class="menu-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Transactions</span>
                </a>
                <a href="admin_withdrawals.php" class="menu-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Withdrawals</span>
                </a>
                <a href="admin_deposits.php" class="menu-item ">
                    <i class="fas fa-money-bill"></i>
                    <span>Deposits</span>
                </a>
                <a href="applications.php" class="menu-item">
                    <i class="fas fa-tasks"></i>
                    <span>Applications</span>
                </a>
                <a href="admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="admin_profile.php" class="menu-item ">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
            </div>
            <div class="sidebar-footer">
                <div class="admin-info">
                    <p>Logged in as <strong><?php echo $admin_username; ?></strong></p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>User Management</h1>
                    <p>Manage users, view details, and perform actions</p>
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
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label class="form-label">Search Users</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by username, email, or phone" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="users.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Users List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-users"></i> Users List (<?php echo count($users); ?>)</h2>
                </div>
                
                <?php if (!empty($users)): ?>
                <div class="tbody">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                    <td>$<?php echo number_format($user['balance'], 2); ?></td>
                                    <td>
                                        <span class="status status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-primary btn-sm view-user" data-user-id="<?php echo $user['id']; ?>" onclick="window.location.href = 'viewuser.php?id=<?= $user['id'] ?>'">
                                                <i class="fas fa-eye"></i> View
                                            </button>

                                            <?php if($referral_code['status'] == 'suspend'):?>
                            
                                            <?php else:?>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <a href="users.php?ban_user=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-ban"></i> Ban
                                                    </a>
                                                <?php else: ?>
                                                    <a href="users.php?unban_user=<?php echo $user['id']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i> Unban
                                                    </a>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm delete-user" data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-users" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <p style="color: var(--text-muted);">No users found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

   <!-- User Details Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>User Details</h3>
                <button class="modal-close" id="closeUserModal">&times;</button>
            </div>
            <div id="userModalContent">
                <!-- Content will be loaded here -->
                <?php if ($user_details): ?>
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['username']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['email']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['phone'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Balance</div>
                        <div class="info-value">$<?php echo number_format($user_details['balance'], 2); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status status-<?php echo $user_details['status']; ?>">
                                <?php echo ucfirst($user_details['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Joined Date</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user_details['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_details['referral_code'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?php echo $user_details['last_login'] ? date('M j, Y g:i A', strtotime($user_details['last_login'])) : 'Never'; ?></div>
                    </div>
                </div>
                
                <div class="tabs">
                    <button class="tab active" data-tab="bets">Bet History (<?php echo $user_bets['total']; ?>)</button>
                    <button class="tab" data-tab="transactions">Transaction History (<?php echo $user_transactions['total']; ?>)</button>
                </div>
                
                <div class="tab-content active" id="bets-tab">
                    <?php displayBetsTab($user_bets, $bets_page, $per_page); ?>
                </div>
                
                <div class="tab-content" id="transactions-tab">
                    <?php displayTransactionsTab($user_transactions, $transactions_page, $per_page); ?>
                </div>
                
                <div style="margin-top: 2rem; text-align: center;">
                    <?php if ($user_details['status'] === 'active'): ?>
                        <a href="users.php?ban_user=<?php echo $user_details['id']; ?>" class="btn btn-warning">
                            <i class="fas fa-ban"></i> Ban User
                        </a>
                    <?php else: ?>
                        <a href="users.php?unban_user=<?php echo $user_details['id']; ?>" class="btn btn-success">
                            <i class="fas fa-check"></i> Unban User
                        </a>
                    <?php endif; ?>
                    <a href="users.php?delete_user=<?php echo $user_details['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete User
                    </a>
                    <button class="btn btn-secondary" id="closeModalBtn">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay confirmation-modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close" id="closeDeleteModal">&times;</button>
            </div>
            <div id="deleteModalContent">
                <p>Are you sure you want to delete user <strong id="deleteUsername"></strong>? This action cannot be undone.</p>
                <div class="confirmation-buttons">
                    <button class="btn btn-secondary" id="cancelDelete">Cancel</button>
                    <a href="#" class="btn btn-danger" id="confirmDelete">Delete User</a>
                </div>
            </div>
        </div>
    </div>

<!-- SweetAlert2 latest from jsDelivr -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<script>
    // Mobile menu functionality (same as before)
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

    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
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
    }

    handleResize();
    window.addEventListener('resize', handleResize);

    // Global variables
    let currentUserId = null;

    // Modal elements
    const userModal = document.getElementById('userModal');
    const closeUserModal = document.getElementById('closeUserModal');
    const userModalContent = document.getElementById('userModalContent');
    const deleteModal = document.getElementById('deleteModal');
    const closeDeleteModal = document.getElementById('closeDeleteModal');
    const cancelDelete = document.getElementById('cancelDelete');
    const deleteUsername = document.getElementById('deleteUsername');
    const confirmDelete = document.getElementById('confirmDelete');

    // View user buttons
    // document.querySelectorAll('.view-user').forEach(button => {
    //     button.addEventListener('click', function() {
    //         const userId = this.getAttribute('data-user-id');
    //         currentUserId = userId;
    //         window.location.href = `users.php?view_user=${userId}`;
    //     });
    // });

    // Close modal handlers
    closeUserModal.addEventListener('click', () => {
        userModal.classList.remove('active');
        currentUserId = null;
    });

    closeDeleteModal.addEventListener('click', () => deleteModal.classList.remove('active'));
    cancelDelete.addEventListener('click', () => deleteModal.classList.remove('active'));

    [userModal, deleteModal].forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                if (modal === userModal) {
                    currentUserId = null;
                }
            }
        });
    });

    // Delete user buttons
    document.querySelectorAll('.delete-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            const username = this.getAttribute('data-username');
            
            deleteUsername.textContent = username;
            confirmDelete.href = `users.php?delete_user=${userId}`;
            deleteModal.classList.add('active');
        });
    });

    // Function to handle pagination clicks
    function handlePaginationClick(event, tabType) {
        event.preventDefault();
        
        const button = event.target;
        const page = button.getAttribute('data-page');
        
        if (!page || !currentUserId) return;
        
        // Show loading state
        const tabContent = document.getElementById(`${tabType}-tab`);
        const oldContent = tabContent.innerHTML;
        tabContent.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <div class="loading"></div>
                <p>Loading ${tabType}...</p>
            </div>
        `;
        
        // Load the page via AJAX
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `users.php?view_user=${currentUserId}&${tabType}_page=${page}&ajax=1`, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                tabContent.innerHTML = xhr.responseText;
                // Re-attach event listeners to the new pagination buttons
                attachPaginationListeners(tabType);
            } else {
                tabContent.innerHTML = oldContent;
                alert('Error loading page content');
            }
        };
        xhr.onerror = function() {
            tabContent.innerHTML = oldContent;
            alert('Error loading page content');
        };
        xhr.send();
    }

    // Function to attach event listeners to pagination buttons
    function attachPaginationListeners(tabType) {
        const paginationContainer = document.querySelector(`#${tabType}-tab .pagination`);
        if (paginationContainer) {
            const buttons = paginationContainer.querySelectorAll('button[data-page]');
            buttons.forEach(button => {
                // Remove existing event listeners and add new ones
                button.replaceWith(button.cloneNode(true));
            });
            
            // Re-select the new buttons and attach listeners
            const newButtons = paginationContainer.querySelectorAll('button[data-page]');
            newButtons.forEach(button => {
                button.addEventListener('click', (e) => handlePaginationClick(e, tabType));
            });
        }
    }

    // Tab switching functionality
    function initializeTabs() {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Update active tab
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Update active content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${targetTab}-tab`).classList.add('active');
            });
        });
    }

    // Close modal button
    function initializeCloseButton() {
        const closeModalBtn = document.getElementById('closeModalBtn');
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => {
                userModal.classList.remove('active');
                window.history.replaceState({}, document.title, 'users.php');
                currentUserId = null;
            });
        }
    }

    // Initialize everything when modal is shown
    <?php if ($user_details): ?>
        currentUserId = <?php echo $user_details['id']; ?>;
        
        // Show modal on page load if user details are present
        window.addEventListener('load', function() {
            userModal.classList.add('active');
            
            // Initialize tabs and pagination
            setTimeout(() => {
                initializeTabs();
                initializeCloseButton();
                attachPaginationListeners('bets');
                attachPaginationListeners('transactions');
            }, 100);
        });
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        setTimeout(() => {
            Swal.fire({'title': "Success"  ,
                'text':'<?php echo $_SESSION['success_message']; ?>',
                'icon':'success',
                'confirmButtonText':'OK'});
        }, 100);
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        setTimeout(() => {
            Swal.fire({'title': "Error"  ,
                'text':'<?php echo $_SESSION['error_message']; ?>',
                'icon':'error',
                'confirmButtonText':'OK'});
        }, 100);
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
</script>


</body>
</html>




