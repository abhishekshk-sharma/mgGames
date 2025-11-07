<?php
// admin_signup.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$username = $email = $phone = $adhar = $pan = $upiId = $address = '';
$is_partner = 'No';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get and sanitize all form data
    $username = sanitize_input($conn, $_POST['username']);
    $email = sanitize_input($conn, $_POST['email']);
    $phone = sanitize_input($conn, $_POST['phone']);
    $adhar = sanitize_input($conn, $_POST['adhar']);
    $pan = sanitize_input($conn, $_POST['pan']);
    $upiId = sanitize_input($conn, $_POST['upiId']);
    $address = sanitize_input($conn, $_POST['address']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // File upload handling
    $adhar_upload = '';
    $pan_upload = '';
    
    // Validate required fields
    if (empty($username) || empty($email) || empty($phone) || empty($adhar) || 
        empty($pan) || empty($upiId) || empty($address) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!is_numeric($phone) || strlen($phone) != 10) {
        $error = "Phone number must be exactly 10 digits.";
    } elseif (!is_numeric($adhar) || strlen($adhar) != 12) {
        $error = "Aadhar number must be exactly 12 digits.";
    } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
        $error = "PAN number must be in valid format (e.g., ABCDE1234F).";
    } else {
        // Handle file uploads
        $upload_errors = [];
        
        // Aadhar upload
        if (isset($_FILES['adhar_upload']) && $_FILES['adhar_upload']['error'] == 0) {
            $adhar_file = $_FILES['adhar_upload'];
            $adhar_upload = upload_file($adhar_file, 'adhar');
            if (!$adhar_upload) {
                $upload_errors[] = "Aadhar document upload failed. Please check file format and size.";
            }
        } else {
            $upload_errors[] = "Aadhar document is required.";
        }
        
        // PAN upload
        if (isset($_FILES['pan_upload']) && $_FILES['pan_upload']['error'] == 0) {
            $pan_file = $_FILES['pan_upload'];
            $pan_upload = upload_file($pan_file, 'pan');
            if (!$pan_upload) {
                $upload_errors[] = "PAN document upload failed. Please check file format and size.";
            }
        } else {
            $upload_errors[] = "PAN document is required.";
        }
        
        if (!empty($upload_errors)) {
            $error = implode(" ", $upload_errors);
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Check if username or email already exists
            $check_sql = "SELECT id FROM admins WHERE username = ? OR email = ?";
            if ($check_stmt = $conn->prepare($check_sql)) {
                $check_stmt->bind_param("ss", $username, $email);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $error = "Username or email already exists.";
                } else {
                    // Insert new admin/broker
                    $sql = "INSERT INTO admins (username, phone, email, adhar, pan, upiId, address, adhar_upload, pan_upload, password_hash, is_partner, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                    
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bind_param("sisssssssss", $username, $phone, $email, $adhar, $pan, $upiId, $address, $adhar_upload, $pan_upload, $hashed_password, $is_partner);
                        
                        if ($stmt->execute()) {
                            // Success - set session variable and redirect to success page
                            $_SESSION['registration_success'] = true;
                            header("Location: signup_success.php");
                            exit;
                        } else {
                            $error = "Something went wrong. Please try again later. Error: " . $conn->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Database error: " . $conn->error;
                    }
                }
                $check_stmt->close();
            }
        }
    }
}

// File upload function
function upload_file($file, $type) {
    $target_dir = "../uploads/" . $type . "/";
    $max_file_size = 5 * 1024 * 1024; // 5MB
    
    // Create directory if it doesn't exist
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed_extensions = array("jpg", "jpeg", "png", "pdf", "doc", "docx");
    
    // Check file size
    if ($file["size"] > $max_file_size) {
        return false;
    }
    
    // Check file extension
    if (!in_array($file_extension, $allowed_extensions)) {
        return false;
    }
    
    $new_filename = $type . "_" . time() . "_" . uniqid() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }
    
    return false;
}

