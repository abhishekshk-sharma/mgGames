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