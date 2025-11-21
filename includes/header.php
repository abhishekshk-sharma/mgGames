<?php

// Check if user is logged in
$is_logged_in = false;
$user_balance = 0;
$username = '';

if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    
    // Fetch user balance and username from database
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT username, balance FROM users WHERE id = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($username, $user_balance);
        $stmt->fetch();
        $stmt->close();
    }
}

// Initialize messages
$success_message = '';
$errors = [];

// Handle deposit form submission - FIXED: Better cash handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deposit_submit'])) {
    error_log("=== DEPOSIT FORM DEBUG ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    error_log("Logged in: " . ($is_logged_in ? 'Yes' : 'No'));
    
    // Check if form was already submitted to prevent duplicate submissions
    if (isset($_SESSION['last_form_submission']) && 
        time() - $_SESSION['last_form_submission'] < 5) {
        $errors[] = "Please wait before submitting again.";
        error_log("Duplicate submission prevented");
    } else {
        $_SESSION['last_form_submission'] = time();
        
        if ($is_logged_in) {
            require_once 'config.php';
            
            if (!$conn) {
                error_log("Database connection failed: " . mysqli_connect_error());
                $errors[] = "Database connection failed. Please try again.";
            } else {
                error_log("Database connected successfully");
                
                // FIXED: Better input validation
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
                $utr_number = isset($_POST['utr_number']) ? trim($_POST['utr_number']) : '';
                $user_id = $_SESSION['user_id'];
                
                error_log("Processing deposit - Amount: $amount, Method: $payment_method, UTR: $utr_number");
                
                // Validation
                if (empty($amount) || $amount <= 0) {
                    $errors[] = "Please enter a valid amount";
                } elseif ($amount < 100) {
                    $errors[] = "Minimum deposit amount is ₹100";
                }
                
                // Payment method validation - updated to include cash
                if (empty($payment_method) || !in_array($payment_method, ['phonepay', 'cash'])) {
                    $errors[] = "Please select a valid payment method";
                    error_log("Invalid payment method: $payment_method");
                }
                
                // UTR validation - only required for non-cash methods
                if ($payment_method !== 'cash') {
                    if (empty($utr_number)) {
                        $errors[] = "UTR number is required for online payments";
                    } elseif (strlen($utr_number) < 5) {
                        $errors[] = "Please enter a valid UTR number (minimum 5 characters)";
                    }
                }
                
                // Handle file upload - only required for non-cash methods
                $payment_proof = '';
                
                if ($payment_method !== 'cash') {
                    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $max_size = 5 * 1024 * 1024;
                        
                        $file_type = $_FILES['payment_proof']['type'];
                        $file_size = $_FILES['payment_proof']['size'];
                        $file_name = $_FILES['payment_proof']['name'];
                        
                        if (!in_array($file_type, $allowed_types)) {
                            $errors[] = "Please upload a valid image file (JPEG, PNG, GIF, WebP)";
                        } elseif ($file_size > $max_size) {
                            $errors[] = "Image size should be less than 5MB";
                        } else {
                            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                            $payment_proof = 'deposit_proof_' . $user_id . '_' . time() . '.' . $file_extension;
                            $upload_path = 'uploads/deposit_proofs/' . $payment_proof;
                            
                            if (!is_dir('uploads/deposit_proofs')) {
                                mkdir('uploads/deposit_proofs', 0755, true);
                            }
                            
                            if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_path)) {
                                $errors[] = "Failed to upload payment proof";
                            }
                        }
                    } else {
                        $errors[] = "Please upload payment proof screenshot for online payments";
                    }
                } else {
                    // For cash deposits, explicitly set UTR to empty
                    $utr_number = '';
                    $payment_proof = '';
                    error_log("Cash deposit - UTR and payment proof set to empty");
                }
                
                if (empty($errors)) {
                    // Insert deposit record
                    $sql = "INSERT INTO deposits (user_id, amount, payment_method, utr_number, payment_proof, status) 
                            VALUES (?, ?, ?, ?, ?, 'pending')";
                    
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bind_param("idsss", $user_id, $amount, $payment_method, $utr_number, $payment_proof);
                        
                        if ($stmt->execute()) {

                            $last_id = $conn->insert_id;

                            // Create appropriate description based on payment method
                            if ($payment_method === 'cash') {
                                $description = "Cash deposit request - Amount: ₹" . $amount;
                            } else {
                                $description = "Online deposit request - UTR: $utr_number, Amount: ₹" . $amount;
                            }
                            
                            $success_message = "Deposit request submitted successfully! It will be processed after verification.";
                            error_log("Deposit inserted successfully - Method: $payment_method, Amount: $amount, UTR: $utr_number");
                            
                            // Create transaction record
                            $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, wd_id,  status) 
                                               VALUES (?, 'deposit', ?, ?, ?, ?, $last_id, 'pending')";
                            
                            if ($transaction_stmt = $conn->prepare($transaction_sql)) {
                                $transaction_stmt->bind_param("iddds", $user_id, $amount, $user_balance, $user_balance, $description);
                                $transaction_stmt->execute();
                                $transaction_stmt->close();
                            }
                            
                            // Store success message in session
                            $_SESSION['deposit_success_message'] = $success_message;
                            $_SESSION['show_deposit_modal_flag'] = true;
                            
                            // Redirect to same page to prevent form resubmission
                            header("Location: " . $_SERVER['PHP_SELF']);
                            exit();
                            
                        } else {
                            $errors[] = "Failed to submit deposit request. Please try again.";
                            error_log("Database error: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $errors[] = "Database error. Please try again.";
                        error_log("Prepare statement failed: " . $conn->error);
                    }
                } else {
                    error_log("Validation errors: " . implode(", ", $errors));
                }
            }
            $conn->close();
        } else {
            $errors[] = "You must be logged in to make a deposit.";
        }
    }
}

