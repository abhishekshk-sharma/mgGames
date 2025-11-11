<?php
require_once 'config.php';
session_start();
include 'includes/header.php';
if (!isset($_SESSION['user_id'])) {
    header("location: login.php");
    exit;
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize user data
$is_logged_in = true;
$user_id = $_SESSION['user_id'];

// Fetch user balance and username from database
$user = getUserData($user_id);
$username = $user['username'];
$user_balance = $user['balance'];
$balance = $user_balance;

// Function to get user data
function getUserData($user_id) {
    global $conn;
    
    $sql = "SELECT username, balance FROM users WHERE id = ?";
    $user = ['username' => '', 'balance' => 0];
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($user['username'], $user['balance']);
        $stmt->fetch();
        $stmt->close();
    }
    
    return $user;
}

// Create a map for game types
$game_type_map = [
    'single_ank' => 'Single Ank',
    'jodi' => 'Jodi',
    'single_patti' => 'Single Patti',
    'double_patti' => 'Double Patti',
    'triple_patti' => 'Triple Patti',
    'half_sangam' => 'Half Sangam',
    'full_sangam' => 'Full Sangam',
    'sp_motor' => 'SP Motor',
    'dp_motor' => 'DP Motor',
    'sp_game' => 'SP Game',
    'dp_game' => 'DP Game',
    'sp_set' => 'SP Set',
    'dp_set' => 'DP Set',
    'tp_set' => 'TP Set',
    'common' => 'Common',
    'series' => 'Series',
    'rown' => 'Rown',
    'eki' => 'Eki',
    'bkki' => 'Bkki',
    'abr_cut' => 'Abr Cut'
];

// Pagination configuration
$bets_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $bets_per_page;

// Filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$game_filter = isset($_GET['game']) ? $_GET['game'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE conditions for filters
$where_conditions = ["b.user_id = ?"];
$params = [$user_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_conditions[] = "b.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if ($game_filter !== 'all') {
    $where_conditions[] = "b.game_name = ?";
    $params[] = $game_filter;
    $param_types .= "s";
}

if ($type_filter !== 'all') {
    $where_conditions[] = "b.game_type = ?";
    $params[] = $type_filter;
    $param_types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(b.placed_at) >= ?";
    $params[] = $date_from;
    $param_types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(b.placed_at) <= ?";
    $params[] = $date_to;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Function to get user bets with pagination and filtering
function getUserBets($user_id, $where_clause, $params, $param_types, $offset, $bets_per_page) {
    global $conn;
    
    $bets = [];
    $sql = "SELECT 
                b.id, 
                b.game_name, 
                b.game_type,
                b.bet_mode, 
                b.numbers_played, 
                b.amount, 
                b.potential_win, 
                b.status, 
                b.placed_at, 
                b.open_time, 
                b.close_time,
                r.result_value,
                r.declared_at,
                CASE 
                    WHEN b.status = 'won' THEN b.potential_win - b.amount
                    WHEN b.status = 'lost' THEN -b.amount
                    ELSE 0 
                END as profit_loss
            FROM bets b
            LEFT JOIN results r ON b.game_session_id = r.game_session_id
            WHERE $where_clause
            ORDER BY b.placed_at DESC
            LIMIT ? OFFSET ?";
    
    // Add pagination parameters
    $params[] = $bets_per_page;
    $params[] = $offset;
    $param_types .= "ii";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $bets[] = $row;
        }
        
        $stmt->close();
    }
    
    return $bets;
}

// Function to get total bets count for pagination
function getTotalBetsCount($user_id, $where_clause, $params, $param_types) {
    global $conn;
    
    $sql = "SELECT COUNT(*) as total 
            FROM bets b
            LEFT JOIN results r ON b.game_session_id = r.game_session_id
            WHERE $where_clause";
    
    $total = 0;
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $total = $row['total'];
        }
        
        $stmt->close();
    }
    
    return $total;
}

