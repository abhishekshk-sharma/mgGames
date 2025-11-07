<?php
// admin_login.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


// Redirect if already logged in as admin
if (isset($_SESSION['admin_id'])) {
    header("location: dashboard.php");
    exit;
}

$error = '';
$username = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = sanitize_input($conn, $_POST['username']);
    $password = $_POST['password'];
    
    // Check if admin username exists
    $sql = "SELECT id, username, password_hash FROM admins WHERE username = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows == 1) {
            $stmt->bind_result($id, $username, $hashed_password);
            $stmt->fetch();
            
            if (password_verify($password, $hashed_password)) {
                // Password is correct, start a new session
                $_SESSION['admin_id'] = $id;
                $_SESSION['admin_username'] = $username;
                
                // Redirect to admin dashboard
                header("location: dashboard.php");
                exit;
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No admin account found with that username.";
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
    <title>Admin Login - RB Games</title>
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
            max-width: 450px;
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

        .success-message {
            background: rgba(0, 184, 148, 0.2);
            border: 1px solid var(--success);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            color: var(--success);
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
            <h1>RB Games Admin</h1>
            <p>Login to admin account</p>
        </div>
        
        <div class="auth-form">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo $username; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn">Login</button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>User Login? <a href="../login.php">User Login</a></p>
        </div>
    </div>
</body>
</html>