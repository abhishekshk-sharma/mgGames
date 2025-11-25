<?php
// session_bets_detail.php
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

// Get session parameters
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$session_id || !$game_id || !$date) {
    header("location: game_sessions_history.php");
    exit;
}

// Get session details
$session_sql = "SELECT gs.*, g.name as game_name, g.open_time, g.close_time
                FROM game_sessions gs
                JOIN games g ON gs.game_id = g.id
                WHERE gs.id = ?";
$stmt_session = $conn->prepare($session_sql);
$stmt_session->bind_param('i', $session_id);
$stmt_session->execute();
$session_result = $stmt_session->get_result();
$session = $session_result->fetch_assoc();

if (!$session) {
    header("location: game_sessions_history.php");
    exit;
}

// Get all bets for this session
$bets_sql = "SELECT b.*, u.username, u.email
             FROM bets b
             JOIN users u ON b.user_id = u.id
             WHERE b.game_session_id = ? AND u.referral_code = '".$referral_code['referral_code']."'
             ORDER BY b.placed_at DESC";
$stmt_bets = $conn->prepare($bets_sql);
$stmt_bets->bind_param('i', $session_id);
$stmt_bets->execute();
$bets_result = $stmt_bets->get_result();
$bets = [];

if ($bets_result && $bets_result->num_rows > 0) {
    while ($row = $bets_result->fetch_assoc()) {
        $bets[] = $row;
    }
}

// Calculate advanced statistics
$total_bets = count($bets);
$total_amount = 0;
$total_potential_win = 0;
$won_bets = 0;
$lost_bets = 0;
$pending_bets = 0;
$total_payout = 0;

// Analyze most frequent numbers
$number_frequency = [];
$game_type_stats = [];
$bet_mode_stats = [];

foreach ($bets as $bet) {
    $total_amount += $bet['amount'];
    $total_potential_win += $bet['potential_win'];
    
    if ($bet['status'] == 'won') {
        $won_bets++;
        $total_payout += $bet['potential_win'];
    } elseif ($bet['status'] == 'lost') {
        $lost_bets++;
    } elseif ($bet['status'] == 'pending') {
        $pending_bets++;
    }
    
    // Count game types
    $game_type = $bet['game_type'];
    if (!isset($game_type_stats[$game_type])) {
        $game_type_stats[$game_type] = 0;
    }
    $game_type_stats[$game_type]++;
    
    // Count bet modes
    $bet_mode = $bet['bet_mode'];
    if (!isset($bet_mode_stats[$bet_mode])) {
        $bet_mode_stats[$bet_mode] = 0;
    }
    $bet_mode_stats[$bet_mode]++;
    
    // Analyze numbers played
    $numbers = json_decode($bet['numbers_played'], true);
    if (is_array($numbers)) {
        if (isset($numbers['selected_digits'])) {
            // For digit selection games
            $digits = $numbers['selected_digits'];
            if (!isset($number_frequency[$digits])) {
                $number_frequency[$digits] = 0;
            }
            $number_frequency[$digits]++;
            
            if (isset($numbers['pana_combinations'])) {
                foreach ($numbers['pana_combinations'] as $pana) {
                    if (!isset($number_frequency[$pana])) {
                        $number_frequency[$pana] = 0;
                    }
                    $number_frequency[$pana]++;
                }
            }
        } else {
            // For single number bets
            foreach ($numbers as $number => $amount) {
                if (!isset($number_frequency[$number])) {
                    $number_frequency[$number] = 0;
                }
                $number_frequency[$number]++;
            }
        }
    }
}

// Sort numbers by frequency
arsort($number_frequency);
$most_frequent_numbers = array_slice($number_frequency, 0, 10, true);

// Calculate percentages
$win_rate = $total_bets > 0 ? ($won_bets / $total_bets) * 100 : 0;
$loss_rate = $total_bets > 0 ? ($lost_bets / $total_bets) * 100 : 0;
$pending_rate = $total_bets > 0 ? ($pending_bets / $total_bets) * 100 : 0;
$profit_loss = $total_amount - $total_payout;