// Get filtered and paginated bets
$user_bets = getUserBets($user_id, $where_clause, $params, $param_types, $offset, $bets_per_page);
$total_bets_count = getTotalBetsCount($user_id, $where_clause, $params, $param_types);
$total_pages = ceil($total_bets_count / $bets_per_page);

// Calculate totals for stats
$total_bets = $total_bets_count;
$total_won = 0;
$total_lost = 0;
$total_pending = 0;
$total_profit = 0;

// Recalculate totals based on filtered data
foreach ($user_bets as $bet) {
    if ($bet['status'] === 'won') {
        $total_won++;
        $total_profit += $bet['profit_loss'];
    } elseif ($bet['status'] === 'lost') {
        $total_lost++;
        $total_profit += $bet['profit_loss'];
    } elseif ($bet['status'] === 'pending') {
        $total_pending++;
    }
}

// Helper function to build pagination URLs with filters
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Function to format numbers based on game type - UPDATED FOR SIMPLIFIED FORMAT
function formatNumbers($game_type, $numbers_played, $result_value = null) {
    $formatted_html = '';
    
    // Try to decode as JSON first
    $decoded_data = json_decode($numbers_played, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
        // Data is valid JSON array - process based on game type
        switch ($game_type) {
            case 'single_ank':
                $formatted_html .= formatSingleAnk($decoded_data, $result_value);
                break;
                
            case 'jodi':
                $formatted_html .= formatJodi($decoded_data, $result_value);
                break;
                
            case 'single_patti':
            case 'double_patti':
            case 'triple_patti':
                $formatted_html .= formatPatti($decoded_data, $result_value, $game_type);
                break;
                
            case 'sp_motor':
            case 'dp_motor':
                $formatted_html .= formatMotor($decoded_data, $result_value, $game_type);
                break;
                
            case 'sp_game':
            case 'dp_game':
                $formatted_html .= formatSPDPGame($decoded_data, $result_value, $game_type);
                break;
                
            case 'sp_set':
            case 'dp_set':
            case 'tp_set':
                $formatted_html .= formatSet($decoded_data, $result_value, $game_type);
                break;
                
            case 'series':
                $formatted_html .= formatSeries($decoded_data, $result_value);
                break;
                
            case 'common':
                $formatted_html .= formatCommon($decoded_data, $result_value);
                break;
                
            case 'rown':
                $formatted_html .= formatRown($decoded_data, $result_value);
                break;
                
            case 'eki':
                $formatted_html .= formatEki($decoded_data, $result_value);
                break;
                
            case 'bkki':
                $formatted_html .= formatBkki($decoded_data, $result_value);
                break;
                
            case 'abr_cut':
                $formatted_html .= formatAbrCut($decoded_data, $result_value);
                break;
                
            default:
                $formatted_html .= formatDefault($decoded_data, $result_value);
                break;
        }
    } else {
        // Data is not JSON, display as plain text
        $formatted_html .= '<div class="bet-numbers">';
        $formatted_html .= '<span class="bet-number">' . htmlspecialchars($numbers_played) . '</span>';
        $formatted_html .= '</div>';
    }
    
    return $formatted_html;
}

