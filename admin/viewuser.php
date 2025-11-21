<?php
session_start();
require_once '../config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit;
}

$user_id = $_GET['id'] ?? 0;

// Fetch user details
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

if (!$user) {
    header('Location: users.php');
    exit();
}

// Get admin details for referral code
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];
$admin_stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$referral_result = $admin_stmt->get_result();
$referral_code = $referral_result->fetch_assoc();

// Pagination variables
$per_page = 10;
$bets_page = isset($_GET['bets_page']) ? max(1, intval($_GET['bets_page'])) : 1;
$deposits_page = isset($_GET['deposits_page']) ? max(1, intval($_GET['deposits_page'])) : 1;
$withdrawals_page = isset($_GET['withdrawals_page']) ? max(1, intval($_GET['withdrawals_page'])) : 1;
$transactions_page = isset($_GET['transactions_page']) ? max(1, intval($_GET['transactions_page'])) : 1;

// Get active tab from URL or default to bets
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bets';

// Calculate total deposits
$deposit_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_deposits FROM deposits WHERE user_id = ? AND status = 'approved'");
$deposit_stmt->bind_param("i", $user_id);
$deposit_stmt->execute();
$deposit_result = $deposit_stmt->get_result();
$total_deposits = $deposit_result->fetch_assoc()['total_deposits'];

// Calculate total withdrawals
$withdrawal_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_withdrawals FROM withdrawals WHERE user_id = ? AND status = 'completed'");
$withdrawal_stmt->bind_param("i", $user_id);
$withdrawal_stmt->execute();
$withdrawal_result = $withdrawal_stmt->get_result();
$total_withdrawals = $withdrawal_result->fetch_assoc()['total_withdrawals'];

// Calculate total bets
$bet_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_bets FROM bets WHERE user_id = ?");
$bet_stmt->bind_param("i", $user_id);
$bet_stmt->execute();
$bet_result = $bet_stmt->get_result();
$total_bets = $bet_result->fetch_assoc()['total_bets'];