include "includes/header.php";
?>
<body>
    <div class="admin-container">
        <!-- Sidebar (reuse from previous file) -->
        <div class="sidebar">
            <!-- Same sidebar content as game_sessions_history.php -->
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Session Analytics</h1>
                    <p>
                        <?php echo htmlspecialchars($session['game_name']); ?> - 
                        <?php echo date('M j, Y', strtotime($date)); ?> |
                        <?php echo date('h:i A', strtotime($session['open_time'])); ?> - 
                        <?php echo date('h:i A', strtotime($session['close_time'])); ?>
                    </p>
                </div>
                <a href="game_sessions_history.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Sessions
                </a>
            </div>

            <!-- Session Results -->
            <div class="dashboard-section">
                <h2 class="section-title">Session Results</h2>
                <div class="session-results" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <?php if ($session['open_result']): ?>
                        <div class="result-badge" style="background: rgba(0, 184, 148, 0.2); color: var(--success); padding: 0.8rem 1.5rem; border-radius: 8px; border: 1px solid rgba(0, 184, 148, 0.3);">
                            <i class="fas fa-sun"></i>
                            Open: <?php echo $session['open_result']; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($session['close_result']): ?>
                        <div class="result-badge" style="background: rgba(253, 203, 110, 0.2); color: var(--warning); padding: 0.8rem 1.5rem; border-radius: 8px; border: 1px solid rgba(253, 203, 110, 0.3);">
                            <i class="fas fa-moon"></i>
                            Close: <?php echo $session['close_result']; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($session['jodi_result']): ?>
                        <div class="result-badge" style="background: rgba(11, 180, 201, 0.2); color: var(--secondary); padding: 0.8rem 1.5rem; border-radius: 8px; border: 1px solid rgba(11, 180, 201, 0.3);">
                            <i class="fas fa-link"></i>
                            Jodi: <?php echo $session['jodi_result']; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$session['open_result'] && !$session['close_result'] && !$session['jodi_result']): ?>
                        <div class="result-badge" style="background: rgba(108, 117, 125, 0.2); color: #6c757d; padding: 0.8rem 1.5rem; border-radius: 8px; border: 1px solid rgba(108, 117, 125, 0.3);">
                            <i class="fas fa-clock"></i>
                            Results Pending
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Key Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-value"><?php echo number_format($total_bets); ?></div>
                    <div class="stat-card-title">Total Bets</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">$<?php echo number_format($total_amount, 2); ?></div>
                    <div class="stat-card-title">Total Bet Amount</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value">$<?php echo number_format($total_payout, 2); ?></div>
                    <div class="stat-card-title">Total Payout</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-value <?php echo $profit_loss >= 0 ? 'profit' : 'loss'; ?>">
                        $<?php echo number_format(abs($profit_loss), 2); ?>
                    </div>
                    <div class="stat-card-title">Profit/Loss</div>
                </div>
            </div>

            <!-- Bet Distribution -->
            <div class="dashboard-section">
                <h2 class="section-title">Bet Distribution</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                    <div>
                        <h4>Status Distribution</h4>
                        <div class="progress-bar">
                            <div class="progress-fill progress-won" style="width: <?php echo $win_rate; ?>%"></div>
                            <div class="progress-fill progress-lost" style="width: <?php echo $loss_rate; ?>%"></div>
                            <div class="progress-fill progress-pending" style="width: <?php echo $pending_rate; ?>%"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-muted);">
                            <span>Won: <?php echo number_format($win_rate, 1); ?>%</span>
                            <span>Lost: <?php echo number_format($loss_rate, 1); ?>%</span>
                            <span>Pending: <?php echo number_format($pending_rate, 1); ?>%</span>
                        </div>
                    </div>
                    
                    <div>
                        <h4>Game Types</h4>
                        <?php foreach ($game_type_stats as $type => $count): ?>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span><?php echo ucfirst(str_replace('_', ' ', $type)); ?></span>
                                <span><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div>
                        <h4>Bet Modes</h4>
                        <?php foreach ($bet_mode_stats as $mode => $count): ?>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span><?php echo ucfirst($mode); ?></span>
                                <span><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Most Frequent Numbers -->
            <?php if (!empty($most_frequent_numbers)): ?>
            <div class="dashboard-section">
                <h2 class="section-title">Most Frequently Played Numbers</h2>
                <div class="numbers-grid">
                    <?php foreach ($most_frequent_numbers as $number => $frequency): ?>
                        <div class="number-item">
                            <div class="number-value"><?php echo htmlspecialchars($number); ?></div>
                            <div class="number-count"><?php echo $frequency; ?> bets</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Individual Bets Table -->
            <div class="dashboard-section">
                <h2 class="section-title">Individual Bets (<?php echo $total_bets; ?>)</h2>
                <?php if (!empty($bets)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Numbers</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><?php echo $bet['id']; ?></td>
                                        <td>
                                            <div><?php echo $bet['username']; ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo $bet['email']; ?></div>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $bet['game_type'])); ?></td>
                                        <td><?php echo ucfirst($bet['bet_mode']); ?></td>
                                        <td style="max-width: 200px; word-break: break-word; font-family: monospace; font-size: 0.85rem;">
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
                                            <span class="status status-<?php echo $bet['status']; ?>">
                                                <?php echo ucfirst($bet['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('h:i A', strtotime($bet['placed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; padding: 2rem; color: var(--text-muted);">
                        No bets placed for this session.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Add any necessary JavaScript here
    </script>
</body>
</html>