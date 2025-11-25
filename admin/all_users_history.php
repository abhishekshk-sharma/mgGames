<?php
// admin_bet_history.php
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

// Get referral_code
$referral_code = '';
$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $referral_code = $row['referral_code'];
    }
    $stmt->close();
}

// Date filtering setup
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'custom';

// Set dates based on quick filters
if ($date_filter != 'custom') {
    $today = date('Y-m-d');
    switch ($date_filter) {
        case 'today':
            $start_date = $today;
            $end_date = $today;
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = $today;
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = $today;
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last month'));
            break;
    }
}

// Additional filters
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : '';
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// SIMPLIFIED QUERY - Get bets data
$bets = [];
$total_records = 0;
$total_bets = 0;
$total_amount = 0;
$total_potential = 0;
$won_bets = 0;
$pending_bets = 0;

// Build main query for bets - MAKE IT IDENTICAL TO STATS QUERY
$sql = "SELECT b.*, u.username, u.email, g.name as game_name, g.open_time, g.close_time,
               gs.session_date, gs.open_result, gs.close_result, gs.jodi_result
        FROM bets b
        JOIN users u ON b.user_id = u.id
        LEFT JOIN game_sessions gs ON b.game_session_id = gs.id
        JOIN games g ON gs.game_id = g.id
        WHERE DATE(b.placed_at) BETWEEN ? AND ? 
        AND u.referral_code = ?";

$params = [$start_date, $end_date, $referral_code];
$types = 'sss';

if ($filter_user) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $types .= 'ss';
}

if ($filter_game) {
    $sql .= " AND g.name LIKE ?";
    $params[] = "%$filter_game%";
    $types .= 's';
}

if ($filter_status) {
    $sql .= " AND b.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$sql .= " ORDER BY b.placed_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute main query with DEBUGGING
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    // DEBUG: Check the actual result
    $num_rows = $result ? $result->num_rows : 0;
    echo "<!-- DEBUG: Main query returned $num_rows rows -->";
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bets[] = $row;
        }
        echo "<!-- DEBUG: Added " . count($bets) . " bets to array -->";
    } else {
        echo "<!-- DEBUG: No rows found in main query -->";
        // Let's check what happens if we remove filters
        $test_sql = "SELECT COUNT(*) as test_count FROM bets b 
                     JOIN users u ON b.user_id = u.id 
                     WHERE DATE(b.placed_at) BETWEEN ? AND ? 
                     AND u.referral_code = ?";
        $test_stmt = $conn->prepare($test_sql);
        $test_stmt->bind_param('sss', $start_date, $end_date, $referral_code);
        $test_stmt->execute();
        $test_result = $test_stmt->get_result();
        $test_data = $test_result->fetch_assoc();
        echo "<!-- DEBUG: Without filters found: " . $test_data['test_count'] . " bets -->";
        $test_stmt->close();
    }
    $stmt->close();
} else {
    echo "<!-- DEBUG: Statement preparation failed -->";
    echo "<!-- DEBUG: SQL Error: " . $conn->error . " -->";
}

// Get total count for pagination - MAKE IT SIMPLE
$count_sql = "SELECT COUNT(*) as total 
              FROM bets b
              JOIN users u ON b.user_id = u.id
              WHERE DATE(b.placed_at) BETWEEN ? AND ? 
              AND u.referral_code = ?";

$count_params = [$start_date, $end_date, $referral_code];
$count_types = 'sss';

if ($filter_user) {
    $count_sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $count_params[] = "%$filter_user%";
    $count_params[] = "%$filter_user%";
    $count_types .= 'ss';
}

if ($filter_game) {
    $count_sql .= " AND b.game_type_id IN (SELECT id FROM games WHERE name LIKE ?)";
    $count_params[] = "%$filter_game%";
    $count_types .= 's';
}

if ($filter_status) {
    $count_sql .= " AND b.status = ?";
    $count_params[] = $filter_status;
    $count_types .= 's';
}

