<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposits - RB Games Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="includes/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <!-- Mobile Menu Toggle - FIXED -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay for Mobile - FIXED -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    

    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>RB Games</h2>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item <?php echo ($pagefilename == "dashboard") ? "active" : ""; ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="users.php" class="menu-item <?php echo ($pagefilename == "users") ? "active" : ""; ?>">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                

                <a href="todays_active_games.php" class="menu-item <?php echo ($pagefilename == "todays_active_games") ? "active" : ""; ?> ">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item <?php echo ($pagefilename == "game_sessions_history") ? "active" : ""; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Game Sessions History</span>
                </a>
                <a href="all_users_history.php" class="menu-item <?php echo ($pagefilename == "all_users_history")? 'active':""  ?> ">
                    <i class="fas fa-history"></i>
                    <span>All Users Bet History</span>
                </a>
                <a href="admin_transactions.php" class="menu-item <?php echo ($pagefilename == "transactions") ? "active" : ""; ?>">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Transactions</span>
                </a>
                <a href="admin_withdrawals.php" class="menu-item <?php echo ($pagefilename == "withdrawals") ? "active" : ""; ?>">
                    <i class="fas fa-credit-card"></i>
                    <span>Withdrawals</span>
                </a>
                <a href="admin_deposits.php" class="menu-item <?php echo ($pagefilename == "deposits") ? "active" : ""; ?>">
                    <i class="fas fa-money-bill"></i>
                    <span>Deposits</span>
                </a>

                <a href="applications.php" class="menu-item <?php echo ($pagefilename == "applications") ? "active" : ""; ?>">
                    <i class="fas fa-tasks"></i>
                    <span>Applications</span>
                </a>
                
                <a href="admin_reports.php" class="menu-item    <?php echo ($pagefilename == "reports") ? "active" : ""; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="admin_profile.php" class="menu-item <?php echo ($pagefilename == "profile") ? "active" : ""; ?>">
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