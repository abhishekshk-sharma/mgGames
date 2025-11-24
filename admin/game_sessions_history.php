<?php
// game_sessions_history.php - CORRECTED VERSION
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
$referral_code_result = $stmt->get_result();
$referral_code_data = $referral_code_result->fetch_assoc();
$referral_code = $referral_code_data['referral_code'];

// Get admin bet limits and PNL ratio
$limit_stmt = $conn->prepare("SELECT bet_limit, pnl_ratio FROM broker_limit WHERE admin_id = ?");
$limit_stmt->execute([$admin_id]);
$limit_result = $limit_stmt->get_result();
$admin_limits = $limit_result->fetch_assoc();

$bet_limit = $admin_limits['bet_limit'] ?? 100;
$pnl_ratio = $admin_limits['pnl_ratio'] ?? null;

// Parse PNL ratio if set
$admin_ratio = 0;
$forward_ratio = 0;
if ($pnl_ratio && strpos($pnl_ratio, ':') !== false) {
    $ratio_parts = explode(':', $pnl_ratio);
    $admin_ratio = intval($ratio_parts[0]);
    $forward_ratio = intval($ratio_parts[1]);
}

// Date filtering setup
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
$filter_result = isset($_GET['filter_result']) ? $_GET['filter_result'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build query for game sessions count
$count_sql = "SELECT COUNT(DISTINCT CONCAT(g.name, '_', DATE(gs.session_date))) as total 
              FROM game_sessions gs
              JOIN games g ON gs.game_id = g.id
              WHERE DATE(gs.session_date) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types = 'ss';

if ($filter_game) {
    $count_sql .= " AND g.name LIKE ?";
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $count_sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $count_sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$stmt_count = $conn->prepare($count_sql);
if ($params) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Build main query for game sessions with aggregated bet data
$sql = "SELECT 
            g.name as game_name,
            DATE(gs.session_date) as session_date,
            g.open_time,
            g.close_time,
            gs.open_result,
            gs.close_result,
            gs.jodi_result,
            gs.id as session_id,
            g.id as game_id,
            COUNT(u.id) as total_bets,
            SUM(b.amount) as total_bet_amount,
            SUM(CASE WHEN b.status = 'won' THEN b.potential_win ELSE 0 END) as total_payout,
            SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as won_bets,
            SUM(CASE WHEN b.status = 'lost' THEN 1 ELSE 0 END) as lost_bets,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets,
            MAX(b.placed_at) as last_bet_time
        FROM game_sessions gs
        JOIN games g ON gs.game_id = g.id
        LEFT JOIN bets b ON gs.id = b.game_session_id
        JOIN users u ON b.user_id = u.id
        WHERE DATE(gs.session_date) BETWEEN ? AND ? AND u.referral_code = ?";

if ($filter_game) {
    $sql .= " AND g.name LIKE ?";
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$sql .= " GROUP BY g.name, DATE(gs.session_date), g.open_time, g.close_time, gs.open_result, gs.close_result, gs.jodi_result, gs.id, g.id
          ORDER BY gs.session_date DESC, g.open_time DESC
          LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params = [$start_date, $end_date, $referral_code];
$types = 'sss';

if ($filter_game) {
    $params[] = "%$filter_game%";
    $types .= 's';
}

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

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

// Get detailed bet data for each session to calculate CORRECTED statistics
$adjusted_sessions = [];

// Pre-fetch game types payout ratios for efficiency
$game_type_ratios = [];
$stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
$stmt_game_types->execute();
$game_types_result = $stmt_game_types->get_result();
while ($game_type_row = $game_types_result->fetch_assoc()) {
    $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
}

foreach ($game_sessions as $session) {
    $session_id = $session['session_id'];
    
    // Get all bets for this session to calculate adjusted amounts
    $bets_sql = "SELECT b.*, u.username 
                 FROM bets b 
                 JOIN users u ON b.user_id = u.id 
                 WHERE b.game_session_id = ? AND u.referral_code = ?";
    $stmt_bets = $conn->prepare($bets_sql);
    $stmt_bets->bind_param('is', $session_id, $referral_code);
    $stmt_bets->execute();
    $bets_result = $stmt_bets->get_result();
    
    $admin_actual_bet_amount = 0;  // Total amount admin actually risked (after forwarding)
    $admin_actual_payout = 0;      // Total payout admin actually paid for won bets (after forwarding)
    $forwarded_total = 0;          // Total forwarded to super admin
    
    if ($bets_result && $bets_result->num_rows > 0) {
        while ($bet = $bets_result->fetch_assoc()) {
            $bet_amount = $bet['amount'];
            $bet_potential_win = $bet['potential_win'];
            $bet_status = $bet['status'];
            $game_type_id = $bet['game_type_id'];
            
            // Get the payout ratio for this game type
            $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00; // Default to 9 if not found
            
            // Calculate automatic forwarding based on bet_limit/pnl_ratio
            $bet_admin_amount = $bet_amount;
            $bet_forwarded_amount = 0;
            
            $bet_numbers = json_decode($bet['numbers_played'], true);
            
            if (is_array($bet_numbers)) {
                if (isset($bet_numbers['selected_digits'])) {
                    // For SP Motor
                    if (isset($bet_numbers['pana_combinations'])) {
                        $amount_per_pana = $bet_numbers['amount_per_pana'] ?? 0;
                        foreach ($bet_numbers['pana_combinations'] as $pana) {
                            if ($pnl_ratio) {
                                // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                                $forwarded = ($amount_per_pana * $forward_ratio) / 100;
                            } else {
                                // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                                $forwarded = max(0, $amount_per_pana - $bet_limit);
                            }
                            
                            $bet_admin_amount -= $forwarded;
                            $bet_forwarded_amount += $forwarded;
                        }
                    }
                } else {
                    // For single number bets
                    foreach ($bet_numbers as $number => $amount) {
                        if ($pnl_ratio) {
                            // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                            $forwarded = ($amount * $forward_ratio) / 100;
                        } else {
                            // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                            $forwarded = max(0, $amount - $bet_limit);
                        }
                        
                        $bet_admin_amount -= $forwarded;
                        $bet_forwarded_amount += $forwarded;
                    }
                }
            }
            
            // Update admin's actual bet amount (exposure)
            $admin_actual_bet_amount += $bet_admin_amount;
            $forwarded_total += $bet_forwarded_amount;
            
            // Calculate admin's actual payout for WON bets with proper payout ratio
            if ($bet_status == 'won') {
                if ($bet_amount > 0) {
                    $admin_payout_ratio_amount = $bet_admin_amount / $bet_amount;
                    $admin_win_amount = $bet_amount * $admin_payout_ratio_amount;
                    $admin_payout = $admin_win_amount * $payout_ratio;
                    
                    $admin_actual_payout += $admin_payout;
                }
            }
        }
    } else {
        // If no bets, use original values
        $admin_actual_bet_amount = $session['total_bet_amount'];
        $admin_actual_payout = $session['total_payout'];
    }
    
    // CORRECTED P&L Calculation:
    // Your P&L = (Your Actual Bet Amount - Your Actual Payout)
    // Positive = Profit, Negative = Loss
    $profit_loss = $admin_actual_bet_amount - $admin_actual_payout;
    
    // Add adjusted values to session data
    $session['adjusted_total_exposure'] = $admin_actual_bet_amount;  // What admin actually risked
    $session['adjusted_total_payout'] = $admin_actual_payout;       // What admin actually paid out
    $session['forwarded_total'] = $forwarded_total;
    $session['profit_loss'] = $profit_loss;                         // Net profit/loss for admin
    
    $adjusted_sessions[] = $session;
}

// Replace original sessions with adjusted sessions
$game_sessions = $adjusted_sessions;

// Get overall stats for dashboard (using adjusted amounts)
$stats_sql = "SELECT 
    COUNT(DISTINCT CONCAT(g.name, '_', DATE(gs.session_date))) as total_sessions,
    SUM(bets_data.total_bets) as total_bets
    FROM game_sessions gs
    JOIN games g ON gs.game_id = g.id
    LEFT JOIN (
        SELECT 
            user_id,
            game_session_id,
            COUNT(u.id) as total_bets
        FROM bets b JOIN users u ON b.user_id = u.id WHERE u.referral_code = ?
        GROUP BY game_session_id
    ) bets_data ON gs.id = bets_data.game_session_id
    JOIN users u ON bets_data.user_id = u.id
    WHERE DATE(gs.session_date) BETWEEN ? AND ? AND u.referral_code = ?";

$stats_params = [$referral_code, $start_date, $end_date, $referral_code];
$stats_types = 'ssss';

if ($filter_game) {
    $stats_sql .= " AND g.name LIKE ?";
    $stats_params[] = "%$filter_game%";
    $stats_types .= 's';
}

if ($filter_result) {
    if ($filter_result == 'has_results') {
        $stats_sql .= " AND (gs.open_result IS NOT NULL OR gs.close_result IS NOT NULL OR gs.jodi_result IS NOT NULL)";
    } elseif ($filter_result == 'no_results') {
        $stats_sql .= " AND gs.open_result IS NULL AND gs.close_result IS NULL AND gs.jodi_result IS NULL";
    }
}

$stmt_stats = $conn->prepare($stats_sql);
if ($stats_params) {
    $stmt_stats->bind_param($stats_types, ...$stats_params);
}
$stmt_stats->execute();
$stats_result = $stmt_stats->get_result();
$stats = $stats_result->fetch_assoc();

// Calculate adjusted overall stats from individual sessions
$total_sessions = $stats['total_sessions'] ? $stats['total_sessions'] : 0;
$total_bets = $stats['total_bets'] ? $stats['total_bets'] : 0;

$total_exposure = 0;      // Total amount admin actually risked
$total_payout = 0;        // Total payout admin actually paid
$total_forwarded = 0;     // Total forwarded to super admin
$total_profit_loss = 0;   // Net profit/loss for admin

foreach ($game_sessions as $session) {
    $total_exposure += $session['adjusted_total_exposure'];
    $total_payout += $session['adjusted_total_payout'];
    $total_forwarded += $session['forwarded_total'];
    $total_profit_loss += $session['profit_loss'];
}

// Overall profit/loss is already calculated in total_payout
$total_profit_loss = $total_payout;

// Get unique games for filter dropdown
$games_sql = "SELECT DISTINCT name FROM games ORDER BY name";
$games_result = $conn->query($games_sql);
$games = [];
if ($games_result && $games_result->num_rows > 0) {
    while ($row = $games_result->fetch_assoc()) {
        $games[] = $row['name'];
    }
}

include "includes/header.php";
?>
<body>

    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item active">
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
                    <h1>Game Sessions History</h1>
                    <p>View and analyze game sessions with detailed statistics</p>
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

            <!-- Configuration Indicator -->
            <div class="scenario-indicator">
                <h4><i class="fas fa-cog"></i> Current Configuration</h4>
                <?php if ($pnl_ratio): ?>
                    <p><strong>PNL Ratio Mode:</strong> <?php echo $pnl_ratio; ?> (Admin:<?php echo $admin_ratio; ?>% | Forward:<?php echo $forward_ratio; ?>%)</p>
                    <p>Profit/Loss sharing enabled. Your share: <?php echo $admin_ratio; ?>%</p>
                <?php else: ?>
                    <p><strong>Bet Limit Mode:</strong> ₹<?php echo number_format($bet_limit); ?> per number</p>
                    <p>Individual number bets capped at limit. Excess amounts forwarded.</p>
                <?php endif; ?>
            </div>

            <!-- Date Range Display -->
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                Showing game sessions from <span><?php echo date('M j, Y', strtotime($start_date)); ?></span> to <span><?php echo date('M j, Y', strtotime($end_date)); ?></span>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon sessions-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_sessions); ?></div>
                    <div class="stat-card-title">Total Sessions</div>
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
                    <div class="stat-card-value">₹<?php echo number_format($total_exposure, 2); ?></div>
                    <div class="stat-card-title">Your Actual Exposure</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon payout-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-card-value">₹<?php echo number_format($total_payout, 2); ?></div>
                    <div class="stat-card-title">Your Net P&L</div> <!-- Changed label -->
                </div>

                <!-- Forwarded Amount Card -->
                <div class="stat-card">
                    <div class="stat-card-icon" style="background: rgba(255, 193, 7, 0.2); color: #ffc107;">
                        <i class="fas fa-share-alt"></i>
                    </div>
                    <div class="stat-card-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                    <div class="stat-card-title">Forwarded Amount</div>
                </div>
            </div>

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
                    <input type="hidden" name="page" value="1">
                    
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
                            <label class="filter-label">Results Status</label>
                            <select name="filter_result" class="filter-control">
                                <option value="">All Sessions</option>
                                <option value="has_results" <?php echo $filter_result == 'has_results' ? 'selected' : ''; ?>>With Results</option>
                                <option value="no_results" <?php echo $filter_result == 'no_results' ? 'selected' : ''; ?>>Without Results</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Records per page</label>
                            <select name="limit" class="filter-control">
                                <?php foreach ($allowed_limits as $allowed_limit): ?>
                                    <option value="<?php echo $allowed_limit; ?>" <?php echo $limit == $allowed_limit ? 'selected' : ''; ?>>
                                        <?php echo $allowed_limit; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="game_sessions_history.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Game Sessions List -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Game Sessions</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> sessions</div>
                </div>
                
                <?php if (!empty($game_sessions)): ?>
                    <div class="sessions-list">
                        <?php foreach ($game_sessions as $session): 
                            $profit_loss = $session['profit_loss'];
                            $profit_loss_class = $profit_loss > 0 ? 'profit' : ($profit_loss < 0 ? 'loss' : 'neutral');
                        ?>
                            <div class="session-card">
                                <div class="session-header">
                                    <div class="session-title">
                                        <?php echo htmlspecialchars($session['game_name']); ?>
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
                                        <span class="detail-label">Last Bet Time</span>
                                        <span class="detail-value">
                                            <?php echo $session['last_bet_time'] ? date('h:i A', strtotime($session['last_bet_time'])) : 'No bets'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($session['open_result'] || $session['close_result'] || $session['jodi_result']): ?>
                                    <div class="session-results">
                                        <?php if ($session['open_result']): ?>
                                            <div class="result-badge result-open">
                                                <i class="fas fa-sun"></i>
                                                Open: <?php echo $session['open_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['close_result']): ?>
                                            <div class="result-badge result-close">
                                                <i class="fas fa-moon"></i>
                                                Close: <?php echo $session['close_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($session['jodi_result']): ?>
                                            <div class="result-badge result-jodi">
                                                <i class="fas fa-link"></i>
                                                Jodi: <?php echo $session['jodi_result']; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="session-results">
                                        <div class="result-badge" style="background: rgba(108, 117, 125, 0.2); color: #6c757d; border: 1px solid rgba(108, 117, 125, 0.3);">
                                            <i class="fas fa-clock"></i>
                                            Results Pending
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Adjusted Session Statistics -->
                                <div class="session-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['total_bets']); ?></div>
                                        <div class="stat-label">Total Bets</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">₹<?php echo number_format($session['adjusted_total_exposure'], 2); ?></div>
                                        <div class="stat-label">Your Exposure</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">₹<?php echo number_format($session['adjusted_total_payout'], 2); ?></div>
                                        <div class="stat-label">Your Payout</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value <?php echo $profit_loss_class; ?>">
                                            ₹<?php echo number_format(abs($session['profit_loss']), 2); ?>
                                            <?php if ($session['profit_loss'] > 0): ?>
                                                <i class="fas fa-arrow-up"></i>
                                            <?php elseif ($session['profit_loss'] < 0): ?>
                                                <i class="fas fa-arrow-down"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stat-label">Your P&L</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['won_bets']); ?></div>
                                        <div class="stat-label">Won Bets</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($session['lost_bets']); ?></div>
                                        <div class="stat-label">Lost Bets</div>
                                    </div>
                                </div>

                                <!-- Forwarding Information -->
                                <?php if ($session['forwarded_total'] > 0): ?>
                                    <div class="forward-badge">
                                        <i class="fas fa-share-alt"></i>
                                        <strong>Forwarded Amount:</strong> ₹<?php echo number_format($session['forwarded_total'], 2); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="session-actions">
                                    <a href="game_session_detail.php?session_id=<?php echo $session['session_id']; ?>&game_id=<?php echo $session['game_id']; ?>&date=<?php echo $session['session_date']; ?>" 
                                       class="btn btn-info">
                                        <i class="fas fa-chart-bar"></i>
                                        View Detailed Analytics
                                    </a>
                                </div>
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                            <span class="disabled"><i class="fas fa-angle-left"></i></span>
                        <?php endif; ?>
                        
                        <?php
                        // Show page numbers
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_result=<?php echo $filter_result; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-angle-right"></i></span>
                            <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function updateMenuTextVisibility() {
            const menuSpans = document.querySelectorAll('.menu-item span');
            
            if (window.innerWidth >= 993) {
                // Large screens - always show text
                menuSpans.forEach(span => {
                    span.style.display = 'inline-block';
                });
            } else if (window.innerWidth >= 769) {
                // Medium screens - hide text (icons only)
                menuSpans.forEach(span => {
                    span.style.display = 'none';
                });
            } else {
                // Small screens - show text only when sidebar is active
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

        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    updateMenuTextVisibility();
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
            
            updateMenuTextVisibility();
        }

        // Initialize
        handleResize();
        window.addEventListener('resize', handleResize);

        // Quick filter buttons
        document.querySelectorAll('.quick-filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                
                // Update active state
                document.querySelectorAll('.quick-filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Submit form
                document.getElementById('filterForm').submit();
            });
        });

        // Make date inputs readonly for non-custom filters
        const dateFilter = document.getElementById('dateFilter');
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');

        function updateDateInputs() {
            if (dateFilter.value === 'custom') {
                startDateInput.removeAttribute('readonly');
                endDateInput.removeAttribute('readonly');
            } else {
                startDateInput.setAttribute('readonly', 'readonly');
                endDateInput.setAttribute('readonly', 'readonly');
            }
        }

        updateDateInputs();
        dateFilter.addEventListener('change', updateDateInputs);
    </script>


</body>
</html>