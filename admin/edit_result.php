<?php
// set_game_results.php
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

// Handle form submission for setting results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_results'])) {
    $session_id = $_POST['session_id'];
    $open_result = $_POST['open_result'];
    $close_result = $_POST['close_result'];
    
    // Validate results
    if (empty($open_result) || empty($close_result)) {
        $error_message = "Both open and close results are required!";
    } else if (strlen($open_result) != 3 || strlen($close_result) != 3) {
        $error_message = "Both open and close results must be 3-digit numbers!";
    } else if (!is_numeric($open_result) || !is_numeric($close_result)) {
        $error_message = "Results must contain only numbers!";
    } else {
        // Calculate jodi result
        $open_sum = array_sum(str_split($open_result));
        $close_sum = array_sum(str_split($close_result));
        
        // Get last digit of each sum
        $open_last = $open_sum % 10;
        $close_last = $close_sum % 10;
        
        $jodi_result = $open_last . $close_last;
        
        try {
            $conn->begin_transaction();
            
            // Update game session with results
            $update_session_sql = "UPDATE game_sessions SET 
                                  open_result = ?, 
                                  close_result = ?, 
                                  jodi_result = ?, 
                                  status = 'completed',
                                  updated_at = CURRENT_TIMESTAMP 
                                  WHERE id = ?";
            $stmt = $conn->prepare($update_session_sql);
            $stmt->bind_param('sssi', $open_result, $close_result, $jodi_result, $session_id);
            $stmt->execute();
            
            // Process bets for this session
            processBetsForSession($conn, $session_id, $open_result, $close_result, $jodi_result);
            
            $conn->commit();
            $success_message = "Results set successfully and bets processed!";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error processing results: " . $e->getMessage();
        }
    }
}

