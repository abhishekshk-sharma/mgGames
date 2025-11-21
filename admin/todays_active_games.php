<?php
// todays_active_games.php
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

$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code  = $stmt->get_result();
$referral_code = $referral_code->fetch_assoc();

// Get today's date
$today = date('Y-m-d');
$current_time = date('H:i:s');

// Get today's active game sessions
$sql = "SELECT 
            gs.id as session_id,
            g.id as game_id,
            g.name as game_name,
            g.open_time,
            g.close_time,
            gs.session_date,
            gs.open_result,
            gs.close_result,
            gs.jodi_result,
            COUNT(b.id) as total_bets,
            SUM(b.amount) as total_bet_amount,
            SUM(CASE WHEN b.status = 'won' THEN b.potential_win ELSE 0 END) as total_payout,
            SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as won_bets,
            SUM(CASE WHEN b.status = 'lost' THEN 1 ELSE 0 END) as lost_bets,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets,
            MAX(b.placed_at) as last_bet_time
        FROM games g
        JOIN game_sessions gs ON g.id = gs.game_id
        LEFT JOIN bets b ON gs.id = b.game_session_id
        JOIN users u ON b.user_id = u.id
        WHERE DATE(gs.session_date) = ? AND u.referral_code = '".$referral_code['referral_code']."'
        GROUP BY gs.id, g.id, g.name, g.open_time, g.close_time, gs.session_date, 
                 gs.open_result, gs.close_result, gs.jodi_result
        ORDER BY g.open_time ASC, g.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();
$active_games = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $active_games[] = $row;
    }
}

// Get today's overall stats
$stats_sql = "SELECT 
    COUNT(DISTINCT gs.id) as total_sessions,
    COUNT(b.id) as total_bets,
    SUM(b.amount) as total_bet_amount,
    SUM(CASE WHEN b.status = 'won' THEN b.potential_win ELSE 0 END) as total_payout,
    SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as total_won_bets
    FROM game_sessions gs
    JOIN games g ON gs.game_id = g.id
    LEFT JOIN bets b ON gs.id = b.game_session_id
    JOIN users u ON b.user_id = u.id
    WHERE DATE(gs.session_date) = ? AND u.referral_code = '".$referral_code['referral_code']."'";

$stmt_stats = $conn->prepare($stats_sql);
$stmt_stats->bind_param('s', $today);
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();

$total_sessions = $stats['total_sessions'] ? $stats['total_sessions'] : 0;
$total_bets = $stats['total_bets'] ? $stats['total_bets'] : 0;
$total_bet_amount = $stats['total_bet_amount'] ? $stats['total_bet_amount'] : 0;
$total_payout = $stats['total_payout'] ? $stats['total_payout'] : 0;
$total_won_bets = $stats['total_won_bets'] ? $stats['total_won_bets'] : 0;

// Get games that haven't started yet (for informational purposes)
$upcoming_sql = "SELECT DISTINCT g.name, g.open_time, g.close_time
                 FROM games g
                 WHERE NOT EXISTS (
                     SELECT 1 FROM game_sessions gs 
                     WHERE gs.game_id = g.id AND DATE(gs.session_date) = ?
                 )
                 ORDER BY g.open_time ASC";
$stmt_upcoming = $conn->prepare($upcoming_sql);
$stmt_upcoming->bind_param('s', $today);
$stmt_upcoming->execute();
$upcoming_result = $stmt_upcoming->get_result();
$upcoming_games = [];

