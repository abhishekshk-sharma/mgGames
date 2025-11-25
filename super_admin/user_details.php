<?php
// user_details.php
require_once '../config.php';

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_login.php");
    exit;
}

// Get user ID from URL
if (!isset($_GET['user_id'])) {
    die("User ID is required");
}

$user_id = intval($_GET['user_id']);
$_SESSION['viewing_user_id'] = $user_id; // Store in session

// Get super admin details
$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

// Get user details with comprehensive data
$user_sql = "SELECT u.*, a.username as admin_username,
            (SELECT COUNT(*) FROM bets b WHERE b.user_id = u.id) as total_bets,
            (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'deposit' AND t.status = 'completed') as total_deposits,
            (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'withdrawal' AND t.status = 'completed') as total_withdrawals,
            (SELECT SUM(amount) FROM transactions t WHERE t.user_id = u.id AND t.type = 'winning' AND t.status = 'completed') as total_winnings,
            (SELECT SUM(amount) FROM bets b WHERE b.user_id = u.id) as total_bets_amount,
            (SELECT COUNT(DISTINCT game_session_id) FROM bets WHERE user_id = u.id) as total_games,
            (SELECT COUNT(*) FROM bets WHERE user_id = u.id AND status = 'won') as won_bets,
            (SELECT MAX(placed_at) FROM bets WHERE user_id = u.id) as last_bet_date,
            (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_active = 1) as active_sessions
            FROM users u
            LEFT JOIN admins a ON u.referral_code = a.referral_code
            WHERE u.id = ?";

$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("User not found");
}

$user = $result->fetch_assoc();

// Calculate additional metrics
$total_bets = $user['total_bets'] ?: 0;
$won_bets = $user['won_bets'] ?: 0;
$user['winning_rate'] = $total_bets > 0 ? round(($won_bets / $total_bets) * 100, 2) . '%' : '0%';

// Get favorite game
$favorite_game_sql = "SELECT game_name, COUNT(*) as game_count 
                     FROM bets 
                     WHERE user_id = ? 
                     GROUP BY game_name 
                     ORDER BY game_count DESC 
                     LIMIT 1";