// Function to process bets for a session
function processBetsForSession($conn, $session_id, $open_result, $close_result, $jodi_result) {
    // Get all pending bets for this session
    $bets_sql = "SELECT b.*, gt.name as game_type_name, gt.code as game_type_code 
                 FROM bets b 
                 JOIN game_types gt ON b.game_type_id = gt.id 
                 WHERE b.game_session_id = ? AND b.status = 'pending'";
    $stmt = $conn->prepare($bets_sql);
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $bets_result = $stmt->get_result();
    
    while ($bet = $bets_result->fetch_assoc()) {
        $bet_id = $bet['id'];
        $user_id = $bet['user_id'];
        $numbers_played = json_decode($bet['numbers_played'], true);
        $game_type = $bet['game_type'];
        $bet_status = 'lost'; // Default to lost
        
        // Log for debugging
        error_log("Processing bet ID: $bet_id, Game Type: $game_type, Numbers: " . json_encode($numbers_played));
        
        // Determine win/loss based on game type
        switch ($game_type) {
            case 'single_ank':
                $bet_status = checkSingleAnk($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
                
            case 'jodi':
                $bet_status = checkJodi($numbers_played, $jodi_result);
                break;
                
            case 'single_patti':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
                
            case 'double_patti':
                $bet_status = checkDoublePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
                
            case 'triple_patti':
                $bet_status = checkTriplePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'sp_motor':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'dp_motor':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'sp':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'dp':
                $bet_status = checkDoublePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'sp_set':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'dp_set':
                $bet_status = checkDoublePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'tp_set':
                $bet_status = checkDoublePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;

            case 'abr_cut':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'rown':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'bkki':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'eki':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'series':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
            
            case 'common':
                $bet_status = checkSinglePatti($numbers_played, $open_result, $close_result, $bet['bet_mode']);
                break;
                
            // Add more game types as needed
            default:
                $bet_status = 'lost';
                break;
        }
        
        // Update bet status
        $update_bet_sql = "UPDATE bets SET status = ?, result_declared_at = CURRENT_TIMESTAMP WHERE id = ?";
        $update_stmt = $conn->prepare($update_bet_sql);
        $update_stmt->bind_param('si', $bet_status, $bet_id);
        $update_stmt->execute();
        
        // If bet is won, create payout record
        if ($bet_status === 'won') {
            createPayout($conn, $bet_id, $user_id, $bet['potential_win']);
        }
        
        // Log result
        error_log("Bet ID: $bet_id - Status: $bet_status");
    }
}

// CORRECTED Game type checking functions
function checkSingleAnk($numbers_played, $open_result, $close_result, $bet_mode) {
    $played_numbers = array_keys($numbers_played);
    
    if ($bet_mode === 'open') {
        // Calculate sum of digits for open result
        $open_sum = array_sum(str_split($open_result));
        // Get last digit of the sum (for single digit comparison)
        $open_last_digit = $open_sum % 10;
        
        foreach ($played_numbers as $number) {
            // For single digit bets, compare with the last digit of the sum
            if (intval($number) == $open_last_digit) {
                return 'won';
            }
        }
    } else {
        // Calculate sum of digits for close result
        $close_sum = array_sum(str_split($close_result));
        // Get last digit of the sum (for single digit comparison)
        $close_last_digit = $close_sum % 10;
        
        foreach ($played_numbers as $number) {
            // For single digit bets, compare with the last digit of the sum
            if (intval($number) == $close_last_digit) {
                return 'won';
            }
        }
    }
    
    return 'lost';
}

function checkJodi($numbers_played, $jodi_result) {
    $played_numbers = array_keys($numbers_played);
    
    foreach ($played_numbers as $number) {
        // For jodi, compare the exact 2-digit number
        if ($number == $jodi_result) {
            return 'won';
        }
    }
    
    return 'lost';
}

function checkSinglePatti($numbers_played, $open_result, $close_result, $bet_mode) {
    $played_numbers = array_keys($numbers_played);
    
    if ($bet_mode === 'open') {
        foreach ($played_numbers as $number) {
            // For single patti, compare the exact 3-digit number
            if ($number == $open_result) {
                return 'won';
            }
        }
    } else {
        foreach ($played_numbers as $number) {
            // For single patti, compare the exact 3-digit number
            if ($number == $close_result) {
                return 'won';
            }
        }
    }
    
    return 'lost';
}

function checkDoublePatti($numbers_played, $open_result, $close_result, $bet_mode) {
    $played_numbers = array_keys($numbers_played);
    
    foreach ($played_numbers as $number) {
        if ($bet_mode === 'open') {
            // For double patti, check if the 2-digit number appears in the 3-digit result
            if (strpos($open_result, $number) !== false) {
                return 'won';
            }
        } else {
            // For double patti, check if the 2-digit number appears in the 3-digit result
            if (strpos($close_result, $number) !== false) {
                return 'won';
            }
        }
    }
    
    return 'lost';
}

function checkTriplePatti($numbers_played, $open_result, $close_result, $bet_mode) {
    $played_numbers = array_keys($numbers_played);
    
    foreach ($played_numbers as $number) {
        if ($bet_mode === 'open') {
            // For triple patti, compare the exact 3-digit number
            if ($number == $open_result) {
                return 'won';
            }
        } else {
            // For triple patti, compare the exact 3-digit number
            if ($number == $close_result) {
                return 'won';
            }
        }
    }
    
    return 'lost';
}

function createPayout($conn, $bet_id, $user_id, $amount) {
    // First, get the current balance
    $balance_sql = "SELECT balance FROM users WHERE id = ?";
    $balance_stmt = $conn->prepare($balance_sql);
    $balance_stmt->bind_param('i', $user_id);
    $balance_stmt->execute();
    $balance_result = $balance_stmt->get_result();
    $user_data = $balance_result->fetch_assoc();
    $balance_before = $user_data['balance'];
    $balance_after = $balance_before + $amount;
    
    // Create payout record
    $payout_sql = "INSERT INTO payouts (bet_id, user_id, amount, status, created_at) 
                   VALUES (?, ?, ?, 'pending', CURRENT_TIMESTAMP)";
    $stmt = $conn->prepare($payout_sql);
    $stmt->bind_param('iid', $bet_id, $user_id, $amount);
    $stmt->execute();
    
    // Update user balance
    $update_balance_sql = "UPDATE users SET balance = balance + ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_balance_sql);
    $update_stmt->bind_param('di', $amount, $user_id);
    $update_stmt->execute();
    
    // Record transaction
    $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status, created_at) 
                       VALUES (?, 'winning', ?, ?, ?, ?, 'completed', CURRENT_TIMESTAMP)";
    
    $description = "Winning from bet #" . $bet_id;
    
    $trans_stmt = $conn->prepare($transaction_sql);
    $trans_stmt->bind_param('iddds', $user_id, $amount, $balance_before, $balance_after, $description);
    $trans_stmt->execute();
}


