<?php
// admin_games.php
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

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

// Handle form submissions
$message = '';
$message_type = '';

// Add new game
if (isset($_POST['add_game'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $code = $conn->real_escape_string($_POST['code']);
    $description = $conn->real_escape_string($_POST['description']);
    $open_time = $_POST['open_time'];
    $close_time = $_POST['close_time'];
    $result_time = $_POST['result_time'];
    $game_mode = $_POST['game_mode'];
    $min_bet = $_POST['min_bet'];
    $max_bet = $_POST['max_bet'];
    $status = $_POST['status'];
    
    // Check if game code already exists
    $check_sql = "SELECT id FROM games WHERE code = '$code'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        $message = "Game code already exists!";
        $message_type = "error";
    } else {
        $sql = "INSERT INTO games (name, code, description, open_time, close_time, result_time, game_mode, min_bet, max_bet, status) 
                VALUES ('$name', '$code', '$description', '$open_time', '$close_time', '$result_time', '$game_mode', '$min_bet', '$max_bet', '$status')";
        
        if ($conn->query($sql) === TRUE) {
            $message = "Game added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding game: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Update game
if (isset($_POST['update_game'])) {
    $game_id = $_POST['game_id'];
    $name = $conn->real_escape_string($_POST['name']);
    $description = $conn->real_escape_string($_POST['description']);
    $open_time = $_POST['open_time'];
    $close_time = $_POST['close_time'];
    $result_time = $_POST['result_time'];
    $game_mode = $_POST['game_mode'];
    $min_bet = $_POST['min_bet'];
    $max_bet = $_POST['max_bet'];
    $status = $_POST['status'];
    
    $sql = "UPDATE games SET 
            name = '$name', 
            description = '$description', 
            open_time = '$open_time', 
            close_time = '$close_time', 
            result_time = '$result_time', 
            game_mode = '$game_mode', 
            min_bet = '$min_bet', 
            max_bet = '$max_bet', 
            status = '$status' 
            WHERE id = $game_id";
    
    if ($conn->query($sql) === TRUE) {
        $message = "Game updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating game: " . $conn->error;
        $message_type = "error";
    }
}

// Delete game
if (isset($_GET['delete'])) {
    $game_id = $_GET['delete'];
    
    // Check if there are any active sessions for this game
    $check_sql = "SELECT id FROM game_sessions WHERE game_id = $game_id AND status != 'completed'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        $message = "Cannot delete game with active sessions!";
        $message_type = "error";
    } else {
        $sql = "DELETE FROM games WHERE id = $game_id";
        if ($conn->query($sql) === TRUE) {
            $message = "Game deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting game: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Get games list
$games = [];
$sql = "SELECT * FROM games ORDER BY open_time, name";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $games[] = $row;
    }
}

// Get game types for reference
$game_types = [];
$sql_types = "SELECT * FROM game_types WHERE status = 'active'";
$result_types = $conn->query($sql_types);
if ($result_types && $result_types->num_rows > 0) {
    while ($row = $result_types->fetch_assoc()) {
        $game_types[] = $row;
    }
}

// Get game for editing
$edit_game = null;
if (isset($_GET['edit'])) {
    $game_id = $_GET['edit'];
    $sql = "SELECT * FROM games WHERE id = $game_id";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $edit_game = $result->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Games Management - RB Games Admin</title>
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
        z-index: 100;
        display: flex;
        flex-direction: column;
        position: fixed;
        height: 100vh;
        transition: all 0.3s ease;
        overflow-y: auto;
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
        overflow-y: auto;
        width: calc(100% - 260px);
    }

    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2.2rem;
        padding-bottom: 1.2rem;
        border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap;
        gap: 1rem;
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
        flex-wrap: wrap;
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

    /* Dashboard Sections */
    .dashboard-section {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 1.8rem;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        border: 1px solid var(--border-color);
        margin-bottom: 2.2rem;
        overflow: hidden;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding-bottom: 0.8rem;
        border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap;
        gap: 1rem;
    }

    .section-title {
        font-size: 1.3rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-weight: 600;
    }

    .view-all {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .view-all:hover {
        color: var(--secondary);
        text-decoration: underline;
    }

    /* Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
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

    .status-inactive {
        background: rgba(253, 203, 110, 0.2);
        color: var(--warning);
        border: 1px solid rgba(253, 203, 110, 0.3);
    }

    .status-maintenance {
        background: rgba(214, 48, 49, 0.2);
        color: var(--danger);
        border: 1px solid rgba(214, 48, 49, 0.3);
    }

    /* Form Styles */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

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

    .form-control option{
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        /* border-radius: 6px; */
        color: var(--text-light);
    }

    .form-text {
        color: var(--text-muted);
        font-size: 0.85rem;
        margin-top: 0.3rem;
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
        white-space: nowrap;
        text-decoration: none;
        font-size: 0.95rem;
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

    .btn-danger {
        background: rgba(214, 48, 49, 0.2);
        color: var(--danger);
        border: 1px solid rgba(214, 48, 49, 0.3);
    }

    .btn-danger:hover {
        background: rgba(214, 48, 49, 0.3);
    }

    .btn-success {
        background: rgba(0, 184, 148, 0.2);
        color: var(--success);
        border: 1px solid rgba(0, 184, 148, 0.3);
    }

    .btn-success:hover {
        background: rgba(0, 184, 148, 0.3);
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Admin badge and time */
    .admin-badge {
        background: rgba(255, 60, 126, 0.2);
        padding: 0.6rem 1.2rem;
        border-radius: 20px;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-weight: 500;
        border: 1px solid rgba(255, 60, 126, 0.3);
        white-space: nowrap;
    }

    .admin-badge i {
        color: var(--primary);
    }

    .admin-name {
        color: var(--primary);
        font-weight: 600;
    }

    .current-time {
        background: rgba(11, 180, 201, 0.2);
        padding: 0.6rem 1.2rem;
        border-radius: 20px;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-weight: 500;
        border: 1px solid rgba(11, 180, 201, 0.3);
        white-space: nowrap;
    }

    .current-time i {
        color: var(--secondary);
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
    }

    /* Table container for horizontal scrolling */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -1.8rem;
        padding: 0 1.8rem;
    }

    /* Card view for mobile */
    .games-cards {
        display: none;
        flex-direction: column;
        gap: 1rem;
    }

    .game-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 1rem;
        border: 1px solid var(--border-color);
    }

    .game-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .game-row:last-child {
        margin-bottom: 0;
        border-bottom: none;
    }

    .game-label {
        color: var(--text-muted);
        font-weight: 500;
        min-width: 120px;
    }

    .game-value {
        text-align: right;
        flex: 1;
    }

    .game-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
        flex-wrap: wrap;
    }

    /* Alert Messages */
    .alert {
        padding: 1rem;
        border-radius: 6px;
        margin-bottom: 1.5rem;
        border: 1px solid;
    }

    .alert-success {
        background: rgba(0, 184, 148, 0.2);
        border-color: rgba(0, 184, 148, 0.3);
        color: var(--success);
    }

    .alert-error {
        background: rgba(214, 48, 49, 0.2);
        border-color: rgba(214, 48, 49, 0.3);
        color: var(--danger);
    }

    /* Tabs */
    .tabs {
        display: flex;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap;
    }

    .tab {
        padding: 0.8rem 1.5rem;
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.3s ease;
        border-bottom: 2px solid transparent;
        font-weight: 500;
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

    /* Responsive Design */
    @media (max-width: 1200px) {
        .main-content {
            padding: 1.5rem;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 992px) {
        .sidebar {
            width: 80px;
            transform: translateX(0);
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
        
        .sidebar-footer {
            padding: 0.8rem;
        }
        
        .main-content {
            margin-left: 80px;
            padding: 1.5rem;
            width: calc(100% - 80px);
        }
        
        .header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1.2rem;
        }
        
        .header-actions {
            width: 100%;
            justify-content: space-between;
        }
        
        .menu-toggle {
            display: block;
        }
    }

    @media (max-width: 768px) {
        .header-actions {
            flex-direction: column;
            gap: 1rem;
            width: 100%;
        }
        
        .admin-badge, .current-time {
            width: 100%;
            justify-content: center;
        }
        
        .main-content {
            padding: 1rem;
        }
        
        .dashboard-section {
            padding: 1rem;
        }
        
        .table-container {
            margin: 0 -1rem;
            padding: 0 1rem;
        }
        
        .data-table {
            display: none;
        }
        
        .games-cards {
            display: flex;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .view-all {
            align-self: flex-end;
        }
        
        .tabs {
            flex-direction: column;
        }
        
        .tab {
            border-bottom: 1px solid var(--border-color);
            border-left: 2px solid transparent;
        }
        
        .tab.active {
            border-left-color: var(--primary);
            border-bottom-color: var(--border-color);
        }
    }

    @media (max-width: 576px) {
        .sidebar {
            width: 0;
            transform: translateX(-100%);
        }
        
        .sidebar.active {
            width: 260px;
            transform: translateX(0);
        }
        
        .main-content {
            margin-left: 0;
            padding: 1rem;
            width: 100%;
        }
        
        .header {
            margin-top: 3rem;
        }
        
        .welcome h1 {
            font-size: 1.5rem;
        }
        
        .dashboard-section {
            padding: 0.8rem;
        }
        
        .table-container {
            margin: 0 -0.8rem;
            padding: 0 0.8rem;
        }
        
        .game-card {
            padding: 0.8rem;
        }
        
        .game-label {
            min-width: 100px;
            font-size: 0.9rem;
        }
        
        .btn {
            padding: 0.7rem 1.2rem;
            font-size: 0.9rem;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 0.5rem;
        }
        
        .dashboard-section {
            padding: 0.7rem;
            border-radius: 8px;
        }
        
        .header {
            margin-bottom: 1.5rem;
        }
        
        .welcome h1 {
            font-size: 1.3rem;
        }
        
        .welcome p {
            font-size: 0.9rem;
        }
        
        .section-title {
            font-size: 1.1rem;
        }
        
        .btn {
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
        }
        
        .game-card {
            padding: 0.7rem;
        }
        
        .game-row {
            flex-direction: column;
            gap: 0.3rem;
        }
        
        .game-label, .game-value {
            width: 100%;
            text-align: left;
        }
        
        .admin-badge, .current-time, .logout-btn {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        
        .game-actions {
            flex-direction: column;
        }
        
        .game-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 400px) {
        .main-content {
            padding: 0.4rem;
        }
        
        .dashboard-section {
            padding: 0.6rem;
            margin-bottom: 1.5rem;
        }
        
        .header {
            margin-bottom: 1rem;
        }
        
        .welcome h1 {
            font-size: 1.2rem;
        }
        
        .section-title {
            font-size: 1rem;
        }
        
        .btn {
            padding: 0.5rem 0.8rem;
            font-size: 0.8rem;
        }
        
        .form-control {
            padding: 0.7rem 0.8rem;
            font-size: 0.9rem;
        }

        
        
        .game-card {
            padding: 0.6rem;
        }
        
        .admin-badge, .current-time, .logout-btn {
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
        }
        
        .status {
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
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
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="admin-container">
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
                <a href="users.php" class="menu-item">
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
                <a href="admin_deposits.php" class="menu-item">
                    <i class="fas fa-money-bill"></i>
                    <span>Deposits</span>
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
                    <h1>Games Management</h1>
                    <p>Create and manage matka games</p>
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

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo !isset($_GET['edit']) ? 'active' : ''; ?>" data-tab="games-list">Games List</button>
                <button class="tab <?php echo isset($_GET['edit']) ? 'active' : ''; ?>" data-tab="game-form">
                    <?php echo isset($_GET['edit']) ? 'Edit Game' : 'Add New Game'; ?>
                </button>
            </div>

            <!-- Games List Tab -->
            <div class="tab-content <?php echo !isset($_GET['edit']) ? 'active' : ''; ?>" id="games-list">
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-list"></i> All Games</h2>
                        <a href="admin_games.php" class="view-all">Refresh</a>
                    </div>
                    
                    <?php if (!empty($games)): ?>
                        <!-- Desktop Table View -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Code</th>
                                        <th>Open Time</th>
                                        <th>Close Time</th>
                                        <th>Result Time</th>
                                        <th>Min Bet</th>
                                        <th>Max Bet</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                        <tr>
                                            <td><?php echo $game['id']; ?></td>
                                            <td><?php echo $game['name']; ?></td>
                                            <td><?php echo $game['code']; ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['open_time'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['close_time'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['result_time'])); ?></td>
                                            <td>$<?php echo number_format($game['min_bet'], 2); ?></td>
                                            <td>$<?php echo number_format($game['max_bet'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $game['status']; ?>">
                                                    <?php echo ucfirst($game['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="admin_games.php?edit=<?php echo $game['id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <a href="admin_games.php?delete=<?php echo $game['id']; ?>" class="btn btn-danger btn-sm" 
                                                       onclick="return confirm('Are you sure you want to delete this game?')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile Card View -->
                        <div class="games-cards">
                            <?php foreach ($games as $game): ?>
                                <div class="game-card">
                                    <div class="game-row">
                                        <span class="game-label">ID:</span>
                                        <span class="game-value"><?php echo $game['id']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Name:</span>
                                        <span class="game-value"><?php echo $game['name']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Code:</span>
                                        <span class="game-value"><?php echo $game['code']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Open Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['open_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Close Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['close_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Result Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['result_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Min Bet:</span>
                                        <span class="game-value">$<?php echo number_format($game['min_bet'], 2); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Max Bet:</span>
                                        <span class="game-value">$<?php echo number_format($game['max_bet'], 2); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Status:</span>
                                        <span class="game-value">
                                            <span class="status status-<?php echo $game['status']; ?>">
                                                <?php echo ucfirst($game['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="game-actions">
                                        <a href="admin_games.php?edit=<?php echo $game['id']; ?>" class="btn btn-secondary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="admin_games.php?delete=<?php echo $game['id']; ?>" class="btn btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this game?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-3">
                            <p>No games found. <a href="admin_games.php" class="view-all">Add your first game</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Game Form Tab -->
            <div class="tab-content <?php echo isset($_GET['edit']) ? 'active' : ''; ?>" id="game-form">
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas <?php echo isset($_GET['edit']) ? 'fa-edit' : 'fa-plus'; ?>"></i>
                            <?php echo isset($_GET['edit']) ? 'Edit Game' : 'Add New Game'; ?>
                        </h2>
                        <a href="admin_games.php" class="view-all">Back to List</a>
                    </div>
                    
                    <form method="POST" id="gameForm">
                        <?php if (isset($_GET['edit'])): ?>
                            <input type="hidden" name="game_id" value="<?php echo $edit_game['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="name">Game Name *</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo isset($edit_game) ? $edit_game['name'] : ''; ?>" 
                                       required>
                                <div class="form-text">Display name for the game</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="code">Game Code *</label>
                                <input type="text" class="form-control" id="code" name="code" 
                                       value="<?php echo isset($edit_game) ? $edit_game['code'] : ''; ?>" 
                                       <?php echo isset($edit_game) ? 'readonly' : 'required'; ?>>
                                <div class="form-text">Unique code (e.g., KALYAN, MUMBAI)</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($edit_game) ? $edit_game['description'] : ''; ?></textarea>
                            <div class="form-text">Brief description of the game</div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="open_time">Open Time *</label>
                                <input type="time" class="form-control" id="open_time" name="open_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['open_time'] : '09:30'; ?>" 
                                       required>
                                <div class="form-text">When betting opens</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="close_time">Close Time *</label>
                                <input type="time" class="form-control" id="close_time" name="close_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['close_time'] : '11:30'; ?>" 
                                       required>
                                <div class="form-text">When betting closes</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="result_time">Result Time *</label>
                                <input type="time" class="form-control" id="result_time" name="result_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['result_time'] : '12:00'; ?>" 
                                       required>
                                <div class="form-text">When results are declared</div>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="game_mode">Game Mode *</label>
                                <select class="form-control" id="game_mode" name="game_mode" required>
                                    <option value="open" <?php echo (isset($edit_game) && $edit_game['game_mode'] == 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="close" <?php echo (isset($edit_game) && $edit_game['game_mode'] == 'close') ? 'selected' : ''; ?>>Close</option>
                                </select>
                                <div class="form-text">Betting mode for the game</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="min_bet">Minimum Bet *</label>
                                <input type="number" class="form-control" id="min_bet" name="min_bet" 
                                       value="<?php echo isset($edit_game) ? $edit_game['min_bet'] : '5.00'; ?>" 
                                       step="0.01" min="0" required>
                                <div class="form-text">Minimum bet amount</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="max_bet">Maximum Bet *</label>
                                <input type="number" class="form-control" id="max_bet" name="max_bet" 
                                       value="<?php echo isset($edit_game) ? $edit_game['max_bet'] : '10000.00'; ?>" 
                                       step="0.01" min="0" required>
                                <div class="form-text">Maximum bet amount</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-control" id="status" name="status" required>
                                <option value="active" <?php echo (isset($edit_game) && $edit_game['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($edit_game) && $edit_game['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo (isset($edit_game) && $edit_game['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                            <div class="form-text">Game availability status</div>
                        </div>
                        
                        <div class="form-group mt-3">
                            <?php if (isset($_GET['edit'])): ?>
                                <button type="submit" name="update_game" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Game
                                </button>
                                <a href="admin_games.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            <?php else: ?>
                                <button type="submit" name="add_game" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Add Game
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 576 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        // Form validation
        document.getElementById('gameForm')?.addEventListener('submit', function(e) {
            const openTime = document.getElementById('open_time').value;
            const closeTime = document.getElementById('close_time').value;
            const resultTime = document.getElementById('result_time').value;
            
            if (openTime >= closeTime) {
                alert('Open time must be before close time!');
                e.preventDefault();
                return;
            }
            
            if (closeTime >= resultTime) {
                alert('Close time must be before result time!');
                e.preventDefault();
                return;
            }
            
            const minBet = parseFloat(document.getElementById('min_bet').value);
            const maxBet = parseFloat(document.getElementById('max_bet').value);
            
            if (minBet >= maxBet) {
                alert('Minimum bet must be less than maximum bet!');
                e.preventDefault();
                return;
            }
        });
        
        // Update time every minute
        function updateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeElement = document.querySelector('.current-time span');
            if (timeElement) {
                timeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        
        // Initial call
        updateTime();
        
        // Update every minute
        setInterval(updateTime, 60000);
    </script>
</body>
</html>