$stmt = $conn->prepare($favorite_game_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$favorite_result = $stmt->get_result();
$user['favorite_game'] = $favorite_result->num_rows > 0 ? $favorite_result->fetch_assoc()['game_name'] : '-';

// Calculate account age
$account_age = floor((time() - strtotime($user['created_at'])) / (60 * 60 * 24));
$user['account_age'] = $account_age;

// Calculate net profit/loss
$user['net_profit_loss'] = ($user['total_winnings'] ?: 0) - ($user['total_bets_amount'] ?: 0);

include 'includes/header.php';
?>


    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="welcome">
                <h1>User Details</h1>
                <p>Viewing details for: <?php echo htmlspecialchars($user['username']); ?> (ID: <?php echo $user['id']; ?>)</p>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <div class="admin-badge">
                    <i class="fas fa-user-shield"></i>
                    <span>Super Admin: <?php echo htmlspecialchars($super_admin_username); ?></span>
                </div>
                <a href="super_admin_all_users.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('basicInfo')">Basic Info</button>
            <button class="tab" onclick="switchTab('financialInfo')">Financial Info</button>
            <button class="tab" onclick="switchTab('activityInfo')">Activity</button>
            <button class="tab" onclick="switchTab('bettingHistory')">Betting History</button>
            <button class="tab" onclick="switchTab('transactions')">Transactions</button>
        </div>

        <!-- Basic Information Tab -->
        <div id="basicInfo" class="tab-content active">
            <form method="POST" action="update_user_info.php">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                <div class="user-info-grid">
                    <div class="info-card">
                        <div class="info-label">User ID</div>
                        <div class="info-value"><?php echo $user['id']; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Username</div>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Email</div>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Phone</div>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" class="form-control">
                    </div>
                    <div class="info-card">
                        <div class="info-label">Status</div>
                        <select name="status" class="form-control">
                            <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="suspended" <?php echo $user['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="banned" <?php echo $user['status'] == 'banned' ? 'selected' : ''; ?>>Banned</option>
                        </select>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Registration Date</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Last Login</div>
                        <div class="info-value"><?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referral Code</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['referral_code']); ?></div>
                    </div>
                    <div class="info-card">
                        <div class="info-label">Referred By Admin</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['admin_username']); ?></div>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem; text-align: right;">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Financial Information Tab -->
        <div id="financialInfo" class="tab-content">
            <div class="user-info-grid">
                <div class="info-card">
                    <div class="info-label">Current Balance</div>
                    <div class="info-value">₹<?php echo number_format($user['balance'], 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Deposits</div>
                    <div class="info-value">₹<?php echo number_format($user['total_deposits'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Withdrawals</div>
                    <div class="info-value">₹<?php echo number_format($user['total_withdrawals'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Bets</div>
                    <div class="info-value"><?php echo number_format($user['total_bets'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Total Winnings</div>
                    <div class="info-value">₹<?php echo number_format($user['total_winnings'] ?: 0, 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Net Profit/Loss</div>
                    <div class="info-value" style="color: <?php echo $user['net_profit_loss'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                        ₹<?php echo number_format($user['net_profit_loss'], 2); ?>
                    </div>
                </div>
            </div>

            <!-- Quick Balance Adjustment -->
            <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(255,255,255,0.05); border-radius: 8px;">
                <h4 style="margin-bottom: 1rem;">Quick Balance Adjustment</h4>
                <form method="POST" action="adjust_user_balance.php">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                        <div>
                            <label class="info-label">Action</label>
                            <select name="adjustment_type" class="form-control" required>
                                <option value="add">Add Balance</option>
                                <option value="subtract">Subtract Balance</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Amount (₹)</label>
                            <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div>
                            <label class="info-label">Reason</label>
                            <input type="text" name="reason" class="form-control" placeholder="Adjustment reason" required>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="submit" class="btn btn-primary">Apply Adjustment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Activity Information Tab -->
        <div id="activityInfo" class="tab-content">
            <div class="user-info-grid">
                <div class="info-card">
                    <div class="info-label">Total Games Played</div>
                    <div class="info-value"><?php echo number_format($user['total_games'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Winning Rate</div>
                    <div class="info-value"><?php echo $user['winning_rate']; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Favorite Game</div>
                    <div class="info-value"><?php echo htmlspecialchars($user['favorite_game']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Last Bet Date</div>
                    <div class="info-value"><?php echo $user['last_bet_date'] ? date('M j, Y g:i A', strtotime($user['last_bet_date'])) : '-'; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Active Sessions</div>
                    <div class="info-value"><?php echo number_format($user['active_sessions'] ?: 0); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Account Age</div>
                    <div class="info-value"><?php echo $user['account_age']; ?> days</div>
                </div>
            </div>
        </div>

         <!-- Betting History Tab -->
        <div id="bettingHistory" class="tab-content">
            <!-- Betting History Filters -->
            <div class="filter-section">
                <h4 style="margin-bottom: 1rem;">Filter Betting History</h4>
                <form id="bettingFilterForm" onsubmit="return loadBettingData(1)">
                    <div class="filter-grid">
                        <div>
                            <label class="info-label">Game Name</label>
                            <input type="text" name="betting_game" id="betting_game" class="form-control" placeholder="Filter by game name">
                        </div>
                        <div>
                            <label class="info-label">Status</label>
                            <select name="betting_status" id="betting_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="won">Won</option>
                                <option value="lost">Lost</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Date From</label>
                            <input type="date" name="betting_date_from" id="betting_date_from" class="form-control">
                        </div>
                        <div>
                            <label class="info-label">Date To</label>
                            <input type="date" name="betting_date_to" id="betting_date_to" class="form-control">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" onclick="resetBettingFilters()" class="reset-btn">
                            <i class="fas fa-times"></i> Reset Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Betting History Content (AJAX loaded) -->
            <div id="bettingContent">
                <div class="loading-content">
                    <div class="loading"></div>
                    <span style="margin-left: 1rem;">Loading betting history...</span>
                </div>
            </div>
        </div>

        <!-- Transactions Tab -->
        <div id="transactions" class="tab-content">
            <!-- Transactions Filters -->
            <div class="filter-section">
                <h4 style="margin-bottom: 1rem;">Filter Transactions</h4>
                <form id="transactionFilterForm" onsubmit="return loadTransactionData(1)">
                    <div class="filter-grid">
                        <div>
                            <label class="info-label">Transaction Type</label>
                            <select name="trans_type" id="trans_type" class="form-control">
                                <option value="">All Types</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                                <option value="winning">Winning</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Status</label>
                            <select name="trans_status" id="trans_status" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                                <option value="failed">Failed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="info-label">Date From</label>
                            <input type="date" name="trans_date_from" id="trans_date_from" class="form-control">
                        </div>
                        <div>
                            <label class="info-label">Date To</label>
                            <input type="date" name="trans_date_to" id="trans_date_to" class="form-control">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" onclick="resetTransactionFilters()" class="reset-btn">
                            <i class="fas fa-times"></i> Reset Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Transactions Content (AJAX loaded) -->
            <div id="transactionContent">
                <div class="loading-content">
                    <div class="loading"></div>
                    <span style="margin-left: 1rem;">Loading transactions...</span>
                </div>
            </div>
        </div>
    </div>


 <script>
    // Global variables
    const userId = <?php echo $user_id; ?>;
    let currentBettingPage = 1;
    let currentTransactionPage = 1;
    let bettingLimit = 20;
    let transactionLimit = 20;

    // Tab switching function
    function switchTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content and activate tab
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
        
        // Load data for betting and transaction tabs when they are activated
        if (tabName === 'bettingHistory') {
            loadBettingData(currentBettingPage);
        } else if (tabName === 'transactions') {
            loadTransactionData(currentTransactionPage);
        }
    }

    // Load betting data via AJAX
    function loadBettingData(page = 1) {
        currentBettingPage = page;
        
        const formData = {
            user_id: userId,
            page: page,
            limit: bettingLimit,
            betting_game: document.getElementById('betting_game').value,
            betting_status: document.getElementById('betting_status').value,
            betting_date_from: document.getElementById('betting_date_from').value,
            betting_date_to: document.getElementById('betting_date_to').value
        };

        // Show loading indicator
        const bettingContent = document.getElementById('bettingContent');
        bettingContent.innerHTML = `
            <div class="loading-overlay">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
        `;

        // Create URL with parameters
        const params = new URLSearchParams(formData);
        
        // Use fetch API instead of jQuery
        fetch('ajax_get_betting_history.php?' + params.toString())
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                bettingContent.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                bettingContent.innerHTML = `
                    <div style="text-align: center; color: var(--danger); padding: 2rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading betting history. Please try again.</p>
                    </div>
                `;
            });

        return false; // Prevent form submission
    }

    // Load transaction data via AJAX
    function loadTransactionData(page = 1) {
        currentTransactionPage = page;
        
        const formData = {
            user_id: userId,
            page: page,
            limit: transactionLimit,
            trans_type: document.getElementById('trans_type').value,
            trans_status: document.getElementById('trans_status').value,
            trans_date_from: document.getElementById('trans_date_from').value,
            trans_date_to: document.getElementById('trans_date_to').value
        };

        // Show loading indicator
        const transactionContent = document.getElementById('transactionContent');
        transactionContent.innerHTML = `
            <div class="loading-overlay">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
        `;

        // Create URL with parameters
        const params = new URLSearchParams(formData);
        
        // Use fetch API instead of jQuery
        fetch('ajax_get_transactions.php?' + params.toString())
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                transactionContent.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                transactionContent.innerHTML = `
                    <div style="text-align: center; color: var(--danger); padding: 2rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading transactions. Please try again.</p>
                    </div>
                `;
            });

        return false; // Prevent form submission
    }

    // Reset betting filters
    function resetBettingFilters() {
        document.getElementById('betting_game').value = '';
        document.getElementById('betting_status').value = '';
        document.getElementById('betting_date_from').value = '';
        document.getElementById('betting_date_to').value = '';
        loadBettingData(1);
    }

    // Reset transaction filters
    function resetTransactionFilters() {
        document.getElementById('trans_type').value = '';
        document.getElementById('trans_status').value = '';
        document.getElementById('trans_date_from').value = '';
        document.getElementById('trans_date_to').value = '';
        loadTransactionData(1);
    }

    // Change limit for betting
    function changeBettingLimit(limit) {
        bettingLimit = parseInt(limit);
        loadBettingData(1);
    }

    // Change limit for transactions
    function changeTransactionLimit(limit) {
        transactionLimit = parseInt(limit);
        loadTransactionData(1);
    }

    // Show success message if present in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success')) {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: urlParams.get('message') || 'Operation completed successfully',
            timer: 3000
        });
    }

    if (urlParams.get('error')) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: urlParams.get('message') || 'An error occurred',
            timer: 5000
        });
    }

    // Load initial data when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Load betting history if that tab is active
        if (document.getElementById('bettingHistory').classList.contains('active')) {
            loadBettingData(1);
        }
        
        // Load transactions if that tab is active
        if (document.getElementById('transactions').classList.contains('active')) {
            loadTransactionData(1);
        }
    });
</script>
</body>
</html>