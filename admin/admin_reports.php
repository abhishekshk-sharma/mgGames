<?php
// admin_reports.php
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

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code_result = $stmt->get_result();
$referral_code_data = $referral_code_result->fetch_assoc();
$referral_code = $referral_code_data['referral_code'];

if($referral_code_data['status'] == 'suspend'){
    header("location: dashboard.php");
    exit;   
}
                            
                   

// Get admin bet limits and PNL ratio
$limit_stmt = $conn->prepare("SELECT bet_limit, pnl_ratio FROM broker_limit WHERE admin_id = ?");
$limit_stmt->execute([$admin_id]);
$limit_result = $limit_stmt->get_result();
$admin_limits = $limit_result->fetch_assoc();

$bet_limit = $admin_limits['bet_limit'] ?? 100;
$pnl_ratio = $admin_limits['pnl_ratio'];

// Parse PNL ratio if set
$admin_ratio = 0;
$forward_ratio = 0;
if ($pnl_ratio && strpos($pnl_ratio, ':') !== false) {
    $ratio_parts = explode(':', $pnl_ratio);
    $admin_ratio = intval($ratio_parts[0]);
    $forward_ratio = intval($ratio_parts[1]);
}

// Date filtering setup - Flexible date range
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Validate dates to ensure they're not in the future
$today = date('Y-m-d');
if ($start_date > $today) {
    $start_date = $today;
}
if ($end_date > $today) {
    $end_date = $today;
}

// Ensure end date is not before start date
if ($end_date < $start_date) {
    $end_date = $start_date;
}

// Additional filters
$filter_game = isset($_GET['filter_game']) ? $_GET['filter_game'] : '';