// Sanitize input function (make sure this exists in your config.php)
if (!function_exists('sanitize_input')) {
    function sanitize_input($conn, $data) {
        return mysqli_real_escape_string($conn, trim($data));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broker Registration - RB Games</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            max-width: 600px;
            background: rgba(26, 26, 46, 0.9);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 60, 126, 0.2);
        }

        .auth-form::-webkit-scrollbar {
            display: none;
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
            max-height: 70vh;
            overflow-y: auto;
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

        .file-upload {
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            border-radius: 8px;
            border: 1px dashed rgba(255, 255, 255, 0.3);
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

        .info-message {
            background: rgba(11, 142, 215, 0.2);
            border: 1px solid #0b8ed7;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 1rem;
            text-align: center;
            color: #0b8ed7;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .password-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--light);
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .toggle-password:hover {
            opacity: 1;
        }

        @media (max-width: 576px) {
            .auth-container {
                max-width: 100%;
            }
            
            .auth-header, .auth-form {
                padding: 1.5rem;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>RB Games Broker</h1>
            <p>Register as a Broker</p>
        </div>
        
        <div class="auth-form">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="info-message">
                Fill in all the details accurately. Your account will be activated after verification.
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" id="registrationForm">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo $username; ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?php echo $email; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number *</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo $phone; ?>" pattern="[0-9]{10}" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="adhar">Aadhar Number *</label>
                        <input type="text" id="adhar" name="adhar" class="form-control" value="<?php echo $adhar; ?>" pattern="[0-9]{12}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="pan">PAN Number *</label>
                        <input type="text" id="pan" name="pan" class="form-control" value="<?php echo $pan; ?>" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="upiId">UPI ID *</label>
                    <input type="text" id="upiId" name="upiId" class="form-control" value="<?php echo $upiId; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="address">Address *</label>
                    <textarea id="address" name="address" class="form-control" rows="3" required><?php echo $address; ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="adhar_upload">Aadhar Document *</label>
                        <div class="file-upload">
                            <input type="file" id="adhar_upload" name="adhar_upload" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required>
                            <small>Accepted formats: JPG, PNG, PDF, DOC (Max: 5MB)</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="pan_upload">PAN Document *</label>
                        <div class="file-upload">
                            <input type="file" id="pan_upload" name="pan_upload" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required>
                            <small>Accepted formats: JPG, PNG, PDF, DOC (Max: 5MB)</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" class="form-control" required>
                            <button type="button" class="toggle-password" data-target="password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <div class="password-container">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            <button type="button" class="toggle-password" data-target="confirm_password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn">Register as Broker</button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <script>
        // Password visibility toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const passwordInput = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // Client-side validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const phone = document.getElementById('phone').value;
            const adhar = document.getElementById('adhar').value;
            const pan = document.getElementById('pan').value;
            let isValid = true;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                showError('Passwords do not match!');
                isValid = false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                showError('Password must be at least 6 characters long!');
                isValid = false;
            }
            
            if (!/^\d{10}$/.test(phone)) {
                e.preventDefault();
                showError('Phone number must be exactly 10 digits!');
                isValid = false;
            }
            
            if (!/^\d{12}$/.test(adhar)) {
                e.preventDefault();
                showError('Aadhar number must be exactly 12 digits!');
                isValid = false;
            }
            
            if (!/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan)) {
                e.preventDefault();
                showError('PAN number must be in valid format (e.g., ABCDE1234F)!');
                isValid = false;
            }

            if (isValid) {
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;
            }
        });

        function showError(message) {
            // Remove any existing error alerts
            const existingAlert = document.querySelector('.swal2-container');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            Swal.fire({
                title: 'Validation Error!',
                text: message,
                icon: 'error',
                confirmButtonText: 'OK',
                confirmButtonColor: '#ff3c7e'
            });
        }
    </script>
</body>
</html>