// Fetch bets with pagination
function getUserBetsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT b.*, g.name as game_name, gt.name as game_type_name 
            FROM bets b 
            LEFT JOIN games g ON b.game_session_id = g.id 
            LEFT JOIN game_types gt ON b.game_type_id = gt.id 
            WHERE b.user_id = ? 
            ORDER BY b.placed_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bets = [];
    while ($row = $result->fetch_assoc()) {
        $bets[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM bets WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $bets,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch deposits with pagination
function getUserDepositsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM deposits 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $deposits = [];
    while ($row = $result->fetch_assoc()) {
        $deposits[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM deposits WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $deposits,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch withdrawals with pagination
function getUserWithdrawalsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM withdrawals 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $withdrawals = [];
    while ($row = $result->fetch_assoc()) {
        $withdrawals[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM withdrawals WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $withdrawals,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Fetch transactions with pagination
function getUserTransactionsPaginated($conn, $user_id, $page = 1, $per_page = 10) {
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT * FROM transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $user_id, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM transactions WHERE user_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param('i', $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    
    return [
        'data' => $transactions,
        'total' => $total,
        'total_pages' => ceil($total / $per_page),
        'current_page' => $page
    ];
}

// Get paginated data
$user_bets = getUserBetsPaginated($conn, $user_id, $bets_page, $per_page);
$user_deposits = getUserDepositsPaginated($conn, $user_id, $deposits_page, $per_page);
$user_withdrawals = getUserWithdrawalsPaginated($conn, $user_id, $withdrawals_page, $per_page);
$user_transactions = getUserTransactionsPaginated($conn, $user_id, $transactions_page, $per_page);

// Handle balance update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_balance'])) {
    $new_balance = $_POST['new_balance'];
    $reason = sanitize_input($conn, $_POST['reason']);


    $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'Balance Update Request'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {

        $update_stmt = $conn->prepare("UPDATE `admin_requests` SET  `amount` = ? WHERE user_id = ? AND status = 'pending' AND title = 'Balance Update Request'");
        $update_stmt->bind_param("di", $new_balance, $user_id   );
        $update_stmt->execute();


        $_SESSION['success_message'] = "Request to update user balance updated successfully!";
        header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
        exit();
    }


    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $previous_amount = $user['balance'];



    
    $title = 'Balance Update Request';
    $description = "Admin $admin_username requested a balance update for user {$user['username']} from $previous_amount to $new_balance (".($new_balance - $previous_amount)." Rs). Reason: $reason";  
    // Update user balance
    $update_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `amount`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,?,'pending',NOW())");
    $update_stmt->bind_param("iidss", $admin_id, $user_id, $new_balance, $title, $description);
    $update_stmt->execute();
    
    // Log the transaction
    // $transaction_stmt = $conn->prepare("
    //     INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) 
    //     VALUES (?, 'adjustment', ?, ?, ?, ?, 'completed')
    // ");
    // $amount_diff = $new_balance - $user['balance'];
    // $transaction_stmt->bind_param("iddds", $user_id, $amount_diff, $user['balance'], $new_balance, $reason);
    // $transaction_stmt->execute();
    
    $_SESSION['success_message'] = " Request to update user balance submitted successfully!";
    header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
    exit();
}

// Handle user status change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_status'])) {
    $new_status = sanitize_input($conn, $_POST['status']);
    
    $status_stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $status_stmt->bind_param("si", $new_status, $user_id);
    $status_stmt->execute();
    
    $_SESSION['success_message'] = "User status updated successfully!";
    header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
    exit();
}

// Handle delete user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {

    $stmt = $conn->prepare("SELECT * FROM admin_requests wHERE user_id = ? AND status = 'pending' AND title = 'User Deletion'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $_SESSION['error_message'] = "There is already a pending user deletion request for this user.";
        header("Location: viewuser.php?id=" . $user_id . "&tab=" . $active_tab);
        exit();
    }

    $title = "User Deletion";
    $description = "User account deleted by admin: " . $admin_username;
    $delete_stmt = $conn->prepare("INSERT INTO `admin_requests`( `admin_id`, `user_id`, `title`, `description`, `status`, `created_at`) VALUES (?,?,?,?,'pending',NOW())");
    $delete_stmt->bind_param("iiss", $admin_id, $user_id, $title, $description);
    $delete_stmt->execute();
    
    $_SESSION['success_message'] = "Request to delete user submitted successfully!";
    header("Location: users.php");
    exit();
}

// Function to generate pagination HTML with active tab preservation
function generatePagination($total_pages, $current_page, $page_param, $user_id, $active_tab) {
    if ($total_pages <= 1) return '';
    
    $pagination = '<div class="pagination">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $prev_page . '" class="pagination-btn">« Previous</a>';
    } else {
        $pagination .= '<span class="pagination-btn disabled">« Previous</span>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=1" class="pagination-btn">1</a>';
        if ($start_page > 2) {
            $pagination .= '<span class="pagination-ellipsis">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="pagination-btn active">' . $i . '</span>';
        } else {
            $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $i . '" class="pagination-btn">' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $pagination .= '<span class="pagination-ellipsis">...</span>';
        }
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $total_pages . '" class="pagination-btn">' . $total_pages . '</a>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        $pagination .= '<a href="viewuser.php?id=' . $user_id . '&tab=' . $active_tab . '&' . $page_param . '=' . $next_page . '" class="pagination-btn">Next »</a>';
    } else {
        $pagination .= '<span class="pagination-btn disabled">Next »</span>';
    }
    
    $pagination .= '</div>';
    
    return $pagination;
}

// Function to generate tab URL with current parameters
function generateTabUrl($tab, $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) {
    $url = "viewuser.php?id=" . $user_id . "&tab=" . $tab;
    
    // Preserve the current page for each tab type
    switch($tab) {
        case 'bets':
            $url .= "&bets_page=" . $bets_page;
            break;
        case 'deposits':
            $url .= "&deposits_page=" . $deposits_page;
            break;
        case 'withdrawals':
            $url .= "&withdrawals_page=" . $withdrawals_page;
            break;
        case 'transactions':
            $url .= "&transactions_page=" . $transactions_page;
            break;
    }
    
    return $url;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - <?= htmlspecialchars($user['username']) ?> | RB Games Admin</title>
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
            left: 0;
            top: 0;
            overflow-x: scroll;
        }
        .sidebar::-webkit-scrollbar{
            display:none;
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
            margin-left: 260px;
            width: calc(100% - 260px);
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

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-light);
            margin: 0;
        }

        /* Financial Summary */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .summary-card.balance { background: linear-gradient(135deg, var(--primary), #ff6b9c); }
        .summary-card.deposits { background: linear-gradient(135deg, var(--success), #00d8a7); }
        .summary-card.withdrawals { background: linear-gradient(135deg, var(--warning), #fed67c); }
        .summary-card.bets { background: linear-gradient(135deg, var(--secondary), #1fd1eb); }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .summary-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.9);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
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

        /* Buttons */
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
            text-decoration: none;
            font-size: 0.9rem;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
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

        .status-pending {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .status-completed {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-cancelled, .status-rejected {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
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

        /* User Info Grid */
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
                left: -260px;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
            .header {
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
            .financial-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            .tabs {
                overflow-x: auto;
            }
            .action-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .financial-summary {
                grid-template-columns: 1fr;
            }
            .user-info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Success Message */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border-color: rgba(0, 184, 148, 0.3);
        }
        
        .alert-danger {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border-color: rgba(214, 48, 49, 0.3);
        }

        .tbody {
            position: relative;
            overflow-x: auto;
        }
        .tbody::-webkit-scrollbar {
            display: none;
        }
    </style>
    
    <style>
        /* ... (all previous CSS remains exactly the same) ... */
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 1.5rem;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        
        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s ease;
            background: var(--card-bg);
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-block;
        }
        
        .pagination-btn:hover {
            background: rgba(255, 60, 126, 0.2);
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        
        .pagination-btn.active {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border-color: var(--primary);
        }
        
        .pagination-btn.disabled {
            color: var(--text-muted);
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.05);
            opacity: 0.6;
        }
        
        .pagination-btn.disabled:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
            transform: none;
        }
        
        .pagination-ellipsis {
            padding: 0.5rem;
            color: var(--text-muted);
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .tab-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
        }
        
        .total-count {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--primary);
            border: 1px solid rgba(255, 60, 126, 0.3);
        }
        
        /* Tab links styling */
        .tab-link {
            text-decoration: none;
            color: inherit;
            display: block;
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        
        .tab-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-link:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
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
                
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="all_users_history.php" class="menu-item">
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
                <a href="admin_deposits.php" class="menu-item">
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
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>User Details: <?= htmlspecialchars($user['username']) ?></h1>
                    <p>Manage user account, view transactions, and perform actions</p>
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

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?= $_SESSION['success_message'] ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?= $_SESSION['error_message'] ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Financial Summary -->
            <div class="financial-summary">
                <div class="summary-card balance">
                    <div class="summary-value">₹<?= number_format($user['balance'], 2) ?></div>
                    <div class="summary-label">Current Balance</div>
                </div>
                <div class="summary-card deposits">
                    <div class="summary-value">₹<?= number_format($total_deposits, 2) ?></div>
                    <div class="summary-label">Total Deposits</div>
                </div>
                <div class="summary-card withdrawals">
                    <div class="summary-value">₹<?= number_format($total_withdrawals, 2) ?></div>
                    <div class="summary-label">Total Withdrawals</div>
                </div>
                <div class="summary-card bets">
                    <div class="summary-value">₹<?= number_format($total_bets, 2) ?></div>
                    <div class="summary-label">Total Bets</div>
                </div>
            </div>

            <!-- User Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Information</h3>
                </div>
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">User ID</div>
                        <div class="info-value"><?= $user['id'] ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($user['username']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status status-<?= $user['status'] ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?= htmlspecialchars($user['referral_code'] ?? 'N/A') ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Registered</div>
                        <div class="info-value"><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?></div>
                    </div>
                </div>
            </div>

            <!-- Balance Update Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Update Balance</h3>
                </div>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label class="form-label">Current Balance</label>
                            <input type="text" class="form-control" value="₹<?= number_format($user['balance'], 2) ?>" readonly>
                        </div>
                        <?php if($referral_code['status'] == 'suspend'):?>
                            
                        <?php else:?>
                                
                            <div class="form-group">
                                <label class="form-label">New Balance</label>
                                <input type="number" step="0.01" class="form-control" name="new_balance" value="<?= $user['balance'] ?>" 
                                <?php 
                                if($referral_code['status'] == 'suspend'){ 
                                    echo 'readonly'; 
                                }?> required >
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reason</label>
                                <input type="text" class="form-control" name="reason" placeholder="Reason for balance adjustment" required>
                            </div>
                            <div class="form-group">
                                <button type="submit" name="update_balance" class="btn btn-warning">
                                    <i class="fas fa-sync-alt"></i>Request to Update Balance
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- User Management Actions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Management</h3>
                </div>
                <div class="action-buttons">
                    <?php if($referral_code['status'] == 'suspend'):?>
                            
                    <?php else:?>
                    <form method="POST" style="display: inline;">
                        <select name="status" class="form-control" style="width: auto; display: inline-block; margin-right: 1rem; background-color: var(--dark); color: var(--text-light);">
                            <option value="active" <?= $user['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="suspended" <?= $user['status'] == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="banned" <?= $user['status'] == 'banned' ? 'selected' : '' ?>>Banned</option>
                        </select>
                        <button type="submit" name="change_status" class="btn btn-primary">
                            <i class="fas fa-user-cog"></i> Change Status
                        </button>
                    </form>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');" style="display: inline;">
                        <button type="submit" name="delete_user" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete User
                        </button>
                    </form>

                    <?php endif; ?>
                    
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                </div>
            </div>

            <!-- Tabs for Detailed Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Activity</h3>
                </div>
                
                <div class="tabs">
                    <a href="<?= generateTabUrl('bets', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'bets' ? 'active' : '' ?>" 
                       data-tab="bets">Bet History</a>
                    <a href="<?= generateTabUrl('deposits', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'deposits' ? 'active' : '' ?>" 
                       data-tab="deposits">Deposit History</a>
                    <a href="<?= generateTabUrl('withdrawals', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'withdrawals' ? 'active' : '' ?>" 
                       data-tab="withdrawals">Withdrawal History</a>
                    <a href="<?= generateTabUrl('transactions', $user_id, $active_tab, $bets_page, $deposits_page, $withdrawals_page, $transactions_page) ?>" 
                       class="tab-link <?= $active_tab == 'transactions' ? 'active' : '' ?>" 
                       data-tab="transactions">All Transactions</a>
                </div>
                
                <!-- Bets Tab -->
                <div class="tab-content <?= $active_tab == 'bets' ? 'active' : '' ?>" id="bets-tab">
                    <div class="tab-header">
                        <div class="tab-title">Bet History</div>
                        <div class="total-count">Total: <?= $user_bets['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Game</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Status</th>
                                    <th>Placed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_bets['data'] as $bet): ?>
                                <tr>
                                    <td><?= $bet['id'] ?></td>
                                    <td><?= htmlspecialchars($bet['game_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($bet['game_type_name'] ?? 'N/A') ?></td>
                                    <td>₹<?= number_format($bet['amount'], 2) ?></td>
                                    <td>₹<?= number_format($bet['potential_win'], 2) ?></td>
                                    <td>
                                        <span class="status status-<?= $bet['status'] ?>">
                                            <?= ucfirst($bet['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($bet['placed_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_bets['data'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No bets found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_bets['total_pages'], $user_bets['current_page'], 'bets_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_bets['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_bets['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_bets['current_page'] * $per_page, $user_bets['total']) ?> of <?= $user_bets['total'] ?> bets
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Deposits Tab -->
                <div class="tab-content <?= $active_tab == 'deposits' ? 'active' : '' ?>" id="deposits-tab">
                    <div class="tab-header">
                        <div class="tab-title">Deposit History</div>
                        <div class="total-count">Total: <?= $user_deposits['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>UTR Number</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_deposits['data'] as $deposit): ?>
                                <tr>
                                    <td><?= $deposit['id'] ?></td>
                                    <td>₹<?= number_format($deposit['amount'], 2) ?></td>
                                    <td><?= ucfirst($deposit['payment_method']) ?></td>
                                    <td><?= htmlspecialchars($deposit['utr_number']) ?></td>
                                    <td>
                                        <span class="status status-<?= $deposit['status'] ?>">
                                            <?= ucfirst($deposit['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($deposit['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_deposits['data'])): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No deposits found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_deposits['total_pages'], $user_deposits['current_page'], 'deposits_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_deposits['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_deposits['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_deposits['current_page'] * $per_page, $user_deposits['total']) ?> of <?= $user_deposits['total'] ?> deposits
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Withdrawals Tab -->
                <div class="tab-content <?= $active_tab == 'withdrawals' ? 'active' : '' ?>" id="withdrawals-tab">
                    <div class="tab-header">
                        <div class="tab-title">Withdrawal History</div>
                        <div class="total-count">Total: <?= $user_withdrawals['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_withdrawals['data'] as $withdrawal): ?>
                                <tr>
                                    <td><?= $withdrawal['id'] ?></td>
                                    <td>₹<?= number_format($withdrawal['amount'], 2) ?></td>
                                    <td><?= ucfirst(str_replace('_', ' ', $withdrawal['payment_method'])) ?></td>
                                    <td>
                                        <span class="status status-<?= $withdrawal['status'] ?>">
                                            <?= ucfirst($withdrawal['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_withdrawals['data'])): ?>
                                <tr>
                                    <td colspan="5" class="text-center">No withdrawals found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_withdrawals['total_pages'], $user_withdrawals['current_page'], 'withdrawals_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_withdrawals['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_withdrawals['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_withdrawals['current_page'] * $per_page, $user_withdrawals['total']) ?> of <?= $user_withdrawals['total'] ?> withdrawals
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Transactions Tab -->
                <div class="tab-content <?= $active_tab == 'transactions' ? 'active' : '' ?>" id="transactions-tab">
                    <div class="tab-header">
                        <div class="tab-title">All Transactions</div>
                        <div class="total-count">Total: <?= $user_transactions['total'] ?></div>
                    </div>
                    <div class="tbody">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance Before</th>
                                    <th>Balance After</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_transactions['data'] as $transaction): ?>
                                <tr>
                                    <td><?= $transaction['id'] ?></td>
                                    <td>
                                        <span class="status status-<?= $transaction['type'] === 'deposit' ? 'active' : 'pending' ?>">
                                            <?= ucfirst($transaction['type']) ?>
                                        </span>
                                    </td>
                                    <td>₹<?= number_format($transaction['amount'], 2) ?></td>
                                    <td>₹<?= number_format($transaction['balance_before'], 2) ?></td>
                                    <td>₹<?= number_format($transaction['balance_after'], 2) ?></td>
                                    <td>
                                        <span class="status status-<?= $transaction['status'] ?>">
                                            <?= ucfirst($transaction['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y g:i A', strtotime($transaction['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($user_transactions['data'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No transactions found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?= generatePagination($user_transactions['total_pages'], $user_transactions['current_page'], 'transactions_page', $user_id, $active_tab) ?>
                    
                    <?php if ($user_transactions['total'] > 0): ?>
                    <div class="pagination-info">
                        Showing <?= (($user_transactions['current_page'] - 1) * $per_page) + 1 ?> to <?= min($user_transactions['current_page'] * $per_page, $user_transactions['total']) ?> of <?= $user_transactions['total'] ?> transactions
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // The page now uses server-side tab switching, so no JavaScript is needed
        // The tabs are now actual links that preserve the active tab state
        
        // Confirm before delete
        const deleteForms = document.querySelectorAll('form[onsubmit]');
        deleteForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>

</body>
</html>