<?php
require_once 'config.php';

// Start session (if not already started)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("location: login.php");
    exit;
}

// Check if user is logged in
$is_logged_in = false;
$user_balance = 0;
$username = '';

if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    
    // Fetch user balance and username from database
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT username, balance FROM users WHERE id = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($username, $user_balance);
        $stmt->fetch();
        $stmt->close();
    }
}

// Pagination configuration
$transactions_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $transactions_per_page;

// Fetch total transactions count
$total_transactions = 0;

// Count deposits
$count_deposits_sql = "SELECT COUNT(*) FROM deposits WHERE user_id = ?";
if ($stmt = $conn->prepare($count_deposits_sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($deposit_count);
    $stmt->fetch();
    $stmt->close();
    $total_transactions += $deposit_count;
}

// Count withdrawals
$count_withdrawals_sql = "SELECT COUNT(*) FROM withdrawals WHERE user_id = ?";
if ($stmt = $conn->prepare($count_withdrawals_sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($withdrawal_count);
    $stmt->fetch();
    $stmt->close();
    $total_transactions += $withdrawal_count;
}

// Calculate total pages
$total_pages = ceil($total_transactions / $transactions_per_page);

// Fetch transactions data with pagination
$transactions = [];

// Fetch deposits with pagination
$deposit_sql = "SELECT 
    id, 
    amount, 
    payment_method, 
    utr_number, 
    status, 
    created_at,
    'deposit' as type 
    FROM deposits 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?";

// Fetch withdrawals with pagination  
$withdrawal_sql = "SELECT 
    id, 
    amount, 
    payment_method, 
    '' as utr_number, 
    status, 
    created_at,
    'withdrawal' as type 
    FROM withdrawals 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?";

// Since we can't easily paginate across two tables, we'll fetch all and paginate in PHP
// For better performance with large datasets, consider using UNION or temporary tables
$all_deposits = [];
if ($stmt = $conn->prepare("SELECT id, amount, payment_method, utr_number, status, created_at, 'deposit' as type, admin_notes FROM deposits WHERE user_id = ? ORDER BY created_at DESC")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_deposits[] = $row;
    }
    $stmt->close();
}

// Fetch withdrawals
$all_withdrawals = [];
if ($stmt = $conn->prepare("SELECT id, amount, payment_method, '' as utr_number, status, created_at, 'withdrawal' as type, admin_notes FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC")) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_withdrawals[] = $row;
    }
    $stmt->close();
}