// Date filtering setup (similar to game_sessions_history.php)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'custom';

// Set dates based on quick filters
if ($date_filter != 'custom') {
    switch ($date_filter) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d');
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            break;
    }
}

// Additional filters
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// Build query for game sessions (only open sessions or sessions without results)
$sql = "SELECT 
            gs.id as session_id,
            g.name as game_name,
            DATE(gs.session_date) as session_date,
            g.open_time,
            g.close_time,
            gs.open_result,
            gs.close_result,
            gs.jodi_result,
            gs.status,
            COUNT(b.id) as total_bets,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets
        FROM game_sessions gs
        JOIN games g ON gs.game_id = g.id
        LEFT JOIN bets b ON gs.id = b.game_session_id
        WHERE DATE(gs.session_date) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = 'ss';

if ($filter_game) {
    $sql .= " AND g.name LIKE ?";
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_status) {
    if ($filter_status == 'pending') {
        $sql .= " AND (gs.open_result IS NULL OR gs.close_result IS NULL)";
    } elseif ($filter_status == 'completed') {
        $sql .= " AND gs.open_result IS NOT NULL AND gs.close_result IS NOT NULL";
    }
}

$sql .= " GROUP BY gs.id, g.name, gs.session_date, g.open_time, g.close_time, gs.open_result, gs.close_result, gs.jodi_result, gs.status
          ORDER BY gs.session_date DESC, g.open_time DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$game_sessions = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $game_sessions[] = $row;
    }
}