// Helper function for Single Ank - NEW SIMPLIFIED FORMAT: {"5":500}
function formatSingleAnk($decoded_data, $result_value) {
    $html = '<div class="bet-numbers single-ank-numbers">';
    
    foreach ($decoded_data as $digit => $amount) {
        $is_winner = ($result_value && $result_value == $digit) ? 'winner-number' : '';
        $html .= '<span class="bet-number ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $digit . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Jodi - NEW SIMPLIFIED FORMAT: {"56":500}
function formatJodi($decoded_data, $result_value) {
    $html = '<div class="bet-numbers jodi-numbers">';
    
    foreach ($decoded_data as $jodi => $amount) {
        $is_winner = ($result_value && $result_value == $jodi) ? 'winner-number' : '';
        $html .= '<span class="bet-number ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $jodi . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Patti types - NEW SIMPLIFIED FORMAT: {"123":500}
function formatPatti($decoded_data, $result_value, $game_type) {
    $html = '<div class="bet-numbers patti-numbers ' . $game_type . '-numbers">';
    
    $patti_type_class = str_replace('_', '-', $game_type);
    
    foreach ($decoded_data as $patti => $amount) {
        $is_winner = ($result_value && $result_value == $patti) ? 'winner-number' : '';
        $html .= '<span class="bet-number patti ' . $patti_type_class . ' ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $patti . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Motor games - NEW SIMPLIFIED FORMAT: {"123":500}
function formatMotor($decoded_data, $result_value, $game_type) {
    $html = '<div class="bet-numbers motor-numbers ' . $game_type . '-numbers">';
    
    $motor_type = str_replace('_motor', '', $game_type);
    
    foreach ($decoded_data as $pana => $amount) {
        $is_winner = ($result_value && $result_value == $pana) ? 'winner-number' : '';
        $html .= '<span class="bet-number motor ' . $motor_type . '-motor ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $pana . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for SP and DP games - NEW SIMPLIFIED FORMAT: {"123":500}
function formatSPDPGame($decoded_data, $result_value, $game_type) {
    $html = '<div class="bet-numbers ' . $game_type . '-numbers">';
    
    foreach ($decoded_data as $outcome => $amount) {
        $is_winner = ($result_value && $result_value == $outcome) ? 'winner-number' : '';
        $html .= '<span class="bet-number ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $outcome . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Set games - NEW SIMPLIFIED FORMAT: {"123":500}
function formatSet($decoded_data, $result_value, $game_type) {
    $html = '<div class="bet-numbers set-numbers ' . $game_type . '-numbers">';
    
    $set_type = str_replace('_set', '', $game_type);
    
    foreach ($decoded_data as $numbers => $amount) {
        $is_winner = ($result_value && $result_value == $numbers) ? 'winner-number' : '';
        $html .= '<span class="bet-number set ' . $set_type . '-set ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $numbers . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Series - NEW SIMPLIFIED FORMAT: {"123":500}
function formatSeries($decoded_data, $result_value) {
    $html = '<div class="bet-numbers series-numbers">';
    
    foreach ($decoded_data as $series => $amount) {
        $is_winner = ($result_value && $result_value == $series) ? 'winner-number' : '';
        $html .= '<span class="bet-number series ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $series . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Common - NEW SIMPLIFIED FORMAT: {"123":500}
function formatCommon($decoded_data, $result_value) {
    $html = '<div class="bet-numbers common-numbers">';
    
    foreach ($decoded_data as $common => $amount) {
        $is_winner = ($result_value && $result_value == $common) ? 'winner-number' : '';
        $html .= '<span class="bet-number common ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $common . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Rown - NEW SIMPLIFIED FORMAT: {"123":500}
function formatRown($decoded_data, $result_value) {
    $html = '<div class="bet-numbers rown-numbers">';
    
    foreach ($decoded_data as $rown => $amount) {
        $is_winner = ($result_value && $result_value == $rown) ? 'winner-number' : '';
        $html .= '<span class="bet-number rown ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $rown . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Eki - NEW SIMPLIFIED FORMAT: {"137":500}
function formatEki($decoded_data, $result_value) {
    $html = '<div class="bet-numbers eki-numbers">';
    
    foreach ($decoded_data as $eki => $amount) {
        $is_winner = ($result_value && $result_value == $eki) ? 'winner-number' : '';
        $html .= '<span class="bet-number eki ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $eki . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Bkki - NEW SIMPLIFIED FORMAT: {"280":500}
function formatBkki($decoded_data, $result_value) {
    $html = '<div class="bet-numbers bkki-numbers">';
    
    foreach ($decoded_data as $bkki => $amount) {
        $is_winner = ($result_value && $result_value == $bkki) ? 'winner-number' : '';
        $html .= '<span class="bet-number bkki ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $bkki . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Helper function for Abr Cut - NEW SIMPLIFIED FORMAT: {"127":500}
function formatAbrCut($decoded_data, $result_value) {
    $html = '<div class="bet-numbers abr-cut-numbers">';
    
    foreach ($decoded_data as $abr_cut => $amount) {
        $is_winner = ($result_value && $result_value == $abr_cut) ? 'winner-number' : '';
        $html .= '<span class="bet-number abr-cut ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $abr_cut . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}

// Default formatter - Show all outcomes for unknown game types
function formatDefault($decoded_data, $result_value) {
    $html = '<div class="bet-numbers default-numbers">';
    
    foreach ($decoded_data as $number => $amount) {
        $is_winner = ($result_value && $result_value == $number) ? 'winner-number' : '';
        $html .= '<span class="bet-number ' . $is_winner . '" title="Amount: ₹' . 
                 number_format($amount, 2) . '">' . $number . '</span>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RB Games - My Bets</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --success: #00A650;
            --warning: #FF9800;
            --danger: #D32F2F;
            --card-bg: #1A1A1A;
            --header-bg: rgba(13, 13, 13, 0.95);
        }

        body {
            background: linear-gradient(135deg, #21213fff 0%, #0e1427ff 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-attachment: fixed;
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
            padding: 2rem;
        }

        .betting-container {
            max-width: 1550px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 215, 0, 0.1);
        }

        .betting-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .betting-title {
            font-size: 2rem;
            color: var(--primary);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            border: 1px solid rgba(255, 215, 0, 0.1);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #b2bec3;
            font-size: 0.9rem;
        }

        .stat-won .stat-value {
            color: var(--success);
        }

        .stat-lost .stat-value {
            color: var(--danger);
        }

        .stat-pending .stat-value {
            color: var(--warning);
        }

        .stat-profit .stat-value {
            color: var(--primary);
        }

        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.9rem;
            color: #b2bec3;
        }

        select, input {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--light);
            font-size: 1rem;
            min-width: 150px;
        }

        .bets-table {
            width: 100%;
           
            border-collapse: collapse;
            margin-bottom: 2rem;
        }

        .bets-table th {
            background: rgba(255, 215, 0, 0.1);
            padding: 1rem;
             height:100%;
            text-align: left;
            font-weight: 600;
            color: var(--primary);
        }

        .bets-table td {
            padding: 1rem;
            
            /* border-bottom: 1px solid rgba(255, 255, 255, 0.1); */
        }

        .bets-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(255, 152, 0, 0.2);
            color: var(--warning);
        }

        .status-won {
            background: rgba(0, 166, 80, 0.2);
            color: var(--success);
        }

        .status-lost {
            background: rgba(211, 47, 47, 0.2);
            color: var(--danger);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 5px;
            color: var(--light);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination-btn:hover {
            background: rgba(255, 215, 0, 0.1);
        }

        .pagination-btn.active {
            background: var(--primary);
            color: #000;
        }

        .no-bets {
            text-align: center;
            padding: 2rem;
            color: #b2bec3;
            font-style: italic;
        }

        /* Footer */
        footer {
            background: #0A0A0A;
            padding: 3rem 2rem 1.5rem;
            margin-top: auto;
            border-top: 1px solid rgba(255, 215, 0, 0.1);
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-links a {
            color: var(--light);
            font-size: 1.5rem;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
        }

        .social-links a:hover {
            color: var(--primary);
            background: rgba(255, 215, 0, 0.1);
            transform: translateY(-3px);
        }

        .footer-links {
            display: flex;
            gap: 2rem;
        }

        .footer-links a {
            color: #b2bec3;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .footer-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 1px;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        .footer-links a:hover::after {
            width: 100%;
        }

        .copyright {
            text-align: center;
            color: #636e72;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Responsive Design */
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
                background: rgba(13, 13, 13, 0.95);
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2rem;
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
                transform: rotate(-45deg) translate(5px, -5px);
            }
            
            .user-info {
                display: none;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
            
            .bets-table {
                display: block;
                overflow-x: auto;
            }
            
            .filters {
                flex-direction: column;
            }
            
            .footer-content {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
            }
            
            .footer-links {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .logo h1 {
                font-size: 1.5rem;
            }
            
            .betting-title {
                font-size: 1.5rem;
            }
                }
                
        .bet-numbers {
            display: flex;
            flex-wrap: wrap;
            gap: 0.2rem;
            max-width: 250px;
            max-height: 60px;
            overflow-y: auto;
            padding: 2px;
            position: relative;
        }

        .bet-number {
            background: rgba(255, 215, 0, 0.1);
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-size: 0.7rem;
            border: 1px solid rgba(255, 215, 0, 0.2);
            cursor: help;
            line-height: 1.2;
        }

        .bet-number:hover {
            background: rgba(255, 215, 0, 0.2);
        }

        /* Tooltip for showing all numbers */
        .bet-numbers:hover .bet-number.hidden-number {
            display: inline-block;
        }

        .hidden-number {
            display: none;
        }

        .more-numbers-indicator {
            background: rgba(255, 215, 0, 0.3);
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-size: 0.7rem;
            border: 1px solid rgba(255, 215, 0, 0.4);
            cursor: help;
        }
            .sp-motor-numbers, .half-sangam-numbers {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .sangam-section {
            padding: 0.3rem;
            background: rgba(255, 215, 0, 0.1);
            border-radius: 4px;
            border: 1px solid rgba(255, 215, 0, 0.2);
            font-size: 0.8rem;
        }

        .winner-number {
            background: rgba(0, 166, 80, 0.3) !important;
            border: 1px solid rgba(0, 166, 80, 0.5) !important;
        }

        .bet-numbers {
            max-height: 120px;
            overflow-y: auto;
        }

    /* ... existing styles ... */
    
    .sp-motor-numbers, .half-sangam-numbers {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        max-height: 120px;
        overflow-y: auto;
        padding: 2px;
    }
    
    .sangam-section {
        padding: 0.3rem;
        background: rgba(255, 215, 0, 0.1);
        border-radius: 4px;
        border: 1px solid rgba(255, 215, 0, 0.2);
        font-size: 0.8rem;
    }
    
    .winner-number {
        background: rgba(0, 166, 80, 0.3) !important;
        border: 1px solid rgba(0, 166, 80, 0.5) !important;
    }
    
    /* Scrollable bet numbers for single patti and other types */
    .bet-numbers {
        display: flex;
        flex-wrap: wrap;
        gap: 0.2rem;
        max-height: 120px;
        overflow-y: auto;
        padding: 2px;
        position: relative;
    }
    
    .bet-number {
        background: rgba(255, 215, 0, 0.1);
        padding: 0.3rem 1rem;
        border-radius: 7px;
        font-size: 1rem;
        border: 1px solid rgba(255, 215, 0, 0.2);
        cursor: help;
        line-height: 1.2;
        flex-shrink: 0;
    }
    
    .bet-number:hover {
        background: rgba(216, 186, 14, 0.2);
    }
    
    /* Custom scrollbar for bet numbers */
  

    
    .bet-numbers::-webkit-scrollbar-thumb {
        background: rgba(255, 215, 0, 0.3);
        border-radius: 3px;
    }
    
    .bet-numbers::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 215, 0, 0.5);
    }
    
    /* For Firefox */
    .bet-numbers {
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 215, 0, 0.3) rgba(255, 255, 255, 0.1);
    }
    
    .more-numbers-indicator {
        background: rgba(255, 215, 0, 0.3);
        padding: 0.2rem 0.4rem;
        border-radius: 3px;
        font-size: 0.7rem;
        border: 1px solid rgba(255, 215, 0, 0.4);
        cursor: help;
        align-self: flex-start;
    }
    
    /* Specific style for single patti with many numbers */
    .single-patti-numbers {
        max-height: 80px;
        overflow-y: auto;
        display: flex;
        flex-wrap: wrap;
        gap: 0.2rem;
        padding: 3px;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 5px;
        border: 1px solid rgba(255, 215, 0, 0.1);
    }
    
    /* Compact view for table cells */
    .compact-numbers {
        max-height: 60px;
        font-size: 0.65rem;
        padding: 0.15rem 0.3rem;
    }

/* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 2rem;
    padding: 1rem 0;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.pagination-info {
    color: #b2bec3;
    font-size: 0.9rem;
}

.pagination {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    list-style: none;
}

.page-item {
    display: inline-block;
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

.pagination-ellipsis {
    color: #b2bec3;
    padding: 0 0.5rem;
    font-weight: 500;
}

/* Filter Form Styles */
.filter-form {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    width: 100%;
}

.filter-btn, .reset-btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-btn {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    color: white;
}

.filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 60, 126, 0.4);
}

.reset-btn {
    background: rgba(255, 255, 255, 0.1);
    color: var(--light);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.reset-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

/* Responsive Pagination */
@media (max-width: 768px) {
    .pagination-container {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .page-link {
        min-width: 35px;
        height: 35px;
        padding: 0 0.6rem;
        font-size: 0.8rem;
    }
}
    </style>
</head>
<body>
   

    <main>
        <div class="betting-container">
            <div class="betting-header">
                <h1 class="betting-title">My Bets History</h1>
                <p>Track all your betting activity in one place</p>
            </div>
            
            <div class="stats-container">
                <div class="stat-card stat-total">
                    <div class="stat-value"><?php echo $total_bets; ?></div>
                    <div class="stat-label">Total Bets</div>
                </div>
                
                <div class="stat-card stat-won">
                    <div class="stat-value"><?php echo $total_won; ?></div>
                    <div class="stat-label">Won</div>
                </div>
                
                <div class="stat-card stat-lost">
                    <div class="stat-value"><?php echo $total_lost; ?></div>
                    <div class="stat-label">Lost</div>
                </div>
                
                <div class="stat-card stat-pending">
                    <div class="stat-value"><?php echo $total_pending; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                
                <div class="stat-card stat-profit">
                    <div class="stat-value">$<?php echo number_format($total_profit, 2); ?></div>
                    <div class="stat-label">Total Profit/Loss</div>
                </div>
            </div>
            
<div class="filters">
    <form method="GET" id="filterForm" class="filter-form">
        <div class="filter-group">
            <label class="filter-label">Status</label>
            <select name="status" id="statusFilter">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="won" <?php echo $status_filter === 'won' ? 'selected' : ''; ?>>Won</option>
                <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>>Lost</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Game</label>
            <select name="game" id="gameFilter">
                <option value="all" <?php echo $game_filter === 'all' ? 'selected' : ''; ?>>All Games</option>
                <?php
                // Get unique games from all user bets (not just current page)
                $all_games_sql = "SELECT DISTINCT game_name FROM bets WHERE user_id = ? ORDER BY game_name";
                if ($stmt = $conn->prepare($all_games_sql)) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $selected = $game_filter === $row['game_name'] ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($row['game_name']) . '" ' . $selected . '>' . htmlspecialchars($row['game_name']) . '</option>';
                    }
                    $stmt->close();
                }
                ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Game Type</label>
            <select name="type" id="typeFilter">
                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                <?php
                // Get unique game types from all user bets
                $all_types_sql = "SELECT DISTINCT game_type FROM bets WHERE user_id = ? ORDER BY game_type";
                if ($stmt = $conn->prepare($all_types_sql)) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $display_type = isset($game_type_map[$row['game_type']]) ? $game_type_map[$row['game_type']] : ucfirst(str_replace('_', ' ', $row['game_type']));
                        $selected = $type_filter === $row['game_type'] ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($row['game_type']) . '" ' . $selected . '>' . htmlspecialchars($display_type) . '</option>';
                    }
                    $stmt->close();
                }
                ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Date From</label>
            <input type="date" name="date_from" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        
        <div class="filter-group">
            <label class="filter-label">Date To</label>
            <input type="date" name="date_to" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        
        <div class="filter-group">
            <label class="filter-label">&nbsp;</label>
            <button type="submit" class="filter-btn">Apply Filters</button>
        </div>
    </form>
</div>
            
            <?php if (count($user_bets) > 0): ?>
             <table class="bets-table">
    <thead>
        <tr>
            <th>Date & Time</th>
            <th>Game</th>
            <th>Type</th>
            <th>Mode</th>
            <th>Open Time</th>
            <th>Close Time</th>
            <th>Numbers Played</th>
            <th>Bet Amount</th>
            <th>Potential Win</th>
            <th>Status</th>
            <th>Result</th>
            <th>P/L</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($user_bets as $bet): ?>
      <tr>
    <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
    <td><?php echo htmlspecialchars($bet['game_name']); ?></td>
    <td>
        <?php 
        // Convert game_type code to readable format
        $game_type_map = [
            'single_ank' => 'Single Ank', 
            'jodi' => 'Jodi',
            'single_patti' => 'Single Patti',
            'double_patti' => 'Double Patti',
            'triple_patti' => 'Triple Patti',
            'half_sangam' => 'Half Sangam',
            'full_sangam' => 'Full Sangam',
            'sp_motor' => 'SP Motor',
            'dp_motor' => 'DP Motor',
            'red_bracket' => 'Red Bracket',
            'digital_jodi' => 'Digital Jodi',
            'choice_pana' => 'Choice Pana',
            'group_jodi' => 'Group Jodi',
            'abr_100' => 'ABR 100',
            'abr_cut' => 'ABR Cut'
        ];
        echo isset($game_type_map[$bet['game_type']]) ? $game_type_map[$bet['game_type']] : ucfirst(str_replace('_', ' ', $bet['game_type']));
        ?>
    </td>
    <td><?php echo ucfirst($bet['bet_mode']); ?></td>
    <td><?php echo htmlspecialchars($bet['open_time']); ?></td>
    <td>
        <?php 
        if (!empty($bet['close_time'])) {
            echo htmlspecialchars($bet['close_time']);
        } else {
            echo 'N/A';
        }
        ?>
    </td>
        <td>
            <?php 
            echo formatNumbers($bet['game_type'], $bet['numbers_played'], isset($bet['result_value']) ? $bet['result_value'] : null);
            ?>
        </td>
    <td>₹<?php echo number_format($bet['amount'], 2); ?></td>
    <td>₹<?php echo number_format($bet['potential_win'], 2); ?></td>
    <td>
        <span class="status-badge status-<?php echo $bet['status']; ?>">
            <?php echo ucfirst($bet['status']); ?>
        </span>
    </td>
    <td>
        <?php if (!empty($bet['result_value'])): ?>
            <?php echo htmlspecialchars($bet['result_value']); ?>
            <br>
            <small><?php echo date('M j, g:i A', strtotime($bet['declared_at'])); ?></small>
        <?php else: ?>
            N/A
        <?php endif; ?>
    </td>
    <td>
        <?php if ($bet['status'] === 'won'): ?>
            <span style="color: var(--success)">+$<?php echo number_format($bet['profit_loss'], 2); ?></span>
        <?php elseif ($bet['status'] === 'lost'): ?>
            <span style="color: var(--danger)">-$<?php echo number_format(abs($bet['profit_loss']), 2); ?></span>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>
</tr>
        <?php endforeach; ?>
    </tbody>
</table>
                
              <?php if (count($user_bets) > 0 && $total_pages > 1): ?>
<div class="pagination-container">
    <div class="pagination-info">
        Showing <?php echo count($user_bets); ?> of <?php echo $total_bets_count; ?> bets
        (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
    </div>
    
    <ul class="pagination">
        <!-- Previous Page -->
        <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
            <?php if ($current_page > 1): ?>
                <a class="page-link" href="<?php echo buildPaginationUrl($current_page - 1); ?>" aria-label="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="page-link disabled">
                    <i class="fas fa-chevron-left"></i>
                </span>
            <?php endif; ?>
        </li>

        <!-- Page Numbers -->
        <?php
        // Show first page
        if ($current_page > 3): ?>
        <li class="page-item">
            <a class="page-link" href="<?php echo buildPaginationUrl(1); ?>">1</a>
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
            <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>"><?php echo $i; ?></a>
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
            <a class="page-link" href="<?php echo buildPaginationUrl($total_pages); ?>"><?php echo $total_pages; ?></a>
        </li>
        <?php endif; ?>

        <!-- Next Page -->
        <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
            <?php if ($current_page < $total_pages): ?>
                <a class="page-link" href="<?php echo buildPaginationUrl($current_page + 1); ?>" aria-label="Next">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="page-link disabled">
                    <i class="fas fa-chevron-right"></i>
                </span>
            <?php endif; ?>
        </li>
    </ul>
</div>
<?php endif; ?>
            <?php else: ?>
                <div class="no-bets">
                    <i class="fas fa-ticket-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <h3>No bets found</h3>
                    <p>You haven't placed any bets yet. Start betting to see your history here.</p>
                    <a href="index.php" class="btn-register" style="display: inline-block; margin-top: 1rem;">Place Your First Bet</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <div class="footer-content">
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook-f"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
            
            <div class="footer-links">
                <a href="terms.php">Terms of Service</a>
                <a href="privacy.php">Privacy Policy</a>
                <a href="responsible.php">Responsible Gaming</a>
                <a href="help.php">Help Center</a>
                <a href="contact.php">Contact Us</a>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2023 RB Games. All rights reserved. Must be 18+ to play.</p>
        </div>
    </footer>
    <script src="includes/script.js"></script>

    <script>

        
      
        // Simple filtering functionality
document.addEventListener('DOMContentLoaded', function() {
    const statusFilter = document.getElementById('statusFilter');
    const gameFilter = document.getElementById('gameFilter');
    const typeFilter = document.getElementById('typeFilter');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const tableRows = document.querySelectorAll('.bets-table tbody tr');
    
    function filterBets() {
        const statusValue = statusFilter.value;
        const gameValue = gameFilter.value;
        const typeValue = typeFilter.value;
        const dateFromValue = dateFrom.value ? new Date(dateFrom.value) : null;
        const dateToValue = dateTo.value ? new Date(dateTo.value) : null;
        
        tableRows.forEach(row => {
            const status = row.querySelector('.status-badge').textContent.toLowerCase();
            const game = row.cells[1].textContent;
            const type = row.cells[2].textContent.toLowerCase();
            const dateStr = row.cells[0].textContent;
            const date = new Date(dateStr);
            
            let statusMatch = statusValue === 'all' || status === statusValue;
            let gameMatch = gameValue === 'all' || game === gameValue;
            let typeMatch = typeValue === 'all' || type.toLowerCase().includes(typeValue.toLowerCase());
            let dateMatch = true;
            
            if (dateFromValue && date < dateFromValue) dateMatch = false;
            if (dateToValue && date > dateToValue) dateMatch = false;
            
            if (statusMatch && gameMatch && typeMatch && dateMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    statusFilter.addEventListener('change', filterBets);
    gameFilter.addEventListener('change', filterBets);
    typeFilter.addEventListener('change', filterBets);
    dateFrom.addEventListener('change', filterBets);
    dateTo.addEventListener('change', filterBets);
});
        document.addEventListener('DOMContentLoaded', function() {
    // Reset filters functionality
    document.getElementById('resetFilters').addEventListener('click', function() {
        window.location.href = 'my_bets.php';
    });

    // Auto-submit form when filters change (optional)
    const filters = ['statusFilter', 'gameFilter', 'typeFilter', 'dateFrom', 'dateTo'];
    filters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        }
    });

    // Keyboard navigation for pagination
    document.addEventListener('keydown', function(e) {
        const currentPage = <?php echo $current_page; ?>;
        const totalPages = <?php echo $total_pages; ?>;
        
        if (e.key === 'ArrowLeft' && currentPage > 1) {
            window.location.href = '<?php echo buildPaginationUrl($current_page - 1); ?>';
        } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
            window.location.href = '<?php echo buildPaginationUrl($current_page + 1); ?>';
        }
    });
});
    </script>
</body>
</html>