// Pagination setup
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Validate limit
$allowed_limits = [10, 20, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Calculate overall monthly statistics
$overall_stats_sql = "SELECT 
    COUNT(DISTINCT gs.id) as total_sessions,
    COUNT(DISTINCT b.id) as total_bets,
    SUM(CASE WHEN b.status = 'won' THEN 1 ELSE 0 END) as won_bets,
    SUM(CASE WHEN b.status = 'lost' THEN 1 ELSE 0 END) as lost_bets,
    SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bets
FROM game_sessions gs
JOIN bets b ON gs.id = b.game_session_id
JOIN users u ON b.user_id = u.id
WHERE DATE(gs.session_date) BETWEEN ? AND ? 
AND u.referral_code = ?";

$overall_params = [$start_date, $end_date, $referral_code];
$overall_stmt = $conn->prepare($overall_stats_sql);
$overall_stmt->bind_param('sss', ...$overall_params);
$overall_stmt->execute();
$overall_result = $overall_stmt->get_result();
$overall_stats = $overall_result->fetch_assoc();

// Pre-fetch game types payout ratios for efficiency
$game_type_ratios = [];
$stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
$stmt_game_types->execute();
$game_types_result = $stmt_game_types->get_result();
while ($game_type_row = $game_types_result->fetch_assoc()) {
    $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
}

// Calculate financial statistics with forwarding logic - FIXED JOIN CONDITIONS
$financial_sql = "SELECT 
    gs.id as session_id,
    g.name as game_name,
    b.id as bet_id,
    b.amount as bet_amount,
    b.potential_win as potential_win,
    b.status as bet_status,
    b.numbers_played,
    b.game_type_id,
    b.bet_mode
FROM game_sessions gs
JOIN games g ON gs.game_id = g.id  -- FIXED: Changed g.game_id to g.id
JOIN bets b ON gs.id = b.game_session_id
JOIN users u ON b.user_id = u.id
WHERE DATE(gs.session_date) BETWEEN ? AND ? 
AND u.referral_code = ?";

// Apply game filter if set
if ($filter_game) {
    $financial_sql .= " AND g.name LIKE ?";
}

$financial_params = [$start_date, $end_date, $referral_code];
if ($filter_game) {
    $financial_params[] = "%$filter_game%";
}

$financial_stmt = $conn->prepare($financial_sql);
if ($filter_game) {
    $financial_stmt->bind_param('ssss', ...$financial_params);
} else {
    $financial_stmt->bind_param('sss', ...$financial_params);
}
$financial_stmt->execute();
$financial_result = $financial_stmt->get_result();

$total_bet_amount = 0;
$total_payout = 0;
$total_forwarded = 0;
$total_actual_exposure = 0;
$total_actual_payout = 0;
$game_stats = [];

while ($row = $financial_result->fetch_assoc()) {
    $game_name = $row['game_name'];
    $bet_amount = $row['bet_amount'];
    $potential_win = $row['potential_win'];
    $bet_status = $row['bet_status'];
    $numbers_played = json_decode($row['numbers_played'], true);
    $game_type_id = $row['game_type_id'];
    
    // Get the payout ratio for this game type
    $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00; // Default to 9 if not found
    
    // Calculate forwarding based on admin configuration
    $admin_amount = $bet_amount;
    $forwarded_amount = 0;
    
    if (is_array($numbers_played)) {
        if (isset($numbers_played['selected_digits'])) {
            // For SP Motor type bets
            if (isset($numbers_played['pana_combinations'])) {
                $amount_per_pana = $numbers_played['amount_per_pana'] ?? 0;
                foreach ($numbers_played['pana_combinations'] as $pana) {
                    if ($pnl_ratio) {
                        // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                        $forwarded = ($amount_per_pana * $forward_ratio) / 100;
                    } else {
                        // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                        $forwarded = max(0, $amount_per_pana - $bet_limit);
                    }
                    $admin_amount -= $forwarded;
                    $forwarded_amount += $forwarded;
                }
            }
        } else {
            // For regular number bets
            foreach ($numbers_played as $number => $amount) {
                if ($pnl_ratio) {
                    // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                    $forwarded = ($amount * $forward_ratio) / 100;
                } else {
                    // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                    $forwarded = max(0, $amount - $bet_limit);
                }
                $admin_amount -= $forwarded;
                $forwarded_amount += $forwarded;
            }
        }
    }
    
    // Calculate actual payout for won bets with proper payout ratio
    $actual_payout = 0;
    if ($bet_status == 'won') {
        if ($bet_amount > 0) {
            $admin_payout_ratio_amount = $admin_amount / $bet_amount;
            $admin_win_amount = $bet_amount * $admin_payout_ratio_amount;
            $actual_payout = $admin_win_amount * $payout_ratio;
        }
    }
    
    // Update overall totals
    $total_bet_amount += $bet_amount;
    $total_payout += $potential_win;
    $total_forwarded += $forwarded_amount;
    $total_actual_exposure += $admin_amount;
    $total_actual_payout += $actual_payout;
    
    // Update game-specific statistics
    if (!isset($game_stats[$game_name])) {
        $game_stats[$game_name] = [
            'total_bets' => 0,
            'total_bet_amount' => 0,
            'total_payout' => 0,
            'total_forwarded' => 0,
            'total_actual_exposure' => 0,
            'total_actual_payout' => 0,
            'won_bets' => 0,
            'lost_bets' => 0,
            'pending_bets' => 0
        ];
    }
    
    $game_stats[$game_name]['total_bets']++;
    $game_stats[$game_name]['total_bet_amount'] += $bet_amount;
    $game_stats[$game_name]['total_payout'] += $potential_win;
    $game_stats[$game_name]['total_forwarded'] += $forwarded_amount;
    $game_stats[$game_name]['total_actual_exposure'] += $admin_amount;
    $game_stats[$game_name]['total_actual_payout'] += $actual_payout;
    
    if ($bet_status == 'won') {
        $game_stats[$game_name]['won_bets']++;
    } elseif ($bet_status == 'lost') {
        $game_stats[$game_name]['lost_bets']++;
    } else {
        $game_stats[$game_name]['pending_bets']++;
    }
}

// Calculate overall profit/loss
$total_profit_loss = $total_actual_exposure - $total_actual_payout;

// Apply game filter to game stats if needed
if ($filter_game) {
    $filtered_game_stats = [];
    foreach ($game_stats as $game_name => $stats) {
        if (stripos($game_name, $filter_game) !== false) {
            $filtered_game_stats[$game_name] = $stats;
        }
    }
    $game_stats = $filtered_game_stats;
}

// Build query for paginated game stats
$game_stats_paginated = array_slice($game_stats, $offset, $limit);
$total_records = count($game_stats);
$total_pages = ceil($total_records / $limit);

// Get unique games for filter dropdown
$games_sql = "SELECT DISTINCT name FROM games ORDER BY name";
$games_result = $conn->query($games_sql);
$games = [];
if ($games_result && $games_result->num_rows > 0) {
    while ($row = $games_result->fetch_assoc()) {
        $games[] = $row['name'];
    }
}

// Generate month options for dropdown
$month_options = [];
for ($i = -6; $i <= 0; $i++) {
    $month = date('Y-m', strtotime("$i months"));
    $month_options[$month] = date('F Y', strtotime($month));
}

$pagefilename = "reports";

include "includes/header.php";
?>


        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Admin Reports</h1>
                    <p>Comprehensive financial reports and analytics</p>
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
            <div class="configuration-badge">
                <h4><i class="fas fa-cog"></i> Current Configuration</h4>
                <?php if ($pnl_ratio): ?>
                    <p><strong>PNL Ratio Mode:</strong> <?php echo $pnl_ratio; ?> (Admin:<?php echo $admin_ratio; ?>% | Forward:<?php echo $forward_ratio; ?>%)</p>
                <?php else: ?>
                    <p><strong>Bet Limit Mode:</strong> ₹<?php echo number_format($bet_limit); ?> per number</p>
                <?php endif; ?>
            </div>

            <!-- Date Range Selection -->
            <div class="filter-section">
                <h3 class="section-title mb-2"><i class="fas fa-calendar"></i> Select Date Range</h3>
                <form method="GET" id="reportForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="filter-control" 
                                value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); ?>"
                                max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="filter-control" 
                                value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t'); ?>"
                                max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Quick Date Ranges</label>
                            <div class="quick-date-buttons">
                                <button type="button" class="quick-date-btn" data-days="7">Last 7 Days</button>
                                <button type="button" class="quick-date-btn" data-days="30">Last 30 Days</button>
                                <button type="button" class="quick-date-btn" data-days="90">Last 90 Days</button>
                                <button type="button" class="quick-date-btn" data-type="month">This Month</button>
                                <button type="button" class="quick-date-btn" data-type="last_month">Last Month</button>
                            </div>
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
                            <i class="fas fa-search"></i> Generate Report
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetDateRange()">
                            <i class="fas fa-redo"></i> Reset to Current Month
                        </button>
                    </div>
                    
                    <input type="hidden" name="page" value="1">
                </form>
            </div>

            <!-- Monthly Summary -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Report Summary - <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></h2>
                    <a href="#" class="btn btn-info export-btn" onclick="exportToExcel()">
                        <i class="fas fa-file-export"></i> Export to Excel
                    </a>
                </div>

                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['total_bets']); ?></div>
                        <div class="summary-label">Total Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['won_bets']); ?></div>
                        <div class="summary-label">Won Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['lost_bets']); ?></div>
                        <div class="summary-label">Lost Bets</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value"><?php echo number_format($overall_stats['pending_bets']); ?></div>
                        <div class="summary-label">Pending Bets</div>
                    </div>
                </div>

                <div class="summary-grid mt-3">
                    <div class="summary-item">
                        <div class="summary-value">₹<?php echo number_format($total_actual_exposure, 2); ?></div>
                        <div class="summary-label">Your Actual Exposure</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value">₹<?php echo number_format($total_actual_payout, 2); ?></div>
                        <div class="summary-label">Your Actual Payout</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value <?php echo $total_profit_loss > 0 ? 'profit-positive' : ($total_profit_loss < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                            ₹<?php echo number_format(abs($total_profit_loss), 2); ?>
                            <?php if ($total_profit_loss > 0): ?>
                                <i class="fas fa-arrow-up"></i>
                            <?php elseif ($total_profit_loss < 0): ?>
                                <i class="fas fa-arrow-down"></i>
                            <?php endif; ?>
                        </div>
                        <div class="summary-label">Your Net Profit/Loss</div>
                    </div>
                    
                    <div class="summary-item">
                        <div class="summary-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                        <div class="summary-label">Forwarded to Super Admin</div>
                    </div>
                </div>
            </div>


            <!-- Horizontal Statistics Bar -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-bar"></i> Financial Overview - <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></h2>
                </div>
                
                <div class="stats-bar-grid">
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Total Bet Amount</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_bet_amount, 2); ?></div>
                        <div class="stats-bar-description">All bets placed by users</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Your Actual Exposure</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_actual_exposure, 2); ?></div>
                        <div class="stats-bar-description">After risk management</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Forwarded Amount</div>
                        <div class="stats-bar-value" style="color: #ffc107;">₹<?php echo number_format($total_forwarded, 2); ?></div>
                        <div class="stats-bar-description">To super admin</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Your Actual Payout</div>
                        <div class="stats-bar-value">₹<?php echo number_format($total_actual_payout, 2); ?></div>
                        <div class="stats-bar-description">For won bets</div>
                    </div>
                    
                    <div class="stats-bar-item">
                        <div class="stats-bar-label">Net Profit/Loss</div>
                        <div class="stats-bar-value <?php echo $total_profit_loss > 0 ? 'profit-positive' : ($total_profit_loss < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                            ₹<?php echo number_format(abs($total_profit_loss), 2); ?>
                            <?php if ($total_profit_loss > 0): ?>
                                <i class="fas fa-arrow-up"></i>
                            <?php elseif ($total_profit_loss < 0): ?>
                                <i class="fas fa-arrow-down"></i>
                            <?php endif; ?>
                        </div>
                        <div class="stats-bar-description">Your final position</div>
                    </div>
                </div>
            </div>

            <!-- Game-wise Statistics -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-gamepad"></i> Game-wise Statistics</h2>
                    <div class="view-all">Total: <?php echo number_format($total_records); ?> games</div>
                </div>

                <?php if (!empty($game_stats_paginated)): ?>
                    <div class="table-responsive">
                        <table class="game-stats-table">
                            <thead>
                                <tr>
                                    <th>Game Name</th>
                                    <th class="text-right">Total Bets</th>
                                    <th class="text-right">Won/Lost/Pending</th>
                                    <th class="text-right">Total Bet Amount</th>
                                    <th class="text-right">Your Exposure</th>
                                    <th class="text-right">Your Payout</th>
                                    <th class="text-right">Net P&L</th>
                                    <th class="text-right">Forwarded</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($game_stats_paginated as $game_name => $stats): 
                                    $game_profit_loss = $stats['total_actual_exposure'] - $stats['total_actual_payout'];
                                    $profit_loss_class = $game_profit_loss > 0 ? 'profit-positive' : ($game_profit_loss < 0 ? 'profit-negative' : 'profit-neutral');
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($game_name); ?></strong></td>
                                        <td class="text-right"><?php echo number_format($stats['total_bets']); ?></td>
                                        <td class="text-right">
                                            <span style="color: var(--success);"><?php echo number_format($stats['won_bets']); ?></span> /
                                            <span style="color: var(--danger);"><?php echo number_format($stats['lost_bets']); ?></span> /
                                            <span style="color: var(--warning);"><?php echo number_format($stats['pending_bets']); ?></span>
                                        </td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_bet_amount'], 2); ?></td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_actual_exposure'], 2); ?></td>
                                        <td class="text-right">₹<?php echo number_format($stats['total_actual_payout'], 2); ?></td>
                                        <td class="text-right <?php echo $profit_loss_class; ?>">
                                            ₹<?php echo number_format(abs($game_profit_loss), 2); ?>
                                            <?php if ($game_profit_loss > 0): ?>
                                                <i class="fas fa-arrow-up"></i>
                                            <?php elseif ($game_profit_loss < 0): ?>
                                                <i class="fas fa-arrow-down"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right" style="color: #ffc107;">₹<?php echo number_format($stats['total_forwarded'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-chart-bar"></i>
                        <h3>No Game Data Found</h3>
                        <p>No game statistics available for the selected month and filters.</p>
                    </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
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
                            <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>" 
                               class="<?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&month=<?php echo $selected_month; ?>&filter_game=<?php echo urlencode($filter_game); ?>">
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

        // Initialize
        updateMenuTextVisibility();
        window.addEventListener('resize', updateMenuTextVisibility);


        // Quick date range functionality
        document.querySelectorAll('.quick-date-btn').forEach(button => {
            button.addEventListener('click', function() {
                const days = this.getAttribute('data-days');
                const type = this.getAttribute('data-type');
                const today = new Date();
                let startDate, endDate;

                if (days) {
                    // For "Last X Days" buttons
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - parseInt(days));
                    endDate = new Date(today);
                } else if (type === 'month') {
                    // This Month
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                } else if (type === 'last_month') {
                    // Last Month
                    startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                }

                // Format dates as YYYY-MM-DD
                const formatDate = (date) => {
                    return date.toISOString().split('T')[0];
                };

                // Set the date inputs
                document.getElementById('startDate').value = formatDate(startDate);
                document.getElementById('endDate').value = formatDate(endDate);

                // Submit the form
                document.getElementById('reportForm').submit();
            });
        });

        // Reset to current month
        function resetDateRange() {
            const today = new Date();
            const startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            const endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            const formatDate = (date) => {
                return date.toISOString().split('T')[0];
            };
            
            document.getElementById('startDate').value = formatDate(startDate);
            document.getElementById('endDate').value = formatDate(endDate);
            document.getElementById('reportForm').submit();
        }

        // Date validation
        document.getElementById('startDate').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDate = new Date(document.getElementById('endDate').value);
            const today = new Date();
            
            // Ensure start date is not in future
            if (startDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            // Ensure end date is not before start date
            if (endDate < startDate) {
                document.getElementById('endDate').value = this.value;
            }
        });

        document.getElementById('endDate').addEventListener('change', function() {
            const endDate = new Date(this.value);
            const startDate = new Date(document.getElementById('startDate').value);
            const today = new Date();
            
            // Ensure end date is not in future
            if (endDate > today) {
                this.value = today.toISOString().split('T')[0];
            }
            
            // Ensure end date is not before start date
            if (endDate < startDate) {
                document.getElementById('startDate').value = this.value;
            }
        });



        // Simple Export to Excel function without currency symbols
