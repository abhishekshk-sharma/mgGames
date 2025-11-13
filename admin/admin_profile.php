<?php
// admin_profile.php
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

// Get admin data
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_data = $stmt->get_result()->fetch_assoc();

// Handle form submissions
$message = '';
$message_type = ''; // success or error

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_field'])) {
        $field = $_POST['field'];
        $value = trim($_POST['value']);
        
        // List of allowed fields that can be updated
        $allowed_fields = ['username', 'phone', 'email', 'upiId', 'address'];
        
        if (!in_array($field, $allowed_fields)) {
            $message = "Invalid field specified";
            $message_type = 'error';
        } else {
            // Field-specific validation
            $valid = true;
            
            switch($field) {
                case 'username':
                    if (empty($value)) {
                        $message = "Username cannot be empty";
                        $valid = false;
                    } else {
                        // Check if username already exists (excluding current admin)
                        $check_stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
                        $check_stmt->execute([$value, $admin_id]);
                        if ($check_stmt->get_result()->num_rows > 0) {
                            $message = "Username already exists. Please choose a different one.";
                            $valid = false;
                        }
                    }
                    break;
                    
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $message = "Please enter a valid email address";
                        $valid = false;
                    }
                    break;
                    
                case 'phone':
                    if (!preg_match('/^\d{10}$/', $value)) {
                        $message = "Please enter a valid 10-digit phone number";
                        $valid = false;
                    }
                    break;
                    
                case 'upiId':
                    if (!preg_match('/^[\w.-]+@[\w]+$/', $value)) {
                        $message = "Please enter a valid UPI ID";
                        $valid = false;
                    }
                    break;
            }
            
            if ($valid) {
                // Update the field
                $update_stmt = $conn->prepare("UPDATE admins SET $field = ? WHERE id = ?");
                if ($update_stmt->execute([$value, $admin_id])) {
                    // Update session if username changed
                    if ($field === 'username') {
                        $_SESSION['admin_username'] = $value;
                    }
                    
                    // Refresh admin data
                    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
                    $stmt->execute([$admin_id]);
                    $admin_data = $stmt->get_result()->fetch_assoc();
                    
                    $message = ucfirst($field) . " updated successfully";
                    $message_type = 'success';
                } else {
                    $message = "Error updating " . $field;
                    $message_type = 'error';
                }
            }
        }
    }
    
    if (isset($_POST['update_password'])) {
        // Update password (existing logic)
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate current password
        if (!password_verify($current_password, $admin_data['password_hash'])) {
            $message = "Current password is incorrect";
            $message_type = 'error';
        } elseif (empty($new_password)) {
            $message = "New password cannot be empty";
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = "New password must be at least 6 characters long";
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = "New passwords do not match";
            $message_type = 'error';
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
            
            if ($update_stmt->execute([$hashed_password, $admin_id])) {
                $message = "Password updated successfully";
                $message_type = 'success';
            } else {
                $message = "Error updating password";
                $message_type = 'error';
            }
        }
    }
}