if ($upcoming_result && $upcoming_result->num_rows > 0) {
    while ($row = $upcoming_result->fetch_assoc()) {
        $upcoming_games[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Active Games - RB Games Admin</title>
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
            overflow-x:scroll;
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
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
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

        .current-date {
            background: rgba(11, 180, 201, 0.2);
            padding: 1rem 1.5rem;
            border-radius: 10px;
            border: 1px solid rgba(11, 180, 201, 0.3);
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .current-date h2 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }

        .current-date .date {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
        }

        /* Stats Grid */
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
            text-align: center;
            position: relative;
            transition: all 0.3s ease;
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

        .sessions-icon { background: rgba(255, 60, 126, 0.2); color: var(--primary); }
        .bets-icon { background: rgba(0, 184, 148, 0.2); color: var(--success); }
        .amount-icon { background: rgba(253, 203, 110, 0.2); color: var(--warning); }
        .payout-icon { background: rgba(11, 180, 201, 0.2); color: var(--secondary); }

        .stat-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* Game Cards */
        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.2rem;
        }

        .game-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .game-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }

        .game-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.35);
            border-color: var(--primary);
        }

        .game-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .game-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-light);
        }

        .game-time {
            background: rgba(255, 60, 126, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            border: 1px solid rgba(255, 60, 126, 0.3);
        }

        .game-status {
            display: inline-block;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .status-active { background: rgba(0, 184, 148, 0.2); color: var(--success); border: 1px solid rgba(0, 184, 148, 0.3); }
        .status-completed { background: rgba(11, 180, 201, 0.2); color: var(--secondary); border: 1px solid rgba(11, 180, 201, 0.3); }
        .status-upcoming { background: rgba(253, 203, 110, 0.2); color: var(--warning); border: 1px solid rgba(253, 203, 110, 0.3); }

        .game-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        .game-results {
            display: flex;
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .result-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .result-open { background: rgba(0, 184, 148, 0.2); color: var(--success); border: 1px solid rgba(0, 184, 148, 0.3); }
        .result-close { background: rgba(253, 203, 110, 0.2); color: var(--warning); border: 1px solid rgba(253, 203, 110, 0.3); }
        .result-jodi { background: rgba(11, 180, 201, 0.2); color: var(--secondary); border: 1px solid rgba(11, 180, 201, 0.3); }
        .result-pending { background: rgba(108, 117, 125, 0.2); color: #6c757d; border: 1px solid rgba(108, 117, 125, 0.3); }

        .game-actions {
            display: flex;
            justify-content: flex-end;
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

        /* Upcoming Games Section */
        .upcoming-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2.2rem;
            border: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .upcoming-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .upcoming-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .upcoming-info h4 {
            margin-bottom: 0.3rem;
        }

        .upcoming-time {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* No Games State */
        .no-games {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .no-games i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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

        /* Responsive Design */
        @media (max-width: 993px){
            .sidebar {
                width: 260px;
                left: 0;
                position: fixed;
            }
            .sidebar-overlay {
                display: none !important;
            }
        }

        @media (max-width: 992px) and (min-width: 769px) {
            .sidebar {
                width: 80px;
                left: 0;
            }
        }
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
                margin-left: 0;
            }
            
            .sidebar {
                width: 260px;
                left: -260px;
            }
            
            .games-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .game-stats {
                grid-template-columns: repeat(3, 1fr);
            }
            .menu-toggle {
                    display: block;
            }
            .sidebar-overlay {
                display: none !important;
            }
            .sidebar.active {
                left: 0;
            }
            .header{
                margin-top: 3rem;
            }
        }

        @media (max-width: 576px) {
           
            
            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .game-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .game-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .menu-toggle {
                    top: 0.8rem;
                    left: 0.8rem;
                }
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
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                
                
                <a href="todays_active_games.php" class="menu-item active">
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
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Today's Active Games</h1>
                    <p>Real-time overview of today's gaming activity</p>
                </div>
            </div>

            <!-- Current Date Display -->
            <div class="current-date">
                <h2><i class="fas fa-calendar-day"></i> Today is</h2>
                <div class="date"><?php echo date('l, F j, Y'); ?></div>
                <div class="current-time" style="margin-top: 0.5rem; font-size: 0.9rem;">
                    <i class="fas fa-clock"></i>
                    Current Time: <span id="liveTime"><?php echo date('h:i:s A'); ?></span>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon sessions-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_sessions); ?></div>
                    <div class="stat-card-title">Active Sessions</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon bets-icon">
                        <i class="fas fa-dice"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_bets); ?></div>
                    <div class="stat-card-title">Total Bets</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon amount-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_bet_amount, 2); ?></div>
                    <div class="stat-card-title">Total Bet Amount</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon payout-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_payout, 2); ?></div>
                    <div class="stat-card-title">Total Payout</div>
                </div>
            </div>

            <!-- Active Games Grid -->
            <div class="section">
                <h2 class="section-title"><i class="fas fa-gamepad"></i> Today's Game Sessions</h2>
                
                <?php if (!empty($active_games)): ?>
                    <div class="games-grid">
                        <?php foreach ($active_games as $game): 
                            $profit_loss = $game['total_bet_amount'] - $game['total_payout'];
                            $has_results = $game['open_result'] || $game['close_result'] || $game['jodi_result'];
                            $status = $has_results ? 'completed' : 'active';
                        ?>
                            <div class="game-card">
                                <div class="game-header">
                                    <div class="game-title"><?php echo htmlspecialchars($game['game_name']); ?></div>
                                    <div class="game-time">
                                        <?php echo date('h:i A', strtotime($game['open_time'])); ?> - 
                                        <?php echo date('h:i A', strtotime($game['close_time'])); ?>
                                    </div>
                                </div>
                                
                                <div class="game-status status-<?php echo $status; ?>">
                                    <i class="fas fa-<?php echo $status == 'active' ? 'play' : 'check'; ?>"></i>
                                    <?php echo ucfirst($status); ?>
                                </div>
                                
                                <div class="game-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($game['total_bets']); ?></div>
                                        <div class="stat-label">Total Bets</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">$<?php echo number_format($game['total_bet_amount'], 2); ?></div>
                                        <div class="stat-label">Bet Amount</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($game['won_bets']); ?></div>
                                        <div class="stat-label">Won</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($game['pending_bets']); ?></div>
                                        <div class="stat-label">Pending</div>
                                    </div>
                                </div>
                                
                                <div class="game-results">
                                    <?php if ($game['open_result']): ?>
                                        <div class="result-badge result-open">
                                            <i class="fas fa-sun"></i> Open: <?php echo $game['open_result']; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="result-badge result-pending">
                                            <i class="fas fa-clock"></i> Open: Pending
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($game['close_result']): ?>
                                        <div class="result-badge result-close">
                                            <i class="fas fa-moon"></i> Close: <?php echo $game['close_result']; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="result-badge result-pending">
                                            <i class="fas fa-clock"></i> Close: Pending
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="game-actions">
                                    <a href="game_session_detail.php?session_id=<?php echo $game['session_id']; ?>&game_id=<?php echo $game['game_id']; ?>&date=<?php echo $today; ?>" 
                                       class="btn btn-primary">
                                        <i class="fas fa-chart-bar"></i>
                                        View Analytics
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-games">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Active Games Today</h3>
                        <p>There are no game sessions scheduled for today.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Games Section -->
            <?php if (!empty($upcoming_games)): ?>
            <div class="upcoming-section">
                <h2 class="section-title"><i class="fas fa-clock"></i> Upcoming Games</h2>
                <div class="upcoming-list">
                    <?php foreach ($upcoming_games as $upcoming): ?>
                        <div class="upcoming-item">
                            <div class="upcoming-info">
                                <h4><?php echo htmlspecialchars($upcoming['name']); ?></h4>
                                <div class="upcoming-time">
                                    <?php echo date('h:i A', strtotime($upcoming['open_time'])); ?> - 
                                    <?php echo date('h:i A', strtotime($upcoming['close_time'])); ?>
                                </div>
                            </div>
                            <div class="game-status status-upcoming">
                                <i class="fas fa-clock"></i> Upcoming
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script>

    $(document).ready(function(){

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
    });
   

        // Live time update
        function updateLiveTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: true,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('liveTime').textContent = timeString;
        }
        
        // Update time immediately and every second
        updateLiveTime();
        setInterval(updateLiveTime, 1000);
        
        // Auto-refresh page every 30 seconds to get updated data
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>

</body>
</html>