// Combine and sort all transactions
$all_transactions = array_merge($all_deposits, $all_withdrawals);
usort($all_transactions, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Apply pagination
$transactions = array_slice($all_transactions, $offset, $transactions_per_page);

include 'includes/header.php';
?>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #c1436dff;
            --secondary: #0fb4c9ff;
            --accent: #00cec9;
            --dark: #7098a3ff;
            --light: #f5f6fa;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
        }

        body {
            background: linear-gradient(135deg, #464668ff 0%, #0e1427ff 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Styles */
        header {
            background: rgba(26, 26, 46, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo img {
            height: 40px;
        }

        .logo h1 {
            font-size: 1.8rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        nav a {
            color: var(--light);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
        }

        nav a:hover {
            color: var(--primary);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 3px;
            background: var(--primary);
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        nav a:hover::after {
            width: 100%;
        }

        .profile-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;    
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .profile-icon:hover {
            transform: scale(1.1);
            border-color: var(--accent);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-right: 15px;
        }

        .username {
            color: var(--light);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .balance-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 60, 126, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .balance-display i {
            color: var(--primary);
        }

        .balance-amount {
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .auth-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-login, .btn-register {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-login {
            color: var(--light);
            border: 1px solid var(--primary);
        }

        .btn-register {
            background: var(--primary);
            color: white;
        }

        .btn-login:hover {
            background: rgba(255, 60, 126, 0.1);
        }

        .btn-register:hover {
            background: #ff2a6d;
            transform: translateY(-2px);
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(26, 26, 46, 0.95);
            border-radius: 8px;
            padding: 0.5rem;
            min-width: 150px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            backdrop-filter: blur(10px);
            margin-top: 10px;
        }

        .dropdown-menu a {
            display: block;
            padding: 0.5rem 1rem;
            color: var(--light);
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .dropdown-menu a:hover {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
        }

        .dropdown-menu.show {
            display: block;
        }
              
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
        }

        .hamburger span {
            width: 25px;
            height: 3px;
            background: var(--light);
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        /* Main Content */
        main {
            flex: 1;
            margin-top: 80px;
            padding: 1.5rem;
        }

        /* Transactions Section - COMPACT STYLES */
        .transactions-section {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
            padding-bottom: 10px;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: 2px;
        }

        .transactions-grid {
            display: grid;
            gap: 1rem;
        }

        /* COMPACT TRANSACTION CARD STYLES */
        .transaction-card {
            background: linear-gradient(145deg, #1e2044, #191a38);
            border-radius: 12px;
            padding: 1.2rem;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
            border-left: 4px solid var(--primary);
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 1rem;
            align-items: center;
        }

        .transaction-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .transaction-type {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            min-width: 120px;
        }

        .transaction-type.deposit {
            color: var(--success);
        }

        .transaction-type.withdrawal {
            color: var(--warning);
        }

        .transaction-type i {
            font-size: 1.1rem;
        }

        .transaction-details {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .detail-label {
            color: #b2bec3;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            color: var(--light);
            font-weight: 500;
            font-size: 0.85rem;
        }

        .transaction-amount {
            font-size: 1.1rem;
            font-weight: 700;
            min-width: 100px;
            text-align: right;
        }

        .transaction-amount.positive {
            color: var(--success);
        }

        .transaction-amount.negative {
            color: var(--warning);
        }

        .transaction-footer {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            min-width: 140px;
        }

        .transaction-date {
            color: #b2bec3;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .transaction-date i {
            font-size: 0.7rem;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .status-approved {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-completed {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-rejected {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: linear-gradient(145deg, #1e2044, #191a38);
            border-radius: 15px;
            border: 2px dashed rgba(255, 255, 255, 0.1);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 3rem;
            color: #b2bec3;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #b2bec3;
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .empty-state p {
            color: #636e72;
            font-size: 0.9rem;
        }

        /* Compact Filter Section */
        .filter-section {
            background: linear-gradient(145deg, #1e2044, #191a38);
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .filter-label {
            color: #f7f3f7ff;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 0.6rem 0.8rem;
            font-size: 0.85rem;
            min-width: 140px;
            color: #1d8ebeff;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Footer */
        footer {
            background: #0c0f1c;
            padding: 2rem 1.5rem 1rem;
            margin-top: auto;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-links a {
            color: var(--light);
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            color: var(--primary);
            transform: translateY(-2px);
        }

        .footer-links {
            display: flex;
            gap: 1.5rem;
        }

        .footer-links a {
            color: #b2bec3;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .copyright {
            text-align: center;
            color: #636e72;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.8rem;
        }

        /* Responsive Design for Compact Layout */
        @media (max-width: 1024px) {
            .transaction-card {
                grid-template-columns: auto 1fr auto;
                gap: 0.8rem;
            }
            
            .transaction-footer {
                grid-column: 1 / -1;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                width: 100%;
                margin-top: 0.5rem;
                padding-top: 0.5rem;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }
        }

        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }
            
            nav ul {
                position: fixed;
                top: 80px;
                left: -100%;
                width: 100%;
                height: calc(100vh - 80px);
                background: rgba(26, 26, 46, 0.98);
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3rem;
                transition: left 0.3s ease;
            }
            
            nav ul.active {
                left: 0;
            }
            
            .hamburger {
                display: flex;
            }
            
            .hamburger.active span:nth-child(1) {
                transform: rotate(45deg) translate(5px, 5px);
            }
            
            .hamburger.active span:nth-child(2) {
                opacity: 0;
            }
            
            .hamburger.active span:nth-child(3) {
                transform: rotate(-45deg) translate(7px, -6px);
            }
            
            .user-info {
                display: none;
            }

            main {
                padding: 1rem;
                margin-top: 70px;
            }

            .transaction-card {
                grid-template-columns: 1fr;
                gap: 0.8rem;
                padding: 1rem;
            }

            .transaction-type {
                min-width: auto;
            }

            .transaction-details {
                gap: 1rem;
            }

            .detail-item {
                flex: 1;
                min-width: calc(50% - 0.5rem);
            }

            .transaction-amount {
                text-align: left;
                min-width: auto;
            }

            .transaction-footer {
                grid-column: 1;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
                gap: 0.8rem;
            }

            .filter-group {
                width: 100%;
            }

            .filter-select {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .section-title {
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }

            .transaction-card {
                padding: 0.8rem;
            }

            .transaction-details {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .detail-item {
                min-width: 100%;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .detail-label {
                font-size: 0.7rem;
            }

            .detail-value {
                font-size: 0.8rem;
            }

            .transaction-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .footer-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 0.8rem;
                text-align: center;
            }

            .social-links {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .transaction-card {
                grid-template-columns: 1fr;
            }

            .transaction-type {
                justify-content: center;
                text-align: center;
            }

            .transaction-amount {
                text-align: center;
                font-size: 1rem;
            }

            .transaction-footer {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
         .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 0.8rem;
            background: linear-gradient(145deg, #1e2044, #191a38);
            color: var(--light);
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .page-link:hover {
            background: linear-gradient(145deg, #2a2c5c, #23244a);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-color: var(--primary);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 60, 126, 0.4);
        }

        .page-item.disabled .page-link {
            background: rgba(255, 255, 255, 0.05);
            color: #636e72;
            cursor: not-allowed;
            transform: none;
        }

        .page-item.disabled .page-link:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            transform: none;
        }

        .pagination-info {
            color: #b2bec3;
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .page-size-label {
            color: #b2bec3;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .page-size-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
            color: var(--light);
            min-width: 70px;
        }

        .pagination-ellipsis {
            color: #b2bec3;
            padding: 0 0.5rem;
            font-weight: 500;
        }

        /* Mobile pagination styles */
        @media (max-width: 768px) {
            .pagination-container {
                flex-direction: column;
                gap: 1rem;
            }

            .page-size-selector {
                margin-left: 0;
            }

            .page-link {
                min-width: 36px;
                height: 36px;
                padding: 0 0.6rem;
                font-size: 0.8rem;
            }

            .pagination {
                gap: 0.3rem;
            }
        }

        @media (max-width: 480px) {
            .pagination {
                gap: 0.2rem;
            }

            .page-link {
                min-width: 32px;
                height: 32px;
                padding: 0 0.4rem;
                font-size: 0.75rem;
            }

            .pagination-info {
                font-size: 0.8rem;
            }
        }

        /* Loading state */
        .loading-transactions {
            text-align: center;
            padding: 2rem;
            color: #b2bec3;
        }

        .loading-transactions i {
            font-size: 2rem;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }/* Admin Notes Styles */
.admin-notes-section {
    margin-top: 0.8rem;
    padding: 0.8rem;
    background: rgba(214, 48, 49, 0.1);
    border-radius: 6px;
    border-left: 3px solid var(--danger);
    grid-column: 1 / -1;
    display: none;
}

.admin-notes-section.show {
    display: block;
}

.admin-notes-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
}

.admin-notes-header i {
    color: var(--danger);
    font-size: 0.9rem;
}

.admin-notes-title {
    color: var(--danger);
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.admin-notes-content {
    color: #ff9999;
    font-size: 0.8rem;
    line-height: 1.3;
    font-style: italic;
}

/* Status badge adjustments for rejected transactions */
.transaction-card[data-status="rejected"] {
    border-left-color: var(--danger);
}

.transaction-card[data-status="rejected"]:hover {
    border-left-color: var(--danger);
    box-shadow: 0 5px 15px rgba(214, 48, 49, 0.2);
}
    </style>



    <!-- Main Content -->
   <main>
        <section class="transactions-section">
            <h2 class="section-title">Transaction History</h2>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <label class="filter-label">Transaction Type</label>
                    <select class="filter-select" id="typeFilter">
                        <option value="all">All Transactions</option>
                        <option value="deposit">Deposits Only</option>
                        <option value="withdrawal">Withdrawals Only</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-select" id="statusFilter">
                        <option value="all">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="completed">Completed</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
           
            </div>

            <!-- Pagination Info -->
            <div class="pagination-info" id="paginationInfo">
                Showing <?php echo count($transactions); ?> of <?php echo $total_transactions; ?> transactions
            </div>

         <!-- Transactions Grid -->
<div class="transactions-grid" id="transactionsGrid">
    <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h3>No Transactions Found</h3>
            <p>You haven't made any deposits or withdrawals yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($transactions as $transaction): ?>
            <div class="transaction-card" data-type="<?php echo $transaction['type']; ?>" data-status="<?php echo $transaction['status']; ?>">
                <div class="transaction-type <?php echo $transaction['type']; ?>">
                    <i class="fas fa-<?php echo $transaction['type'] === 'deposit' ? 'plus-circle' : 'minus-circle'; ?>"></i>
                    <?php echo ucfirst($transaction['type']); ?>
                </div>
                
                <div class="transaction-details">
                    <div class="detail-item">
                        <span class="detail-label">ID</span>
                        <span class="detail-value">#<?php echo str_pad($transaction['id'], 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Method</span>
                        <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $transaction['payment_method'])); ?></span>
                    </div>
                    <?php if ($transaction['type'] === 'deposit' && !empty($transaction['utr_number'])): ?>
                    <div class="detail-item">
                        <span class="detail-label">UTR</span>
                        <span class="detail-value"><?php echo substr(htmlspecialchars($transaction['utr_number']), 0, 8) . '...'; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="transaction-amount <?php echo $transaction['type'] === 'deposit' ? 'positive' : 'negative'; ?>">
                    <?php echo $transaction['type'] === 'deposit' ? '+' : '-'; ?>₹<?php echo number_format($transaction['amount'], 2); ?>
                </div>
                
                <div class="transaction-footer">
                    <div class="transaction-date">
                        <i class="far fa-clock"></i>
                        <?php echo date('M j, Y', strtotime($transaction['created_at'])); ?>
                    </div>
                    <div class="status-badge status-<?php echo $transaction['status']; ?>">
                        <?php echo ucfirst($transaction['status']); ?>
                    </div>
                </div>

                <!-- Admin Notes Section - Show only for rejected transactions with notes -->
                <?php if ($transaction['status'] === 'rejected' && !empty($transaction['admin_notes'])): ?>
                <div class="admin-notes-section show">
                    <div class="admin-notes-header">
                        <i class="fas fa-comment-exclamation"></i>
                        <span class="admin-notes-title">Admin Notes</span>
                    </div>
                    <div class="admin-notes-content">
                        <?php echo htmlspecialchars($transaction['admin_notes']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <ul class="pagination" id="pagination">
                    <!-- Previous Page -->
                    <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $current_page - 1; ?>" aria-label="Previous">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>

                    <!-- Page Numbers -->
                    <?php
                    // Show first page
                    if ($current_page > 3): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=1">1</a>
                    </li>
                    <?php if ($current_page > 4): ?>
                    <li class="page-item disabled">
                        <span class="pagination-ellipsis">...</span>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Page range around current page -->
                    <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                    <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>

                    <!-- Show last page -->
                    <?php if ($current_page < $total_pages - 2): ?>
                    <?php if ($current_page < $total_pages - 3): ?>
                    <li class="page-item disabled">
                        <span class="pagination-ellipsis">...</span>
                    </li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                    </li>
                    <?php endif; ?>

                    <!-- Next Page -->
                    <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $current_page + 1; ?>" aria-label="Next">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>

                <div class="page-size-selector">
                    <span class="page-size-label">Show:</span>
                    <select class="page-size-select" id="pageSizeSelect">
                        <option value="5" <?php echo $transactions_per_page == 5 ? 'selected' : ''; ?>>5</option>
                        <option value="10" <?php echo $transactions_per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $transactions_per_page == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $transactions_per_page == 50 ? 'selected' : ''; ?>>50</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
            <div class="footer-links">
                <a href="#">Terms & Conditions</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Responsible Gaming</a>
                <a href="#">Contact Us</a>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; 2023 RB Games. All rights reserved.</p>
        </div>
    </footer>
    <script src="includes/script.js"></script>

    <script>
        function renderTransactions() {
    const startIndex = (currentPage - 1) * pageSize;
    const endIndex = startIndex + pageSize;
    const pageTransactions = filteredTransactions.slice(startIndex, endIndex);

    if (pageTransactions.length === 0) {
        transactionsGrid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h3>No Transactions Found</h3>
                <p>No transactions match your current filters.</p>
            </div>
        `;
    } else {
        transactionsGrid.innerHTML = pageTransactions.map(transaction => `
            <div class="transaction-card" data-type="${transaction.type}" data-status="${transaction.status}">
                <div class="transaction-type ${transaction.type}">
                    <i class="fas fa-${transaction.type === 'deposit' ? 'plus-circle' : 'minus-circle'}"></i>
                    ${transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1)}
                </div>
                
                <div class="transaction-details">
                    <div class="detail-item">
                        <span class="detail-label">ID</span>
                        <span class="detail-value">#${String(transaction.id).padStart(6, '0')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Method</span>
                        <span class="detail-value">${transaction.payment_method.replace(/_/g, ' ').replace(/\w\S*/g, txt => txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase())}</span>
                    </div>
                    ${transaction.type === 'deposit' && transaction.utr_number ? `
                    <div class="detail-item">
                        <span class="detail-label">UTR</span>
                        <span class="detail-value">${transaction.utr_number.substring(0, 8)}...</span>
                    </div>
                    ` : ''}
                </div>
                
                <div class="transaction-amount ${transaction.type === 'deposit' ? 'positive' : 'negative'}">
                    ${transaction.type === 'deposit' ? '+' : '-'}₹${parseFloat(transaction.amount).toFixed(2)}
                </div>
                
                <div class="transaction-footer">
                    <div class="transaction-date">
                        <i class="far fa-clock"></i>
                        ${new Date(transaction.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    </div>
                    <div class="status-badge status-${transaction.status}">
                        ${transaction.status.charAt(0).toUpperCase() + transaction.status.slice(1)}
                    </div>
                </div>

                <!-- Admin Notes Section -->
                ${transaction.status === 'rejected' && transaction.admin_notes ? `
                <div class="admin-notes-section show">
                    <div class="admin-notes-header">
                        <i class="fas fa-comment-exclamation"></i>
                        <span class="admin-notes-title">Admin Notes</span>
                    </div>
                    <div class="admin-notes-content">
                        ${transaction.admin_notes}
                    </div>
                </div>
                ` : ''}
            </div>
        `).join('');
    }

    updatePaginationInfo();
    updatePaginationControls();
}
        // Pagination and Filter functionality
        const typeFilter = document.getElementById('typeFilter');
        const statusFilter = document.getElementById('statusFilter');
        const pageSizeSelect = document.getElementById('pageSizeSelect');
        const transactionsGrid = document.getElementById('transactionsGrid');
        const paginationInfo = document.getElementById('paginationInfo');

        // Store all transactions data for client-side filtering
        const allTransactions = <?php echo json_encode($all_transactions); ?>;
        let currentPage = <?php echo $current_page; ?>;
        let pageSize = <?php echo $transactions_per_page; ?>;
        let filteredTransactions = [...allTransactions];

        function updatePaginationInfo() {
            const start = ((currentPage - 1) * pageSize) + 1;
            const end = Math.min(currentPage * pageSize, filteredTransactions.length);
            paginationInfo.textContent = `Showing ${start}-${end} of ${filteredTransactions.length} transactions`;
        }

        function updatePaginationControls() {
            const totalPages = Math.ceil(filteredTransactions.length / pageSize);
            const paginationContainer = document.querySelector('.pagination-container');
            
            if (totalPages <= 1) {
                paginationContainer.style.display = 'none';
                return;
            }

            paginationContainer.style.display = 'flex';
            
            let paginationHTML = `
                <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="changePage(${currentPage - 1})" aria-label="Previous">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
            `;

            // Always show first page
            if (currentPage > 3) {
                paginationHTML += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="changePage(1)">1</a>
                    </li>
                `;
                if (currentPage > 4) {
                    paginationHTML += `<li class="page-item disabled"><span class="pagination-ellipsis">...</span></li>`;
                }
            }

            // Show pages around current page
            for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
                paginationHTML += `
                    <li class="page-item ${i === currentPage ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                    </li>
                `;
            }

            // Always show last page
            if (currentPage < totalPages - 2) {
                if (currentPage < totalPages - 3) {
                    paginationHTML += `<li class="page-item disabled"><span class="pagination-ellipsis">...</span></li>`;
                }
                paginationHTML += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="changePage(${totalPages})">${totalPages}</a>
                    </li>
                `;
            }

            paginationHTML += `
                <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="changePage(${currentPage + 1})" aria-label="Next">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            `;

            document.getElementById('pagination').innerHTML = paginationHTML;
        }

        function changePage(page) {
            const totalPages = Math.ceil(filteredTransactions.length / pageSize);
            if (page < 1 || page > totalPages) return;
            
            currentPage = page;
            renderTransactions();
            
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.history.replaceState({}, '', url);
        }

        function filterTransactions() {
            const selectedType = typeFilter.value;
            const selectedStatus = statusFilter.value;

            filteredTransactions = allTransactions.filter(transaction => {
                const typeMatch = selectedType === 'all' || transaction.type === selectedType;
                const statusMatch = selectedStatus === 'all' || transaction.status === selectedStatus;
                return typeMatch && statusMatch;
            });

            currentPage = 1;
            renderTransactions();
        }

        function changePageSize() {
            pageSize = parseInt(pageSizeSelect.value);
            currentPage = 1;
            renderTransactions();
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('per_page', pageSize);
            window.history.replaceState({}, '', url);
        }

        // Event listeners
        typeFilter.addEventListener('change', filterTransactions);
        statusFilter.addEventListener('change', filterTransactions);
        pageSizeSelect.addEventListener('change', changePageSize);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            renderTransactions();
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft') {
                changePage(currentPage - 1);
            } else if (e.key === 'ArrowRight') {
                changePage(currentPage + 1);
            }
        });
    </script>
</body>
</html>