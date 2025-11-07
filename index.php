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

// Fetch all games from database for dynamic modal
$games_data = [];
$sql_games = "SELECT id, name, open_time, close_time, description FROM games WHERE status = 'active'";
if ($result = $conn->query($sql_games)) {
    while ($row = $result->fetch_assoc()) {
        $games_data[$row['name']] = [
            'id' => $row['id'],
            'openTime' => date('H:i', strtotime($row['open_time'])),
            'closeTime' => date('H:i', strtotime($row['close_time'])),
            'description' => $row['description']
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

// Function to check if game is playable - UPDATED: Allow both coming and open games
function isGamePlayable($open_time, $close_time) {
    $status = getGameStatus($open_time, $close_time);
    return $status['status'] === 'open' || $status['status'] === 'coming';
}
// Fetch all games with proper time format and status
$games_data = [];
$sql_games = "SELECT id, name, open_time, close_time, description FROM games WHERE status = 'active'";
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
            'status' => $game_status['status'],
            'statusText' => $game_status['text'],
            'statusClass' => $game_status['class'],
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
            'isPlayable' => false
        ],
        'Mumbai Main' => [
            'id' => 2,
            'openTime' => '12:00',
            'closeTime' => '14:00',
            'status' => 'closed',
            'statusText' => 'Closed',
            'statusClass' => 'status-closed',
            'isPlayable' => false
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
            background: linear-gradient(135deg, #052b43ff 0%, #0e1427ff 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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

                    /* Banner Slider */
            .banner-slider {
                position: relative;
                height: 400px;
                border-radius: 15px;
                overflow: hidden;
                margin-bottom: 3rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            }

            .slide {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                opacity: 0;
                transition: opacity 1s ease;
                display: flex;
                align-items: center;
                padding: 0 5%;
                background-size: cover;
                background-position: center;
                background-repeat: no-repeat;
            }

            .slide.active {
                opacity: 1;
            }

            .slide:nth-child(1) {
                background-image: linear-gradient(to right, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.3)), url('https://images.unsplash.com/photo-1659382151328-30c3df37a69a?w=1200&auto=format&fit=crop&q=80&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MzR8fGJldHRpbmd8ZW58MHx8MHx8fDA%3D');
            }

            .slide:nth-child(2) {
                background-image: linear-gradient(to right, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.3)), url('https://images.unsplash.com/photo-1542027953342-020384de63a0?w=1200&auto=format&fit=crop&q=80&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MjZ8fGJldHRpbmd8ZW58MHx8MHx8fDA%3D');
            }

            .slide:nth-child(3) {
                background-image: linear-gradient(to right, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.3)), url('https://plus.unsplash.com/premium_photo-1718826131603-2a5d9398c05a?w=700&auto=format&fit=crop&q=60&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MjIxfHxiZXR0aW5nfGVufDB8MHwwfHx8MA%3D%3D');
            }

            .slide-content {
                max-width: 600px;
                z-index: 2;
                position: relative;
            }

            .slide h2 {
                font-size: 3rem;
                margin-bottom: 1rem;
                text-shadow: 2px 2px 5px rgba(0, 0, 0, 0.5);
            }

            .slide p {
                font-size: 1.2rem;
                margin-bottom: 2rem;
                text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
            }

            .play-btn {
                background: linear-gradient(to right, var(--primary), var(--secondary));
                color: white;
                border: none;
                padding: 12px 30px;
                font-size: 1.1rem;
                border-radius: 50px;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 5px 15px rgba(255, 60, 126, 0.4);
            }

            .play-btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 20px rgba(255, 60, 126, 0.6);
            }

            .slider-dots {
                position: absolute;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                display: flex;
                gap: 10px;
                z-index: 3;
            }

            .dot {
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.5);
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .dot.active {
                background: var(--primary);
                transform: scale(1.2);
            }

        /* Games Section */
        .section-title {
            font-size: 2rem;
            margin-bottom: 2rem;
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
            width: 100px;
            height: 4px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: 2px;
        }

        .games-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 4rem;
        }

        .game-card {
            background: linear-gradient(145deg, #1e2044, #191a38);
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .game-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        .game-img {
            height: 200px;
            width: 100%;
            background-size: cover;
            background-position: center;
        }

        .game-content {
            padding: 1.5rem;
        }

        .game-title {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .game-desc {
            color: #b2bec3;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .game-btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            font-weight: 500;
        }

        .game-btn:hover {
            background: linear-gradient(to right, var(--secondary), var(--primary));
        }

    
   
   
        .satta-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 25px;
        margin-bottom: 4rem;
    
     }

    .satta-card {
        background: linear-gradient(145deg, #1e2044, #191a38);
        border-radius: 15px;
        overflow: hidden;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        position: relative;
    }

    .satta-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(108, 92, 231, 0.3);
    }
    .satta-card a{
      text-decoration: none;
    }

    .satta-img {
        height: 160px;
        width: 100%;
        background-size: cover;
        background-position: center;
        position: relative;
    }

    .satta-img::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 60%;
        background: linear-gradient(to top, rgba(30, 32, 68, 0.9), transparent);
    }

    .satta-header {
        padding: 1.2rem;
        background: rgba(108, 92, 231, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
    }

    .satta-title {
        font-size: 1.3rem;
        margin-bottom: 0.5rem;
        color: var(--accent);
        text-shadow: 0 0 5px rgba(0, 206, 201, 0.5);
    }

    .satta-result {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 1.1rem;
        color: var(--warning);
        font-weight: 600;
        letter-spacing: 2px;
    }

    .satta-body {
        padding: 1.5rem;
    }

    .satta-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .satta-info:last-child {
        margin-bottom: 1.5rem;
    }

    .satta-label {
        color: #b2bec3;
        font-size: 0.9rem;
    }

    .satta-value {
        font-weight: 500;
        color: var(--primary);
    }

    .timer {
        font-family: 'Courier New', monospace;
        background: rgba(0, 0, 0, 0.2);
        padding: 3px 8px;
        border-radius: 4px;
        color: var(--warning);
    }

    /* Different card styles */
    .satta-card:nth-child(1) {
        background: linear-gradient(145deg, #2d1b35, #1d1135);
        border-top: 3px solid #ff3c7e;
    }
    
    .satta-card:nth-child(1) .satta-header {
        background: rgba(255, 60, 126, 0.1);
    }
    
    .satta-card:nth-child(6) {
        background: linear-gradient(145deg, #1b3b5c, #0d2b4b);
        border-top: 3px solid #0fb4c9;
    }
    
    .satta-card:nth-child(6) .satta-header {
        background: rgba(15, 180, 201, 0.1);
    }
    
    .satta-card:nth-child(3) {
        background: linear-gradient(145deg, #2c5530, #1c4220);
        border-top: 3px solid #00b894;
    }
    
    .satta-card:nth-child(3) .satta-header {
        background: rgba(0, 184, 148, 0.1);
    }
    
    .satta-card:nth-child(4) {
        background: linear-gradient(145deg, #5c2b2b, #4b1d1d);
        border-top: 3px solid #d63031;
    }
    
    .satta-card:nth-child(4) .satta-header {
        background: rgba(214, 48, 49, 0.1);
    }
    
    .satta-card:nth-child(5) {
        background: linear-gradient(145deg, #5c4b2b, #4b3a1d);
        border-top: 3px solid #fdcb6e;
    }
    
    .satta-card:nth-child(5) .satta-header {
        background: rgba(253, 203, 110, 0.1);
    }
    
    .satta-card:nth-child(2) {
        background: linear-gradient(145deg, #2b5c5c, #1d4b4b);
        border-top: 3px solid #00cec9;
    }
    
    .satta-card:nth-child(2) .satta-header {
        background: rgba(0, 206, 201, 0.1);
    }
        /* Card 7 - Purple & Pink */
        .satta-card:nth-child(7) {
            background: linear-gradient(145deg, #4a235a, #2f1b3a);
            border-top: 3px solid #9b59b6;
        }

        .satta-card:nth-child(7) .satta-header {
            background: rgba(155, 89, 182, 0.1);
        }

        /* Card 8 - Deep Blue & Teal */
        .satta-card:nth-child(8) {
            background: linear-gradient(145deg, #1a5276, #0f2d44);
            border-top: 3px solid #3498db;
        }

        .satta-card:nth-child(8) .satta-header {
            background: rgba(52, 152, 219, 0.1);
        }

        /* Card 9 - Emerald & Forest Green */
        .satta-card:nth-child(9) {
            background: linear-gradient(145deg, #186a3b, #0f4526);
            border-top: 3px solid #27ae60;
        }

        .satta-card:nth-child(9) .satta-header {
            background: rgba(39, 174, 96, 0.1);
        }

        /* Card 10 - Ruby & Crimson */
        .satta-card:nth-child(10) {
            background: linear-gradient(145deg, #7d3c98, #5e2a7a);
            border-top: 3px solid #e74c3c;
        }

        .satta-card:nth-child(10) .satta-header {
            background: rgba(231, 76, 60, 0.1);
        }

        /* Card 11 - Amber & Orange */
        .satta-card:nth-child(11) {
            background: linear-gradient(145deg, #b9770e, #8e5c0b);
            border-top: 3px solid #f39c12;
        }

        .satta-card:nth-child(11) .satta-header {
            background: rgba(243, 156, 18, 0.1);
        }

        /* Card 12 - Cyan & Sky Blue */
        .satta-card:nth-child(12) {
            background: linear-gradient(145deg, #117a65, #0b5545);
            border-top: 3px solid #1abc9c;
        }

        .satta-card:nth-child(12) .satta-header {
            background: rgba(26, 188, 156, 0.1);
        }
    /* Status badges */
    .status-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .status-open {
        background: var(--success);
        color: white;
    }
    
    .status-closed {
        background: var(--danger);
        color: white;
    }
    
    .status-coming {
        background: var(--warning);
        color: black;
    }

    @media (max-width: 1024px) {
        .satta-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 576px) {
        .satta-grid {
            grid-template-columns: 1fr;
        }
    }

        /* Footer */
        footer {
            background: #0c0f1c;
            padding: 3rem 2rem 1.5rem;
            margin-top: auto;
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
        }

        .social-links a:hover {
            color: var(--primary);
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
        }


        .footer-links a:hover {
            color: var(--primary);
        }

        .copyright {
            text-align: center;
            color: #636e72;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .games-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .satta-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .slide h2 {
                font-size: 2rem;
            }
            
            .slide p {
                font-size: 1rem;
            }
            
            .user-info {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .games-grid {
                grid-template-columns: 1fr;
            }
            
            .satta-grid {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                flex-direction: column;
                gap: 1.5rem;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .banner-slider {
                height: 350px;
            }
        }
          /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(5px);
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-content {
        background: linear-gradient(145deg, #1e2044, #191a38);
        margin: 5% auto;
        padding: 0;
        border-radius: 15px;
        width: 90%;
        max-width: 1000px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        animation: slideIn 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    @keyframes slideIn {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .close-modal {
        position: absolute;
        top: 15px;
        right: 20px;
        color: #fff;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        z-index: 10;
        transition: all 0.3s ease;
        background: rgba(255, 60, 126, 0.7);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .close-modal:hover {
        background: var(--primary);
        transform: rotate(90deg);
    }

    .modal-header {
        background: linear-gradient(to right, var(--primary), var(--secondary));
        padding: 1.5rem 2rem;
        color: white;
        border-radius: 15px 15px 0 0;
    }

    .modal-game-title {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .modal-game-time {
        font-size: 1.1rem;
        opacity: 0.9;
    }

    .modal-body {
        padding: 2rem;
        max-height: 70vh;
        overflow-y: auto;
    }

    /* Bet Types Grid in Modal */
    .modal-bet-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.5rem;
        margin-top: 1.5rem;
    }

    .modal-bet-card {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 10px;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .modal-bet-card:hover {
        transform: translateY(-5px);
        background: rgba(255, 255, 255, 0.1);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        border-color: var(--primary);
    }

    .modal-bet-icon {
        font-size: 2.5rem;
        margin-bottom: 1rem;
        display: block;
    }

    .modal-bet-title {
        font-size: 1.2rem;
        margin-bottom: 0.5rem;
        color: var(--accent);
    }

    .modal-bet-desc {
        font-size: 0.9rem;
        color: #b2bec3;
        margin-bottom: 1rem;
    }

    .modal-bet-btn {
        background: linear-gradient(to right, var(--primary), var(--secondary));
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 50px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 500;
        width: 100%;
    }

    .modal-bet-btn:hover {
        background: linear-gradient(to right, var(--secondary), var(--primary));
        transform: scale(1.05);
    }

    /* Responsive Modal */
    @media (max-width: 768px) {
        .modal-content {
            width: 95%;
            margin: 10% auto;
        }
        
        .modal-bet-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 576px) {
        .modal-bet-grid {
            grid-template-columns: 1fr;
        }
        
        .modal-header {
            padding: 1rem;
        }
        
        .modal-game-title {
            font-size: 1.5rem;
        }
    }
    .modal-bet-payout {
    font-size: 0.8rem;
    color: var(--warning);
    margin-bottom: 1rem;
    font-weight: 600;
    background: rgba(0, 0, 0, 0.2);
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
 }

  /* Mobile-only responsive styles - FIXED VERSION */ 
 @media (max-width: 768px) {
    /* Updated Satta Grid for Mobile - Rectangle Cards */
    .satta-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .satta-card {
        height: auto; /* Let content determine height */
        min-height: 120px;
        display: flex;
        flex-direction: row;
        padding: 0;
        overflow: hidden;
    }

    .satta-img {
        display: none; /* Hide image on mobile */
    }

    .satta-header {
        flex: 1;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-right: 1px solid rgba(255, 255, 255, 0.1);
        min-width: 120px; /* Ensure enough space for game title */
    }

    .satta-title {
        font-size: 1rem;
        margin-bottom: 0.3rem;
        line-height: 1.2;
    }

    .satta-result {
        font-size: 0.8rem;
    }

    .satta-body {
        flex: 2;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .satta-info {
        margin-bottom: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }

    .satta-label {
        font-size: 0.8rem;
        min-width: 70px;
    }

    .satta-value {
        font-size: 0.9rem;
        text-align: right;
    }

    .game-btn {
        padding: 8px 15px;
        font-size: 0.9rem;
        margin: 2px 50px;
        align-self: flex-end;
        width: auto;
        min-width: 100px;
    }

    .status-badge {
        top: 10px;
        right: 20px;
        font-size: 0.6rem;
        padding: 3px 8px;
    }

    /* Ensure all satta-info elements are visible */
    .satta-info:nth-child(1),
    .satta-info:nth-child(2) {
        display: flex !important;
    }

    /* Updated Modal for Mobile - Two game types per row */
    .modal-bet-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .modal-bet-card {
        padding: 1rem;
        min-height: 120px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .modal-bet-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .modal-bet-title {
        font-size: 1rem;
        margin-bottom: 0.3rem;
    }

    .modal-bet-desc {
        font-size: 0.8rem;
        margin-bottom: 0.5rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        flex-grow: 1;
    }

    .modal-bet-btn {
        padding: 6px 15px;
        font-size: 0.9rem;
    }

    .modal-bet-payout {
        font-size: 0.7rem;
        margin-bottom: 0.5rem;
    }

    /* Adjust modal header for mobile */
    .modal-header {
        padding: 1rem;
    }

    .modal-game-title {
        font-size: 1.5rem;
    }

    .modal-game-time {
        font-size: 1rem;
    }
  }

  /* Keep existing desktop styles for larger screens */
   @media (min-width: 769px) {
    .satta-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 25px;
    }
    
    .modal-bet-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 1.5rem;
    }
    
    /* Reset mobile styles for desktop */
    .satta-card {
        display: block;
        height: auto;
    }
    
    .satta-img {
        display: block;
    }
    
    .satta-header {
        border-right: none;
        min-width: auto;
    }
    
    .satta-body {
        display: block;
    }
    
    .satta-info {
        display: flex;
        justify-content: space-between;
    }
 }
 
</style>



    <!-- Main Content -->
    <main>
        <!-- Banner Slider -->
            <section class="banner-slider">
                <div class="slide active">
                    <div class="slide-content">
                        <h2>Win Big with RB Games</h2>
                        <p>Experience the thrill of betting with the most trusted platform. Join now and get 100% bonus on your first deposit!</p>
                        <button class="play-btn">Play Now</button>
                    </div>
                </div>
                <div class="slide">
                    <div class="slide-content">
                        <h2>Live Betting Action</h2>
                        <p>Place your bets in real-time with our live betting feature. Exciting matches, incredible odds!</p>
                        <button class="play-btn">Join Now</button>
                    </div>
                </div>
                <div class="slide">
                    <div class="slide-content">
                        <h2>Daily Jackpots</h2>
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
<!-- Satta Matka Lobby -->
<section class="satta-lobby">
    <h2 class="section-title">Satta Matka Lobby</h2>
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
            <div class="satta-img" style="background-image: url('https://images.unsplash.com/photo-1542744095-fcf48d80b0fd?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1000&q=80');"></div>
            <div class="satta-header">
                <span class="status-badge <?php echo $game_info['statusClass']; ?>">
                    <?php echo $game_info['statusText']; ?>
                </span>
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
                <button class="game-btn" <?php echo $is_closed ? 'disabled style="background: #666; cursor: not-allowed;"' : ''; ?>>
                    <?php echo $is_closed ? 'Closed' : 'Play Now'; ?>
                </button>
                
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
                    ✅ Time Running - Bet Now!
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
                newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(0, 184, 148, 0.2); border-radius: 5px; font-weight: bold; color: #00b894;">✅ Time Running - Bet Now!</div>';
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
        slideInterval = setInterval(nextSlide, 3000);
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
                        newMessage = '<div style="text-align: center; margin-top: 10px; padding: 10px; background: rgba(0, 184, 148, 0.2); border-radius: 5px; font-weight: bold; color: #00b894;">✅ Time Running - Bet Now!</div>';
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