// Get unique games for filter dropdown
$games_sql = "SELECT DISTINCT name FROM games ORDER BY name";
$games_result = $conn->query($games_sql);
$games = [];
if ($games_result && $games_result->num_rows > 0) {
    while ($row = $games_result->fetch_assoc()) {
        $games[] = $row['name'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Game Results - RB Games Admin</title>
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
            overflow-y: auto;
            left: 0;
            top: 0;
        }
        .sidebar::-webkit-scrollbar{
            display:none;
        }
        .sidebar.active {
            left: 0;
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

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
            transition: all 0.3s ease;
            min-height: 100vh;
            width: calc(100% - 260px);
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .sessions-icon {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
        }

        .bets-icon {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }

        .amount-icon {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }

        .payout-icon {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
        }

        .stat-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            background: linear-gradient(to right, var(--text-light), var(--text-muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2.2rem;
            border: 1px solid var(--border-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .filter-control {
            width: 100%;
            padding: 0.7rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .filter-control option{
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        /* border-radius: 6px; */
        color: var(--text-light);
        }

        .quick-filters {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        .quick-filter-btn {
            padding: 0.6rem 1.2rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .quick-filter-btn.active {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-color: var(--primary);
            color: white;
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.7rem 1.3rem;
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

        .btn-info {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
            text-decoration: none;
        }

        .btn-info:hover {
            background: rgba(11, 180, 201, 0.3);
        }

        /* Game Session Cards */
        .session-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .session-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border-color: var(--primary);
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .session-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .session-date {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .session-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.3rem;
        }

        .detail-value {
            font-weight: 500;
        }

        .session-results {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .result-badge {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-open {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .result-close {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .result-jodi {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        .session-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.8rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .session-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            min-width: 40px;
        }

        .pagination a:hover {
            background: linear-gradient(to right, rgba(255, 60, 126, 0.2), rgba(11, 180, 201, 0.2));
            border-color: var(--primary);
        }

        .pagination .current {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border-color: var(--primary);
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Date range display */
        .date-range {
            background: rgba(11, 180, 201, 0.1);
            padding: 0.8rem 1.2rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(11, 180, 201, 0.2);
            text-align: center;
        }

        .date-range span {
            font-weight: 600;
            color: var(--secondary);
        }

        /* No results state */
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1.5rem;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 993px) {

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
            
            .sidebar-header h2 {
                font-size: 1.2rem;
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
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .session-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .session-actions {
                width: 100%;
                justify-content: center;
            }
        }

        /* Medium screens (769px - 992px) */
        @media (max-width: 992px) and (min-width: 769px) {
            .sidebar {
                width: 10%;
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
            
            .menu-item {
                justify-content: center;
                padding: 1rem;
            }
            
            .sidebar-footer {
                padding: 0.8rem;
            }
            /* Hide menu text on medium screens (icons only) */
            .menu-item span {
                display: none !important;
            }
            
        }

        @media (max-width: 768px) {

            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            .menu-item{
                width :100%;
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
            
            .main-content {
                padding: 1rem;
            }
            
            .dashboard-section {
                padding: 1rem;
            }
            
            .session-details, .session-stats {
                grid-template-columns: 1fr;
            }
            
            .session-results {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-filters {
                justify-content: center;
            }
            
            .filter-actions {
                justify-content: center;
            }
            /* Show menu text when sidebar is active on small screens */
            .sidebar.active .menu-item span {
                display: inline-block !important;
                margin-left: 12px;
            }
            
            /* Hide menu text when sidebar is inactive on small screens */
            .sidebar:not(.active) .menu-item span {
                display: none !important;
            }
        }

        @media (max-width: 576px) {

            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                padding: 1rem;
            }
            .menu-toggle {
                display: block;
            }
            
            
            /* Ensure menu text is visible when sidebar is active */
            .sidebar.active .menu-item span {
                display: inline-block !important;
            }
            
            .sidebar:not(.active) .menu-item span {
                display: none !important;
            }
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card-value {
                font-size: 1.6rem;
            }
            
            .session-card {
                padding: 1rem;
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
                padding: 0.5rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .session-card {
                padding: 0.8rem;
            }
            
            .admin-badge, .current-time, .logout-btn {
                font-size: 0.9rem;
                padding: 0.5rem 1rem;
            }
            
            .quick-filters {
                flex-direction: column;
            }
            
            .quick-filter-btn {
                width: 100%;
                text-align: center;
            }
        }

        /* Ultra small devices (400px and below) */
        @media (max-width: 400px) {
            .main-content {
                padding: 0.8rem;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .welcome h1 {
                font-size: 1.3rem;
            }
            .welcome p {
                font-size: 0.9rem;
            }
            .header-actions {
                flex-direction: column;
                width: 100%;
                gap: 0.8rem;
            }
            .admin-badge, .current-time {
                width: 100%;
                justify-content: center;
            }
            .logout-btn {
                width: 100%;
                justify-content: center;
            }
            .menu-toggle {
                top: 0.8rem;
                left: 0.8rem;
            }
            
            /* Ensure menu text is visible when sidebar is active */
            .sidebar.active .menu-item span {
                display: inline-block !important;
            }
            
            .sidebar:not(.active) .menu-item span {
                display: none !important;
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

        /* Profit/Loss indicators */
        .profit {
            color: var(--success);
        }

        .loss {
            color: var(--danger);
        }

        .neutral {
            color: var(--text-muted);
        }
    </style>
    
    <style>
        /* Include all the CSS styles from game_sessions_history.php */
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

        /* Include all other CSS styles from game_sessions_history.php */
        /* ... (copy all the CSS styles from the previous file) ... */

        /* Additional styles for result form */
        .result-form {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 1rem;
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

        .btn-danger {
            background: linear-gradient(to right, var(--danger), #e17055);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(to right, #c23616, #d63031);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(0, 184, 148, 0.2);
            border-color: rgba(0, 184, 148, 0.3);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(214, 48, 49, 0.2);
            border-color: rgba(214, 48, 49, 0.3);
            color: var(--danger);
        }

        .calculated-jodi {
            background: rgba(11, 180, 201, 0.2);
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            border: 1px solid rgba(11, 180, 201, 0.3);
            text-align: center;
        }

        .jodi-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--secondary);
        }
    </style>
</head>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="admin-container">
        <!-- Sidebar (same as game_sessions_history.php) -->
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
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="#" class="menu-item active">
                    <i class="fas fa-flag-checkered"></i>
                    <span>Set Game Results</span>
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
                <a href="admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
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
                    <h1>Set Game Results</h1>
                    <p>Enter game results and process pending bets</p>
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

            <!-- Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-filter"></i> Filter Game Sessions</h3>
                
                <!-- Quick Date Filters -->
                <div class="quick-filters">
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'today' ? 'active' : ''; ?>" data-filter="today">Today</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'yesterday' ? 'active' : ''; ?>" data-filter="yesterday">Yesterday</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'this_week' ? 'active' : ''; ?>" data-filter="this_week">This Week</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'last_week' ? 'active' : ''; ?>" data-filter="last_week">Last Week</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'this_month' ? 'active' : ''; ?>" data-filter="this_month">This Month</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'last_month' ? 'active' : ''; ?>" data-filter="last_month">Last Month</button>
                    <button type="button" class="quick-filter-btn <?php echo $date_filter == 'custom' ? 'active' : ''; ?>" data-filter="custom">Custom</button>
                </div>

                <form method="GET" id="filterForm">
                    <input type="hidden" name="date_filter" id="dateFilter" value="<?php echo $date_filter; ?>">
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Start Date</label>
                            <input type="date" name="start_date" class="filter-control" value="<?php echo $start_date; ?>" 
                                   <?php echo $date_filter != 'custom' ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">End Date</label>
                            <input type="date" name="end_date" class="filter-control" value="<?php echo $end_date; ?>" 
                                   <?php echo $date_filter != 'custom' ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Game</label>
                            <select name="filter_game" class="filter-control">
                                <option value="">All Games</option>
                                <?php foreach ($games as $game): ?>
                                    <option value="<?php echo htmlspecialchars($game); ?>" <?php echo $filter_game == $game ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($game); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Session Status</label>
                            <select name="filter_status" class="filter-control">
                                <option value="">All Sessions</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending Results</option>
                                <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="set_game_results.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Game Sessions List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Game Sessions</h2>
                    <div class="view-all">Total: <?php echo count($game_sessions); ?> sessions</div>
                </div>
                
                <?php if (!empty($game_sessions)): ?>
                    <div class="sessions-list">
                        <?php foreach ($game_sessions as $session): ?>
                            <div class="session-card">
                                <div class="session-header">
                                    <div class="session-title">
                                        <?php echo htmlspecialchars($session['game_name']); ?>
                                        <?php if ($session['open_result'] && $session['close_result']): ?>
                                            <span style="color: var(--success); margin-left: 10px;">
                                                <i class="fas fa-check-circle"></i> Completed
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--warning); margin-left: 10px;">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="session-date">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M j, Y', strtotime($session['session_date'])); ?>
                                    </div>
                                </div>
                                
                                <div class="session-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Time Slot</span>
                                        <span class="detail-value">
                                            <?php echo date('h:i A', strtotime($session['open_time'])); ?> - 
                                            <?php echo date('h:i A', strtotime($session['close_time'])); ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Session ID</span>
                                        <span class="detail-value">#<?php echo $session['session_id']; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Pending Bets</span>
                                        <span class="detail-value"><?php echo $session['pending_bets']; ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($session['open_result'] && $session['close_result']): ?>
                                    <div class="session-results">
                                        <div class="result-badge result-open">
                                            <i class="fas fa-sun"></i>
                                            Open: <?php echo $session['open_result']; ?>
                                        </div>
                                        <div class="result-badge result-close">
                                            <i class="fas fa-moon"></i>
                                            Close: <?php echo $session['close_result']; ?>
                                        </div>
                                        <?php if ($session['jodi_result']): ?>
                                            <div class="result-badge result-jodi">
                                                <i class="fas fa-link"></i>
                                                Jodi: <?php echo $session['jodi_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- Result Entry Form -->
                                    <div class="result-form">
                                        <form method="POST" onsubmit="return validateResults(this)">
                                            <input type="hidden" name="session_id" value="<?php echo $session['session_id']; ?>">
                                            
                                            <div class="form-group">
                                                <label class="form-label">Open Result</label>
                                                <input type="text" name="open_result" class="form-control" 
                                                       placeholder="Enter open result" maxlength="3" 
                                                       pattern="[0-9]{3}" title="Enter 3-digit number" required>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">Close Result</label>
                                                <input type="text" name="close_result" class="form-control" 
                                                       placeholder="Enter close result" maxlength="3" 
                                                       pattern="[0-9]{3}" title="Enter 3-digit number" required>
                                            </div>
                                            
                                            <div class="calculated-jodi" style="display: none;" id="jodi-preview-<?php echo $session['session_id']; ?>">
                                                <strong>Calculated Jodi:</strong>
                                                <div class="jodi-value" id="jodi-value-<?php echo $session['session_id']; ?>"></div>
                                            </div>
                                            
                                            <div class="session-actions">
                                                <button type="submit" name="set_results" class="btn btn-primary">
                                                    <i class="fas fa-flag-checkered"></i> Set Results & Process Bets
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Game Sessions Found</h3>
                        <p>No game sessions match your current filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality (same as game_sessions_history.php)
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function updateMenuTextVisibility() {
            const menuSpans = document.querySelectorAll('.menu-item span');
            
            if (window.innerWidth >= 993) {
                menuSpans.forEach(span => {
                    span.style.display = 'inline-block';
                });
            } else if (window.innerWidth >= 769) {
                menuSpans.forEach(span => {
                    span.style.display = 'none';
                });
            } else {
                if (sidebar.classList.contains('active')) {
                    menuSpans.forEach(span => {
                        span.style.display = 'inline-block';
                    });
                } else {
                    menuSpans.forEach(span => {
                        span.style.display = 'none';
                    });
                }
            }
        }

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            updateMenuTextVisibility();
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            updateMenuTextVisibility();
        });

        // Quick filter buttons
        document.querySelectorAll('.quick-filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                document.getElementById('filterForm').submit();
            });
        });

        // Jodi calculation preview
        document.querySelectorAll('input[name="open_result"], input[name="close_result"]').forEach(input => {
            input.addEventListener('input', function() {
                const form = this.closest('form');
                const sessionId = form.querySelector('input[name="session_id"]').value;
                const openResult = form.querySelector('input[name="open_result"]').value;
                const closeResult = form.querySelector('input[name="close_result"]').value;
                
                if (openResult.length === 3 && closeResult.length === 3) {
                    calculateJodiPreview(openResult, closeResult, sessionId);
                } else {
                    document.getElementById('jodi-preview-' + sessionId).style.display = 'none';
                }
            });
        });

        function calculateJodiPreview(openResult, closeResult, sessionId) {
            const openSum = Array.from(openResult).reduce((sum, digit) => sum + parseInt(digit), 0);
            const closeSum = Array.from(closeResult).reduce((sum, digit) => sum + parseInt(digit), 0);
            
            const openLast = openSum % 10;
            const closeLast = closeSum % 10;
            const jodiResult = openLast + '' + closeLast;
            
            document.getElementById('jodi-value-' + sessionId).textContent = jodiResult;
            document.getElementById('jodi-preview-' + sessionId).style.display = 'block';
        }

        function validateResults(form) {
            const openResult = form.open_result.value;
            const closeResult = form.close_result.value;
            
            if (openResult.length !== 3 || closeResult.length !== 3) {
                alert('Both open and close results must be 3-digit numbers');
                return false;
            }
            
            if (!/^\d+$/.test(openResult) || !/^\d+$/.test(closeResult)) {
                alert('Results must contain only numbers');
                return false;
            }
            
            return confirm('Are you sure you want to set these results? This will process all pending bets for this session.');
        }

        // Initialize
        updateMenuTextVisibility();
        window.addEventListener('resize', updateMenuTextVisibility);
    </script>
</body>
</html>