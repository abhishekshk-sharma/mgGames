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

                // Log logIn action
                try {
                    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, title, description, created_at) VALUES (?, 'Admin Login', 'Admin logged into the system', NOW())");
                    $stmt->execute([$id]);
                } catch (Exception $e) {
                    // Silently fail if logging doesn't work
                    error_log("Failed to log dashboard access: " . $e->getMessage());
                }
                
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
    <link rel="stylesheet" href="includes/style.css">
    <link rel="stylesheet" href="includes/loginpage.css">
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
            <p>Not Registered? <a href="signup.php">Sign Up</a></p>
        </div>
    </div>
</body>
</html>