$stmt_count = $conn->prepare($count_sql);
if ($stmt_count) {
    if ($count_params) {
        $stmt_count->bind_param($count_types, ...$count_params);
    }
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    if ($result_count) {
        $row = $result_count->fetch_assoc();
        $total_records = $row['total'] ?? 0;
        echo "<!-- DEBUG: Count query found: $total_records records -->";
    }
    $stmt_count->close();
} else {
    echo "<!-- DEBUG: Count statement preparation failed -->";
}

$total_pages = ceil($total_records / $limit);

// Get stats - MAKE IT IDENTICAL TO MAIN QUERY
$stats_sql = "SELECT 
    COUNT(*) as total_bets,
    SUM(b.amount) as total_amount,
    SUM(b.potential_win) as total_potential,
    COUNT(CASE WHEN b.status = 'won' THEN 1 END) as won_bets,
    COUNT(CASE WHEN b.status = 'pending' THEN 1 END) as pending_bets
    FROM bets b
    JOIN users u ON b.user_id = u.id 
    JOIN games g ON b.game_type_id = g.id
    WHERE DATE(b.placed_at) BETWEEN ? AND ? 
    AND u.referral_code = ?";

$stats_params = [$start_date, $end_date, $referral_code];
$stats_types = 'sss';

if ($filter_user) {
    $stats_sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $stats_params[] = "%$filter_user%";
    $stats_params[] = "%$filter_user%";
    $stats_types .= 'ss';
}

if ($filter_game) {
    $stats_sql .= " AND g.name LIKE ?";
    $stats_params[] = "%$filter_game%";
    $stats_types .= 's';
}

if ($filter_status) {
    $stats_sql .= " AND b.status = ?";
    $stats_params[] = $filter_status;
    $stats_types .= 's';
}