function exportToExcel() {



    <?php
        // Log report generation action
            try {
                $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, title, description, created_at) VALUES (?, 'Generated Report', 'Admin generated a report', NOW())");
                $stmt->execute([$admin_id]);
            } catch (Exception $e) {
                // Silently fail if logging doesn't work
                error_log("Failed to log dashboard access: " . $e->getMessage());
            }

    ?>

    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const startFormatted = new Date(startDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const endFormatted = new Date(endDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    
    const filename = `reports_${startDate}_to_${endDate}.csv`;
    
    let csv = '';
    
    // Header Section
    csv += '"RB GAMES - ADMIN REPORTS"\n';
    csv += `"Report Period: ${startFormatted} to ${endFormatted}"\n`;
    csv += `"Generated on: <?php echo date('F j, Y \\a\\t g:i A'); ?>"\n`;
    csv += `"Admin: <?php echo $admin_username; ?>"\n\n`;
    
    // Financial Summary Section
    csv += '"FINANCIAL SUMMARY"\n';
    csv += '"Description","Amount (₹)","Count"\n';
    csv += `"Total Bets","<?php echo number_format($total_bet_amount, 2); ?>","<?php echo number_format($overall_stats['total_bets']); ?>"\n`;
    csv += `"Your Actual Exposure","<?php echo number_format($total_actual_exposure, 2); ?>","-"\n`;
    csv += `"Forwarded to Super Admin","<?php echo number_format($total_forwarded, 2); ?>","-"\n`;
    csv += `"Your Actual Payout","<?php echo number_format($total_actual_payout, 2); ?>","-"\n`;
    csv += `"","",""\n`; // Empty row for spacing
    csv += `"TOTAL PROFIT/LOSS","<?php echo number_format($total_profit_loss, 2); ?>","-"\n`;
    csv += `"Status","<?php echo $total_profit_loss > 0 ? 'PROFIT' : ($total_profit_loss < 0 ? 'LOSS' : 'BREAK-EVEN'); ?>","-"\n\n`;
    
    // Bet Statistics Section
    csv += '"BET STATISTICS"\n';
    csv += '"Description","Count","Percentage"\n';
    csv += `"Total Bets","<?php echo number_format($overall_stats['total_bets']); ?>","100.00%"\n`;
    csv += `"Won Bets","<?php echo number_format($overall_stats['won_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['won_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Lost Bets","<?php echo number_format($overall_stats['lost_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['lost_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n`;
    csv += `"Pending Bets","<?php echo number_format($overall_stats['pending_bets']); ?>","<?php echo $overall_stats['total_bets'] > 0 ? number_format(($overall_stats['pending_bets'] / $overall_stats['total_bets']) * 100, 2) : '0.00'; ?>%"\n\n`;
    
    // Game-wise Detailed Statistics
    csv += '"GAME-WISE DETAILED STATISTICS"\n';
    csv += '"Game Name","Total Bets","Won","Lost","Pending","Total Bet Amount (₹)","Your Exposure (₹)","Your Payout (₹)","Net P&L (₹)","Forwarded (₹)"\n';
    
    <?php 
    $grand_total_bets = 0;
    $grand_total_won = 0;
    $grand_total_lost = 0;
    $grand_total_pending = 0;
    $grand_total_bet_amount = 0;
    $grand_total_exposure = 0;
    $grand_total_payout = 0;
    $grand_total_forwarded = 0;
    
    foreach ($game_stats as $game_name => $stats): 
        $game_profit_loss = $stats['total_actual_exposure'] - $stats['total_actual_payout'];
        
        $grand_total_bets += $stats['total_bets'];
        $grand_total_won += $stats['won_bets'];
        $grand_total_lost += $stats['lost_bets'];
        $grand_total_pending += $stats['pending_bets'];
        $grand_total_bet_amount += $stats['total_bet_amount'];
        $grand_total_exposure += $stats['total_actual_exposure'];
        $grand_total_payout += $stats['total_actual_payout'];
        $grand_total_forwarded += $stats['total_forwarded'];
    ?>
        csv += `"<?php echo $game_name; ?>","<?php echo $stats['total_bets']; ?>","<?php echo $stats['won_bets']; ?>","<?php echo $stats['lost_bets']; ?>","<?php echo $stats['pending_bets']; ?>","<?php echo number_format($stats['total_bet_amount'], 2); ?>","<?php echo number_format($stats['total_actual_exposure'], 2); ?>","<?php echo number_format($stats['total_actual_payout'], 2); ?>","<?php echo number_format($game_profit_loss, 2); ?>","<?php echo number_format($stats['total_forwarded'], 2); ?>"\n`;
    <?php endforeach; ?>
    
    // Grand Totals Row
    csv += `"GRAND TOTALS","<?php echo $grand_total_bets; ?>","<?php echo $grand_total_won; ?>","<?php echo $grand_total_lost; ?>","<?php echo $grand_total_pending; ?>","<?php echo number_format($grand_total_bet_amount, 2); ?>","<?php echo number_format($grand_total_exposure, 2); ?>","<?php echo number_format($grand_total_payout, 2); ?>","<?php echo number_format($total_profit_loss, 2); ?>","<?php echo number_format($grand_total_forwarded, 2); ?>"\n\n`;
    
    // Configuration Section
    csv += '"SYSTEM CONFIGURATION"\n';
    csv += '"Setting","Value"\n';
    <?php if ($pnl_ratio): ?>
        csv += `"Risk Management","PNL Ratio (<?php echo $pnl_ratio; ?>)"\n`;
        csv += `"Your Share","<?php echo $admin_ratio; ?>%"\n`;
        csv += `"Forwarded Share","<?php echo $forward_ratio; ?>%"\n`;
    <?php else: ?>
        csv += `"Risk Management","Bet Limit"\n`;
        csv += `"Limit per Number","<?php echo number_format($bet_limit); ?>"\n`;
    <?php endif; ?>
    csv += `"Date Range","${startFormatted} to ${endFormatted}"\n`;
    csv += `"Total Days","<?php echo round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1; ?>"\n`;
    
    // Create and download the file with proper encoding
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv; charset=utf-8' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
        
    </script>
</body>
</html>