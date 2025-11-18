<?php
// super_admin_login.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in as super admin
if (isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_dashboard.php");
    exit;
}

$error = '';
$username = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = sanitize_input($conn, $_POST['username']);
    $password = $_POST['password'];
    
    // Check if super admin username exists
    $sql = "SELECT id, username, hash_password FROM super_admin WHERE username = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $username, $hashed_password);
            $stmt->fetch();
            
            if (password_verify($password, $hashed_password)) {
                // Password is correct, start a new session
                $_SESSION['super_admin_id'] = $id;
                $_SESSION['super_admin_username'] = $username;
                
                // Redirect to super admin dashboard
                header("location: super_admin_dashboard.php");
                exit;
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No super admin account found with that username.";
        }
        $stmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - RB Games</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9ff;
            --accent: #00cec9;
            --dark: #7098a3ff;
            --light: #f5f6fa;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --super-admin: #ffc107;
        }

        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: var(--light);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            width: 100%;
            max-width: 450px;
            background: rgba(26, 26, 46, 0.9);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .auth-header {
            background: linear-gradient(to right, var(--super-admin), #ff9800);
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .auth-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .auth-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .auth-header p {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .super-admin-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0, 0, 0, 0.3);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 1;
        }

        .auth-form {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: var(--light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--super-admin);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.3);
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(to right, var(--super-admin), #ff9800);
            color: #000;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .auth-footer {
            text-align: center;
            padding: 1rem 2rem 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .auth-footer a {
            color: var(--super-admin);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .error-message {
            background: rgba(214, 48, 49, 0.2);
            border: 1px solid var(--danger);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            color: #ff6b6b;
        }

        .success-message {
            background: rgba(0, 184, 148, 0.2);
            border: 1px solid var(--success);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            color: var(--success);
        }

        .security-notice {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--super-admin);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.9rem;
            color: var(--super-admin);
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle .form-control {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 5px;
        }

        @media (max-width: 576px) {
            .auth-container {
                max-width: 100%;
            }
            
            .auth-header, .auth-form {
                padding: 1.5rem;
            }
            
            .auth-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="super-admin-badge">
                <i class="fas fa-crown"></i> SUPER ADMIN
            </div>
            <h1>RB Games</h1>
            <p>Super Admin Portal</p>
        </div>
        
        <div class="auth-form">
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="security-notice">
                <i class="fas fa-shield-alt"></i> Restricted Access - Super Admin Only
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user-shield"></i> Super Admin Username
                    </label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo $username; ?>" required placeholder="Enter super admin username">
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-key"></i> Password
                    </label>
                    <div class="password-toggle">
                        <input type="password" id="password" name="password" class="form-control" required placeholder="Enter your password">
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login as Super Admin
                </button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p><a href="admin_login.php"><i class="fas fa-arrow-left"></i> Back to Admin Login</a></p>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Add enter key support
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const form = this.closest('form');
                        if (form) {
                            form.submit();
                        }
                    }
                });
            });
        });

        // Add loading state to form submission
        document.querySelector('form').addEventListener('submit', function() {
            const btn = this.querySelector('.btn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            btn.disabled = true;
        });
    </script>
</body>
</html>