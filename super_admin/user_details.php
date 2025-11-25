<?php
// user_details.php
require_once '../config.php';

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_login.php");
    exit;
}

// Get user ID from URL
if (!isset($_GET['user_id'])) {
    die("User ID is required");
}

$user_id = intval($_GET['user_id']);
$_SESSION['viewing_user_id'] = $user_id; // Store in session

// Get super admin details
$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

// Get user details with comprehensive data
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
    die("User not found");
}

$user = $result->fetch_assoc();

// Calculate additional metrics
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

// Calculate account age
$account_age = floor((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
$user['account_age'] = $account_age;

// Calculate net profit/loss
$user['net_profit_loss'] = ($user['total_winnings'] ?: 0) - ($user['total_bets_amount'] ?: 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Load jQuery from CDN with fallback -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Fallback if jQuery fails to load
        window.jQuery || document.write('<script src="../assets/js/jquery-3.6.0.min.js"><\/script>');
    </script>

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
        /* Use the same styles as your main admin panel */
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
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .welcome h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
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

        .back-btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.6rem 1.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.8rem 1.5rem;
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
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .tab-content.active {
            display: block;
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.2rem;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 1rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th, .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        .loading-content {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: var(--text-muted);
        }

         /* Additional styles for filters and pagination */
        .filter-section {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .page-link {
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover, .page-link.active {
            background: var(--primary);
            color: white;
        }
        
        .page-info {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .reset-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome">
                <h1>User Details</h1>
                <p>Viewing details for: <?php echo htmlspecialchars($user['username']); ?> (ID: <?php echo $user['id']; ?>)</p>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Super Admin: <?php echo htmlspecialchars($super_admin_username); ?></span>
                </div>
                <a href="super_admin_all_users.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('basicInfo')">Basic Info</button>
            <button class="tab" onclick="switchTab('financialInfo')">Financial Info</button>
            <button class="tab" onclick="switchTab('activityInfo')">Activity</button>
            <button class="tab" onclick="switchTab('bettingHistory')">Betting History</button>
            <button class="tab" onclick="switchTab('transactions')">Transactions</button>
        </div>

        <!-- Basic Information Tab -->
        <div id="basicInfo" class="tab-content active">
            <form method="POST" action="update_user_info.php">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">User ID</div>
                        <div class="info-value"><?php echo $user['id']; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <select name="status" class="form-control">
                            <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="suspended" <?php echo $user['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="banned" <?php echo $user['status'] == 'banned' ? 'selected' : ''; ?>>Banned</option>
                        </select>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Registration Date</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['referral_code']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referred By Admin</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['admin_username']); ?></div>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem; text-align: right;">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Financial Information Tab -->
        <div id="financialInfo" class="tab-content">
            <div class="user-info-grid">
                <div class="info-card">
                    <div class="info-label">Current Balance</div>
                    <div class="info-value">₹<?php echo number_format($user['balance'], 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Deposits</div>
                    <div class="info-value">₹<?php echo number_format($user['total_deposits'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Withdrawals</div>
                    <div class="info-value">₹<?php echo number_format($user['total_withdrawals'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Bets</div>
                    <div class="info-value"><?php echo number_format($user['total_bets'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Winnings</div>
                    <div class="info-value">₹<?php echo number_format($user['total_winnings'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Net Profit/Loss</div>
                    <div class="info-value" style="color: <?php echo $user['net_profit_loss'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                        ₹<?php echo number_format($user['net_profit_loss'], 2); ?>
                    </div>
                </div>
            </div>

            <!-- Quick Balance Adjustment -->
            <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(255,255,255,0.05); border-radius: 8px;">
                <h4 style="margin-bottom: 1rem;">Quick Balance Adjustment</h4>
                <form method="POST" action="adjust_user_balance.php">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                        <div>
                            <label class="info-label">Action</label>
                            <select name="adjustment_type" class="form-control" required>
                                <option value="add">Add Balance</option>
                                <option value="subtract">Subtract Balance</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Amount (₹)</label>
                            <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div>
                            <label class="info-label">Reason</label>
                            <input type="text" name="reason" class="form-control" placeholder="Adjustment reason" required>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="submit" class="btn btn-primary">Apply Adjustment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Activity Information Tab -->
        <div id="activityInfo" class="tab-content">
            <div class="user-info-grid">
                <div class="info-card">
                    <div class="info-label">Total Games Played</div>
                    <div class="info-value"><?php echo number_format($user['total_games'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Winning Rate</div>
                    <div class="info-value"><?php echo $user['winning_rate']; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Favorite Game</div>
                    <div class="info-value"><?php echo htmlspecialchars($user['favorite_game']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Last Bet Date</div>
                    <div class="info-value"><?php echo $user['last_bet_date'] ? date('M j, Y g:i A', strtotime($user['last_bet_date'])) : '-'; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Active Sessions</div>
                    <div class="info-value"><?php echo number_format($user['active_sessions'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Account Age</div>
                    <div class="info-value"><?php echo $user['account_age']; ?> days</div>
                </div>
            </div>
        </div>

         <!-- Betting History Tab -->
        <div id="bettingHistory" class="tab-content">
            <!-- Betting History Filters -->
            <div class="filter-section">
                <h4 style="margin-bottom: 1rem;">Filter Betting History</h4>
                <form id="bettingFilterForm" onsubmit="return loadBettingData(1)">
                    <div class="filter-grid">
                        <div>
                            <label class="info-label">Game Name</label>
                            <input type="text" name="betting_game" id="betting_game" class="form-control" placeholder="Filter by game name">
                        </div>
                        <div>
                            <label class="info-label">Status</label>
                            <select name="betting_status" id="betting_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="won">Won</option>
                                <option value="lost">Lost</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Date From</label>
                            <input type="date" name="betting_date_from" id="betting_date_from" class="form-control">
                        </div>
                        <div>
                            <label class="info-label">Date To</label>
                            <input type="date" name="betting_date_to" id="betting_date_to" class="form-control">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" onclick="resetBettingFilters()" class="reset-btn">
                            <i class="fas fa-times"></i> Reset Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Betting History Content (AJAX loaded) -->
            <div id="bettingContent">
                <div class="loading-content">
                    <div class="loading"></div>
                    <span style="margin-left: 1rem;">Loading betting history...</span>
                </div>
            </div>
        </div>

        <!-- Transactions Tab -->
        <div id="transactions" class="tab-content">
            <!-- Transactions Filters -->
            <div class="filter-section">
                <h4 style="margin-bottom: 1rem;">Filter Transactions</h4>
                <form id="transactionFilterForm" onsubmit="return loadTransactionData(1)">
                    <div class="filter-grid">
                        <div>
                            <label class="info-label">Transaction Type</label>
                            <select name="trans_type" id="trans_type" class="form-control">
                                <option value="">All Types</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                                <option value="winning">Winning</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Status</label>
                            <select name="trans_status" id="trans_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                                <option value="failed">Failed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Date From</label>
                            <input type="date" name="trans_date_from" id="trans_date_from" class="form-control">
                        </div>
                        <div>
                            <label class="info-label">Date To</label>
                            <input type="date" name="trans_date_to" id="trans_date_to" class="form-control">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" onclick="resetTransactionFilters()" class="reset-btn">
                            <i class="fas fa-times"></i> Reset Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Transactions Content (AJAX loaded) -->
            <div id="transactionContent">
                <div class="loading-content">
                    <div class="loading"></div>
                    <span style="margin-left: 1rem;">Loading transactions...</span>
                </div>
            </div>
        </div>
    </div>


 <script>
    // Global variables
    const userId = <?php echo $user_id; ?>;
    let currentBettingPage = 1;
    let currentTransactionPage = 1;
    let bettingLimit = 20;
    let transactionLimit = 20;

    // Tab switching function
    function switchTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content and activate tab
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
        
        // Load data for betting and transaction tabs when they are activated
        if (tabName === 'bettingHistory') {
            loadBettingData(currentBettingPage);
        } else if (tabName === 'transactions') {
            loadTransactionData(currentTransactionPage);
        }
    }

    // Load betting data via AJAX
    function loadBettingData(page = 1) {
        currentBettingPage = page;
        
        const formData = {
            user_id: userId,
            page: page,
            limit: bettingLimit,
            betting_game: document.getElementById('betting_game').value,
            betting_status: document.getElementById('betting_status').value,
            betting_date_from: document.getElementById('betting_date_from').value,
            betting_date_to: document.getElementById('betting_date_to').value
        };

        // Show loading indicator
        const bettingContent = document.getElementById('bettingContent');
        bettingContent.innerHTML = `
            <div class="loading-overlay">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
        `;

        // Create URL with parameters
        const params = new URLSearchParams(formData);
        
        // Use fetch API instead of jQuery
        fetch('ajax_get_betting_history.php?' + params.toString())
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                bettingContent.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                bettingContent.innerHTML = `
                    <div style="text-align: center; color: var(--danger); padding: 2rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading betting history. Please try again.</p>
                    </div>
                `;
            });

        return false; // Prevent form submission
    }

    // Load transaction data via AJAX
    function loadTransactionData(page = 1) {
        currentTransactionPage = page;
        
        const formData = {
            user_id: userId,
            page: page,
            limit: transactionLimit,
            trans_type: document.getElementById('trans_type').value,
            trans_status: document.getElementById('trans_status').value,
            trans_date_from: document.getElementById('trans_date_from').value,
            trans_date_to: document.getElementById('trans_date_to').value
        };

        // Show loading indicator
        const transactionContent = document.getElementById('transactionContent');
        transactionContent.innerHTML = `
            <div class="loading-overlay">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
        `;

        // Create URL with parameters
        const params = new URLSearchParams(formData);
        
        // Use fetch API instead of jQuery
        fetch('ajax_get_transactions.php?' + params.toString())
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                transactionContent.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                transactionContent.innerHTML = `
                    <div style="text-align: center; color: var(--danger); padding: 2rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading transactions. Please try again.</p>
                    </div>
                `;
            });

        return false; // Prevent form submission
    }

    // Reset betting filters
    function resetBettingFilters() {
        document.getElementById('betting_game').value = '';
        document.getElementById('betting_status').value = '';
        document.getElementById('betting_date_from').value = '';
        document.getElementById('betting_date_to').value = '';
        loadBettingData(1);
    }

    // Reset transaction filters
    function resetTransactionFilters() {
        document.getElementById('trans_type').value = '';
        document.getElementById('trans_status').value = '';
        document.getElementById('trans_date_from').value = '';
        document.getElementById('trans_date_to').value = '';
        loadTransactionData(1);
    }

    // Change limit for betting
    function changeBettingLimit(limit) {
        bettingLimit = parseInt(limit);
        loadBettingData(1);
    }

    // Change limit for transactions
    function changeTransactionLimit(limit) {
        transactionLimit = parseInt(limit);
        loadTransactionData(1);
    }

    // Show success message if present in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success')) {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: urlParams.get('message') || 'Operation completed successfully',
            timer: 3000
        });
    }

    if (urlParams.get('error')) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: urlParams.get('message') || 'An error occurred',
            timer: 5000
        });
    }

    // Load initial data when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Load betting history if that tab is active
        if (document.getElementById('bettingHistory').classList.contains('active')) {
            loadBettingData(1);
        }
        
        // Load transactions if that tab is active
        if (document.getElementById('transactions').classList.contains('active')) {
            loadTransactionData(1);
        }
    });
</script>
</body>
</html>