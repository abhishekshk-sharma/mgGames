<?php
// super_admin_profile.php
require_once '../config.php';

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as super admin
if (!isset($_SESSION['super_admin_id'])) {
    header("location: super_admin_login.php");
    exit;
}

// Get super admin details
$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

// Get super admin data
$stmt = $conn->prepare("SELECT * FROM super_admin WHERE id = ?");
$stmt->execute([$super_admin_id]);
$super_admin_data = $stmt->get_result()->fetch_assoc();

// Handle form submissions
$message = '';
$message_type = ''; // success or error

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_field'])) {
        $field = $_POST['field'];
        $value = trim($_POST['value']);
        
        // List of allowed fields that can be updated
        $allowed_fields = ['username', 'email_id', 'phone_number'];
        
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
                        // Check if username already exists (excluding current super admin)
                        $check_stmt = $conn->prepare("SELECT id FROM super_admin WHERE username = ? AND id != ?");
                        $check_stmt->execute([$value, $super_admin_id]);
                        if ($check_stmt->get_result()->num_rows > 0) {
                            $message = "Username already exists. Please choose a different one.";
                            $valid = false;
                        }
                    }
                    break;
                    
                case 'email_id':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $message = "Please enter a valid email address";
                        $valid = false;
                    } else {
                        // Check if email already exists (excluding current super admin)
                        $check_stmt = $conn->prepare("SELECT id FROM super_admin WHERE email_id = ? AND id != ?");
                        $check_stmt->execute([$value, $super_admin_id]);
                        if ($check_stmt->get_result()->num_rows > 0) {
                            $message = "Email already exists. Please choose a different one.";
                            $valid = false;
                        }
                    }
                    break;
                    
                case 'phone_number':
                    if (!preg_match('/^\d{10}$/', $value)) {
                        $message = "Please enter a valid 10-digit phone number";
                        $valid = false;
                    } else {
                        // Check if phone number already exists (excluding current super admin)
                        $check_stmt = $conn->prepare("SELECT id FROM super_admin WHERE phone_number = ? AND id != ?");
                        $check_stmt->execute([$value, $super_admin_id]);
                        if ($check_stmt->get_result()->num_rows > 0) {
                            $message = "Phone number already exists. Please choose a different one.";
                            $valid = false;
                        }
                    }
                    break;
            }
            
            if ($valid) {
                // Update the field
                $update_stmt = $conn->prepare("UPDATE super_admin SET $field = ? WHERE id = ?");
                if ($update_stmt->execute([$value, $super_admin_id])) {
                    // Update session if username changed
                    if ($field === 'username') {
                        $_SESSION['super_admin_username'] = $value;
                    }
                    
                    // Refresh super admin data
                    $stmt = $conn->prepare("SELECT * FROM super_admin WHERE id = ?");
                    $stmt->execute([$super_admin_id]);
                    $super_admin_data = $stmt->get_result()->fetch_assoc();
                    
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
        // Update password
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate current password
        if (!password_verify($current_password, $super_admin_data['hash_password'])) {
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
            $update_stmt = $conn->prepare("UPDATE super_admin SET hash_password = ? WHERE id = ?");
            
            if ($update_stmt->execute([$hashed_password, $super_admin_id])) {
                $message = "Password updated successfully";
                $message_type = 'success';
            } else {
                $message = "Error updating password";
                $message_type = 'error';
            }
        }
    }
}

$title = "Super Admin Profile - RB Games";

include 'includes/header.php';
?>



        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <div class="header">
                <div class="welcome">
                    <h1>Super Admin Profile</h1>
                    <p>Manage your account details and security settings</p>
                </div>
                <div class="header-actions">
                    <div class="current-time">
                        <i class="fas fa-clock"></i>
                        <span id="currentTime"><?php echo date('F j, Y g:i A'); ?></span>
                    </div>
                    
                    <div class="admin-badge">
                        <i class="fas fa-user-shield"></i>
                        <span class="admin-name">Super Admin: <?php echo htmlspecialchars($super_admin_username); ?></span>
                    </div>
                    
                    <a href="super_admin_logout.php" class="logout-btn">
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

                <!-- Super Admin Information Section -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-user-shield"></i> Super Admin Information</h2>
                    </div>
                    
                    <div class="profile-grid">
                        <!-- Editable Fields -->
                        <div class="info-group">
                            <span class="info-label">
                                Username
                                <i class="fas fa-edit edit-icon" data-field="username" data-value="<?php echo htmlspecialchars($super_admin_data['username']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($super_admin_data['username']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                Email Address
                                <i class="fas fa-edit edit-icon" data-field="email_id" data-value="<?php echo htmlspecialchars($super_admin_data['email_id']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($super_admin_data['email_id']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">
                                Phone Number
                                <i class="fas fa-edit edit-icon" data-field="phone_number" data-value="<?php echo htmlspecialchars($super_admin_data['phone_number']); ?>"></i>
                            </span>
                            <div class="info-value"><?php echo htmlspecialchars($super_admin_data['phone_number']); ?></div>
                        </div>
                        
                        <!-- Non-editable Fields -->
                        <div class="info-group">
                            <span class="info-label">Super Admin ID</span>
                            <div class="info-value uneditable-field"><?php echo htmlspecialchars($super_admin_data['sp_adm_id']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label " id="referral-span">Referral Code &nbsp; </span>
                            <i class="fa-solid fa-copy" id="copyBtn"></i>
                            <div class="info-value"> 
                                <span id="referral-code">
                                    <?php echo htmlspecialchars($super_admin_data['referral_code']); ?>
                                </span> 
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <span class="info-label">Member Since</span>
                            <div class="info-value uneditable-field"><?php echo date('F j, Y, g:i A', strtotime($super_admin_data['created_at'])); ?></div>
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
                    case 'email_id':
                        input.type = 'email';
                        hint.textContent = 'Enter a valid email address';
                        break;
                    case 'phone_number':
                        input.type = 'tel';
                        input.pattern = '[0-9]{10}';
                        hint.textContent = 'Enter a 10-digit phone number';
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
                'phone_number': 'Phone Number',
                'email_id': 'Email Address'
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