// Handle withdrawal form submission - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_submit'])) {
    error_log("=== WITHDRAWAL FORM DEBUG ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("Logged in: " . ($is_logged_in ? 'Yes' : 'No'));
    
    // Check if form was already submitted to prevent duplicate submissions
    if (isset($_SESSION['last_form_submission']) && 
        time() - $_SESSION['last_form_submission'] < 5) {
        $errors[] = "Please wait before submitting again.";
        error_log("Duplicate submission prevented");
    } else {
        $_SESSION['last_form_submission'] = time();
        
        if ($is_logged_in) {
            require_once 'config.php';
            
            if (!$conn) {
                error_log("Database connection failed: " . mysqli_connect_error());
                $errors[] = "Database connection failed. Please try again.";
            } else {
                error_log("Database connected successfully");
                
                // Get form data
                $amount = isset($_POST['withdraw_amount']) ? floatval($_POST['withdraw_amount']) : 0;
                $payment_method = isset($_POST['withdraw_method']) ? trim($_POST['withdraw_method']) : '';
                $account_details = isset($_POST['account_details']) ? trim($_POST['account_details']) : '';
                $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
                $user_id = $_SESSION['user_id'];
                
                error_log("Processing withdrawal - Amount: $amount, Method: $payment_method, Bank ID: $bank_id, Account Details: $account_details");
                
                // Validation
                if (empty($amount) || $amount <= 0) {
                    $errors[] = "Please enter a valid amount";
                } elseif ($amount < 500) {
                    $errors[] = "Minimum withdrawal amount is ₹500";
                } elseif ($amount > 50000) {
                    $errors[] = "Maximum withdrawal amount is ₹50,000";
                } elseif ($amount > $user_balance) {
                    $errors[] = "Insufficient balance. Your current balance is ₹" . number_format($user_balance, 2);
                }
                
                // Payment method validation
                if (empty($payment_method) || !in_array($payment_method, ['upi', 'bank'])) {
                    $errors[] = "Please select a valid withdrawal method";
                    error_log("Invalid withdrawal method: $payment_method");
                }
                
                // Account details validation based on method
                if ($payment_method === 'upi') {
                    if (empty($account_details)) {
                        $errors[] = "UPI ID is required";
                    } elseif (!preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/', $account_details)) {
                        $errors[] = "Please enter a valid UPI ID (e.g.: yourname@upi)";
                    }
                } elseif ($payment_method === 'bank') {
                    if ($bank_id <= 0) {
                        $errors[] = "Please select a bank account";
                    } else {
                        // Verify bank account belongs to user and get details
                        $bank_check_sql = "SELECT holder_name, bank_name, account_number, ifsc_code FROM user_banks WHERE id = ? AND user_id = ?";
                        if ($bank_check_stmt = $conn->prepare($bank_check_sql)) {
                            $bank_check_stmt->bind_param("ii", $bank_id, $user_id);
                            $bank_check_stmt->execute();
                            $bank_check_stmt->store_result();
                            
                            if ($bank_check_stmt->num_rows === 0) {
                                $errors[] = "Invalid bank account selected";
                            } else {
                                // Get bank details for account_details field
                                $bank_check_stmt->bind_result($holder_name, $bank_name, $account_number, $ifsc_code);
                                $bank_check_stmt->fetch();
                                
                                // Create account_details JSON string with all bank information
                                $account_details = json_encode([
                                    'holder_name' => $holder_name,
                                    'bank_name' => $bank_name,
                                    'account_number' => $account_number,
                                    'ifsc_code' => $ifsc_code,
                                    'bank_id' => $bank_id
                                ]);
                                error_log("Bank details prepared: $holder_name, $bank_name, $account_number, $ifsc_code");
                            }
                            $bank_check_stmt->close();
                        }
                    }
                }
                
                if (empty($errors)) {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Map payment method to database values
                        $db_payment_method = ($payment_method === 'bank') ? 'bank_transfer' : $payment_method;
                        
                        error_log("Inserting withdrawal with method: $db_payment_method");
                        error_log("Account details: " . $account_details);
                        
                        // Insert withdrawal record
                        $withdraw_sql = "INSERT INTO withdrawals (user_id, amount, payment_method, account_details, status) 
                                        VALUES (?, ?, ?, ?, 'pending')";
                        
                        if ($withdraw_stmt = $conn->prepare($withdraw_sql)) {
                            $withdraw_stmt->bind_param("idss", $user_id, $amount, $db_payment_method, $account_details);
                            
                            if ($withdraw_stmt->execute()) {
                                $withdrawal_id = $conn->insert_id;
                                error_log("Withdrawal record inserted successfully. ID: $withdrawal_id");
                                
                                // Update user balance
                                $new_balance = $user_balance - $amount;
                                $update_sql = "UPDATE users SET balance = ? WHERE id = ?";
                                if ($update_stmt = $conn->prepare($update_sql)) {
                                    $update_stmt->bind_param("di", $new_balance, $user_id);
                                    
                                    if ($update_stmt->execute()) {
                                        error_log("User balance updated from $user_balance to $new_balance");
                                        
                                        // Create transaction record
                                        $transaction_sql = "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, wd_id, status) 
                                                           VALUES (?, 'withdrawal', ?, ?, ?, ?, $withdrawal_id, 'pending')";
                                        
                                        $description = "Withdrawal request - " . $db_payment_method;
                                        if ($transaction_stmt = $conn->prepare($transaction_sql)) {
                                            $transaction_stmt->bind_param("iddds", $user_id, $amount, $user_balance, $new_balance, $description);
                                            $transaction_stmt->execute();
                                            $transaction_stmt->close();
                                            error_log("Transaction record created");
                                        }
                                        
                                        // Commit transaction
                                        $conn->commit();
                                        
                                        $withdraw_success_message = "Withdrawal request submitted successfully! ₹" . number_format($amount, 2) . " will be processed within 24 hours.";
                                        error_log("Withdrawal SUCCESS - Method: $db_payment_method, Amount: $amount");
                                        
                                        // Store success message in session
                                        $_SESSION['withdraw_success_message'] = $withdraw_success_message;
                                        $_SESSION['show_withdraw_modal_flag'] = true;
                                        
                                        // Update session balance
                                        $_SESSION['user_balance'] = $new_balance;
                                        
                                        // Redirect to same page to prevent form resubmission
                                        header("Location: " . $_SERVER['PHP_SELF']);
                                        exit();
                                        
                                    } else {
                                        throw new Exception("Failed to update user balance: " . $update_stmt->error);
                                    }
                                    $update_stmt->close();
                                } else {
                                    throw new Exception("Failed to prepare balance update statement");
                                }
                            } else {
                                throw new Exception("Failed to submit withdrawal request: " . $withdraw_stmt->error);
                            }
                            $withdraw_stmt->close();
                        } else {
                            throw new Exception("Failed to prepare withdrawal statement: " . $conn->error);
                        }
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollback();
                        $errors[] = "Failed to process withdrawal request. Please try again.";
                        error_log("Withdrawal error: " . $e->getMessage());
                    }
                } else {
                    error_log("Validation errors: " . implode(", ", $errors));
                }
            }
            $conn->close();
        } else {
            $errors[] = "You must be logged in to make a withdrawal.";
        }
    }
}
// Handle add bank form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank_submit'])) {
    error_log("=== ADD BANK FORM DEBUG ===");
    
    if ($is_logged_in) {
        require_once 'config.php';
        
        if ($conn) {
            $bank_holder_name = isset($_POST['bank_holder_name']) ? trim($_POST['bank_holder_name']) : '';
            $bank_name = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : '';
            $account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : '';
            $ifsc_code = isset($_POST['ifsc_code']) ? trim($_POST['ifsc_code']) : '';
            $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
            
            error_log("Adding bank - Holder: $bank_holder_name, Bank: $bank_name, Account: $account_number");
            
            $bank_errors = [];
            
            // Validation
            if (empty($bank_holder_name)) {
                $bank_errors[] = "Bank holder name is required";
            }
            
            if (empty($bank_name)) {
                $bank_errors[] = "Bank name is required";
            }
            
            if (empty($account_number) || !is_numeric($account_number)) {
                $bank_errors[] = "Valid account number is required";
            } elseif (strlen($account_number) < 9 || strlen($account_number) > 18) {
                $bank_errors[] = "Account number must be between 9 and 18 digits";
            }
            
            if (empty($ifsc_code)) {
                $bank_errors[] = "IFSC code is required";
            } elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) {
                $bank_errors[] = "Please enter a valid IFSC code (e.g., SBIN0000123)";
            }
            
            if (empty($phone_number) || !is_numeric($phone_number)) {
                $bank_errors[] = "Valid phone number is required";
            } elseif (strlen($phone_number) !== 10) {
                $bank_errors[] = "Phone number must be 10 digits";
            }
            
            // Check if bank account already exists for this user
            if (empty($bank_errors)) {
                $check_sql = "SELECT id FROM user_banks WHERE user_id = ? AND account_number = ?";
                if ($check_stmt = $conn->prepare($check_sql)) {
                    $check_stmt->bind_param("is", $user_id, $account_number);
                    $check_stmt->execute();
                    $check_stmt->store_result();
                    
                    if ($check_stmt->num_rows > 0) {
                        $bank_errors[] = "This bank account is already added";
                    }
                    $check_stmt->close();
                }
            }
            
            if (empty($bank_errors)) {
                $insert_sql = "INSERT INTO user_banks (user_id, holder_name, bank_name, account_number, ifsc_code, phone_number, is_active) 
                              VALUES (?, ?, ?, ?, ?, ?, 1)";
                
                if ($insert_stmt = $conn->prepare($insert_sql)) {
                    $insert_stmt->bind_param("isssss", $user_id, $bank_holder_name, $bank_name, $account_number, $ifsc_code, $phone_number);
                    
                    if ($insert_stmt->execute()) {
                        $_SESSION['bank_success_message'] = "Bank account added successfully!";
                        $_SESSION['show_withdraw_modal_flag'] = true; // Show withdraw modal after adding bank
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $bank_errors[] = "Failed to add bank account. Please try again.";
                    }
                    $insert_stmt->close();
                } else {
                    $bank_errors[] = "Database error. Please try again.";
                }
            }
            
            // Add bank errors to main errors array
            if (!empty($bank_errors)) {
                $errors = array_merge($errors, $bank_errors);
            }
            
            $conn->close();
        }
    }
}

// Fetch user's banks for the withdrawal form
$user_banks = [];
if ($is_logged_in) {
    // Check if user_banks table exists, if not create it
    $check_table = $conn->query("SHOW TABLES LIKE 'user_banks'");
    if ($check_table->num_rows == 0) {
        // Create user_banks table if it doesn't exist
        $create_table_sql = "CREATE TABLE IF NOT EXISTS user_banks (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            holder_name VARCHAR(100) NOT NULL,
            bank_name VARCHAR(100) NOT NULL,
            account_number VARCHAR(20) NOT NULL,
            ifsc_code VARCHAR(11) NOT NULL,
            phone_number VARCHAR(15) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )";
        $conn->query($create_table_sql);
    }
    
    $banks_sql = "SELECT id, holder_name, bank_name, account_number, ifsc_code FROM user_banks WHERE user_id = ? AND is_active = 1";
    if ($banks_stmt = $conn->prepare($banks_sql)) {
        $banks_stmt->bind_param("i", $user_id);
        $banks_stmt->execute();
        $banks_stmt->bind_result($bank_id, $holder_name, $bank_name, $account_number, $ifsc_code);
        
        while ($banks_stmt->fetch()) {
            $user_banks[] = [
                'id' => $bank_id,
                'holder_name' => $holder_name,
                'bank_name' => $bank_name,
                'account_number' => $account_number,
                'ifsc_code' => $ifsc_code
            ];
        }
        $banks_stmt->close();
    }
}

// Check for bank success message
$bank_success_message = '';
if (isset($_SESSION['bank_success_message'])) {
    $bank_success_message = $_SESSION['bank_success_message'];
    unset($_SESSION['bank_success_message']);
}
// profile model
// Check if user is logged in
$is_logged_in = false;
$user_balance = 0;
$username = '';
$user_email = '';
$user_phone = '';
$user_created_at = '';
$user_status = '';
$user_referral_code = '';
$broker_name = '';

if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    
    // Fetch user data from database
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT username, email, phone, balance, status, created_at, referral_code FROM users WHERE id = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($username, $user_email, $user_phone, $user_balance, $user_status, $user_created_at, $user_referral_code);
        $stmt->fetch();
        $stmt->close();
    }
    
    // Fetch broker name if referral code exists
    if (!empty($user_referral_code)) {
        $broker_sql = "SELECT username FROM admins WHERE referral_code = ?";
        if ($broker_stmt = $conn->prepare($broker_sql)) {
            $broker_stmt->bind_param("s", $user_referral_code);
            $broker_stmt->execute();
            $broker_stmt->bind_result($broker_name);
            $broker_stmt->fetch();
            $broker_stmt->close();
        }
    }
}

// Initialize messages
$success_message = '';
$errors = [];


// Check for success messages from session - FIXED
$deposit_success_message = '';
$withdraw_success_message = '';

if (isset($_SESSION['deposit_success_message'])) {
    $deposit_success_message = $_SESSION['deposit_success_message'];
    unset($_SESSION['deposit_success_message']);
}