$stmt_stats = $conn->prepare($stats_sql);
if ($stmt_stats) {
    if ($stats_params) {
        $stmt_stats->bind_param($stats_types, ...$stats_params);
    }
    $stmt_stats->execute();
    $stats_result = $stmt_stats->get_result();
    if ($stats_result && $stats_result->num_rows > 0) {
        $stats = $stats_result->fetch_assoc();
        $total_bets = $stats['total_bets'] ?? 0;
        $total_amount = $stats['total_amount'] ?? 0;
        $total_potential = $stats['total_potential'] ?? 0;
        $won_bets = $stats['won_bets'] ?? 0;
        $pending_bets = $stats['pending_bets'] ?? 0;
        echo "<!-- DEBUG: Stats query found: $total_bets bets -->";
    }
    $stmt_stats->close();
} else {
    echo "<!-- DEBUG: Stats statement preparation failed -->";
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


// DIRECT TEST: Let's run the exact same query without pagination to see what we get
$test_direct_sql = "SELECT b.id, b.placed_at, u.username, u.referral_code 
                    FROM bets b
                    JOIN users u ON b.user_id = u.id
                    WHERE DATE(b.placed_at) BETWEEN ? AND ? 
                    AND u.referral_code = ?
                    ORDER BY b.placed_at DESC 
                    LIMIT 5";
$test_direct_stmt = $conn->prepare($test_direct_sql);
$test_direct_stmt->bind_param('sss', $start_date, $end_date, $referral_code);
$test_direct_stmt->execute();
$test_direct_result = $test_direct_stmt->get_result();
$direct_bets = [];
while ($row = $test_direct_result->fetch_assoc()) {
    $direct_bets[] = $row;
}
$test_direct_stmt->close();

echo "<!-- DEBUG DIRECT QUERY: Found " . count($direct_bets) . " bets -->";
foreach ($direct_bets as $direct_bet) {
    echo "<!-- DEBUG: Bet ID: " . $direct_bet['id'] . ", Date: " . $direct_bet['placed_at'] . ", User: " . $direct_bet['username'] . ", Ref: " . $direct_bet['referral_code'] . " -->";
}

$pagefilename = "all_users_history";

include "includes/header.php";
?>


        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Bet History</h1>
                    <p>View and analyze user betting activity</p>
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

            

            <!-- Date Range Display -->
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                Showing bets from <span><?php echo date('M j, Y', strtotime($start_date)); ?></span> to <span><?php echo date('M j, Y', strtotime($end_date)); ?></span>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
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
                    <div class="stat-card-value">$<?php echo number_format($total_amount, 2); ?></div>
                    <div class="stat-card-title">Total Bet Amount</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon potential-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-card-value">$<?php echo number_format($total_potential, 2); ?></div>
                    <div class="stat-card-title">Potential Winnings</div>
                </div>

                <div class="stat-card">
                    <div class="stat-card-icon won-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($won_bets); ?></div>
                    <div class="stat-card-title">Won Bets</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-filter"></i> Filter Bets</h3>
                
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
                            <label class="filter-label">User Search</label>
                            <input type="text" name="filter_user" class="filter-control" placeholder="Username or email" value="<?php echo htmlspecialchars($filter_user); ?>">
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
                            <label class="filter-label">Status</label>
                            <select name="filter_status" class="filter-control">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="won" <?php echo $filter_status == 'won' ? 'selected' : ''; ?>>Won</option>
                                <option value="lost" <?php echo $filter_status == 'lost' ? 'selected' : ''; ?>>Lost</option>
                                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                        <a href="all_users_history.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bets Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Bet History</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> bets</div>
                </div>
                
                <?php if (!empty($bets)): ?>
                    <!-- Desktop Table View -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Game</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Numbers Played</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Results</th>
                                    <th>Status</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><?php echo $bet['id']; ?></td>
                                        <td>
                                            <div><?php echo $bet['username']; ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?php echo $bet['email']; ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo $bet['game_name']; ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;">
                                                <?php echo date('h:i A', strtotime($bet['open_time'])); ?> - 
                                                <?php echo date('h:i A', strtotime($bet['close_time'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="game-type">
                                                <?php echo str_replace('_', ' ', $bet['game_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="bet-mode">
                                                <?php echo ucfirst($bet['bet_mode']); ?>
                                            </span>
                                        </td>
                                        <td class="numbers-played">
                                            <?php 
                                            $numbers = json_decode($bet['numbers_played'], true);
                                            if (is_array($numbers)) {
                                                if (isset($numbers['selected_digits'])) {
                                                    echo "Digits: " . $numbers['selected_digits'];
                                                    if (isset($numbers['pana_combinations'])) {
                                                        echo "<br>Panas: " . implode(', ', $numbers['pana_combinations']);
                                                    }
                                                } else {
                                                    foreach ($numbers as $number => $amount) {
                                                        echo $number . " ($" . $amount . ")<br>";
                                                    }
                                                }
                                            } else {
                                                echo htmlspecialchars($bet['numbers_played']);
                                            }
                                            ?>
                                        </td>
                                        <td>$<?php echo number_format($bet['amount'], 2); ?></td>
                                        <td>$<?php echo number_format($bet['potential_win'], 2); ?></td>
                                        <td>
                                            <div class="results">
                                                <?php if ($bet['open_result']): ?>
                                                    <span class="result-badge">Open: <?php echo $bet['open_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if ($bet['close_result']): ?>
                                                    <span class="result-badge">Close: <?php echo $bet['close_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if ($bet['jodi_result']): ?>
                                                    <span class="result-badge">Jodi: <?php echo $bet['jodi_result']; ?></span>
                                                <?php endif; ?>
                                                <?php if (!$bet['open_result'] && !$bet['close_result'] && !$bet['jodi_result']): ?>
                                                    <span class="text-muted">No results</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status status-<?php echo $bet['status']; ?>">
                                                <?php echo ucfirst($bet['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Card View -->
                    <div class="bets-cards">
                        <?php foreach ($bets as $bet): ?>
                            <div class="bet-card">
                                <div class="bet-row">
                                    <span class="bet-label">ID:</span>
                                    <span class="bet-value"><?php echo $bet['id']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">User:</span>
                                    <span class="bet-value"><?php echo $bet['username']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Game:</span>
                                    <span class="bet-value"><?php echo $bet['game_name']; ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Type:</span>
                                    <span class="bet-value">
                                        <span class="game-type">
                                            <?php echo str_replace('_', ' ', $bet['game_type']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Mode:</span>
                                    <span class="bet-value">
                                        <span class="bet-mode">
                                            <?php echo ucfirst($bet['bet_mode']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Numbers:</span>
                                    <span class="bet-value numbers-played">
                                        <?php 
                                        $numbers = json_decode($bet['numbers_played'], true);
                                        if (is_array($numbers)) {
                                            if (isset($numbers['selected_digits'])) {
                                                echo "Digits: " . $numbers['selected_digits'];
                                                if (isset($numbers['pana_combinations'])) {
                                                    echo " | Panas: " . implode(', ', $numbers['pana_combinations']);
                                                }
                                            } else {
                                                foreach ($numbers as $number => $amount) {
                                                    echo $number . " ($" . $amount . ") ";
                                                }
                                            }
                                        } else {
                                            echo htmlspecialchars($bet['numbers_played']);
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Amount:</span>
                                    <span class="bet-value">$<?php echo number_format($bet['amount'], 2); ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Potential Win:</span>
                                    <span class="bet-value">$<?php echo number_format($bet['potential_win'], 2); ?></span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Results:</span>
                                    <span class="bet-value">
                                        <div class="results">
                                            <?php if ($bet['open_result']): ?>
                                                <span class="result-badge">Open: <?php echo $bet['open_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($bet['close_result']): ?>
                                                <span class="result-badge">Close: <?php echo $bet['close_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($bet['jodi_result']): ?>
                                                <span class="result-badge">Jodi: <?php echo $bet['jodi_result']; ?></span>
                                            <?php endif; ?>
                                            <?php if (!$bet['open_result'] && !$bet['close_result'] && !$bet['jodi_result']): ?>
                                                <span class="text-muted">No results</span>
                                            <?php endif; ?>
                                        </div>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Status:</span>
                                    <span class="bet-value">
                                        <span class="status status-<?php echo $bet['status']; ?>">
                                            <?php echo ucfirst($bet['status']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="bet-row">
                                    <span class="bet-label">Date:</span>
                                    <span class="bet-value"><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                        <p>No bets found for the selected criteria.</p>
                        <?php if ($total_bets > 0): ?>
                            <p class="text-muted">Debug: Stats show <?php echo $total_bets; ?> bets but none in display array.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&date_filter=<?php echo $date_filter; ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_game=<?php echo urlencode($filter_game); ?>&filter_status=<?php echo $filter_status; ?>">
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

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });

        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });

        // Quick filter functionality
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                
                // Update active state
                document.querySelectorAll('.quick-filter-btn').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                // Enable/disable date inputs
                const startDate = document.querySelector('input[name="start_date"]');
                const endDate = document.querySelector('input[name="end_date"]');
                
                if (filter === 'custom') {
                    startDate.removeAttribute('readonly');
                    endDate.removeAttribute('readonly');
                } else {
                    startDate.setAttribute('readonly', 'readonly');
                    endDate.setAttribute('readonly', 'readonly');
                }
                
                // Set page to 1 when changing filters
                document.querySelector('input[name="page"]').value = 1;
                
                // Submit form
                document.getElementById('filterForm').submit();
            });
        });

        // Debug: Log bets data to console
        console.log('Bets data loaded:', <?php echo json_encode($bets); ?>);
    </script>



            <script>
       // Mobile menu functionality
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
        
        // Quick filter functionality - FIXED VERSION
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                document.getElementById('dateFilter').value = filter;
                
                // Update active state
                document.querySelectorAll('.quick-filter-btn').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                // Enable/disable date inputs
                const startDate = document.querySelector('input[name="start_date"]');
                const endDate = document.querySelector('input[name="end_date"]');
                
                if (filter === 'custom') {
                    startDate.removeAttribute('readonly');
                    endDate.removeAttribute('readonly');
                } else {
                    startDate.setAttribute('readonly', 'readonly');
                    endDate.setAttribute('readonly', 'readonly');
                }
                
                // Set page to 1 when changing filters
                document.querySelector('input[name="page"]').value = 1;
                
                // Submit form
                document.getElementById('filterForm').submit();
            });
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


        document.getElementById('filterForm').addEventListener('submit', function(e) {
            console.log('Submitting filter:', {
                date_filter: document.getElementById('dateFilter').value,
                start_date: document.querySelector('input[name="start_date"]').value,
                end_date: document.querySelector('input[name="end_date"]').value
            });
        });
    </script>


</body>
</html>


