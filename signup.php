<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("location: index.php");
    exit;
}

$error = '';
$username = $email = $phone = $referral_code = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = sanitize_input($conn, $_POST['username']);
    $email = sanitize_input($conn, $_POST['email']);
    $phone = sanitize_input($conn, $_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $referral_code = sanitize_input($conn, $_POST['referral_code']);
    
    // Validate inputs
    if (empty($username) || empty($email) || empty($password) || empty($referral_code)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check if referral code exists in admin table
        $sql = "SELECT id FROM admins WHERE referral_code = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $referral_code);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows === 0) {
                $error = "Invalid referral code. Please enter a valid broker referral code.";
            } else {
                // Check if username already exists
                $sql = "SELECT id FROM users WHERE username = ?";
                
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $stmt->store_result();
                    
                    if ($stmt->num_rows > 0) {
                        $error = "Username already taken.";
                    } else {
                        // Check if email already exists
                        $sql = "SELECT id FROM users WHERE email = ?";
                        
                        if ($stmt = $conn->prepare($sql)) {
                            $stmt->bind_param("s", $email);
                            $stmt->execute();
                            $stmt->store_result();
                            
                            if ($stmt->num_rows > 0) {
                                $error = "Email already registered.";
                            } else {
                                // Get admin ID for the referral code
                                $sql = "SELECT id FROM admins WHERE referral_code = ?";
                                if ($stmt = $conn->prepare($sql)) {
                                    $stmt->bind_param("s", $referral_code);
                                    $stmt->execute();
                                    $stmt->bind_result($admin_id);
                                    $stmt->fetch();
                                    $stmt->close();
                                    
                                    // Use the referral code entered by user (broker's code)
                                    $user_referral_code = $referral_code;
                                    
                                    // Hash password
                                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                    
                                    // Insert new user with referral info
                                    $sql = "INSERT INTO users (username, email, phone, password_hash, referral_code, referred_by, balance) VALUES (?, ?, ?, ?, ?, ?, 0.00)";
                                    
                                    if ($stmt = $conn->prepare($sql)) {
                                        $stmt->bind_param("sssssi", $username, $email, $phone, $hashed_password, $user_referral_code, $admin_id);
                                        
                                        if ($stmt->execute()) {
                                            header("location: login.php?registered=true");
                                            exit;
                                        } else {
                                            $error = "Something went wrong. Please try again later.";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - RB Games</title>
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
            max-width: 500px;
            background: rgba(26, 26, 46, 0.9);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 60, 126, 0.2);
        }

        .auth-header {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            padding: 2rem;
            text-align: center;
        }

        .auth-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .auth-header p {
            font-size: 1rem;
            opacity: 0.9;
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
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 60, 126, 0.3);
        }

        .referral-note {
            background: rgba(15, 180, 201, 0.2);
            border: 1px solid var(--secondary);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-align: center;
        }

        .referral-note i {
            color: var(--secondary);
            margin-right: 5px;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 60, 126, 0.4);
        }

        .auth-footer {
            text-align: center;
            padding: 1rem 2rem 2rem;
        }

        .auth-footer a {
            color: var(--primary);
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

        @media (max-width: 576px) {
            .auth-container {
                max-width: 100%;
            }
            
            .auth-header, .auth-form {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>RB Games</h1>
            <p>Create your account</p>
        </div>
        
        <div class="auth-form">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="referral-note">
                <i class="fas fa-info-circle"></i>
                You need a valid broker referral code to register. Please contact your broker for the referral code.
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo $username; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?php echo $email; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo $phone; ?>">
                </div>
                
                <div class="form-group">
                    <label for="referral_code">Broker Referral Code *</label>
                    <input type="text" id="referral_code" name="referral_code" class="form-control" value="<?php echo $referral_code; ?>" required placeholder="Enter your broker's referral code">
                </div>
                
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn">Register</button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <script>
        // Simple password strength check
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const confirmPassword = document.getElementById('confirm_password');
            
            if (password.length < 6) {
                this.style.borderColor = 'var(--danger)';
            } else {
                this.style.borderColor = 'var(--success)';
            }
            
            // Check if passwords match
            if (confirmPassword.value && password !== confirmPassword.value) {
                confirmPassword.style.borderColor = 'var(--danger)';
            } else if (confirmPassword.value) {
                confirmPassword.style.borderColor = 'var(--success)';
            }
        });
        
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.style.borderColor = 'var(--danger)';
            } else {
                this.style.borderColor = 'var(--success)';
            }
        });
    </script>
</body>
</html>