$title = "Admin Profile - RB Games";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
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
            overflow-x: hidden;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles (same as before) */
        .sidebar {
            width: 260px;
            background: var(--dark);
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            transition: all 0.3s ease;
            overflow-y: auto;
            left: 0;
            top: 0;
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
            letter-spacing: 0.5px;
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
            transform: translateX(5px);
        }

        .menu-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 1.2rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
            transition: all 0.3s ease;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.2rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .welcome h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .welcome p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .logout-btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.6rem 1.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            box-shadow: 0 4px 10px rgba(255, 60, 126, 0.3);
            text-decoration: none;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 60, 126, 0.4);
        }

        /* Profile Content Styles */
        .profile-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .profile-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 600;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.8rem;
        }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .info-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        #referral-code{
            /* background-color: red; */
            /* background: linear-gradient(to right, red, blue); */
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: transparent;
            -webkit-background-clip: text;
            font-weight: 600;
        }

        #referral-span{
            display:inline;
            background: linear-gradient(to right, red, blue);
            -webkit-background-clip: text;
            color: transparent;
            font-size: 16px;
            font-weight: 700;
        }

        .fa-copy{
            
            background-color: var(--dark); 
            background: linear-gradient(to right, pink, skyblue);
            -webkit-background-clip: text;
            color: transparent;
            cursor:pointer; 
            font-size: 20px;
        }

        .edit-icon {
            color: var(--primary);
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            opacity: 0.7;
        }

        .edit-icon:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 500;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            border: 1px solid var(--border-color);
            min-height: 50px;
            display: flex;
            align-items: center;
        }

        .uneditable-field {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-muted);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .uneditable-field .edit-icon {
            display: none;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-input {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
        }

        .btn {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.8rem 1.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
            font-size: 1rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 60, 126, 0.4);
        }

        .btn-block {
            display: block;
            width: 100%;
            justify-content: center;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(0, 184, 148, 0.2);
            border-color: rgba(0, 184, 148, 0.3);
            color: var(--success);
        }

        .alert-error {
            background: rgba(214, 48, 49, 0.2);
            border-color: rgba(214, 48, 49, 0.3);
            color: var(--danger);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-20px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .modal.active .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.1);
        }

        .modal-body {
            margin-bottom: 1.5rem;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Password Modal Specific */
        .password-strength {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .password-strength-fill {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak {
            background: var(--danger);
            width: 33%;
        }

        .strength-medium {
            background: var(--warning);
            width: 66%;
        }

        .strength-strong {
            background: var(--success);
            width: 100%;
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
        @media (max-width: 993px) {
            .sidebar {
                width: 260px;
                left: 0;
                position: fixed;
            }
            
            .sidebar-header h2 {
                font-size: 1.2rem;
            }

            .menu-item span {
                display: none;
            }

            .menu-item {
                justify-content: center;
                padding: 1rem;
            }
            
            .menu-item i {
                margin-right: 0;
            }
            
            .sidebar-footer {
                padding: 0.8rem;
            }
            
            .main-content {
                margin-left: 80px;
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.2rem;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 260px;
                left: -260px;
            }
            .sidebar.active {
                left: 0;
            }
            
            .header-actions {
                flex-direction: column;
                gap: 1rem;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .modal-content {
                padding: 1.5rem;
                margin: 1rem;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 0;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                width: 260px;
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .header {
                margin-top: 3rem;
            }
            
            .welcome h1 {
                font-size: 1.5rem;
            }
            
            .profile-section {
                padding: 1rem;
            }
            
            .modal-footer {
                flex-direction: column;
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
                <a href="todays_active_games.php" class="menu-item">
                    <i class="fas fa-play-circle"></i>
                    <span>Today's Games</span>
                </a>
                <a href="game_sessions_history.php" class="menu-item">
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
                <a href="admin_reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="admin_profile.php" class="menu-item active">
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
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>Admin Profile</h1>
                    <p>Manage your account details and security settings</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span><?php echo date('l, F j, Y'); ?></span>
                    </div>
                    <a href="admin_logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <div class="profile-container">
                <!-- Display Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <!-- Admin Information Section -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-user-circle"></i> Admin Information</h2>
                    </div>
                    
                    <div class="profile-grid">
                        <!-- Editable Fields -->
                        <div class="info-group">
                            <span class="info-label">
                                Username
                                <i class="fas fa-edit edit-icon" data-field="username" data-value="<?php echo htmlspecialchars($admin_data['username']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($admin_data['username']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                Phone
                                <i class="fas fa-edit edit-icon" data-field="phone" data-value="<?php echo htmlspecialchars($admin_data['phone']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($admin_data['phone']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                Email
                                <i class="fas fa-edit edit-icon" data-field="email" data-value="<?php echo htmlspecialchars($admin_data['email']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($admin_data['email']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                UPI ID
                                <i class="fas fa-edit edit-icon" data-field="upiId" data-value="<?php echo htmlspecialchars($admin_data['upiId']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($admin_data['upiId']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                Address
                                <i class="fas fa-edit edit-icon" data-field="address" data-value="<?php echo htmlspecialchars($admin_data['address']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($admin_data['address']); ?></div>
                        </div>
                        
                        <!-- Non-editable Fields -->
                        <div class="info-group">
                            <span class="info-label">Aadhar Number</span>
                            <div class="info-value uneditable-field"><?php echo htmlspecialchars($admin_data['adhar']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">PAN Number</span>
                            <div class="info-value uneditable-field"><?php echo htmlspecialchars($admin_data['pan']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label " id="referral-span">Referral Code &nbsp; </span>
                            <i class="fa-solid fa-copy" id="copyBtn"></i>
                            <div class="info-value"> 
                                <span id="referral-code">
                                    <?php echo htmlspecialchars($admin_data['referral_code']); ?>
                                </span> 
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">Partner Status</span>
                            <div class="info-value uneditable-field"><?php echo htmlspecialchars($admin_data['is_partner']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">Account Status</span>
                            <div class="info-value uneditable-field"><?php echo htmlspecialchars(ucfirst($admin_data['status'])); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">Member Since</span>
                            <div class="info-value uneditable-field"><?php echo date('F j, Y, g:i A', strtotime($admin_data['created_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Change Password Section -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-lock"></i> Security</h2>
                    </div>
                    
                    <button type="button" class="btn" id="changePasswordBtn">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Field Modal -->
    <div class="modal" id="editFieldModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-edit"></i>
                    <span id="modalFieldTitle">Edit Field</span>
                </h3>
                <button class="modal-close" id="closeFieldModal">&times;</button>
            </div>
            <form method="POST" action="" id="fieldForm">
                <div class="modal-body">
                    <input type="hidden" name="field" id="modalFieldName">
                    <div class="form-group">
                        <label class="form-label" id="modalFieldLabel">Field Value</label>
                        <input type="text" name="value" id="modalFieldValue" class="form-input" required>
                        <div class="field-hint" id="fieldHint" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelFieldEdit">Cancel</button>
                    <button type="submit" name="update_field" class="btn">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="changePasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-key"></i>
                    Change Password
                </h3>
                <button class="modal-close" id="closePasswordModal">&times;</button>
            </div>
            <form method="POST" action="" id="passwordForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required>
                        <div class="password-strength">
                            <div class="password-strength-fill" id="passwordStrength"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                        <div id="passwordMatch" style="font-size: 0.8rem; margin-top: 0.5rem;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelPasswordChange">Cancel</button>
                    <button type="submit" name="update_password" class="btn">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

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

        // Modal functionality
        const editFieldModal = document.getElementById('editFieldModal');
        const changePasswordModal = document.getElementById('changePasswordModal');
        const fieldForm = document.getElementById('fieldForm');
        
        // Field editing functionality
        document.querySelectorAll('.edit-icon').forEach(icon => {
            icon.addEventListener('click', function() {
                const field = this.dataset.field;
                const value = this.dataset.value;
                
                // Set modal content based on field
                document.getElementById('modalFieldTitle').textContent = `Edit ${getFieldDisplayName(field)}`;
                document.getElementById('modalFieldLabel').textContent = getFieldDisplayName(field);
                document.getElementById('modalFieldName').value = field;
                document.getElementById('modalFieldValue').value = value;
                
                // Set input type and hints
                const input = document.getElementById('modalFieldValue');
                const hint = document.getElementById('fieldHint');
                
                switch(field) {
                    case 'email':
                        input.type = 'email';
                        hint.textContent = 'Enter a valid email address';
                        break;
                    case 'phone':
                        input.type = 'tel';
                        input.pattern = '[0-9]{10}';
                        hint.textContent = 'Enter a 10-digit phone number';
                        break;
                    case 'upiId':
                        input.type = 'text';
                        hint.textContent = 'Enter a valid UPI ID (e.g., username@upi)';
                        break;
                    default:
                        input.type = 'text';
                        hint.textContent = '';
                }
                
                // Show modal
                editFieldModal.classList.add('active');
            });
        });

        // Password modal
        document.getElementById('changePasswordBtn').addEventListener('click', function() {
            changePasswordModal.classList.add('active');
        });

        // Close modals
        document.getElementById('closeFieldModal').addEventListener('click', function() {
            editFieldModal.classList.remove('active');
        });

        document.getElementById('closePasswordModal').addEventListener('click', function() {
            changePasswordModal.classList.remove('active');
        });

        document.getElementById('cancelFieldEdit').addEventListener('click', function() {
            editFieldModal.classList.remove('active');
        });

        document.getElementById('cancelPasswordChange').addEventListener('click', function() {
            changePasswordModal.classList.remove('active');
        });

        // Close modals when clicking outside
        editFieldModal.addEventListener('click', function(e) {
            if (e.target === editFieldModal) {
                editFieldModal.classList.remove('active');
            }
        });

        changePasswordModal.addEventListener('click', function(e) {
            if (e.target === changePasswordModal) {
                changePasswordModal.classList.remove('active');
            }
        });

        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrength');
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/\d/)) strength++;
            if (password.match(/[^a-zA-Z\d]/)) strength++;
            
            strengthBar.className = 'password-strength-fill';
            if (password.length === 0) {
                strengthBar.style.width = '0';
            } else if (strength <= 1) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 3) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        });

        // Password match indicator
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const matchIndicator = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchIndicator.textContent = '';
                matchIndicator.style.color = '';
            } else if (newPassword === confirmPassword) {
                matchIndicator.textContent = '✓ Passwords match';
                matchIndicator.style.color = 'var(--success)';
            } else {
                matchIndicator.textContent = '✗ Passwords do not match';
                matchIndicator.style.color = 'var(--danger)';
            }
        });

        // Helper function to get display names for fields
        function getFieldDisplayName(field) {
            const names = {
                'username': 'Username',
                'phone': 'Phone Number',
                'email': 'Email Address',
                'upiId': 'UPI ID',
                'address': 'Address'
            };
            return names[field] || field;
        }

        // Prevent accidental form submission on Enter key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
                if (!e.target.closest('.modal')) {
                    e.preventDefault();
                }
            }
        });

        $(document).ready(function(){
            $("#copyBtn").click(function() {
                const text = $("#referral-code").text().trim();

                // Use Clipboard API (modern, mobile-friendly)
                navigator.clipboard.writeText(text).then(function() {
                    alert("Copied to clipboard!");
                }).catch(function(err) {
                    console.error("Failed to copy: ", err);
                });
            });

        });
    </script>
</body>
</html>