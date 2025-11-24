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

include "includes/header.php";
?>

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