if (isset($_SESSION['withdraw_success_message'])) {
    $withdraw_success_message = $_SESSION['withdraw_success_message'];
    unset($_SESSION['withdraw_success_message']);
}

// Check if we should show modals - FIXED
$show_deposit_modal = isset($_SESSION['show_deposit_modal_flag']) || (!empty($errors) && isset($_POST['deposit_submit']));
if (isset($_SESSION['show_deposit_modal_flag'])) {
    unset($_SESSION['show_deposit_modal_flag']);
}

$show_withdraw_modal = isset($_SESSION['show_withdraw_modal_flag']) || (!empty($errors) && isset($_POST['withdraw_submit']));
if (isset($_SESSION['show_withdraw_modal_flag'])) {
    unset($_SESSION['show_withdraw_modal_flag']);
}

// Set the main success message for display
$success_message = $deposit_success_message ?: $withdraw_success_message;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RB Games - Betting Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300..700&display=swap" rel="stylesheet">
<style>
        * {
              margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #f7bc0cff;
            --secondary: #f7e30dff;
            --accent: #c0c0c0;
            --dark: #3a3939ff;
            --light: #f7f6f4ff;
            --success: #32cd32;
            --warning: #ffbf00ff;
            --danger: #ff4500;
            --card-bg: rgba(20, 20, 20, 0.95);
            --header-bg: rgba(255, 255, 255, 0.98);
            --gradient-primary: linear-gradient(135deg, #b09707ff 0%, #ffed4e 100%);
            --gradient-secondary: linear-gradient(135deg, #000000 0%, #2c2c2c 100%);
            --gradient-accent: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
            --gradient-dark: linear-gradient(135deg, #e4d69bff 0%, rgba(13, 13, 13, 1) 100%);
            --gradient-premium: linear-gradient(135deg, #ffd700 0%,rgba(16, 16, 15, 1)100%);
            --card-shadow: 0 12px 40px rgba(255, 215, 0, 0.15);
            --glow-effect: 0 0 25px rgba(255, 215, 0, 0.3);
            --glow-blue: 0 0 25px rgba(0, 0, 0, 0.3);
            --border-radius: 16px;
        }

        body {
            background: var(--gradient-dark);
            color: var(--dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
        }

        /* Header Styles */
        header {
            background: rgba(11, 8, 1, 0.97);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo img {
            height: 40px;
        }

        .logo h1 {
            font-size: 1.8rem;
          
            background: linear-gradient(to right, var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        nav a {
            color: var(--light);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
        }

        nav a:hover {
            color: var(--primary);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 3px;
            background: var(--primary);
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        nav a:hover::after {
            width: 100%;
        }

        .profile-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;    
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .profile-icon:hover {
            transform: scale(1.1);
            border-color: var(--accent);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-right: 15px;
        }

        .username {
            color: var(--light);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .balance-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(221, 170, 17, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid rgba(221, 170, 17, 0.3);
        }

        .balance-display i {
            color: var(--primary);
        }

        .balance-amount {
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .auth-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-login, .btn-register {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-login {
            color: var(--light);
            border: 1px solid var(--primary);
        }

        .btn-register {
            background: var(--primary);
            color: white;
        }

        .btn-login:hover {
            background: rgba(221, 170, 17, 0.1);
        }

        .btn-register:hover {
            background: #c4950e;
            transform: translateY(-2px);
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(26, 26, 46, 0.95);
            border-radius: 8px;
            padding: 0.5rem;
            min-width: 150px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            backdrop-filter: blur(10px);
            margin-top: 10px;
        }

        .dropdown-menu a {
            display: block;
            padding: 0.5rem 1rem;
            color: var(--light);
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .dropdown-menu a:hover {
            background: rgba(221, 170, 17, 0.2);
            color: var(--primary);
        }

        .dropdown-menu.show {
            display: block;
        }
              
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 6px;
            cursor: pointer;
            padding: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .hamburger:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .hamburger span {
            width: 28px;
            height: 3px;
            background: var(--light);
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }
            
            nav ul {
                position: fixed;
                top: 80px;
                left: -100%;
                width: 100%;
                height: calc(100vh - 80px);
                background: rgba(110, 110, 128, 0.98);
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 3rem;
                transition: left 0.3s ease;
            }
            
            nav ul.active {
                left: 0;
            }
            
            .hamburger {
                display: flex;
            }
            
            .hamburger.active span:nth-child(1) {
                transform: rotate(45deg) translate(5px, 5px);
            }
            
            .hamburger.active span:nth-child(2) {
                opacity: 0;
            }
            
            .hamburger.active span:nth-child(3) {
                transform: rotate(-45deg) translate(7px, -6px);
            }
            
            .user-info {
                display: none;
            }
        }

        /* Deposit Modal Styles */
            .deposit-modal {
                display: none;
                position: fixed;
                z-index: 2000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.8);
                backdrop-filter: blur(5px);
                animation: fadeIn 0.3s ease;
                overflow-y: auto; Enable scrolling
            }
            .deposit-modal-content {
                background: linear-gradient(145deg, #1e2044, #191a38);
                margin: 2% auto; /* Reduced margin for better spacing */
                padding: 0;
                border-radius: 15px;
                width: 90%;
                max-width: 500px;
                max-height: 95vh; /* Limit height */
                /* overflow-y: auto; Enable scrolling inside modal */
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                animation: slideIn 0.3s ease;
                position: relative;
            }


            .deposit-modal-header {
                background: linear-gradient(to right, var(--primary), var(--secondary));
                padding: 1.5rem 2rem;
                color: white;
                border-radius: 15px 15px 0 0;
                text-align: center;
            }

            .deposit-modal-title {
                font-size: 1.8rem;
                margin-bottom: 0.5rem;
            }

            .deposit-modal-body {
                padding: 2rem;
                max-height: calc(90vh - 120px); /* Adjust based on header height */
                overflow-y: auto; /* Enable scrolling for content */
            }

            .payment-options {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
                margin-bottom: 2rem;
            }

            .payment-option {
                background: rgba(255, 255, 255, 0.05);
                border: 2px solid transparent;
                border-radius: 10px;
                padding: 1rem; /* Reduced padding */
                text-align: center;
                cursor: pointer;
                transition: all 0.3s ease;
                min-height: auto; /* Remove fixed height */
            }


            .payment-option:hover {
                transform: translateY(-5px);
                border-color: var(--primary);
                background: rgba(255, 255, 255, 0.1);
            }

            .payment-option.active {
                border-color: var(--primary);
                background: rgba(255, 60, 126, 0.1);
            }

            .payment-icon {
                font-size: 2rem; /* Reduced icon size */
                margin-bottom: 0.5rem; /* Reduced margin */
                display: block;
            }

            .payment-name {
                font-size: 0.9rem; /* Smaller font for mobile */
                font-weight: 600;
            }

            .payment-form {
                display: none;
            }

            .payment-form.active {
                display: block;
            }

            .form-group {
                margin-bottom: 1.5rem;
            }

            .form-label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 500;
                color: #b2bec3;
            }

            .form-input {
                width: 100%;
                padding: 12px 15px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.05);
                color: white;
                font-size: 1rem;
                transition: all 0.3s ease;
            }

            .form-input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 2px rgba(255, 60, 126, 0.2);
            }

            .qr-scanner {
                text-align: center;
                margin: 1.5rem 0;
                padding: 1rem;
                background: rgba(0, 0, 0, 0.2);
                border-radius: 8px;
            }

            .qr-code {
                width: 150px;
                height: 150px;
                margin: 0 auto 1rem;
                background: white;
                padding: 10px;
                border-radius: 8px;
            }

            .qr-placeholder {
                width: 100%;
                height: 100%;
                background: #f0f0f0;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #eeececff;
                font-size: 0.8rem;
            }

            .submit-btn {
                background: linear-gradient(to right, var(--primary), var(--secondary));
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 50px;
                cursor: pointer;
                transition: all 0.3s ease;
                font-weight: 600;
                font-size: 1rem;
                width: 100%;
                margin-top: 1rem;
            }

            .submit-btn:hover {
                background: linear-gradient(to right, var(--secondary), var(--primary));
                transform: translateY(-2px);
            }

            .close-deposit-modal {
                position: absolute;
                top: 15px;
                right: 20px;
                color: #fff;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                z-index: 10;
                transition: all 0.3s ease;
                background: rgba(255, 60, 126, 0.7);
                width: 40px;
                height: 40px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .close-deposit-modal:hover {
                background: var(--primary);
                transform: rotate(90deg);
            }


            /* Improved responsive design */
            @media (max-width: 768px) {
                .deposit-modal-content {
                    width: 95%;
                    margin: 5% auto; /* Reduced margin for mobile */
                    max-height: 95vh; /* More height on mobile */
                }
                
                .deposit-modal-body {
                    padding: 1.5rem;
                    max-height: calc(95vh - 100px); /* Adjust for mobile */
                }
                
                .payment-options {
                    grid-template-columns: repeat(2, 1fr); /* Keep 2 columns on mobile */
                    gap: 0.5rem; /* Reduced gap */
                }
                
                .payment-option {
                    padding: 0.8rem; /* Further reduced padding */
                }
                
                .payment-icon {
                    font-size: 1.8rem; /* Smaller icons on mobile */
                    margin-bottom: 0.3rem;
                }
                
                .payment-name {
                    font-size: 0.8rem; /* Smaller text on mobile */
                }
                
                .qr-scanner {
                    margin: 1rem 0;
                    padding: 0.8rem;
                }
                
                .qr-code {
                    width: 120px; /* Smaller QR code on mobile */
                    height: 120px;
                }
                
                .form-group {
                    margin-bottom: 1rem; /* Reduced spacing */
                }
                
                .image-upload-section {
                    margin: 1rem 0;
                    padding: 0.8rem;
                }
                
                .image-preview img {
                    max-width: 150px; /* Smaller preview on mobile */
                    max-height: 150px;
                }
            }

            /* Very small screens */
            @media (max-width: 480px) {
                .payment-options {
                    grid-template-columns: 1fr; /* Single column on very small screens */
                    gap: 0.5rem;
                }
                
                .deposit-modal-content {
                    margin: 2% auto;
                    width: 98%;
                }
                
                .deposit-modal-body {
                    padding: 1rem;
                }
                
                .payment-option {
                    padding: 0.7rem;
                }
            }

            /* Add smooth scrolling */
            .deposit-modal-content {
                scrollbar-width: thin;
                scrollbar-color: var(--primary) rgba(255, 255, 255, 0.1);
            }

            .deposit-modal-content::-webkit-scrollbar {
                width: 6px;
            }

            .deposit-modal-content::-webkit-scrollbar-track {
                background: rgba(255, 255, 255, 0.1);
                border-radius: 3px;
            }

            .deposit-modal-content::-webkit-scrollbar-thumb {
                background: var(--primary);
                border-radius: 3px;
            }

            .deposit-modal-content::-webkit-scrollbar-thumb:hover {
                background: var(--secondary);
            }
            .scanner{
                height:150px;
                width: 150px;
            }

            /* Add to existing CSS */
            .image-upload-section {
                margin: 1.5rem 0;
                padding: 1rem;
                background: rgba(0, 0, 0, 0.2);
                border-radius: 8px;
                border: 1px dashed rgba(255, 255, 255, 0.3);
            }

            .image-upload-label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 500;
                color: #b2bec3;
            }

            .image-upload-input {
                width: 100%;
                padding: 10px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.05);
                color: white;
                font-size: 0.9rem;
            }

            .image-preview {
                margin-top: 1rem;
                text-align: center;
                display: none;
            }

            .image-preview img {
                max-width: 200px;
                max-height: 200px;
                border-radius: 8px;
                border: 2px solid var(--primary);
            }

            .upload-hint {
                font-size: 0.8rem;
                color: #b2bec3;
                margin-top: 0.5rem;
            }
            /* Alert Styles */
            .alert {
                padding: 12px 15px;
                margin-bottom: 1.5rem;
                border-radius: 8px;
                font-weight: 500;
            }

            .alert-success {
                background: rgba(0, 184, 148, 0.2);
                border: 1px solid var(--success);
                color: var(--success);
            }

            .alert-error {
                background: rgba(214, 48, 49, 0.2);
                border: 1px solid var(--danger);
                color: var(--danger);
            }

            .alert-error div {
                margin: 5px 0;
            }
            .withdraw-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(5px);
    animation: fadeIn 0.3s ease;
    overflow-y: auto;
        }

        .withdraw-modal-content {
            background: linear-gradient(145deg, #1e2044, #191a38);
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 95vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        .withdraw-modal-header {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            padding: 1.5rem 2rem;
            color: white;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }

        .withdraw-modal-title {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .withdraw-modal-body {
            padding: 2rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }

        .withdraw-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .withdraw-option {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: auto;
        }

        .withdraw-option:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.1);
        }

        .withdraw-option.active {
            border-color: var(--primary);
            background: rgba(255, 60, 126, 0.1);
        }

        .withdraw-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .withdraw-name {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .withdraw-form {
            display: none;
        }

        .withdraw-form.active {
            display: block;
        }

        .close-withdraw-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #fff;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
            background: rgba(255, 60, 126, 0.7);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-withdraw-modal:hover {
            background: var(--primary);
            transform: rotate(90deg);
        }

        .withdrawal-info {
            margin: 1.5rem 0;
        }

        /* Profile Modal Styles */
        .profile-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        .profile-modal-content {
            background: linear-gradient(145deg, #1e2044, #191a38);
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 95vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        .profile-modal-header {
            background: linear-gradient(to right, var(--primary));
            padding: 1.5rem 2rem;
            color: white;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }

        .profile-modal-title {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .profile-modal-body {
            padding: 2rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }

        .close-profile-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #fff;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
            background: rgba(255, 60, 126, 0.7);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-profile-modal:hover {
            background: var(--primary);
            transform: rotate(90deg);
        }

        .profile-info-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .profile-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            margin-bottom: 0.5rem; /* Added spacing between items */
        }

        .profile-info-item label {
            font-weight: 600;
            color: #b2bec3;
        }

        .profile-info-item span {
            color: var(--light);
            font-weight: 500;
        }

        .balance-highlight {
            color: var(--primary) !important;
            font-weight: 700 !important;
            font-size: 1.1rem;
        }
        .status-badge1 {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap; 
            margin-left: 1rem; 
        }
        .status-badge.active {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .status-badge.suspended {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .status-badge.banned {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .profile-stats {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .profile-stats h3 {
            color: var(--light);
            margin-bottom: 1rem;
            text-align: center;
            font-size: 1.3rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .stat-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #b2bec3;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .profile-modal-body {
                padding: 1.5rem;
            }
            
            .profile-info-item {
                flex-direction: row; /* Keep horizontal layout on mobile */
                align-items: center;
                gap: 0.5rem;
                padding: 0.8rem;
                
            }
            .status-badge1 {
                margin-left: auto; /* Push badge to the right on mobile */
                flex-shrink: 0; /* Prevent badge from shrinking */
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Broker Information Styles */
        .broker-section {
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border-left: 4px solid var(--secondary);
        }

        .broker-section h3 {
            color: var(--secondary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .broker-section h3::before {
            content: '👤';
            font-size: 1.2rem;
        }

        .broker-details {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .broker-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .broker-item i {
            color: var(--secondary);
            font-size: 1.2rem;
            width: 30px;
            text-align: center;
        }

        .broker-text {
            display: flex;
            flex-direction: column;
        }

        .broker-text strong {
            color: #b2bec3;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .broker-text span {
            color: var(--light);
            font-weight: 600;
        }

        .broker-info {
            color: var(--secondary) !important;
            font-weight: 600;
        }

        /* Responsive design for broker section */
        @media (max-width: 768px) {
            .broker-section {
                padding: 1rem;
            }
            
            .broker-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .broker-item i {
                align-self: flex-start;
            }
        }
        /* Bank Management Styles */
        .bank-management-section {
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .bank-management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .bank-management-title {
            color: var(--light);
            font-size: 1.2rem;
            font-weight: 600;
        }

        .add-bank-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .add-bank-btn:hover {
            background: #0da4b5;
            transform: translateY(-2px);
        }

        .banks-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .bank-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .bank-item:hover {
            border-color: var(--primary);
            background: rgba(255, 60, 126, 0.05);
        }

        .bank-item.selected {
            border-color: var(--primary);
            background: rgba(255, 60, 126, 0.1);
        }

        .bank-item::after {
            content: "✓";
            position: absolute;
            top: 10px;
            right: 10px;
            color: var(--primary);
            font-weight: bold;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .bank-item.selected::after {
            opacity: 1;
        }

        .bank-details {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .bank-name {
            font-weight: 600;
            color: var(--light);
            font-size: 1rem;
        }

        .account-number {
            color: #b2bec3;
            font-size: 0.9rem;
        }

        .holder-name {
            color: #b2bec3;
            font-size: 0.9rem;
        }

        .no-banks-message {
            text-align: center;
            padding: 2rem;
            color: #b2bec3;
            font-style: italic;
        }

        /* Add Bank Modal Styles */
        .add-bank-modal {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        .add-bank-modal-content {
            background: linear-gradient(145deg, #1e2044, #191a38);
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            animation: slideIn 0.3s ease;
            position: relative;
        }

        .add-bank-modal-header {
            background: linear-gradient(to right, var(--secondary), var(--primary));
            padding: 1.5rem 2rem;
            color: white;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }

        .add-bank-modal-title {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .add-bank-modal-body {
            padding: 2rem;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }

        .close-add-bank-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            color: #fff;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
            background: rgba(255, 60, 126, 0.7);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-add-bank-modal:hover {
            background: var(--primary);
            transform: rotate(90deg);
        }

        .terms-alert {
            background: rgba(253, 203, 110, 0.1);
            border: 1px solid var(--warning);
            border-radius: 8px;
            padding: 1rem;
            margin: 1.5rem 0;
        }

        .terms-alert p {
            color: var(--warning);
            font-size: 0.9rem;
            margin: 0;
            text-align: center;
        }

        .bank-alert {
            background: rgba(214, 48, 49, 0.1);
            border: 1px solid var(--danger);
            border-radius: 8px;
            padding: 1rem;
            margin: 1.5rem 0;
        }

        .bank-alert p {
            color: var(--danger);
            font-size: 0.9rem;
            margin: 0;
            text-align: center;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .add-bank-modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .add-bank-modal-body {
                padding: 1.5rem;
            }
            
            .bank-management-section {
                padding: 1rem;
            }
        }
</style>
 </head>
<body>
    <!-- Header -->
     <header>
        <div class="logo">
            <h1>RB Games</h1>
        </div>
        
        <nav>
            <ul id="nav-menu">
                <li><a href="index.php">Home</a></li>
                <li><a href="my_bet.php">My Bet</a></li>
                <li><a href="chart.php">Chart</a></li>
                <li><a href="transactions.php">Transactions</a></li>
            </ul>
        </nav>
        
        <div class="header-right">
            <?php if ($is_logged_in): ?>
                <div class="user-info">
                    <span class="username">Welcome, <?php echo htmlspecialchars($username); ?></span>
                    <div class="balance-display">
                        <i class="fas fa-wallet"></i>
                        <span class="balance-amount" id="balance-amount">₹<?php echo number_format($user_balance, 2); ?></span>
                    </div>
                </div>
                <div class="profile-icon" id="profile-icon">
                    <i class="fas fa-user"></i>
                   <div class="dropdown-menu" id="dropdown-menu">
                        <a href="#" id="profile-link">Profile</a>
                        <a href="#" id="deposit-link">Deposit</a>
                        <a href="#" id="withdraw-link">Withdraw</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="login.php" class="btn-login">Login</a>
                    <a href="signup.php" class="btn-register">Register</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="hamburger" id="hamburger">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </header>
<!-- Profile Modal -->
<div id="profileModal" class="profile-modal">
    <div class="profile-modal-content">
        <span class="close-profile-modal">&times;</span>
        <div class="profile-modal-header">
            <h2 class="profile-modal-title">User Profile</h2>
            <p>Your account information</p>
        </div>
        
        <div class="profile-modal-body">
            <div class="profile-info-grid">
                <div class="profile-info-item">
                    <label>Username:</label>
                    <span><?php echo htmlspecialchars($username); ?></span>
                </div>
                <div class="profile-info-item">
                    <label>Email:</label>
                    <span><?php echo htmlspecialchars($user_email); ?></span>
                </div>
                <div class="profile-info-item">
                    <label>Phone:</label>
                    <span><?php echo !empty($user_phone) ? htmlspecialchars($user_phone) : 'Not provided'; ?></span>
                </div>
                <div class="profile-info-item">
                    <label>Account Status:</label>
                    <span class="status-badge1 <?php echo strtolower($user_status); ?>"><?php echo ucfirst($user_status); ?></span>
                </div>
                <div class="profile-info-item">
                    <label>Current Balance:</label>
                    <span class="balance-highlight">₹<?php echo number_format($user_balance, 2); ?></span>
                </div>
            
                <div class="profile-info-item">
                    <label>Member Since:</label>
                    <span><?php echo date('F j, Y', strtotime($user_created_at)); ?></span>
                </div>
            </div>
            
            <!-- Broker Information Section -->
            <?php if (!empty($broker_name)): ?>
            <div class="broker-section">
                <h3>Broker Information</h3>
                <div class="broker-details">
                    <div class="broker-item">
                        <i class="fas fa-user-tie"></i>
                        <div class="broker-text">
                            <strong>Broker Name:</strong>
                            <span><?php echo htmlspecialchars($broker_name); ?></span>
                        </div>
                    </div>
                    <div class="broker-item">
                        <i class="fas fa-id-card"></i>
                        <div class="broker-text">
                            <strong>Referral Code:</strong>
                            <span><?php echo htmlspecialchars($user_referral_code); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="profile-stats">
                <h3>Account Statistics</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value">₹0.00</div>
                        <div class="stat-label">Total Deposits</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">₹0.00</div>
                        <div class="stat-label">Total Withdrawals</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">₹0.00</div>
                        <div class="stat-label">Total Winnings</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
 

<!-- Deposit Modal -->
<div id="depositModal" class="deposit-modal">
    <div class="deposit-modal-content">
        <span class="close-deposit-modal">&times;</span>
        <div class="deposit-modal-header">
            <h2 class="deposit-modal-title">Deposit Funds</h2>
            <p>Choose your payment method</p>
        </div>
        
        <?php if (!empty($errors) || !empty($success_message)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const depositModal = document.getElementById('depositModal');
            if (depositModal) {
                depositModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        });
        </script>
        <?php endif; ?>
        
        <div class="deposit-modal-body">
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo $error; ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form id="depositForm" method="POST" enctype="multipart/form-data">
                <div class="payment-options">
                    <div class="payment-option active" data-method="phonepay">
                        <i class="payment-icon fab fa-google-pay"></i>
                        <div class="payment-name">PhonePe</div>
                    </div>
                    <div class="payment-option" data-method="cash">
                        <i class="payment-icon fas fa-money-bill-wave"></i>
                        <div class="payment-name">Cash</div>
                    </div>
                </div>
                
                <input type="hidden" name="payment_method" id="payment_method" value="phonepay">
                
                <!-- PhonePe Payment Form -->
                <div id="phonepay-form" class="payment-form active">
                    <div class="qr-scanner">
                        <div class="qr-code">
                            <div class="qr-placeholder"><img src="scanner.jpg" alt="" class="scanner"></div>
                        </div>
                        <p>Scan QR code to pay with PhonePe</p>
                    </div>
                </div>
                
                <!-- Cash Payment Form -->
                <div id="cash-form" class="payment-form">
                    <div class="form-group">
                        <div style="background: rgba(0, 184, 148, 0.1); padding: 1rem; border-radius: 8px; border: 1px solid var(--success); margin-bottom: 1.5rem;">
                            <p style="color: var(--success); margin-bottom: 0.5rem;"><strong>Cash Deposit Instructions</strong></p>
                            <p style="color: #b2bec3; font-size: 0.9rem;">
                                Please visit our office to deposit cash. Our executive will process your deposit and provide you with a transaction receipt.
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- COMMON FORM FIELDS -->
                <div class="form-group">
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" name="amount" class="form-input" placeholder="Enter amount" min="100" required 
                           value="<?php echo !empty($success_message) ? '' : (isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : ''); ?>">
                </div>
                
                <!-- UTR Number Field (Hidden for Cash) -->
                <div class="form-group" id="utr-section">
                    <label class="form-label">UTR Number (12 digits)</label>
                    <input type="text" name="utr_number" class="form-input" placeholder="Enter 12-digit UTR number" 
                           pattern="[0-9]{12}" maxlength="12"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 12)"
                           value="<?php echo !empty($success_message) ? '' : (isset($_POST['utr_number']) ? htmlspecialchars($_POST['utr_number']) : ''); ?>">
                    <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                        Must be exactly 12 digits (numbers only)
                    </small>
                </div>
                
                <!-- Payment Proof Section (Hidden for Cash) -->
                <div class="form-group" id="payment-proof-section">
                    <label class="form-label">Payment Proof (Screenshot)</label>
                    <div class="image-upload-section">
                        <label class="image-upload-label">Upload screenshot of successful payment</label>
                        <input type="file" name="payment_proof" class="image-upload-input" accept="image/*">
                        <div class="image-preview">
                            <img src="" alt="Payment proof preview">
                        </div>
                        <p class="upload-hint">Please upload a clear screenshot showing transaction details and UTR number</p>
                    </div>
                </div>
                
                <button type="submit" name="deposit_submit" class="submit-btn">Submit Deposit</button>
            </form>
        </div>
    </div>
</div>

<div id="withdrawModal" class="withdraw-modal">
    <div class="withdraw-modal-content">
        <span class="close-withdraw-modal">&times;</span>
        <div class="withdraw-modal-header">
            <h2 class="withdraw-modal-title">Withdraw Funds</h2>
            <p>Request withdrawal to your account</p>
        </div>

        <?php if (!empty($errors) && (isset($_POST['withdraw_submit']) || isset($_POST['add_bank_submit']))): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const withdrawModal = document.getElementById('withdrawModal');
                    if (withdrawModal) {
                        withdrawModal.style.display = 'block';
                        document.body.style.overflow = 'hidden';
                    }
                });
            </script>
        <?php endif; ?>

        <div class="withdraw-modal-body">
            <?php if (!empty($withdraw_success_message)): ?>
                <div class="alert alert-success"><?php echo $withdraw_success_message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($bank_success_message)): ?>
                <div class="alert alert-success"><?php echo $bank_success_message; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errors) && (isset($_POST['withdraw_submit']) || isset($_POST['add_bank_submit']))): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo $error; ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- FIXED: Proper form structure -->
            <form id="withdrawForm" method="POST" action="">
                <div class="withdraw-options">
                    <div class="withdraw-option active" data-method="upi">
                        <i class="withdraw-icon fas fa-mobile-alt"></i>
                        <div class="withdraw-name">UPI</div>
                    </div>
                    <div class="withdraw-option" data-method="bank">
                        <i class="withdraw-icon fas fa-university"></i>
                        <div class="withdraw-name">Bank</div>
                    </div>
                </div>
                
                <input type="hidden" name="withdraw_method" id="withdraw_method" value="upi">
                <input type="hidden" name="bank_id" id="bank_id" value="0">
                
                <!-- UPI Withdrawal Form -->
                <div id="upi-form" class="withdraw-form active">
                    <div class="form-group">
                        <label class="form-label">UPI ID</label>
                        <input type="text" name="account_details" class="form-input" placeholder="Enter your UPI ID" required 
                               value="<?php echo isset($_POST['account_details']) ? htmlspecialchars($_POST['account_details']) : ''; ?>">
                        <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                            Example: yourname@upi
                        </small>
                    </div>
                    
                    <div class="terms-alert">
                        <p><strong>Important:</strong> Please ensure your UPI ID is correct. We are not responsible for transfers to incorrect UPI IDs.</p>
                    </div>
                </div>
                
                <!-- Bank Withdrawal Form -->
                <div id="bank-form" class="withdraw-form">
                    <?php if (empty($user_banks)): ?>
                        <div class="bank-alert">
                            <p>No bank accounts added. Please add a bank account to proceed with bank withdrawal.</p>
                            <button type="button" class="add-bank-btn" id="add-bank-btn-main" style="margin-top: 1rem; width: 100%;">+ Add Bank Account</button>
                        </div>
                    <?php else: ?>
                        <div class="bank-management-section">
                            <div class="bank-management-header">
                                <h3 class="bank-management-title">Select Bank Account</h3>
                                <button type="button" class="add-bank-btn" id="add-bank-btn">+ Add New Bank</button>
                            </div>
                            
                            <div class="banks-list" id="banks-list">
                                <?php foreach ($user_banks as $bank): ?>
                                    <div class="bank-item" data-bank-id="<?php echo $bank['id']; ?>">
                                        <div class="bank-details">
                                            <div class="bank-name"><?php echo htmlspecialchars($bank['bank_name']); ?></div>
                                            <div class="account-number">Account: ****<?php echo substr($bank['account_number'], -4); ?></div>
                                            <div class="holder-name">Holder: <?php echo htmlspecialchars($bank['holder_name']); ?></div>
                                            <div class="ifsc-code">IFSC: <?php echo htmlspecialchars($bank['ifsc_code']); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="terms-alert">
                            <p><strong>Important:</strong> Please ensure your bank details are correct. We are not responsible for transfers to incorrect bank accounts.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" name="withdraw_amount" class="form-input" placeholder="Enter amount" min="500" max="50000" required 
                           value="<?php echo isset($_POST['withdraw_amount']) ? htmlspecialchars($_POST['withdraw_amount']) : ''; ?>">
                    <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                        Minimum: ₹500, Maximum: ₹50,000
                    </small>
                </div>
                
                <div class="withdrawal-info">
                    <div style="background: rgba(0, 184, 148, 0.1); padding: 1rem; border-radius: 8px; border: 1px solid var(--success);">
                        <p style="color: var(--success); margin-bottom: 0.5rem;"><strong>Current Balance: ₹<?php echo number_format($user_balance, 2); ?></strong></p>
                        <p style="color: #b2bec3; font-size: 0.9rem;">
                            Withdrawal requests are processed within 24 hours. Amount will be deducted immediately upon submission.
                        </p>
                    </div>
                </div>
                
                <button type="submit" name="withdraw_submit" class="submit-btn" id="withdraw-submit-btn">REQUEST WITHDRAWAL</button>
            </form>
            <!-- END OF FIXED FORM -->
        </div>
    </div>
</div>

<!-- Add Bank Modal -->
<div id="addBankModal" class="add-bank-modal">
    <div class="add-bank-modal-content">
        <span class="close-add-bank-modal">&times;</span>
        <div class="add-bank-modal-header">
            <h2 class="add-bank-modal-title">Add Bank Account</h2>
            <p>Add your bank account for withdrawals</p>
        </div>
        
        <div class="add-bank-modal-body">
            <form id="addBankForm" method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Account Holder Name</label>
                    <input type="text" name="bank_holder_name" class="form-input" placeholder="Enter account holder name" required 
                           value="<?php echo isset($_POST['bank_holder_name']) ? htmlspecialchars($_POST['bank_holder_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-input" placeholder="Enter bank name" required 
                           value="<?php echo isset($_POST['bank_name']) ? htmlspecialchars($_POST['bank_name']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="account_number" class="form-input" placeholder="Enter account number" required 
                           pattern="[0-9]{9,18}" maxlength="18"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                           value="<?php echo isset($_POST['account_number']) ? htmlspecialchars($_POST['account_number']) : ''; ?>">
                    <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                        9-18 digits only
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="ifsc_code" class="form-input" placeholder="Enter IFSC code" required 
                           pattern="[A-Z]{4}0[A-Z0-9]{6}" maxlength="11"
                           oninput="this.value = this.value.toUpperCase()"
                           value="<?php echo isset($_POST['ifsc_code']) ? htmlspecialchars($_POST['ifsc_code']) : ''; ?>">
                    <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                        Format: ABCD0123456
                    </small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" class="form-input" placeholder="Enter phone number" required 
                           pattern="[0-9]{10}" maxlength="10"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                           value="<?php echo isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : ''; ?>">
                    <small style="color: #b2bec3; font-size: 0.8rem; margin-top: 5px; display: block;">
                        10 digits only
                    </small>
                </div>
                
                <div class="terms-alert">
                    <p><strong>Important:</strong> Please ensure all bank details are correct. If your bank details are incorrect, we are not responsible for failed transfers.</p>
                </div>
                
                <button type="submit" name="add_bank_submit" class="submit-btn">ADD BANK ACCOUNT</button>
            </form>
        </div>
    </div>
</div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>


<script>
 // Mobile menu functionality
const hamburger = document.getElementById("hamburger");
const navMenu = document.getElementById("nav-menu");

hamburger.addEventListener("click", function () {
    hamburger.classList.toggle("active");
    navMenu.classList.toggle("active");
});

// Close mobile menu when clicking on a link
document.querySelectorAll("#nav-menu a").forEach((link) => {
    link.addEventListener("click", function () {
        hamburger.classList.remove("active");
        navMenu.classList.remove("active");
    });
});

// Dropdown menu functionality
const profileIcon = document.getElementById("profile-icon");
const dropdownMenu = document.getElementById("dropdown-menu");

if (profileIcon && dropdownMenu) {
    profileIcon.addEventListener("click", function (e) {
        e.stopPropagation();
        dropdownMenu.classList.toggle("show");
    });

    // Close dropdown when clicking elsewhere
    document.addEventListener("click", function () {
        dropdownMenu.classList.remove("show");
    });

    // Prevent dropdown from closing when clicking inside it
    dropdownMenu.addEventListener("click", function (e) {
        e.stopPropagation();
    });
}

// Deposit Modal Functionality
const depositModal = document.getElementById("depositModal");
const closeDepositModal = document.querySelector(".close-deposit-modal");
const paymentOptions = document.querySelectorAll(".payment-option");
const paymentForms = document.querySelectorAll(".payment-form");
const paymentMethodInput = document.getElementById("payment_method");

// Get references to UTR and Payment Proof sections
const utrSection = document.getElementById("utr-section");
const paymentProofSection = document.getElementById("payment-proof-section");
const utrInput = document.querySelector('input[name="utr_number"]');
const paymentProofInput = document.querySelector('input[name="payment_proof"]');

// Open deposit modal when Deposit link is clicked
const depositLink = document.getElementById("deposit-link");
if (depositLink) {
    depositLink.addEventListener("click", function (e) {
        e.preventDefault();
        depositModal.style.display = "block";
        document.body.style.overflow = "hidden";
    });
}

// Close deposit modal
if (closeDepositModal) {
    closeDepositModal.addEventListener("click", function () {
        depositModal.style.display = "none";
        document.body.style.overflow = "auto";
    });
}

// Close modal when clicking outside
window.addEventListener("click", function (event) {
    if (event.target === depositModal) {
        depositModal.style.display = "none";
        document.body.style.overflow = "auto";
    }
});

// Payment method selection with cash support
paymentOptions.forEach((option) => {
    option.addEventListener("click", function () {
        // Remove active class from all options
        paymentOptions.forEach((opt) => opt.classList.remove("active"));
        // Add active class to clicked option
        this.classList.add("active");

        // Get the payment method
        const method = this.getAttribute("data-method");
        paymentMethodInput.value = method;

        // Hide all forms
        paymentForms.forEach((form) => form.classList.remove("active"));
        // Show selected form
        const targetForm = document.getElementById(method + "-form");
        if (targetForm) {
            targetForm.classList.add("active");
        }

        // Toggle UTR and Payment Proof sections based on payment method
        if (method === "cash") {
            // Hide UTR and Payment Proof for cash
            if (utrSection) utrSection.style.display = "none";
            if (paymentProofSection) paymentProofSection.style.display = "none";
            if (utrInput) utrInput.removeAttribute("required");
            if (paymentProofInput) paymentProofInput.removeAttribute("required");
        } else {
            // Show UTR and Payment Proof for other methods
            if (utrSection) utrSection.style.display = "block";
            if (paymentProofSection) paymentProofSection.style.display = "block";
            if (utrInput) utrInput.setAttribute("required", "required");
            if (paymentProofInput)
                paymentProofInput.setAttribute("required", "required");
        }
    });
});

// Image preview functionality
document.querySelectorAll(".image-upload-input").forEach((input) => {
    input.addEventListener("change", function (e) {
        const file = e.target.files[0];
        const preview = this.closest(".image-upload-section").querySelector(
            ".image-preview"
        );
        const previewImg = preview.querySelector("img");

        if (file) {
            const reader = new FileReader();

            reader.onload = function (e) {
                previewImg.src = e.target.result;
                preview.style.display = "block";
            };

            reader.readAsDataURL(file);
        } else {
            preview.style.display = "none";
        }
    });
});

// Form validation with cash method support - FIXED CLIENT-SIDE VERSION
const depositForm = document.getElementById("depositForm");
if (depositForm) {
    depositForm.addEventListener("submit", function (e) {
        const form = this;
        const amount = form.querySelector('input[name="amount"]').value;
        const paymentMethod = form.querySelector("#payment_method").value;
        const utr = form.querySelector('input[name="utr_number"]')?.value || "";
        const imageInput = form.querySelector('input[name="payment_proof"]');

        // Clear previous error styles
        form.querySelectorAll(".form-input").forEach((input) => {
            input.style.borderColor = "";
        });

        let hasErrors = false;

        // Amount validation (common for all methods)
        if (!amount || amount < 100) {
            form.querySelector('input[name="amount"]').style.borderColor = "red";
            hasErrors = true;
        }

        // UTR validation (only for non-cash methods and when section is visible)
        if (paymentMethod !== "cash" && utrSection.style.display !== "none") {
            if (!utr || utr.trim() === "") {
                form.querySelector('input[name="utr_number"]').style.borderColor =
                    "red";
                hasErrors = true;
            }
        }

        // Payment proof validation (only for non-cash methods and when section is visible)
        if (
            paymentMethod !== "cash" &&
            paymentProofSection.style.display !== "none"
        ) {
            if (!imageInput.files || !imageInput.files[0]) {
                imageInput.style.borderColor = "red";
                hasErrors = true;
            } else {
                // Validate file type
                const file = imageInput.files[0];
                const validImageTypes = [
                    "image/jpeg",
                    "image/png",
                    "image/gif",
                    "image/webp",
                ];
                if (!validImageTypes.includes(file.type)) {
                    imageInput.style.borderColor = "red";
                    hasErrors = true;
                }

                // Validate file size (max 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    imageInput.style.borderColor = "red";
                    hasErrors = true;
                }
            }
        }

        if (hasErrors) {
            e.preventDefault();
            return false;
        }

        return true;
    });

    // Real-time validation
    depositForm.querySelectorAll(".form-input").forEach((input) => {
        input.addEventListener("input", function () {
            this.style.borderColor = "";
        });
    });
}

// Prevent modal content from closing when clicking inside
const modalContent = document.querySelector(".deposit-modal-content");
if (modalContent) {
    modalContent.addEventListener("click", function (e) {
        e.stopPropagation();
    });
}

// Withdrawal modal functionality
const withdrawLink = document.getElementById("withdraw-link");
const withdrawModal = document.getElementById("withdrawModal");
const closeWithdrawModal = document.querySelector(".close-withdraw-modal");

if (withdrawLink && withdrawModal) {
    withdrawLink.addEventListener("click", function (e) {
        e.preventDefault();
        withdrawModal.style.display = "block";
        document.body.style.overflow = "hidden";
    });
}

if (closeWithdrawModal && withdrawModal) {
    closeWithdrawModal.addEventListener("click", function () {
        withdrawModal.style.display = "none";
        document.body.style.overflow = "auto";
    });
}

// Close withdraw modal when clicking outside
if (withdrawModal) {
    withdrawModal.addEventListener("click", function (e) {
        if (e.target === withdrawModal) {
            withdrawModal.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
}

// Add Bank Modal Functionality
const addBankModal = document.getElementById("addBankModal");
const closeAddBankModal = document.querySelector(".close-add-bank-modal");

// Function to open add bank modal
function openAddBankModal() {
    if (addBankModal) {
        addBankModal.style.display = "block";
        document.body.style.overflow = "hidden";
    }
}

// Add event listeners for both add bank buttons
document.addEventListener('click', function(e) {
    if (e.target && (e.target.id === 'add-bank-btn' || e.target.id === 'add-bank-btn-main')) {
        e.preventDefault();
        openAddBankModal();
    }
});

if (closeAddBankModal && addBankModal) {
    closeAddBankModal.addEventListener("click", function () {
        addBankModal.style.display = "none";
        document.body.style.overflow = "auto";
    });
}

// Close add bank modal when clicking outside
if (addBankModal) {
    addBankModal.addEventListener("click", function (e) {
        if (e.target === addBankModal) {
            addBankModal.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
}

// Bank selection functionality
const bankIdInput = document.getElementById("bank_id");
const withdrawSubmitBtn = document.getElementById("withdraw-submit-btn");


// Function to update withdraw button state based on form validity
function updateWithdrawButtonState() {
    const method = document.getElementById("withdraw_method").value;
    const amountInput = document.querySelector('input[name="withdraw_amount"]');
    const amount = amountInput ? parseFloat(amountInput.value) : 0;
    
    let isValid = true;
    
    // Basic amount validation
    if (!amount || amount < 500 || amount > 50000) {
        isValid = false;
    }
    
    // Method-specific validation
    if (method === "bank") {
        const selectedBank = document.querySelector(".bank-item.selected");
        const hasBanks = document.querySelectorAll(".bank-item").length > 0;
        isValid = isValid && (selectedBank !== null && hasBanks);
    } else if (method === "upi") {
        const upiInput = document.querySelector('input[name="account_details"]');
        isValid = isValid && (upiInput && upiInput.value.trim() !== "");
    }
    
    // Update button state
    if (withdrawSubmitBtn) {
        withdrawSubmitBtn.disabled = !isValid;
        withdrawSubmitBtn.style.opacity = isValid ? "1" : "0.6";
        withdrawSubmitBtn.style.cursor = isValid ? "pointer" : "not-allowed";
    }
}

// Add real-time validation for form inputs
document.addEventListener("input", function(e) {
    if (e.target.name === "withdraw_amount" || e.target.name === "account_details") {
        updateWithdrawButtonState();
    }
});
// Enhanced bank selection with better validation
function setupBankSelection() {
    const bankItems = document.querySelectorAll(".bank-item");
    const bankIdInput = document.getElementById("bank_id");
    const withdrawMethod = document.getElementById("withdraw_method").value;
    
    console.log("Setting up bank selection:", {
        bankItems: bankItems.length,
        bankIdInput: bankIdInput,
        currentMethod: withdrawMethod
    });
    
    if (bankItems.length > 0 && bankIdInput) {
        bankItems.forEach((item) => {
            item.addEventListener("click", function () {
                const bankId = this.getAttribute("data-bank-id");
                console.log("Bank item clicked - ID:", bankId);
                
                // Remove selected class from all banks
                bankItems.forEach((bank) => bank.classList.remove("selected"));
                
                // Add selected class to clicked bank
                this.classList.add("selected");
                
                // Update hidden bank_id input
                bankIdInput.value = bankId;
                
                console.log("Bank selected - ID:", bankId);
                
                // Update submit button state
                updateWithdrawButtonState();
            });
        });
        
        // Auto-select first bank if none selected and method is bank
        if (withdrawMethod === 'bank' && !document.querySelector(".bank-item.selected") && bankItems.length > 0) {
            console.log("Auto-selecting first bank");
            bankItems[0].click();
        }
    } else {
        console.log("No banks found or bankIdInput missing");
    }
}
// Enhanced withdrawal method switching
const withdrawOptions = document.querySelectorAll(".withdraw-option");
const withdrawMethodInput = document.getElementById("withdraw_method");
const withdrawForms = document.querySelectorAll(".withdraw-form");

withdrawOptions.forEach((option) => {
    option.addEventListener("click", function () {
        const method = this.getAttribute("data-method");

        console.log("Switching to method:", method);

        // Update active option
        withdrawOptions.forEach((opt) => opt.classList.remove("active"));
        this.classList.add("active");

        // Update hidden input
        withdrawMethodInput.value = method;

        // Show corresponding form
        withdrawForms.forEach((form) => {
            form.classList.remove("active");
            if (form.id === method + "-form") {
                form.classList.add("active");
            }
        });

        // Clear the account details field when switching methods
        const accountDetailsInputs = document.querySelectorAll(
            'input[name="account_details"], textarea[name="account_details"]'
        );
        accountDetailsInputs.forEach((input) => {
            if (input.type === "hidden") return;
            input.value = "";
        });

        // Setup bank selection when switching to bank method
        if (method === "bank") {
            setTimeout(() => {
                setupBankSelection();
                updateWithdrawButtonState();
            }, 100);
        } else {
            updateWithdrawButtonState();
        }
    });
});

// Form validation for add bank form
const addBankForm = document.getElementById("addBankForm");
if (addBankForm) {
    addBankForm.addEventListener("submit", function(e) {
        const form = this;
        const ifscInput = form.querySelector('input[name="ifsc_code"]');
        const accountInput = form.querySelector('input[name="account_number"]');
        const phoneInput = form.querySelector('input[name="phone_number"]');
        
        let hasErrors = false;
        
        // IFSC validation
        if (ifscInput && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifscInput.value)) {
            ifscInput.style.borderColor = "red";
            hasErrors = true;
        }
        
        // Account number validation
        if (accountInput && (!/^\d{9,18}$/.test(accountInput.value))) {
            accountInput.style.borderColor = "red";
            hasErrors = true;
        }
        
        // Phone validation
        if (phoneInput && (!/^\d{10}$/.test(phoneInput.value))) {
            phoneInput.style.borderColor = "red";
            hasErrors = true;
        }
        
        if (hasErrors) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
    
    // Real-time validation for add bank form
    addBankForm.querySelectorAll(".form-input").forEach((input) => {
        input.addEventListener("input", function() {
            this.style.borderColor = "";
        });
    });
}

// Profile Modal functionality
const profileLink = document.getElementById("profile-link");
const profileModal = document.getElementById("profileModal");
const closeProfileModal = document.querySelector(".close-profile-modal");

if (profileLink && profileModal) {
    profileLink.addEventListener("click", function (e) {
        e.preventDefault();
        profileModal.style.display = "block";
        document.body.style.overflow = "hidden";
    });
}

if (closeProfileModal && profileModal) {
    closeProfileModal.addEventListener("click", function () {
        profileModal.style.display = "none";
        document.body.style.overflow = "auto";
    });
}

// Close modal when clicking outside
if (profileModal) {
    window.addEventListener("click", function (e) {
        if (e.target === profileModal) {
            profileModal.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
}

// Enhanced withdrawal form validation and submission
function validateWithdrawalForm() {
    const method = document.getElementById("withdraw_method").value;
    const amountInput = document.querySelector('input[name="withdraw_amount"]');
    const amount = amountInput ? parseFloat(amountInput.value) : 0;
    const accountDetails = document.querySelector('input[name="account_details"]');
    const bankItems = document.querySelectorAll('.bank-item.selected');
    
    let isValid = true;
    let errorMessage = '';
    
    // Amount validation
    if (!amount || amount < 500 || amount > 50000) {
        isValid = false;
        errorMessage = 'Please enter a valid amount between ₹500 and ₹50,000';
    }
    
    // Method-specific validation
    if (method === 'bank') {
        if (bankItems.length === 0) {
            isValid = false;
            errorMessage = 'Please select a bank account for withdrawal';
        }
    } else if (method === 'upi') {
        if (!accountDetails || !accountDetails.value.trim()) {
            isValid = false;
            errorMessage = 'Please enter your UPI ID';
        } else if (!/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/.test(accountDetails.value)) {
            isValid = false;
            errorMessage = 'Please enter a valid UPI ID (e.g.: yourname@upi)';
        }
    }
    
    return { isValid, errorMessage };
}

const withdrawForm = document.getElementById("withdrawForm");
if (withdrawForm) {
    withdrawForm.addEventListener("submit", function(e) {
        console.log("=== WITHDRAWAL FORM SUBMISSION DEBUG ===");
        
        // Get all form data for debugging
        const formData = new FormData(this);
        const submitData = {};
        for (let [key, value] of formData.entries()) {
            submitData[key] = value;
        }
        
        console.log("Full form data:", submitData);
        console.log("Selected bank ID:", document.getElementById("bank_id").value);
        console.log("Selected method:", document.getElementById("withdraw_method").value);
        console.log("Amount:", document.querySelector('input[name="withdraw_amount"]').value);
        
        const validation = validateWithdrawalForm();
        
        if (!validation.isValid) {
            e.preventDefault();
            alert(validation.errorMessage);
            return false;
        }
        
        // Additional client-side validation
        const amount = parseFloat(document.querySelector('input[name="withdraw_amount"]').value);
        const userBalance = <?php echo $user_balance; ?>;
        
        if (amount > userBalance) {
            e.preventDefault();
            alert(`Insufficient balance. Your current balance is ₹${userBalance.toFixed(2)}`);
            return false;
        }
        
        // Show confirmation dialog
        const method = document.getElementById("withdraw_method").value;
        const methodName = method === 'upi' ? 'UPI' : 'Bank Transfer';
        const confirmation = confirm(`Are you sure you want to withdraw ₹${amount} via ${methodName}?\n\nThis action cannot be undone.`);
        
        if (!confirmation) {
            e.preventDefault();
            return false;
        }
        
        console.log("Form submission proceeding...");
        return true;
    });
}
// Enhanced bank selection with better validation
function setupBankSelection() {
    const bankItems = document.querySelectorAll(".bank-item");
    const bankIdInput = document.getElementById("bank_id");
    const withdrawMethod = document.getElementById("withdraw_method").value;
    
    console.log("Setting up bank selection:", {
        bankItems: bankItems.length,
        bankIdInput: bankIdInput,
        currentMethod: withdrawMethod,
        selectedBank: bankIdInput.value
    });
    
    if (bankItems.length > 0 && bankIdInput) {
        bankItems.forEach((item) => {
            item.addEventListener("click", function () {
                const bankId = this.getAttribute("data-bank-id");
                console.log("Bank item clicked - ID:", bankId);
                
                // Remove selected class from all banks
                bankItems.forEach((bank) => bank.classList.remove("selected"));
                
                // Add selected class to clicked bank
                this.classList.add("selected");
                
                // Update hidden bank_id input
                bankIdInput.value = bankId;
                
                console.log("Bank selected - ID:", bankId);
                
                // Update submit button state
                updateWithdrawButtonState();
            });
        });
        
        // Auto-select first bank if none selected and method is bank
        if (withdrawMethod === 'bank' && !document.querySelector(".bank-item.selected") && bankItems.length > 0) {
            console.log("Auto-selecting first bank");
            bankItems[0].click();
        } else if (document.querySelector(".bank-item.selected")) {
            // If already selected, ensure bank_id is set
            const selectedBank = document.querySelector(".bank-item.selected");
            const selectedBankId = selectedBank.getAttribute("data-bank-id");
            bankIdInput.value = selectedBankId;
            console.log("Bank already selected - ID:", selectedBankId);
        }
    } else {
        console.log("No banks found or bankIdInput missing");
    }
}
// Real-time amount validation for withdrawal form
function setupWithdrawalAmountValidation() {
    const amountInput = document.querySelector('input[name="withdraw_amount"]');
    
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            const value = parseFloat(this.value) || 0;
            const minAmount = 500;
            const maxAmount = 50000;
            const currentBalance = <?php echo $user_balance; ?>;
            
            // Clear previous error styling
            this.style.borderColor = '';
            hideAmountError();
            
            if (value > 0) {
                if (value > currentBalance) {
                    this.style.borderColor = 'red';
                    showAmountError(`Insufficient balance. Your current balance is ₹${currentBalance.toFixed(2)}`);
                } else if (value < minAmount) {
                    this.style.borderColor = 'red';
                    showAmountError(`Minimum withdrawal amount is ₹${minAmount}`);
                } else if (value > maxAmount) {
                    this.style.borderColor = 'red';
                    showAmountError(`Maximum withdrawal amount is ₹${maxAmount}`);
                }
            }
            
            updateWithdrawButtonState();
        });
    }
}

function showAmountError(message) {
    let errorDiv = document.getElementById('amount-error');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'amount-error';
        errorDiv.style.color = 'var(--danger)';
        errorDiv.style.fontSize = '0.8rem';
        errorDiv.style.marginTop = '5px';
        errorDiv.style.padding = '5px';
        errorDiv.style.background = 'rgba(214, 48, 49, 0.1)';
        errorDiv.style.borderRadius = '4px';
        const amountInput = document.querySelector('input[name="withdraw_amount"]');
        amountInput.parentNode.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
}

function hideAmountError() {
    const errorDiv = document.getElementById('amount-error');
    if (errorDiv) {
        errorDiv.style.display = 'none';
    }
}

// Update the initialization to include withdrawal validation
document.addEventListener("DOMContentLoaded", function () {
    // ... existing code ...
    
    // Setup withdrawal amount validation
    setupWithdrawalAmountValidation();
    
    // Update button state on page load
    updateWithdrawButtonState();
});
// Initialize the modal state on page load
document.addEventListener("DOMContentLoaded", function () {
    // Set initial state for payment method sections
    const initialMethod = document
        .querySelector(".payment-option.active")
        ?.getAttribute("data-method");
    if (initialMethod === "cash") {
        if (utrSection) utrSection.style.display = "none";
        if (paymentProofSection) paymentProofSection.style.display = "none";
        if (utrInput) utrInput.removeAttribute("required");
        if (paymentProofInput) paymentProofInput.removeAttribute("required");
    }
    
    // Setup bank selection on page load
    setupBankSelection();
    
    // Update button state on page load
    updateWithdrawButtonState();
    
    // Auto-select first bank if available and no bank is selected
    const bankItems = document.querySelectorAll(".bank-item");
    if (bankItems.length > 0 && !document.querySelector(".bank-item.selected")) {
        bankItems[0].click();
    }
    
    // Auto-open modals if there are errors or success messages
    <?php if ($show_deposit_modal): ?>
        if (depositModal) {
            depositModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    <?php endif; ?>
    
    <?php if ($show_withdraw_modal): ?>
        if (withdrawModal) {
            withdrawModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    <?php endif; ?>
});

// Additional withdrawal form enhancements
function enhanceWithdrawalForm() {
    const amountInput = document.querySelector('input[name="withdraw_amount"]');
    
    if (amountInput) {
        // Real-time amount validation
        amountInput.addEventListener('input', function() {
            const value = parseFloat(this.value) || 0;
            const minAmount = 500;
            const maxAmount = 50000;
            const currentBalance = <?php echo $user_balance; ?>;
            
            if (value > currentBalance) {
                this.style.borderColor = 'red';
                showAmountError(`Insufficient balance. Your current balance is ₹${currentBalance.toFixed(2)}`);
            } else if (value < minAmount) {
                this.style.borderColor = 'red';
                showAmountError(`Minimum withdrawal amount is ₹${minAmount}`);
            } else if (value > maxAmount) {
                this.style.borderColor = 'red';
                showAmountError(`Maximum withdrawal amount is ₹${maxAmount}`);
            } else {
                this.style.borderColor = '';
                hideAmountError();
            }
            
            updateWithdrawButtonState();
        });
    }
}

function showAmountError(message) {
    let errorDiv = document.getElementById('amount-error');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'amount-error';
        errorDiv.style.color = 'red';
        errorDiv.style.fontSize = '0.8rem';
        errorDiv.style.marginTop = '5px';
        const amountInput = document.querySelector('input[name="withdraw_amount"]');
        amountInput.parentNode.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

function hideAmountError() {
    const errorDiv = document.getElementById('amount-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

// Initialize withdrawal form enhancements
document.addEventListener('DOMContentLoaded', function() {
    enhanceWithdrawalForm();
});

// let lastScrollTop = 0;
// window.addEventListener("scroll", function() {
//   let currentScroll = document.documentElement.scrollTop || document.body.scrollTop;
  
//   if (currentScroll > lastScrollTop) {
//     $("header").css({
//         "background": "transparent"
//     });
//   } else {
//     $("header").css({
//         "background": " rgba(11, 8, 1, 0.97)"
//     });
//   }
//   lastScrollTop = currentScroll <= 0 ? 0 : currentScroll; // avoid negative values
// });

</script>