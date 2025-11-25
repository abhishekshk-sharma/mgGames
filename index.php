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

$today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

$stmt = $conn->prepare("SELECT bl.*, a.id as adminId FROM broker_limit bl 
JOIN admins a ON bl.admin_id = a.id 
JOIN users u ON u.referral_code = a.referral_code   

WHERE u.id = ? ");

$todayString = "%".$today."%";

$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$broker_limit_result = $stmt->get_result();
$broker_limit = $broker_limit_result->fetch_assoc();


$stmt = $conn->prepare("SELECT bl.*, a.id as adminId FROM broker_limit bl 
JOIN admins a ON bl.admin_id = a.id 
JOIN users u ON u.referral_code = a.referral_code   
JOIN admin_game_sessions ags ON ags.admin_id = a.id
WHERE u.id = ? AND ags.created_at LIKE ?");

$todayString = "%".$today."%";

$stmt->bind_param("is", $_SESSION['user_id'], $todayString);
$stmt->execute();
$checkbroker_limit_result = $stmt->get_result();
$checkbroker_limit = $checkbroker_limit_result->fetch_assoc();
    
if(!$checkbroker_limit) {

    $stmt = $conn->prepare("INSERT INTO admin_game_sessions (admin_id, deposit_limit, withdrawal_limit, bet_limit, pnl_ratio) VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("iiiis", $broker_limit['adminId'], $broker_limit['deposit_limit'], $broker_limit['withdrawal_limit'], $broker_limit['bet_limit'], $broker_limit['pnl_ratio']);
    $stmt->execute();

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

// Fetch all games from database with images
$games_data = [];
$sql_games = "SELECT id, name, open_time, close_time, description, dynamic_images FROM games WHERE status = 'active'";
if ($result = $conn->query($sql_games)) {
    while ($row = $result->fetch_assoc()) {
        $games_data[$row['name']] = [
            'id' => $row['id'],
            'openTime' => date('H:i', strtotime($row['open_time'])),
            'closeTime' => date('H:i', strtotime($row['close_time'])),
            'description' => $row['description'],
            'image' => $row['dynamic_images'] ? $row['dynamic_images'] : 'uploads/imgs/default-game.jpg'
        ];
    }
    $result->free();
}

// Fetch game types for bet options
$game_types = [];
$sql_types = "SELECT id, name, code, payout_ratio FROM game_types WHERE status = 'active'";
if ($result = $conn->query($sql_types)) {
    while ($row = $result->fetch_assoc()) {
        $game_types[] = $row;
    }
    $result->free();
}

// Function to get game status based on current time
function getGameStatus($open_time, $close_time) {
    $current_time = date('H:i:s');
    $current_timestamp = strtotime($current_time);
    $open_timestamp = strtotime($open_time);
    $close_timestamp = strtotime($close_time);
    
    if ($current_timestamp < $open_timestamp) {
        return ['status' => 'coming', 'text' => 'Coming Soon', 'class' => 'status-coming'];
    } elseif ($current_timestamp >= $open_timestamp && $current_timestamp <= $close_timestamp) {
        return ['status' => 'open', 'text' => 'Time Running', 'class' => 'status-open'];
    } else {
        return ['status' => 'closed', 'text' => 'Closed', 'class' => 'status-closed'];
    }
}

// Function to check if game is playable
function isGamePlayable($open_time, $close_time) {
    $status = getGameStatus($open_time, $close_time);
    return $status['status'] === 'open' || $status['status'] === 'coming';
}

// Fetch all games with proper time format, status, and images
$games_data = [];
$sql_games = "SELECT * FROM games WHERE status = 'active'";
if ($result = $conn->query($sql_games)) {
    while ($row = $result->fetch_assoc()) {
        $game_status = getGameStatus($row['open_time'], $row['close_time']);
        $games_data[$row['name']] = [
            'id' => $row['id'],
            'openTime' => date('H:i', strtotime($row['open_time'])),
            'closeTime' => date('H:i', strtotime($row['close_time'])),
            'openTimeFull' => $row['open_time'],
            'closeTimeFull' => $row['close_time'],
            'description' => $row['description'],
            'image' => $row['dynamic_images'] ?  $row['dynamic_images'] : 'uploads/imgs/default-game.jpg',
            'status' => $game_status['status'],
            'statusText' => $game_status['text'],
            'statusClass' => $game_status['class'],
            'image' => $row['dynamic_images'],
            'isPlayable' => isGamePlayable($row['open_time'], $row['close_time'])
        ];
    }
    $result->free();
} else {
    // Fallback if query fails
    $games_data = [
        'Kalyan Matka' => [
            'id' => 1,
            'openTime' => '09:30',
            'closeTime' => '11:30',
            'status' => 'closed',
            'statusText' => 'Closed',
            'statusClass' => 'status-closed',
            'isPlayable' => false,
            'image' => 'uploads/imgs/default-game.jpg'
        ],
        'Mumbai Main' => [
            'id' => 2,
            'openTime' => '12:00',
            'closeTime' => '14:00',
            'status' => 'closed',
            'statusText' => 'Closed',
            'statusClass' => 'status-closed',
            'isPlayable' => false,
            'image' => 'uploads/imgs/default-game.jpg'
        ]
    ];
}

// DEBUG: Check if data is populated correctly
error_log("Games Data: " . print_r($games_data, true));
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
                --primary: #ddaa11ff;
                --secondary: #0fb4c9ff;
                --accent: #c0c0c0;
                --dark: #1d1d1dff;
                --light: #fff8dc;
                --success: #32cd32;
                --warning: #ffbf00ff;
                --danger: #ff4500;
                --card-bg: rgba(230, 227, 227, 0.95);
                --header-bg: rgba(255, 255, 255, 0.98);
                --gradient-primary: linear-gradient(135deg, #b09707ff 0%, #ffed4e 100%);
                --gradient-secondary: linear-gradient(135deg, #000000 0%, #2c2c2c 100%);
                --gradient-accent: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
                --gradient-dark: linear-gradient(135deg, #2e2e2dff 0%, rgba(33, 33, 33, 1) 100%);
                --gradient-premium: linear-gradient(135deg, #ffd700 0%,rgba(16, 16, 15, 1)100%);
                --card-shadow: 0 12px 40px rgba(255, 215, 0, 0.15);
                --glow-effect: 0 0 25px rgba(255, 215, 0, 0.3);
                --glow-blue: 0 0 25px rgba(0, 0, 0, 0.3);
                --border-radius: 16px;
            }
        body {
                background: var(--gradient-dark);
                color: var(--dark);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                overflow-x: hidden;
                position: relative;
            }
            /* Animated Background Elements */
            .bg-elements {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: -1;
                overflow: hidden;
            }

            .bg-element {
                position: absolute;
                border-radius: 50%;
                background: radial-gradient(circle, var(--primary) 0%, transparent 70%);
                opacity: 0.08;
                animation: float 20s infinite linear;
            }

            .bg-element:nth-child(1) {
                width: 300px;
                height: 300px;
                top: 10%;
                left: 10%;
                animation-delay: 0s;
            }

            .bg-element:nth-child(2) {
                width: 200px;
                height: 200px;
                top: 60%;
                right: 10%;
                animation-delay: -5s;
                background: radial-gradient(circle, var(--secondary) 0%, transparent 70%);
            }

            .bg-element:nth-child(3) {
                width: 150px;
                height: 150px;
                bottom: 20%;
                left: 20%;
                animation-delay: -10s;
                background: radial-gradient(circle, var(--accent) 0%, transparent 70%);
            }

            @keyframes float {
                0%, 100% {
                    transform: translateY(0) rotate(0deg);
                }
                25% {
                    transform: translateY(-20px) rotate(90deg);
                }
                50% {
                    transform: translateY(0) rotate(180deg);
                }
                75% {
                    transform: translateY(20px) rotate(270deg);
                }
            }

        
            /* Main Content */
            main {
                flex: 1;
                margin-top: 90px;
                padding: 2.5rem;
                max-width: 1800px;
                margin-left: auto;
                margin-right: auto;
                width: 100%;
            }
    /* Premium Banner Slider - Reduced Image Shining */
    .banner-slider {
        position: relative;
        height: 550px;
        border-radius:5px;
        overflow: hidden;
        margin-bottom: 3rem;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        background: var(--gradient-dark);
    }

    .slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 0.8s ease;
        display: flex;
        align-items: center;
        padding: 0 8%;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        transform: scale(1.05);
        transition: all 1s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .slide.active {
        opacity: 1;
        transform: scale(1);
    }

    /* Balanced Corner Overlays */
    .slide::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: 
            /* Top left dark corner */
            radial-gradient(circle at 0% 0%, rgba(0, 0, 0, 0.5) 0%, transparent 30%),
            /* Top right dark corner */
            radial-gradient(circle at 100% 0%, rgba(0, 0, 0, 0.5) 0%, transparent 30%),
            /* Bottom left dark corner */
            radial-gradient(circle at 0% 100%, rgba(0, 0, 0, 0.5) 0%, transparent 30%),
            /* Bottom right dark corner */
            radial-gradient(circle at 100% 100%, rgba(0, 0, 0, 0.5) 0%, transparent 30%),
            /* Left side gradient */
            linear-gradient(90deg, rgba(0, 0, 0, 0.4) 0%, transparent 60%),
            /* Right side gradient */
            linear-gradient(270deg, rgba(0, 0, 0, 0.4) 0%, transparent 60%),
            /* Bottom gradient */
            linear-gradient(0deg, rgba(0, 0, 0, 0.3) 0%, transparent 60%);
        z-index: 1;
    }

    /* Reduced shining effect on images */
    .slide:nth-child(1) {
        background-image: url('uploads/imgs/img1.jpg');
        filter: saturate(1.1) contrast(1.1) brightness(1.05);
        animation: slideZoom1 20s infinite alternate;
    }

    .slide:nth-child(2) {
        background-image: url('uploads/imgs/img8.jpg');
        filter: saturate(1.05) contrast(1.15) brightness(1.02);
        animation: slideZoom2 20s infinite alternate;
    }


    .slide:nth-child(3) {
        background-image: url('uploads/imgs/img10.jpg');
        filter: saturate(1.08) contrast(1.1) brightness(1.03);
        animation: slideZoom4 20s infinite alternate;
    }

    /* Subtle zoom animations without brightness changes */
    @keyframes slideZoom1 {
        0% { transform: scale(1.05); }
        100% { transform: scale(1.08); }
    }

    @keyframes slideZoom2 {
        0% { transform: scale(1.05); }
        100% { transform: scale(1.1); }
    }

    @keyframes slideZoom3 {
        0% { transform: scale(1.05); }
        100% { transform: scale(1.07); }
    }

    @keyframes slideZoom4 {
        0% { transform: scale(1.05); }
        100% { transform: scale(1.09); }
    }

    .slide.active {
        animation-play-state: running;
    }

    .slide:not(.active) {
        animation-play-state: paused;
    }

    /* Rest of your original content CSS remains unchanged */
    .slide-content {
        max-width: 650px;
        z-index: 2;
        position: relative;
        transform: translateX(-50px);
        opacity: 0;
        transition: all 0.8s ease 0.3s;
    }

    .slide.active .slide-content {
        transform: translateX(0);
        opacity: 1;
    }

    .slide-content-h2 {
        font-size: 4rem;
        margin-bottom: 1.5rem;
        color: #FFFFFF;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: -1px;
        text-shadow: 
            3px 3px 15px rgba(0, 0, 0, 0.7),
            0 0 30px rgba(255, 215, 0, 0.3);
    }

    .slide-content-h2 strong {
        background: linear-gradient(135deg, #FFD700 0%, #FFA500 50%, #D4AF37 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 900;
        text-shadow: none;
    }

    .slide p {
        font-size: 1.3rem;
        margin-bottom: 2.5rem;
        line-height: 1.6;
        color: rgba(255, 255, 255, 0.95);
        font-weight: 500;
        text-shadow: 2px 2px 8px rgba(0, 0, 0, 0.5);
        position: relative;
        padding-left: 1.5rem;
    }

    .slide p::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 80%;
        background: linear-gradient(135deg, #FFD700, #FFA500);
        border-radius: 2px;
        box-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
    }

    .play-btn {
        background: linear-gradient(135deg, #d6b603c2 0%, #d4af37e2 100%);
        color: #2a2a2a;
        border: none;
        padding: 18px 45px;
        font-size: 1.2rem;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        box-shadow: 
            0 8px 25px rgba(255, 215, 0, 0.4),
            0 0 0 2px rgba(255, 215, 0, 0.2);
        position: relative;
        overflow: hidden;
    }

    .play-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        transition: left 0.6s ease;
    }

    .play-btn:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FFD700 100%);
        transform: translateY(-4px);
        box-shadow: 
            0 12px 35px rgba(255, 215, 0, 0.6),
            0 0 0 2px rgba(255, 215, 0, 0.3),
            0 0 30px rgba(255, 215, 0, 0.4);
    }

    .play-btn:hover::before {
        left: 100%;
    }

    .play-btn::after {
        content: '🎯';
        font-size: 1.3rem;
        filter: drop-shadow(1px 1px 2px rgba(0, 0, 0, 0.3));
    }

    .slider-dots {
        position: absolute;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 15px;
        z-index: 3;
    }

    .dot {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.4);
        cursor: pointer;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        border: 2px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .dot::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #FFD700, #FFA500);
        transition: left 0.4s ease;
    }

    .dot.active {
        transform: scale(1.4);
        border-color: rgba(255, 215, 0, 0.8);
        box-shadow: 
            0 0 20px rgba(255, 215, 0, 0.6),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
    }

    .dot.active::before {
        left: 0;
    }

    .dot:hover:not(.active) {
        background: rgba(255, 215, 0, 0.6);
        transform: scale(1.2);
    }

    .banner-stats {
        position: absolute;
        top: 40px;
        right: 50px;
        z-index: 2;
        display: flex;
        gap: 2.5rem;
        opacity: 0;
        transform: translateX(50px);
        transition: all 0.8s ease 0.5s;
    }

    .slide.active .banner-stats {
        opacity: 1;
        transform: translateX(0);
    }

    .stat-item {
        text-align: center;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(15px);
        padding: 1.2rem 1.5rem;
        border-radius: 12px;
        border: 1px solid rgba(255, 215, 0, 0.3);
        box-shadow: 
            0 8px 25px rgba(0, 0, 0, 0.3),
            0 0 15px rgba(255, 215, 0, 0.2);
        transition: all 0.3s ease;
        min-width: 120px;
    }

    .stat-item:hover {
        transform: translateY(-5px);
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 
            0 12px 30px rgba(0, 0, 0, 0.4),
            0 0 20px rgba(255, 215, 0, 0.3);
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: #FFD700;
        margin-bottom: 0.3rem;
        text-shadow: 0 0 15px rgba(255, 215, 0, 0.5);
        font-family: 'Courier New', monospace;
    }

    .stat-label {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.9);
        text-transform: uppercase;
        letter-spacing: 1.5px;
        font-weight: 600;
        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
    }

    .slider-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 100%;
        display: flex;
        justify-content: space-between;
        padding: 0 2rem;
        z-index: 3;
    }

    .nav-arrow {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border: 2px solid rgba(255, 215, 0, 0.4);
        color: #FFD700;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 1.5rem;
        font-weight: bold;
    }

    .nav-arrow:hover {
        background: rgba(255, 215, 0, 0.2);
        transform: scale(1.1);
        box-shadow: 0 0 20px rgba(255, 215, 0, 0.4);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .banner-slider {
            height: 450px;
        }
        
        .slide-content-h2 {
            font-size: 2.8rem;
        }
        
        .slide p {
            font-size: 1.1rem;
        }
        
        .banner-stats {
            top: 25px;
            right: 25px;
            gap: 1.5rem;
        }
        
        .stat-item {
            padding: 1rem 1.2rem;
            min-width: 100px;
        }
        
        .stat-value {
            font-size: 1.5rem;
        }
        
        .play-btn {
            padding: 16px 35px;
            font-size: 1.1rem;
        }
        
        .slider-nav {
            padding: 0 1rem;
        }
        
        .nav-arrow {
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
        }
    }

    @media (max-width: 480px) {
        .banner-slider {
            height: 400px;
        }
        
        .slide-content-h2 {
            font-size: 2.2rem;
        }
        
        .slide p {
            font-size: 1rem;
            padding-left: 1rem;
        }
        
        .play-btn {
            padding: 14px 30px;
            font-size: 1rem;
        }
        
        .banner-stats {
            position: relative;
            top: auto;
            right: auto;
            justify-content: center;
            margin-top: 2rem;
            transform: none;
        }
        
        .slider-dots {
            bottom: 20px;
        }
        
        .dot {
            width: 12px;
            height: 12px;
        }
    }
            /* Premium Section Headers */
            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 3rem;
                position: relative;
            }

            .section-title {
                font-size: 2.5rem;
                position: relative;
                padding-bottom: 15px;
                font-weight: 800;
                color: var(--dark);
            }

            .section-title::after {
                content: '';
                position: absolute;
                bottom: 0;
                left: 0;
                width: 100px;
                height: 5px;
                background: var(--gradient-primary);
                border-radius: 3px;
                box-shadow: 0 0 10px var(--primary);
            }

        /* Premium Games Section - Vibrant Colorful Theme */
        .games-section {
            background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
            padding: 5rem 0;
            position: relative;
            overflow: hidden;
        }

        .games-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 20%, rgba(255, 107, 107, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(78, 205, 196, 0.05) 0%, transparent 50%);
            z-index: 0;
        }

        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            margin-bottom: 5rem;
            position: relative;
            z-index: 1;
        }

        .game-card {
            background: linear-gradient(145deg, #3a3a3a, #2a2a2a);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            border: 3px solid;
            box-shadow: 
                0 15px 35px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }


        .game-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: 1;
        }

        .game-card:hover {
            transform: translateY(-10px) scale(1.03);
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.7),
                0 0 40px currentColor;
        }

        .game-card:hover::before {
            opacity: 1;
        }

        .game-img {
            height: 280px;
            width: 100%;
            background: linear-gradient(135deg, var(--card-color, #667eea) 0%, var(--card-color-secondary, #764ba2) 100%);
            background-size: cover;
            background-position: center;
            position: relative;
            transition: all 0.4s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .game-card:hover .game-img {
            transform: scale(1.08);
            filter: brightness(1.1) saturate(1.2);
        }

        .game-img::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 60%;
            background: linear-gradient(to top, rgba(42, 42, 42, 0.95), transparent);
        }

        .game-icon {
            font-size: 5rem;
            color: white;
            z-index: 2;
            text-shadow: 
                0 0 30px rgba(0, 0, 0, 0.8),
                0 0 20px currentColor;
            transition: all 0.4s ease;
        }

        .game-card:hover .game-icon {
            transform: scale(1.15);
            filter: brightness(1.3);
        }

        .game-content {
            padding: 2rem;
            position: relative;
            z-index: 2;
        }

        .game-title {
            font-size: 1.5rem;
            margin-bottom: 0.8rem;
            font-weight: 800;
            color: #FFFFFF;
            text-align: center;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            position: relative;
        }


        .game-title::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: currentColor;
            border-radius: 2px;
            opacity: 0.7;
        }

        .game-desc {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.95rem;
            margin-bottom: 2rem;
            line-height: 1.6;
            text-align: center;
            font-weight: 400;
        }

        .game-btn {
            background: linear-gradient(135deg, currentColor, var(--btn-color-secondary));
            color: #1a1a1a;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            width: 100%;
            font-weight: 800;
            box-shadow: 
                0 6px 25px currentColor,
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            font-size: 1rem;
        }


        .game-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
            transition: left 0.6s ease;
        }

        .game-btn:hover {
            transform: translateY(-4px);
            box-shadow: 
                0 10px 30px currentColor,
                inset 0 1px 0 rgba(255, 255, 255, 0.4),
                0 0 20px currentColor;
            color: #1a1a1a;
        }

        .game-btn:hover::before {
            left: 100%;
        }

        /* Premium Satta Section - Colorful Theme */
        .satta-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 25px;
            position: relative;
            z-index: 1;
        }

        .satta-card {
            background: linear-gradient(145deg, #3a3a3a, #2a2a2a);
            border-radius:15px;
            overflow: hidden;
            width: 400px;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            border: 0.5px solid #FFD700;
            box-shadow: 
                0 15px 35px rgba(0, 0, 0, 0.5),
                0 0 25px rgba(255, 215, 0, 0.3);
            backdrop-filter: blur(10px);
        }

        .satta-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.7),
                0 0 35px rgba(255, 215, 0, 0.4);
        }

        .satta-img {
            height: 270px;
            width: 100%;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            background-size: cover;
            background-position: center;
            position: relative;
            transition: transform 0.4s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .satta-card:hover .satta-img {
            transform: scale(1.05);
            filter: brightness(1.1);
        }

        .satta-img::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 60%;
            background: linear-gradient(to top, rgba(42, 42, 42, 0.9), transparent);
        }

        .satta-header {
            padding: 1.1rem;
            background: rgba(255, 215, 0, 0.1);
            border-bottom: 1px solid rgba(255, 215, 0, 0.2);
            position: relative;
        }

        .satta-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #f4b70eff;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .satta-result {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 1.2rem;
            color: #FFD700;
            font-weight: 700;
            letter-spacing: 1px;
            font-family: 'Courier New', monospace;
        }

        .satta-body {
            padding: 1.8rem;
        }

        .satta-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .satta-info:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .satta-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .satta-value {
            font-weight: 700;
            color: #f4b70eff;
            font-size: 1.1rem;
        }

        .timer {
            font-family: 'Courier New', monospace;
            background: rgba(255, 149, 0, 0.11);
            padding: 8px 16px;
            border-radius: 10px;
            color: #fec815ff;
            font-weight: 700;
            border: 1px solid rgba(246, 196, 17, 0.49);
            font-size: 1.2rem;
        }

        /* Single Status Badge - Colorful Theme */
        .status-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            z-index: 2;
            backdrop-filter: blur(10px);
            border: 1px solid;
            display:none;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }



        /* Enhanced Section Headers */
        .section-header {
            text-align: center;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .section-title {
            font-size: 3.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, #FF6B6B, #4ECDC4, #FFD700, #9B59B6, #3498DB);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.0rem;
            text-shadow: 0 0 30px rgba(255, 215, 0, 0.3);
        }

        .section-subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.2rem;
            max-width: 600px;
            margin: 0 auto;
            font-weight: 400;
            line-height: 1.6;
        }


        /* Premium Footer - Betting Website */
    footer {
        background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%);
        padding: 4rem 2rem 2rem;
        margin-top: auto;
        border-top: 3px solid;
        border-image: linear-gradient(135deg, #FF6B6B, #4ECDC4, #FFD700, #9B59B6, #3498DB) 1;
        backdrop-filter: blur(20px);
        position: relative;
        overflow: hidden;
    }

    footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: 
            radial-gradient(circle at 20% 80%, rgba(255, 107, 107, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(78, 205, 196, 0.05) 0%, transparent 50%);
        z-index: 0;
    }

    .footer-container {
        max-width: 1600px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }

    .footer-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 3rem;
        flex-wrap: wrap;
        gap: 3rem;
    }

    .footer-brand {
        flex: 1;
        min-width: 300px;
    }

    .footer-logo {
        font-size: 2.5rem;
        font-weight: 900;
        background: linear-gradient(135deg, #FF6B6B, #4ECDC4, #FFD700, #9B59B6, #3498DB);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 1rem;
        text-shadow: 0 0 30px rgba(255, 215, 0, 0.3);
    }

    .footer-tagline {
        color: rgba(255, 255, 255, 0.7);
        font-size: 1.1rem;
        line-height: 1.6;
        max-width: 400px;
    }

    .footer-links-section {
        display: flex;
        gap: 4rem;
        flex-wrap: wrap;
    }

    .footer-links-group {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .footer-links-group h4 {
        color: #FFFFFF;
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 1rem;
        position: relative;
    }

    .footer-links-group h4::after {
        content: '';
        position: absolute;
        bottom: -8px;
        left: 0;
        width: 40px;
        height: 3px;
        background: linear-gradient(135deg, #FF6B6B, #4ECDC4);
        border-radius: 2px;
    }

    .footer-links {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
    }

    .footer-links a {
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        transition: all 0.3s ease;
        font-weight: 500;
        padding: 8px 12px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .footer-links a:hover {
        color: #FFFFFF;
        background: rgba(255, 255, 255, 0.1);
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .footer-links a::before {
        content: '▶';
        font-size: 0.7rem;
        color: #FFD700;
        transition: transform 0.3s ease;
    }

    .footer-links a:hover::before {
        transform: translateX(3px);
    }

    /* Social Links Section */
    .footer-social {
        flex: 1;
        min-width: 300px;
    }

    .footer-social h4 {
        color: #FFFFFF;
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
        text-align: center;
    }

    .social-links {
        display: flex;
        justify-content: center;
        gap: 1.5rem;
        flex-wrap: wrap;
    }

    .social-links a {
        color: #FFFFFF;
        font-size: 1.8rem;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
        overflow: hidden;
    }

    .social-links a::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.6s ease;
    }

    .social-links a:hover {
        transform: translateY(-8px) scale(1.1);
        box-shadow: 
            0 10px 25px rgba(0, 0, 0, 0.3),
            0 0 20px currentColor;
    }

    .social-links a:hover::before {
        left: 100%;
    }

    /* Social Platform Specific Colors */
    .social-links a:nth-child(1):hover { color: #1877F2; } /* Facebook */
    .social-links a:nth-child(2):hover { color: #1DA1F2; } /* Twitter */
    .social-links a:nth-child(3):hover { color: #E4405F; } /* Instagram */
    .social-links a:nth-child(4):hover { color: #25D366; } /* WhatsApp */
    .social-links a:nth-child(5):hover { color: #FF0000; } /* YouTube */

    /* Footer Bottom */
    .footer-bottom {
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        padding-top: 2rem;
        margin-top: 2rem;
    }

    .footer-disclaimer {
        text-align: center;
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.9rem;
        line-height: 1.6;
        margin-bottom: 1.5rem;
        max-width: 800px;
        margin-left: auto;
        margin-right: auto;
    }

    .copyright {
        text-align: center;
        color: rgba(255, 255, 255, 0.7);
        padding-top: 1.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 0.9rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .copyright-links {
        display: flex;
        gap: 2rem;
    }

    .copyright-links a {
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        transition: color 0.3s ease;
        font-size: 0.85rem;
    }

    .copyright-links a:hover {
        color: #FFD700;
    }

    /* Age Verification Badge */
    .age-verification {
        display: flex;
        align-items: center;
        gap: 1rem;
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.85rem;
    }

    .age-badge {
        background: linear-gradient(135deg, #FF6B6B, #E74C3C);
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 0.8rem;
        box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
    }

    /* Payment Methods */
    .payment-methods {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin: 2rem 0;
        flex-wrap: wrap;
    }

    .payment-method {
        width: 50px;
        height: 30px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: rgba(255, 255, 255, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .payment-method:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .footer-content {
            justify-content: center;
            text-align: center;
        }
        
        .footer-brand {
            text-align: center;
        }
        
        .footer-links-group h4::after {
            left: 50%;
            transform: translateX(-50%);
        }
    }

    @media (max-width: 768px) {
        footer {
            padding: 3rem 1.5rem 1.5rem;
        }
        
        .footer-content {
            flex-direction: column;
            align-items: center;
            gap: 2rem;
        }
        
        .footer-links-section {
            gap: 2rem;
            justify-content: center;
        }
        
        .footer-links-group {
            align-items: center;
        }
        
        .footer-links a {
            justify-content: center;
        }
        
        .copyright {
            flex-direction: column;
            text-align: center;
            gap: 1rem;
        }
        
        .copyright-links {
            justify-content: center;
        }
        
        .social-links a {
            width: 50px;
            height: 50px;
            font-size: 1.6rem;
        }
    }

    @media (max-width: 480px) {
        footer {
            padding: 2rem 1rem 1rem;
        }
        
        .footer-logo {
            font-size: 2rem;
        }
        
        .footer-links-section {
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .social-links {
            gap: 1rem;
        }
        
        .social-links a {
            width: 45px;
            height: 45px;
            font-size: 1.4rem;
        }
        
        .payment-methods {
            gap: 0.5rem;
        }
        
        .payment-method {
            width: 40px;
            height: 25px;
            font-size: 1rem;
        }
    }

    /* Dark Mode Support */
    @media (prefers-color-scheme: dark) {
        footer {
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
        }
    }

    /* Reduced Motion Support */
    @media (prefers-reduced-motion: reduce) {
        .footer-links a,
        .social-links a,
        .payment-method {
            transition: none;
        }
        
        .footer-links a::before,
        .social-links a::before {
            transition: none;
        }
    }
    /* Premium Modal Styles - Refined Golden Theme */
    .modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background: rgba(10, 10, 10, 0.85);
        backdrop-filter: blur(15px);
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-content {
        background: linear-gradient(145deg, #1a1a1a 0%, #2a2a2a 100%);
        margin: 3% auto;
        padding: 0;
        border-radius: 16px;
        width: 95%;
        max-width: 1000px;
        box-shadow: 
            0 20px 40px rgba(0, 0, 0, 0.8),
            0 0 0 1px rgba(255, 140, 0, 0.3),
            0 0 30px rgba(255, 140, 0, 0.2);
        animation: slideInUp 0.5s ease-out;
        position: relative;
        overflow: hidden;
        border: 0.5px solid #ffc400ff;
    }

    @keyframes slideInUp {
        from { 
            transform: translateY(50px) scale(0.98); 
            opacity: 0; 
        }
        to { 
            transform: translateY(0) scale(1); 
            opacity: 1; 
        }
    }

    .close-modal {
        position: absolute;
        top: 20px;
        right: 20px;
        color: #1a1a1a;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        z-index: 10;
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #eaff009e 0%, #FFd700 100%);
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 15px rgba(255, 140, 0, 0.4);
        border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .close-modal:hover {
        background: linear-gradient(135deg, #545353e4 0%, #3e3e3d78 100%);
        transform: rotate(90deg) scale(1.1);
        box-shadow: 0 6px 20px rgba(255, 140, 0, 0.6);
    }

    .modal-header {
        background: linear-gradient(135deg, #ff8c00b3 0%, #ffa600cb 50%, #FF8C00 100%);
        padding: 2.5rem 2.5rem 2rem;
        color: #1a1a1a;
        border-radius: 14px 14px 0 0;
        position: relative;
        overflow: hidden;
        border-bottom: 2px solid #FF8C00;
    }

    .modal-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    }

    .modal-game-title {
        font-size: 2.8rem;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: 800;
        color: #1a1a1a;
        position: relative;
        z-index: 2;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
    }

    .modal-game-time {
        font-size: 1.2rem;
        font-weight: 600;
        color: #1a1a1a;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        position: relative;
        z-index: 2;
        background: rgba(255, 255, 255, 0.15);
        padding: 6px 14px;
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-game-time::before {
        font-size: 1rem;
    }

    .modal-body {
        padding: 2.5rem;
        max-height: 65vh;
        overflow-y: auto;
        background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
        position: relative;
    }

    .modal-body::-webkit-scrollbar {
        width: 6px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 3px;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        border-radius: 3px;
    }

    .modal-body::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
    }

    /* Premium Bet Types Grid */
    .modal-bet-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-top: 2rem;
    }

    .modal-bet-card {
        background: linear-gradient(145deg, #2a2a2a, #1a1a1a);
        border-radius: 12px;
        padding: 2rem 1.5rem;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
        border: 0.5px solid #ffd700;
        position: relative;
        overflow: hidden;
        box-shadow: 
            0 8px 25px rgba(0, 0, 0, 0.4),
            0 0 0 1px rgba(255, 140, 0, 0.1);
    }

    .modal-bet-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 140, 0, 0.08), transparent);
        transition: left 0.5s ease;
    }

    .modal-bet-card:hover::before {
        left: 100%;
    }

    .modal-bet-card:hover {
        transform: translateY(-5px);
        box-shadow: 
            0 12px 30px rgba(0, 0, 0, 0.6),
            0 0 0 1px rgba(255, 140, 0, 0.3);
        border-color: #FFA500;
    }

    .modal-bet-icon {
        font-size: 3.5rem;
        margin-bottom: 1rem;
        display: block;
        transition: all 0.3s ease;
        position: relative;
        z-index: 2;
        color: #FFA500;
        text-shadow: 0 0 10px rgba(255, 140, 0, 0.3);
    }

    .modal-bet-card:hover .modal-bet-icon {
        transform: scale(1.1);
        color: #FF8C00;
    }

    .modal-bet-title {
        font-size: 1.4rem;
        margin-bottom: 0.8rem;
        font-weight: 700;
        font-family: "Space Grotesk",;
        transition: all 0.3s ease;
        position: relative;
        z-index: 2;
        color: #ffd700;
    }

    .modal-bet-card:hover .modal-bet-title {
        color: #FF8C00;
    }

    .modal-bet-desc {
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.7);
        margin-bottom: 1.5rem;
        line-height: 1.5;
        position: relative;
        z-index: 2;
        min-height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Professional Payout Section */
    .modal-bet-payout {
        font-size: 1rem;
        font-weight: 700;
        background: linear-gradient(135deg, rgba(255, 140, 0, 0.15) 0%, rgba(255, 140, 0, 0.05) 100%);
        padding: 10px 20px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid rgba(255, 140, 0, 0.3);
        margin-bottom: 1.5rem;
        position: relative;
        z-index: 2;
        color: #FFA500;
    }

    .payout-icon {
        font-size: 1rem;
    }

    .payout-value {
        font-family: 'Courier New', monospace;
        font-weight: 800;
        font-size: 1.1rem;
        color: #FF8C00;
    }

    .modal-bet-btn {
        background: linear-gradient(135deg, #ffd000f4 0%, #ffcc00fe 100%);
        color: #1a1a1a;
        border: none;
        padding: 14px 28px;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 700;
        width: 100%;
        box-shadow: 0 4px 15px rgba(255, 140, 0, 0.3);
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 1.23rem;
        font-weight: 700;
        position: relative;
        z-index: 2;
        border: 1px solid rgba(255, 255, 255, 0.2);
        overflow: hidden;
    }

    .modal-bet-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s ease;
    }

    .modal-bet-btn:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 140, 0, 0.4);
    }

    .modal-bet-btn:hover::before {
        left: 100%;
    }

    /* Section Title in Modal */
    .modal-body .section-title {
        font-size: 2.2rem;
        font-weight: 800;
        color: #FFA500;
        margin-bottom: 1.5rem;
        text-align: center;
        position: relative;
        padding-bottom: 12px;
    }

    .modal-body .section-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 3px;
        background: linear-gradient(135deg, #FF8C00, #FFA500);
        border-radius: 2px;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .modal {
            padding: 10px;
        }
        
        .modal-content {
            margin: 2% auto;
            width: 98%;
            border-radius: 12px;
        }
        
        .modal-header {
            padding: 2rem 1.5rem 1.5rem;
        }
        
        .modal-game-title {
            font-size: 2rem;
            letter-spacing: 1px;
        }
        
        .modal-game-time {
            font-size: 1rem;
            padding: 5px 12px;
        }
        
        .modal-body {
            padding: 2rem 1.5rem;
            max-height: 70vh;
        }
        
        .modal-bet-grid {
            grid-template-columns: 1fr;
            gap: 1.2rem;
            margin-top: 1.5rem;
        }
        
        .modal-bet-card {
            padding: 1.5rem 1.2rem;
        }
        
        .modal-bet-icon {
            font-size: 3rem;
            margin-bottom: 0.8rem;
        }
        
        .modal-bet-title {
            font-size: 1.2rem;
            margin-bottom: 0.6rem;
        }
        
        .modal-bet-desc {
            font-size: 0.85rem;
            margin-bottom: 1.2rem;
            min-height: 35px;
        }
        
        .modal-bet-payout {
            font-size: 0.7rem;
            padding: 4px 10px;
            margin-bottom: 1.2rem;
        }
        
        .payout-value {
            font-size: 1rem;
        }
        
        .close-modal {
            width: 40px;
            height: 40px;
            font-size: 20px;
            top: 15px;
            right: 15px;
        }
        
        .modal-body .section-title {
            font-size: 1.8rem;
            padding-bottom: 10px;
        }
        
        .modal-body .section-title::after {
            width: 60px;
            height: 2px;
        }
        
        .modal-bet-btn {
            padding: 10px 15px;
            font-size: 0.65rem;
        }
    }

    @media (max-width: 480px) {
        .modal-header {
            padding: 1.5rem 1rem 1rem;
        }
        
        .modal-game-title {
            font-size: 1.6rem;
        }
        
        .modal-body {
            padding: 1.5rem 1rem;
        }
        
        .modal-bet-card {
            padding: 1.2rem 1rem;
        }
        
        .modal-bet-icon {
            font-size: 2.5rem;
        }
        
        .modal-bet-title {
            font-size: 1.1rem;
        }
        
        .modal-bet-desc {
            font-size: 0.8rem;
            min-height: 32px;
        }
        
        .modal-bet-payout {
            font-size: 0.7rem;
            padding: 4px 10px;
        }
        
        .payout-value {
            font-size: 0.9rem;
        }
        
        .modal-bet-btn {
            padding: 5px 15px;
            font-size: 0.65rem;
        }
        
        .modal-body .section-title {
            font-size: 1.5rem;
        }
    }

    /* Small Mobile Devices */
    @media (max-width: 360px) {
        .modal-header {
            padding: 1.2rem 0.8rem 0.8rem;
        }
        
        .modal-game-title {
            font-size: 1.4rem;
        }
        
        .modal-body {
            padding: 1.2rem 0.8rem;
        }
        
        .modal-bet-card {
            padding: 1rem 0.8rem;
        }
        
        .modal-bet-grid {
            gap: 1rem;
        }
    }

    /* Landscape Mobile Optimization */
    @media (max-width: 768px) and (orientation: landscape) {
        .modal-content {
            margin: 1% auto;
            max-height: 98vh;
        }
        
        .modal-body {
            max-height: 55vh;
            padding: 1.5rem;
        }
        
        .modal-bet-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .modal-bet-card {
            padding: 1.2rem 1rem;
        }
    }

    /* Touch Device Optimizations */
    @media (hover: none) and (pointer: coarse) {
        .modal-bet-card:hover {
            transform: none;
        }
        
        .modal-bet-card:active {
            transform: scale(0.98);
        }
        
        .modal-bet-btn:hover {
            transform: none;
        }
        
        .modal-bet-btn:active {
            transform: scale(0.95);
        }
    }

    /* Reduced Motion Support */
    @media (prefers-reduced-motion: reduce) {
        .modal,
        .modal-content,
        .modal-bet-card,
        .modal-bet-btn {
            animation: none;
            transition: none;
        }
    }
        </style>

    <!-- cards responsive -->
    <style>
        /* Enhanced Mobile Responsiveness for Cards & Modals */
        
        /* Base Mobile Responsive Grid */
        @media (max-width: 1200px) {
            .games-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 25px;
            }
            
            .satta-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 25px;
            }
            
            .modal-bet-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
        }

        @media (max-width: 992px) {
            .games-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .satta-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .modal-bet-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.2rem;
            }
            
            .game-card, .satta-card {
                margin-bottom: 15px;
            }
            
            .section-title {
                font-size: 2.2rem;
            }
        }

        @media (max-width: 768px) {
            .games-grid {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-bottom: 3rem;
            }
            
            .satta-grid {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-bottom: 3rem;
            }
            
            /* MODAL GAMES - 2 COLUMNS FOR MOBILE */
            .modal-bet-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .modal-bet-card {
                min-height: 120px;
                padding: 1rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .modal-bet-icon {
                font-size: 1.8rem;
                margin-bottom: 0.6rem;
            }
            
            .modal-bet-title {
                font-size: 0.95rem;
                margin-bottom: 0.4rem;
                line-height: 1.2;
            }
            
            .modal-bet-desc {
                font-size: 0.75rem;
                margin-bottom: 0.8rem;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            
            .modal-bet-btn {
                padding: 4px 8px;
                font-size: 1rem;
                width: 88%;
                margin-top: auto;
            }
            
            /* Enhanced Mobile Card Layout */
            .game-card, .satta-card {
                min-height: 120px;
                margin-bottom: 12px;
                border-radius: 12px;
            }
            
            /* Mobile Game Cards - Horizontal Layout */
            .game-card {
                display: flex;
                flex-direction: row;
                height: auto;
                padding: 0;
                overflow: hidden;
            }
            
            .game-img {
                width: 120px;
                height: 120px;
                min-width: 120px;
                border-radius: 12px 0 0 12px;
            }
            
            .game-img::after {
                border-radius: 12px 0 0 12px;
            }
            
            .game-content {
                padding: 1rem;
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            
            .game-title {
                font-size: 1.1rem !important;
                margin-bottom: 0.5rem;
                line-height: 1.3;
            }
            
            .game-desc {
                font-size: 0.85rem;
                margin-bottom: 1rem;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            
            .game-btn {
                padding: 8px 16px;
                font-size: 0.85rem !important;
                width: auto;
                align-self: flex-start;
                min-width: 100px;
            }
            
            /* Mobile Satta Cards - Compact Layout */
            .satta-card {
                display: flex;
                flex-direction: row;
                height: auto;
                padding: 0;
            }
            
            .satta-img {
                display: none;
            }
            
            .satta-header {
                flex: 0 0 140px;
                padding: 1rem;
                display: flex;
                flex-direction: column;
                justify-content: center;
                border-right: 2px solid rgba(255, 255, 255, 0.15);
                border-bottom: none;
            }
            
            .satta-title {
                font-size: 1rem !important;
                margin-bottom: 0.4rem;
                line-height: 1.2;
            }
            
            .satta-result {
                font-size: 0.9rem !important;
                letter-spacing: 1px;
            }
            
            .satta-body {
                flex: 1;
                padding: 1rem;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            
            .satta-info {
                margin-bottom: 0.6rem;
                padding-bottom: 0.5rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .satta-label {
                font-size: 0.8rem;
                min-width: 70px;
            }
            
            .satta-value {
                font-size: 0.9rem !important;
                text-align: right;
            }
            
            .timer {
                font-size: 0.85rem !important;
                padding: 5px 10px;
            }
            
            /* Mobile Status Badges */
            .status-badge {
                top: 10px;
                right: 10px;
                font-size: 0.6rem !important;
                padding: 4px 10px;
            }
            
            /* Section Headers Mobile */
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin-bottom: 2rem;
            }
            
            .section-title {
                font-size: 1.8rem;
                padding-bottom: 8px;
            }
            
            .section-title::after {
                width: 60px;
                height: 3px;
            }
            
            .view-all {
                align-self: flex-end;
                padding: 8px 16px;
                font-size: 0.9rem;
            }
            
            /* Main Content Mobile */
            main {
                padding: 1.5rem 1rem;
                margin-top: 70px;
            }
            
            /* Banner Slider Mobile */
            .banner-slider {
                height: 300px;
                margin-bottom: 2.5rem;
                border-radius: 12px;
            }
            
            .slide h2 {
                font-size: 2rem;
                margin-bottom: 1rem;
            }
            
            .slide p {
                font-size: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .play-btn {
                padding: 12px 25px;
                font-size: 1rem;
            }
            
            .slider-dots {
                bottom: 15px;
            }
            
            .dot {
                width: 10px;
                height: 10px;
            }
            
            /* Enhanced Mobile Modal Responsiveness */
            .modal-content {
                width: 95%;
                margin: 2% auto;
                border-radius: 12px;
            }
            
            .modal-header {
                padding: 1.2rem;
            }
            
            .modal-game-title {
                font-size: 1.5rem;
            }
            
            .modal-game-time {
                font-size: 1rem;
            }
            
            .modal-body {
                padding: 1.2rem;
                max-height: 80vh;
                overflow-y: auto;
            }
            
            .close-modal {
                width: 35px;
                height: 35px;
                font-size: 24px;
                top: 10px;
                right: 15px;
            }
        }

        @media (max-width: 576px) {
            /* Extra Small Mobile Devices */
            .games-grid,
            .satta-grid {
                gap: 3px;
            }
            
            /* MODAL GAMES - ADJUSTED FOR SMALLER SCREENS */
            .modal-bet-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.8rem;
            }
            
            .modal-bet-card {
                min-height: 110px;
                padding: 0.8rem;
            }
            
            .modal-bet-icon {
                font-size: 1.6rem;
                margin-bottom: 0.5rem;
            }
            
            .modal-bet-title {
                font-size: 1.3rem;
            }
            
            .modal-bet-desc {
                font-size: 0.7rem;
                -webkit-line-clamp: 2;
            }
            
            .modal-bet-btn {
                padding: 2px 4px;
                font-size: 1rem;
            }
            
            .game-card,
            .satta-card {
                min-height: 110px;
                border-radius: 10px;
            }
            
            .game-img {
                width: 100px;
                height: 100px;
                min-width: 100px;
            }
            
            .game-content {
                padding: 0.8rem;
            }
            
            .game-title {
                font-size: 1rem !important;
            }
            
            .game-desc {
                font-size: 0.8rem;
                -webkit-line-clamp: 2;
            }
            
            .game-btn {
                padding: 6px 12px;
                font-size: 0.8rem !important;
                min-width: 90px;
            }
            
            .satta-header {
                flex: 0 0 120px;
                padding: 0.8rem;
            }
            
            .satta-body {
                padding: 0.8rem;
            }
            
            .satta-title {
                font-size: 0.95rem !important;
            }
            
            .satta-info {
                margin-bottom: 0.5rem;
                padding-bottom: 0.2rem;
            }
            
            .satta-label {
                font-size: 0.75rem;
                min-width: 60px;
            }
            
            .satta-value {
                font-size: 0.85rem !important;
            }
            
            .section-title {
                font-size: 1.6rem;
            }
            
            .banner-slider {
                height: 250px;
            }
            
            .slide h2 {
                font-size: 1.8rem;
            }
            
            main {
                padding: 1rem 0.8rem;
            }
        }

        @media (max-width: 400px) {
            /* Very Small Mobile Devices */
            /* MODAL GAMES - COMPACT 2 COLUMN LAYOUT */
            .modal-bet-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.6rem;
            }
            
            .modal-bet-card {
                min-height: 100px;
                padding: 0.7rem;
            }
            
            .modal-bet-icon {
                font-size: 1.4rem;
                margin-bottom: 0.4rem;
            }
            
            .modal-bet-title {
                font-size: 1rem;
                margin-bottom: 0.3rem;
            }
            
            .modal-bet-desc {
                font-size: 0.65rem;
                -webkit-line-clamp: 2;
                margin-bottom: 0.6rem;
            }
            
            .modal-bet-btn {
                padding: 1px 4px;
                font-size: 0.8rem;
            }
            
            .game-img {
                width: 90px;
                height: 90px;
                min-width: 90px;
            }
            
            .game-content {
                padding: 0.7rem;
            }
            
            .satta-header {
                flex: 0 0 110px;
                padding: 0.7rem;
            }
            
            .satta-body {
                padding: 0.7rem;
            }
            
            .game-title,
            .satta-title {
                font-size: 0.95rem !important;
            }
            
            .game-desc {
                font-size: 0.75rem;
                -webkit-line-clamp: 2;
            }
        }

        /* Touch Device Optimizations */
        @media (hover: none) and (pointer: coarse) {
            .game-card:hover,
            .satta-card:hover,
            .modal-bet-card:hover {
                transform: none !important;
            }
            
            .game-card:active,
            .satta-card:active,
            .modal-bet-card:active {
                transform: scale(0.98) !important;
                transition: transform 0.1s ease;
            }
            
            .game-btn:active,
            .play-btn:active,
            .modal-bet-btn:active {
                transform: scale(0.95) !important;
            }
            
            /* Larger touch targets */
            .game-btn,
            .play-btn,
            .view-all,
            .modal-bet-btn {
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .dot {
                width: 14px;
                height: 14px;
            }
            
            .modal-bet-card {
                cursor: pointer;
            }
        }

        /* Landscape Mobile Optimizations */
        @media (max-width: 768px) and (orientation: landscape) {
            .games-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .satta-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            /* MODAL GAMES - 3 COLUMNS IN LANDSCAPE */
            .modal-bet-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .game-card,
            .satta-card {
                min-height: 100px;
            }
            
            .game-img {
                width: 100px;
                height: 100px;
            }
            
            .banner-slider {
                height: 200px;
            }
        }

        /* High DPI Mobile Screens */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .game-card,
            .satta-card,
            .modal-bet-card {
                border-width: 0.5px;
            }
            
            .status-badge,
            .timer {
                border-width: 1px;
            }
        }

        /* Reduced Motion Support */
        @media (prefers-reduced-motion: reduce) {
            .game-card,
            .satta-card,
            .modal-bet-card,
            .game-btn,
            .play-btn,
            .modal-bet-btn {
                transition: none !important;
                animation: none !important;
            }
            
            .bg-element {
                animation: none !important;
            }
        }

        /* Dark Mode Support */
        @media (prefers-color-scheme: dark) {
            .game-card,
            .satta-card,
            .modal-bet-card {
            }
        }

        /* Print Styles */
        @media print {
            .game-card,
            .satta-card,
            .modal-bet-card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }
            
            .game-btn,
            .play-btn,
            .modal-bet-btn {
                display: none;
            }
        }

        /* Mobile Footer Optimization */
        @media (max-width: 768px) {
            footer {
                padding: 2rem 1rem 1rem;
            }
            
            .footer-content {
                flex-direction: column;
                gap: 1.5rem;
                margin-bottom: 2rem;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 0.8rem;
                text-align: center;
            }
            
            .social-links {
                justify-content: center;
            }
            
            .social-links a {
                width: 40px;
                height: 40px;
                font-size: 1.4rem;
            }
        }

        /* Smooth Mobile Scrolling */
        @media (max-width: 768px) {
            html {
                scroll-behavior: smooth;
            }
            
            .games-grid,
            .satta-grid {
                scroll-margin-top: 80px;
            }
            
            .modal-body {
                scroll-margin-top: 20px;
            }
        }

        /* Modal Games Specific Mobile Styles */
        @media (max-width: 768px) {
            .modal-games-container {
                padding: 0.5rem;
            }
            
            .modal-game-header {
                padding: 1rem;
                text-align: center;
            }
            
            .modal-game-stats {
                flex-direction: column;
                gap: 0.8rem;
                margin-bottom: 1.5rem;
            }
            
            .modal-game-stat {
                flex: 1;
                text-align: center;
                padding: 0.8rem;
            }
            
            .modal-game-stat-value {
                font-size: 1.2rem;
            }
            
            .modal-game-stat-label {
                font-size: 0.8rem;
            }
        }

        /* Very Small Screen Modal Adjustments */
        @media (max-width: 360px) {
            .modal-bet-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
            
            .modal-bet-card {
                min-height: 90px;
                flex-direction: row;
                text-align: left;
                padding: 0.8rem;
            }
            
            .modal-bet-icon {
                margin-right: 0.8rem;
                margin-bottom: 0;
                font-size: 1.6rem;
                min-width: 40px;
            }
            
            .modal-bet-content {
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            
            .modal-bet-btn {
                width: auto;
                
                min-width: 80px;
                margin-top: 0;
                align-self: flex-start;
            }
        }
        
                /* Mobile Responsive */
                @media (max-width: 768px) {
                    .banner-slider {
                        height: 600px;
                        margin-bottom: 3rem;
                    }
                    
                    .slide {
                        padding: 0 1.5rem;
                    }
                    
                    .slide-content-h2 {
                        font-size: 2.8rem;
                    }
                    
                    .slide p {
                        font-size: 1.1rem;
                        padding-left: 1rem;
                    }
                    
                    .play-btn {
                        padding: 16px 35px;
                        font-size: 1.1rem;
                    }
                    
                    .banner-stats {
                        top: 20px;
                        right: 20px;
                        gap: 1rem;
                    }
                    
                    .stat-item {
                        padding: 0.8rem 1rem;
                    }
                    
                    .stat-value {
                        font-size: 1.5rem;
                    }
                    
                    .slider-dots {
                        bottom: 20px;
                    }
                }

                @media (max-width: 480px) {
                    .banner-slider {
                        height: 350px;
                    }
                    
                    .slide-content-h2 {
                        font-size: 2.2rem;
                    }
                    
                    .slide p {
                        font-size: 1rem;
                    }
                    
                    .play-btn {
                        padding: 14px 30px;
                        font-size: 1rem;
                    }
                    
                    .banner-stats {
                        display: none;
                    }
                }

</style>



    <!-- Main Content -->
    <main>
        <!-- Banner Slider -->
            <section class="banner-slider">
                <div class="slide active">
                    <div class="slide-content">
                        <h2 class='slide-content-h2'>Win Big with RB Games</h2>
                        <p>Experience the thrill of betting with the most trusted platform. Join now and get 100% bonus on your first deposit!</p>
                        <button class="play-btn">Play Now</button>
                    </div>
                </div>
                <div class="slide">
                    <div class="slide-content">
                        <h2 class='slide-content-h2'>Live Betting Action</h2>
                        <p>Place your bets in real-time with our live betting feature. Exciting matches, incredible odds!</p>
                        <button class="play-btn">Join Now</button>
                    </div>
                </div>
                <div class="slide">
                    <div class="slide-content">
                        <h2 class='slide-content-h2'>Daily Jackpots</h2>
                        <p>Massive jackpots waiting to be won. Your next bet could change your life forever!</p>
                        <button class="play-btn">Try Luck</button>
                    </div>
                </div>
                  
                <div class="slider-dots">
                    <div class="dot active" data-slide="0"></div>
                    <div class="dot" data-slide="1"></div>
                    <div class="dot" data-slide="2"></div>
                </div>
            </section>

       

<!-- Satta Matka Lobby -->
<section class="satta-lobby">
    <h2 class="section-title">Satta Matka Lobby </h2>
    <div class="satta-grid">
        <?php 
        $card_index = 0;
        foreach ($games_data as $game_name => $game_info): 
            $is_playable = $game_info['isPlayable'];
            $is_closed = $game_info['status'] === 'closed';
            $onclick = !$is_closed ? 
                "openGameModal('{$game_name}', '{$game_info['openTime']}', '{$game_info['closeTime']}', '{$game_info['id']}')" : 
                "void(0)";
        ?>
        <div class="satta-card" onclick="<?php echo $onclick; ?>" style="<?php echo $is_closed ? 'opacity: 0.7; cursor: not-allowed;' : ''; ?>">
            <div class="satta-img" style="background-image: url('<?php echo $game_info['image']; ?>');"></div>
            <div class="satta-header">
                <h3 class="satta-title"><?php echo $game_name; ?></h3>
                <div class="satta-result">
                    <span>---</span>
                </div>
            </div>
            <div class="satta-body">
                <div class="satta-info">
                    <span class="satta-label">Open Time</span>
                    <span class="satta-value"><span class="timer"><?php echo $game_info['openTime']; ?></span></span>
                </div>
                <div class="satta-info">
                    <span class="satta-label">Close Time</span>
                    <span class="satta-value"><span class="timer"><?php echo $game_info['closeTime']; ?></span></span>
                </div>
                <span class="status-badge <?php echo $game_info['statusClass']; ?>">
                    <?php echo $game_info['statusText']; ?>
                </span>
                
                <?php if ($is_closed): ?>
                <div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(214, 48, 49, 0.2); border-radius: 5px; font-weight: bold; color: #ff6b6b;">
                    ⚠️ Game Closed for Today
                </div>
                <?php elseif ($game_info['status'] === 'coming'): ?>
                <div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(253, 203, 110, 0.2); border-radius: 5px; font-weight: bold; color: #fdcb6e;">
                    ⏰ Opens at <?php echo $game_info['openTime']; ?>
                </div>
                <?php elseif ($game_info['status'] === 'open'): ?>
                <div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(0, 184, 148, 0.2); border-radius: 5px; font-weight: bold; color: #00b894;">
                    ✅ Time Running!
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php 
            $card_index++;
        endforeach; 
        ?>
    </div>
</section>
        <!-- Game Modal -->
<div id="gameModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <div class="modal-header">
            <h2 class="modal-game-title" id="modalGameName">Game Name</h2>
            <p class="modal-game-time" id="modalGameTime">Time: 00:00 to 00:00</p>
        </div>
        <div class="modal-body">
            <h3 class="section-title">Select Bet Type</h3>
            <div class="modal-bet-grid" id="modalBetGrid">
                <!-- Bet types will be dynamically inserted here -->
            </div>
        </div>
    </div>
</div>
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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
window.gameData = <?php echo json_encode($games_data); ?>;
    
// Convert PHP game types to JavaScript - MAKE IT GLOBAL  
window.gameTypes = <?php echo json_encode($game_types); ?>;

    // DEBUG: Log game types to console
    console.log('Game Types from Database:', gameTypes);

// In your PHP code, update the gameTypeMapping to include the new types
window.gameTypeMapping = {
    'single_ank': { name: 'Single Ank', icon: '🔢', desc: 'Bet on single digit 0-9' },
    'jodi': { name: 'Jodi', icon: '🔣', desc: 'Bet on pair of digits 00-99' },
    'single_patti': { name: 'Single Patti', icon: '🎯', desc: 'Bet on three-digit number' },
    'double_patti': { name: 'Double Patti', icon: '🎲', desc: 'Bet on two three-digit numbers' },
    'triple_patti': { name: 'Triple Patti', icon: '🎰', desc: 'Bet on three three-digit numbers' },
    'sp_motor': { name: 'SP Motor', icon: '🏍️', desc: 'Special Motor betting' },
    'dp_motor': { name: 'DP Motor', icon: '🏎️', desc: 'Double Panel Motor betting' },
    'dp_set': { name: 'DP Set', icon: '🔄', desc: 'Double Patti Set betting' },
    'tp_set': { name: 'TP Set', icon: '🎯', desc: 'Triple Patti Set betting' },
    'sp': { name: 'SP', icon: '⭐', desc: 'Single Patti betting' },
    'dp': { name: 'DP', icon: '🎲', desc: 'Double Patti betting' },
    'sp_set': { name: 'SP Set', icon: '🔄', desc: 'Single Patti Set betting' },
    'common': { name: 'Common', icon: '🎫', desc: 'Common betting game' },
    'series': { name: 'Series', icon: '📊', desc: 'Series betting game' },
    'rown': { name: 'Rown', icon: '📈', desc: 'Rown betting game' },
    'abr_cut': { name: 'Abr-Cut', icon: '✂️', desc: 'Abr Cut betting game' },
    'eki': { name: 'Eki', icon: '🎴', desc: 'Eki betting game' },
    'bkki': { name: 'Bkki', icon: '🎲', desc: 'Bkki betting game' }
};

    // Get modal elements
    const modal = document.getElementById('gameModal');
    const modalGameName = document.getElementById('modalGameName');
    const modalGameTime = document.getElementById('modalGameTime');
    const modalBetGrid = document.getElementById('modalBetGrid');
    const closeModal = document.querySelector('.close-modal');
    
// Function to open modal with game data
function openGameModal(gameName1, openTime1, closeTime1, id) {
    let gameName = gameName1;
    let openTime = openTime1 || '00:00';
    let closeTime = closeTime1 || '23:59';
    let info = "createsession";
    
    // Check if game is closed (only block closed games)
    const currentTime = new Date();
    const currentHours = currentTime.getHours().toString().padStart(2, '0');
    const currentMinutes = currentTime.getMinutes().toString().padStart(2, '0');
    const currentTimeString = `${currentHours}:${currentMinutes}`;
    
    const currentTimeNum = parseInt(currentTimeString.replace(':', ''));
    const closeTimeNum = parseInt(closeTime.replace(':', ''));
    
    // Only block if game is closed (time over)
    if (currentTimeNum > closeTimeNum) {
        alert('❌ This game is closed for today. Please try again tomorrow.');
        return;
    }
    
    $.ajax({
        url: "create_game_sessions.php",
        method: "POST",
        data:{info:info, id:id},
        success: function(e){
            if(e == 0){
                const game = window.gameData && window.gameData[gameName] ? window.gameData[gameName] : null;
                if (game) {
                    modalGameName.textContent = gameName;
                    
                    // Show appropriate message based on current time
                    const openTimeNum = parseInt(openTime.replace(':', ''));
                    if (currentTimeNum < openTimeNum) {
                        modalGameTime.textContent = `⏰ Game starts at ${openTime} IST`;
                    } else {
                        modalGameTime.textContent = `⏱️ Time Running: ${openTime} to ${closeTime} IST`;
                    }
                    
                    // Clear previous bet types
                    modalBetGrid.innerHTML = '';
                    
                    // Add bet types to modal from database
                    if (window.gameTypes && Array.isArray(window.gameTypes)) {
                        window.gameTypes.forEach(gameType => {
                            // Convert code to lowercase for consistent mapping
                            const codeKey = gameType.code ? gameType.code.toLowerCase() : 'single_ank';
                            const typeInfo = window.gameTypeMapping && window.gameTypeMapping[codeKey] ? 
                                window.gameTypeMapping[codeKey] : { 
                                    name: gameType.name || 'Unknown', 
                                    icon: '🎯', 
                                    desc: `Bet on ${gameType.name || 'this game'}` 
                                };
                            
                            const betCard = document.createElement('div');
                            betCard.className = 'modal-bet-card';
                            betCard.innerHTML = `
                                <span class="modal-bet-icon">${typeInfo.icon}</span>
                                <h4 class="modal-bet-title">${typeInfo.name}</h4>
                                <p class="modal-bet-desc">${typeInfo.desc}</p>
                                <p class="modal-bet-payout">Payout: ${gameType.payout_ratio || 9}x</p>
                                <button class="modal-bet-btn" onclick="redirectToGame('${gameName}', '${gameType.code || 'single_ank'}', '${openTime}', '${closeTime}', ${game.id})">Play Now</button>
                            `;
                            modalBetGrid.appendChild(betCard);
                        });
                    } else {
                        // Fallback if no game types
                        modalBetGrid.innerHTML = '<p>No bet types available</p>';
                    }
                    
                    // Show modal
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                } else {
                    alert('Game data not found!');
                }
            }
            else if(e == 1){
                alert("Something Went Wrong!");
            }
            else{
                alert("Something Went Wrong!");
            }
        },
        error: function() {
            alert("Network error! Please try again.");
        }
    });
}
    // Function to redirect to game page
    function redirectToGame(gameName, betType, openTime, closeTime, gameId) {
        console.log('Redirecting with:', {gameName, betType, openTime, closeTime, gameId});
        
        // Convert bet type to lowercase for consistent mapping
        let gameType = betType.toLowerCase();
        
        // Enhanced type mapping with all supported game types including new ones
        const typeMapping = {
            'single_ank': 'single_ank',
            'jodi': 'jodi', 
            'single_patti': 'single_patti',
            'double_patti': 'double_patti',
            'triple_patti': 'triple_patti',
            'sp_motor': 'sp_motor',
            'dp_motor': 'dp_motor',
            'sp_game': 'sp_game',
            'dp_game': 'dp_game',
            'sp': 'sp_game',
            'dp': 'dp_game',
            'sp_set': 'sp_set',
            'common': 'common',
            'series': 'series',
            // NEW GAME TYPES MAPPING
            'rown': 'rown',
            'abr_cut': 'abr_cut',
            'eki': 'eki',
            'bkki': 'bkki'
        };
        
        // Use mapping or fallback to original type
        const mappedType = typeMapping[gameType] || gameType;
        
        console.log('Original Type:', betType, 'Mapped Type:', mappedType);
        
        // Construct the URL with proper parameters
        const url = `single_ank.php?game=${encodeURIComponent(gameName)}&type=${encodeURIComponent(mappedType)}&openTime=${encodeURIComponent(openTime)}&closeTime=${encodeURIComponent(closeTime)}&gameId=${gameId}`;
        
        console.log('Redirect URL:', url);
        
        // Redirect to the game page
        window.location.href = url;
    }

    // Close modal when clicking the X
    closeModal.addEventListener('click', function() {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    });

    // Close modal when clicking outside the content
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.style.display === 'block') {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    });

// Function to update game status in real-time
function updateGameStatus() {
    const currentTime = new Date();
    const currentHours = currentTime.getHours().toString().padStart(2, '0');
    const currentMinutes = currentTime.getMinutes().toString().padStart(2, '0');
    const currentTimeString = `${currentHours}:${currentMinutes}`;
    
    console.log('Current Time:', currentTimeString);
    
    // Update each game card status
    document.querySelectorAll('.satta-card').forEach(card => {
        const gameTitle = card.querySelector('.satta-title').textContent;
        const gameData = window.gameData && window.gameData[gameTitle] ? window.gameData[gameTitle] : null;
        
        if (gameData) {
            const openTime = gameData.openTime || '00:00';
            const closeTime = gameData.closeTime || '23:59';
            const statusBadge = card.querySelector('.status-badge');
            const gameBtn = card.querySelector('.game-btn');
            const statusMessage = card.querySelector('.satta-body > div[style*="background"]');
            
            const currentTimeNum = parseInt(currentTimeString.replace(':', ''));
            const openTimeNum = parseInt(openTime.replace(':', ''));
            const closeTimeNum = parseInt(closeTime.replace(':', ''));
            
            let newStatus, newStatusClass, newStatusText, isPlayable, isClosed;
            
            if (currentTimeNum < openTimeNum) {
                newStatus = 'coming';
                newStatusClass = 'status-coming';
                newStatusText = 'Coming Soon';
                isPlayable = true;
                isClosed = false;
            } else if (currentTimeNum >= openTimeNum && currentTimeNum <= closeTimeNum) {
                newStatus = 'open';
                newStatusClass = 'status-open';
                newStatusText = 'Time Running';
                isPlayable = true;
                isClosed = false;
            } else {
                newStatus = 'closed';
                newStatusClass = 'status-closed';
                newStatusText = 'Closed';
                isPlayable = false;
                isClosed = true;
            }
            
            // Update status badge
            if (statusBadge) {
                statusBadge.className = `status-badge ${newStatusClass}`;
                statusBadge.textContent = newStatusText;
            }
            
            // Update button
            if (gameBtn) {
                gameBtn.textContent = isClosed ? 'Closed' : 'Play Now';
                gameBtn.disabled = isClosed;
                gameBtn.style.background = isClosed ? '#666' : '';
                gameBtn.style.cursor = isClosed ? 'not-allowed' : 'pointer';
            }
            
            // Update card opacity and cursor
            card.style.opacity = isClosed ? '0.7' : '1';
            card.style.cursor = isClosed ? 'not-allowed' : 'pointer';
            
            // Update onclick event
            card.onclick = isClosed ? 
                function() { void(0); } : 
                function() { 
                    openGameModal(gameTitle, openTime, closeTime, gameData.id); 
                };
            
            // Update status message
            if (statusMessage) {
                statusMessage.remove();
            }
            
            let newMessage = '';
            if (isClosed) {
                newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(214, 48, 49, 0.2); border-radius: 5px; font-weight: bold; color: #ff6b6b;">⚠️ Game Closed for Today</div>';
            } else if (newStatus === 'coming') {
                newMessage = `<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(253, 203, 110, 0.2); border-radius: 5px; font-weight: bold; color: #fdcb6e;">⏰ Opens at ${openTime}</div>`;
            } else if (newStatus === 'open') {
                newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(0, 184, 148, 0.2); border-radius: 5px; font-weight: bold; color: #00b894;">✅ Time Running!</div>';
            }
            
            if (newMessage) {
                card.querySelector('.satta-body').insertAdjacentHTML('beforeend', newMessage);
            }
        } else {
            // If no game data found, set to closed by default
            console.warn('No game data found for:', gameTitle);
            const statusBadge = card.querySelector('.status-badge');
            const gameBtn = card.querySelector('.game-btn');
            
            if (statusBadge) {
                statusBadge.className = 'status-badge status-closed';
                statusBadge.textContent = 'Closed';
            }
            
            if (gameBtn) {
                gameBtn.textContent = 'Closed';
                gameBtn.disabled = true;
                gameBtn.style.background = '#666';
                gameBtn.style.cursor = 'not-allowed';
            }
            
            card.style.opacity = '0.7';
            card.style.cursor = 'not-allowed';
            card.onclick = function() { void(0); };
        }
    });
}

// Update status every minute
setInterval(updateGameStatus, 60000);

// Initial update
updateGameStatus();
</script>
    <script>
           // Banner Slider Functionality - FIXED VERSION
    let currentSlide = 0;
    const slides = document.querySelectorAll('.slide');
    const dots = document.querySelectorAll('.dot');
    let slideInterval;
    
    function showSlide(n) {
        // Remove active class from all slides and dots
        slides.forEach(slide => slide.classList.remove('active'));
        dots.forEach(dot => dot.classList.remove('active'));
        
        currentSlide = (n + slides.length) % slides.length;
        
        // Add active class to current slide and dot
        slides[currentSlide].classList.add('active');
        dots[currentSlide].classList.add('active');
    }
    
    function nextSlide() {
        showSlide(currentSlide + 1);
    }
    
    // Initialize the slider
    function initSlider() {
        // Make sure first slide is active on load
        showSlide(0);
        
        // Start auto slide change
        slideInterval = setInterval(nextSlide, 5000);
    }
    
    // Start the slider when page loads
    document.addEventListener('DOMContentLoaded', initSlider);
    
    // Dot click event
    dots.forEach(dot => {
        dot.addEventListener('click', function() {
            // Clear existing interval
            clearInterval(slideInterval);
            // Show selected slide
            showSlide(parseInt(this.getAttribute('data-slide')));
            // Restart auto slide
            slideInterval = setInterval(nextSlide, 3000);
        });
    });
    
    // Pause slider on hover (optional)
    const bannerSlider = document.querySelector('.banner-slider');
    if (bannerSlider) {
        bannerSlider.addEventListener('mouseenter', () => {
            clearInterval(slideInterval);
        });
        
        bannerSlider.addEventListener('mouseleave', () => {
            slideInterval = setInterval(nextSlide, 3000);
        });
    }
    </script>

    <!-- sequence js -->
<script>
        // Function to sort and reorder games based on status
        function sortGamesByStatus() {
            const sattaGrid = document.querySelector('.satta-grid');
            if (!sattaGrid) return;
            
            // Get all game cards
            const gameCards = Array.from(sattaGrid.querySelectorAll('.satta-card'));
            
            // Sort games based on status priority: open > coming > closed
            gameCards.sort((a, b) => {
                const statusA = getGameStatusFromCard(a);
                const statusB = getGameStatusFromCard(b);
                
                // Priority order: open (2) > coming (1) > closed (0)
                const priority = {
                    'open': 2,
                    'coming': 1,
                    'closed': 0
                };
                
                return priority[statusB] - priority[statusA];
            });
            
            // Clear the grid
            sattaGrid.innerHTML = '';
            
            // Reappend sorted cards
            gameCards.forEach(card => {
                sattaGrid.appendChild(card);
            });
            
            console.log('Games sorted by status: Open > Coming > Closed');
        }

        // Function to get game status from card element
        function getGameStatusFromCard(card) {
            const statusBadge = card.querySelector('.status-badge');
            if (!statusBadge) return 'closed';
            
            if (statusBadge.classList.contains('status-open')) return 'open';
            if (statusBadge.classList.contains('status-coming')) return 'coming';
            if (statusBadge.classList.contains('status-closed')) return 'closed';
            
            return 'closed';
        }

        // Function to update game status and reorder in real-time
        function updateGameStatusAndReorder() {
            const currentTime = new Date();
            const currentHours = currentTime.getHours().toString().padStart(2, '0');
            const currentMinutes = currentTime.getMinutes().toString().padStart(2, '0');
            const currentTimeString = `${currentHours}:${currentMinutes}`;
            
            console.log('Current Time:', currentTimeString);
            
            // Update each game card status
            document.querySelectorAll('.satta-card').forEach(card => {
                const gameTitle = card.querySelector('.satta-title').textContent;
                const gameData = window.gameData && window.gameData[gameTitle] ? window.gameData[gameTitle] : null;
                
                if (gameData) {
                    const openTime = gameData.openTime || '00:00';
                    const closeTime = gameData.closeTime || '23:59';
                    const statusBadge = card.querySelector('.status-badge');
                    const gameBtn = card.querySelector('.game-btn');
                    const statusMessage = card.querySelector('.satta-body > div[style*="background"]');
                    
                    const currentTimeNum = parseInt(currentTimeString.replace(':', ''));
                    const openTimeNum = parseInt(openTime.replace(':', ''));
                    const closeTimeNum = parseInt(closeTime.replace(':', ''));
                    
                    let newStatus, newStatusClass, newStatusText, isPlayable, isClosed;
                    
                    if (currentTimeNum < openTimeNum) {
                        newStatus = 'coming';
                        newStatusClass = 'status-coming';
                        newStatusText = 'Coming Soon';
                        isPlayable = true;
                        isClosed = false;
                    } else if (currentTimeNum >= openTimeNum && currentTimeNum <= closeTimeNum) {
                        newStatus = 'open';
                        newStatusClass = 'status-open';
                        newStatusText = 'Time Running';
                        isPlayable = true;
                        isClosed = false;
                    } else {
                        newStatus = 'closed';
                        newStatusClass = 'status-closed';
                        newStatusText = 'Closed';
                        isPlayable = false;
                        isClosed = true;
                    }
                    
                    // Update status badge
                    if (statusBadge) {
                        statusBadge.className = `status-badge ${newStatusClass}`;
                        statusBadge.textContent = newStatusText;
                    }
                    
                    // Update button
                    if (gameBtn) {
                        gameBtn.textContent = isClosed ? 'Closed' : 'Play Now';
                        gameBtn.disabled = isClosed;
                        gameBtn.style.background = isClosed ? '#666' : '';
                        gameBtn.style.cursor = isClosed ? 'not-allowed' : 'pointer';
                    }
                    
                    // Update card opacity and cursor
                    card.style.opacity = isClosed ? '0.7' : '1';
                    card.style.cursor = isClosed ? 'not-allowed' : 'pointer';
                    
                    // Update onclick event
                    card.onclick = isClosed ? 
                        function() { void(0); } : 
                        function() { 
                            openGameModal(gameTitle, openTime, closeTime, gameData.id); 
                        };
                    
                    // Update status message
                    if (statusMessage) {
                        statusMessage.remove();
                    }
                    
                    let newMessage = '';
                    if (isClosed) {
                        newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(214, 48, 49, 0.2); border-radius: 5px; font-weight: bold; color: #ff6b6b;">⚠️ Game Closed for Today</div>';
                    } else if (newStatus === 'coming') {
                        newMessage = `<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(253, 203, 110, 0.2); border-radius: 5px; font-weight: bold; color: #fdcb6e;">⏰ Opens at ${openTime}</div>`;
                    } else if (newStatus === 'open') {
                        newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(0, 184, 148, 0.2); border-radius: 5px; font-weight: bold; color: #00b894;">✅ Time Running!</div>';
                    }
                    
                    if (newMessage) {
                        card.querySelector('.satta-body').insertAdjacentHTML('beforeend', newMessage);
                    }
                } else {
                    // If no game data found, set to closed by default
                    console.warn('No game data found for:', gameTitle);
                    const statusBadge = card.querySelector('.status-badge');
                    const gameBtn = card.querySelector('.game-btn');
                    
                    if (statusBadge) {
                        statusBadge.className = 'status-badge status-closed';
                        statusBadge.textContent = 'Closed';
                    }
                    
                    if (gameBtn) {
                        gameBtn.textContent = 'Closed';
                        gameBtn.disabled = true;
                        gameBtn.style.background = '#666';
                        gameBtn.style.cursor = 'not-allowed';
                    }
                    
                    card.style.opacity = '0.7';
                    card.style.cursor = 'not-allowed';
                    card.onclick = function() { void(0); };
                }
            });
            
            // Reorder games after updating status
            sortGamesByStatus();
        }

        // Enhanced function to sort with time-based priority within same status
        function sortGamesWithTimePriority() {
            const sattaGrid = document.querySelector('.satta-grid');
            if (!sattaGrid) return;
            
            const gameCards = Array.from(sattaGrid.querySelectorAll('.satta-card'));
            const currentTime = new Date();
            const currentHours = currentTime.getHours();
            const currentMinutes = currentTime.getMinutes();
            const currentTimeNum = currentHours * 60 + currentMinutes;
            
            gameCards.sort((a, b) => {
                const statusA = getGameStatusFromCard(a);
                const statusB = getGameStatusFromCard(b);
                
                // Priority order: open (2) > coming (1) > closed (0)
                const priority = {
                    'open': 2,
                    'coming': 1,
                    'closed': 0
                };
                
                // If different status, sort by status priority
                if (statusA !== statusB) {
                    return priority[statusB] - priority[statusA];
                }
                
                // If same status, sort by time logic
                const timeA = getGameTimeData(a);
                const timeB = getGameTimeData(b);
                
                if (statusA === 'open') {
                    // For open games: sort by closing time (earlier closing first)
                    return timeA.closeTimeNum - timeB.closeTimeNum;
                } else if (statusA === 'coming') {
                    // For coming games: sort by opening time (earlier opening first)
                    return timeA.openTimeNum - timeB.openTimeNum;
                } else {
                    // For closed games: sort by opening time for next day (earlier opening first)
                    return timeA.openTimeNum - timeB.openTimeNum;
                }
            });
            
            // Clear and reappend
            sattaGrid.innerHTML = '';
            gameCards.forEach(card => {
                sattaGrid.appendChild(card);
            });
        }

        // Helper function to get time data from game card
        function getGameTimeData(card) {
            const timeElements = card.querySelectorAll('.satta-value .timer');
            let openTime = '00:00';
            let closeTime = '23:59';
            
            if (timeElements.length >= 2) {
                openTime = timeElements[0].textContent;
                closeTime = timeElements[1].textContent;
            }
            
            const openTimeNum = convertTimeToMinutes(openTime);
            const closeTimeNum = convertTimeToMinutes(closeTime);
            
            return {
                openTime,
                closeTime,
                openTimeNum,
                closeTimeNum
            };
        }

        // Helper function to convert time string to minutes
        function convertTimeToMinutes(timeString) {
            const [hours, minutes] = timeString.split(':').map(Number);
            return hours * 60 + minutes;
        }

        // Update status and reorder every minute
        setInterval(updateGameStatusAndReorder, 60000);

        // Enhanced update function that includes time-based sorting
        setInterval(() => {
            updateGameStatusAndReorder();
            sortGamesWithTimePriority();
        }, 60000);

        // Initial sort when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit for the initial status update to complete
            setTimeout(() => {
                updateGameStatusAndReorder();
                sortGamesWithTimePriority();
            }, 1000);
        });

        // Also call the sorting function after modal interactions
        function openGameModal(gameName1, openTime1, closeTime1, id) {
            let gameName = gameName1;
            let openTime = openTime1 || '00:00';
            let closeTime = closeTime1 || '23:59';
            let info = "createsession";
            
            // Check if game is closed (only block closed games)
            const currentTime = new Date();
            const currentHours = currentTime.getHours().toString().padStart(2, '0');
            const currentMinutes = currentTime.getMinutes().toString().padStart(2, '0');
            const currentTimeString = `${currentHours}:${currentMinutes}`;
            
            const currentTimeNum = parseInt(currentTimeString.replace(':', ''));
            const closeTimeNum = parseInt(closeTime.replace(':', ''));
            
            // Only block if game is closed (time over)
            if (currentTimeNum > closeTimeNum) {
                alert('❌ This game is closed for today. Please try again tomorrow.');
                return;
            }
            
            $.ajax({
                url: "create_game_sessions.php",
                method: "POST",
                data:{info:info, id:id},
                success: function(e){
                    if(e == 0){
                        const game = window.gameData && window.gameData[gameName] ? window.gameData[gameName] : null;
                        if (game) {
                            modalGameName.textContent = gameName;
                            
                            // Show appropriate message based on current time
                            const openTimeNum = parseInt(openTime.replace(':', ''));
                            if (currentTimeNum < openTimeNum) {
                                modalGameTime.textContent = `⏰ Game starts at ${openTime} IST`;
                            } else {
                                modalGameTime.textContent = `⏱️ Time Running: ${openTime} to ${closeTime} IST`;
                            }
                            
                            // Clear previous bet types
                            modalBetGrid.innerHTML = '';
                            
                            // Add bet types to modal from database
                            if (window.gameTypes && Array.isArray(window.gameTypes)) {
                                window.gameTypes.forEach(gameType => {
                                    // Convert code to lowercase for consistent mapping
                                    const codeKey = gameType.code ? gameType.code.toLowerCase() : 'single_ank';
                                    const typeInfo = window.gameTypeMapping && window.gameTypeMapping[codeKey] ? 
                                        window.gameTypeMapping[codeKey] : { 
                                            name: gameType.name || 'Unknown', 
                                            icon: '🎯', 
                                            desc: `Bet on ${gameType.name || 'this game'}` 
                                        };
                                    
                                    const betCard = document.createElement('div');
                                    betCard.className = 'modal-bet-card';
                                    betCard.innerHTML = `
                                        <span class="modal-bet-icon">${typeInfo.icon}</span>
                                        <h4 class="modal-bet-title">${typeInfo.name}</h4>
                                        <p class="modal-bet-desc">${typeInfo.desc}</p>
                                        <p class="modal-bet-payout">Payout: ${gameType.payout_ratio || 9}x</p>
                                        <button class="modal-bet-btn" onclick="redirectToGame('${gameName}', '${gameType.code || 'single_ank'}', '${openTime}', '${closeTime}', ${game.id})">Play Now</button>
                                    `;
                                    modalBetGrid.appendChild(betCard);
                                });
                            } else {
                                // Fallback if no game types
                                modalBetGrid.innerHTML = '<p>No bet types available</p>';
                            }
                            
                            // Show modal
                            modal.style.display = 'block';
                            document.body.style.overflow = 'hidden';
                        } else {
                            alert('Game data not found!');
                        }
                    }
                    else if(e == 1){
                        alert("Something Went Wrong!");
                    }
                    else{
                        alert("Something Went Wrong!");
                    }
                },
                error: function() {
                    alert("Network error! Please try again.");
                }
            });
            
            // Re-sort games after modal interaction
            setTimeout(sortGamesWithTimePriority, 100);
        }

        // Add manual refresh function for debugging
        window.refreshGameOrder = function() {
            updateGameStatusAndReorder();
            sortGamesWithTimePriority();
            console.log('Game order manually refreshed');
        };
</script>
<!-- remove jodi game js -->
<script>
    // Enhanced openGameModal function with Jodi filtering based on open time
    function openGameModal(gameName1, openTime1, closeTime1, id) {
        let gameName = gameName1;
        let openTime = openTime1 || '00:00';
        let closeTime = closeTime1 || '23:59';
        let info = "createsession";
        
        // Check current time
        const currentTime = new Date();
        const currentHours = currentTime.getHours().toString().padStart(2, '0');
        const currentMinutes = currentTime.getMinutes().toString().padStart(2, '0');
        const currentTimeString = `${currentHours}:${currentMinutes}`;
        
        const currentTimeNum = parseInt(currentTimeString.replace(':', ''));
        const closeTimeNum = parseInt(closeTime.replace(':', ''));
        const openTimeNum = parseInt(openTime.replace(':', ''));
        
        // Check if game is closed (only block closed games)
        if (currentTimeNum > closeTimeNum) {
            alert('❌ This game is closed for today. Please try again tomorrow.');
            return;
        }
        
        $.ajax({
            url: "create_game_sessions.php",
            method: "POST",
            data:{info:info, id:id},
            success: function(e){
                if(e == 0){
                    const game = window.gameData && window.gameData[gameName] ? window.gameData[gameName] : null;
                    if (game) {
                        modalGameName.textContent = gameName;
                        
                        // Show appropriate message based on current time
                        if (currentTimeNum < openTimeNum) {
                            modalGameTime.textContent = `⏰ Game starts at ${openTime} IST`;
                        } else {
                            modalGameTime.textContent = `⏱️ Time Running: ${openTime} to ${closeTime} IST`;
                        }
                        
                        // Clear previous bet types
                        modalBetGrid.innerHTML = '';
                        
                        // Add bet types to modal from database with Jodi filtering based on open time
                        if (window.gameTypes && Array.isArray(window.gameTypes)) {
                            // Filter Jodi based on open time
                            const filteredGameTypes = window.gameTypes.filter(gameType => {
                                const isJodi = gameType.code && 
                                    (gameType.code.toLowerCase() === 'jodi' || 
                                    gameType.name.toLowerCase().includes('jodi'));
                                
                                // If it's Jodi, only show before open time
                                if (isJodi) {
                                    return currentTimeNum < openTimeNum; // Show Jodi only before open time
                                }
                                
                                // Show all other games always
                                return true;
                            });
                            
                            filteredGameTypes.forEach(gameType => {
                                // Convert code to lowercase for consistent mapping
                                const codeKey = gameType.code ? gameType.code.toLowerCase() : 'single_ank';
                                const typeInfo = window.gameTypeMapping && window.gameTypeMapping[codeKey] ? 
                                    window.gameTypeMapping[codeKey] : { 
                                        name: gameType.name || 'Unknown', 
                                        icon: '🎯', 
                                        desc: `Bet on ${gameType.name || 'this game'}` 
                                    };
                                
                                const betCard = document.createElement('div');
                                betCard.className = 'modal-bet-card';
                                betCard.innerHTML = `
                                    <span class="modal-bet-icon">${typeInfo.icon}</span>
                                    <h4 class="modal-bet-title">${typeInfo.name}</h4>
                                    <p class="modal-bet-desc">${typeInfo.desc}</p>
                                    <p class="modal-bet-payout">Payout: ${gameType.payout_ratio || 9}x</p>
                                    <button class="modal-bet-btn" onclick="redirectToGame('${gameName}', '${gameType.code || 'single_ank'}', '${openTime}', '${closeTime}', ${game.id})">Play Now</button>
                                `;
                                modalBetGrid.appendChild(betCard);
                            });
                        } else {
                            // Fallback if no game types
                            modalBetGrid.innerHTML = '<p>No bet types available</p>';
                        }
                        
                        // Show modal
                        modal.style.display = 'block';
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('Game data not found!');
                    }
                }
                else if(e == 1){
                    alert("Something Went Wrong!");
                }
                else{
                    alert("Something Went Wrong!");
                }
            },
            error: function() {
                alert("Network error! Please try again.");
            }
        });
    }
</script>

</body>
</html>