<?php
// set_game_results.php
require_once '../config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_login.php");
    exit;
}

// Get admin details
// $admin_id = $_SESSION['admin_id'];
// $admin_username = $_SESSION['admin_username'];

$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

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

include 'includes/header.php';
?>



        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Set Game Results</h1>
                    <p>Enter game results and process pending bets</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span id="currentTime"><?php echo date('F j, Y g:i A'); ?></span>
                    </div>
                    
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span class="admin-name">Super Admin: <?php echo htmlspecialchars($super_admin_username); ?></span>
                    </div>
                    
                    <a href="super_admin_logout.php" class="logout-btn">
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