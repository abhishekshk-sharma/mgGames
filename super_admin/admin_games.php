
<?php
// admin_games.php
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

// Get admin details
$super_admin_id = $_SESSION['super_admin_id'];
$super_admin_username = $_SESSION['super_admin_username'];

// Handle form submissions
$message = '';
$message_type = '';

// Add new game
if (isset($_POST['add_game'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $code = $conn->real_escape_string($_POST['code']);
    $description = $conn->real_escape_string($_POST['description']);
    $open_time = $_POST['open_time'];
    $close_time = $_POST['close_time'];
    $result_time = $_POST['result_time'];
    $game_mode = $_POST['game_mode'];
    $min_bet = $_POST['min_bet'];
    $max_bet = $_POST['max_bet'];
    $status = $_POST['status'];
    
    // Handle image upload
    $dynamic_images = '';
    if (isset($_FILES['dynamic_images']) && $_FILES['dynamic_images']['error'] == 0) {
        $uploadDir = '../uploads/imgs/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = time() . '_' . basename($_FILES['dynamic_images']['name']);
        $targetFilePath = $uploadDir . $fileName;
        
        // Check if image file is an actual image
        $imageFileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
        $check = getimagesize($_FILES['dynamic_images']['tmp_name']);
        
        if ($check !== false) {
            // Allow certain file formats
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($imageFileType, $allowedTypes)) {
                // Check file size (5MB maximum)
                if ($_FILES['dynamic_images']['size'] <= 5000000) {
                    if (move_uploaded_file($_FILES['dynamic_images']['tmp_name'], $targetFilePath)) {
                        $dynamic_images = 'uploads/imgs/' . $fileName;
                    } else {
                        $message = "Sorry, there was an error uploading your file.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Sorry, your file is too large. Maximum size is 5MB.";
                    $message_type = "error";
                }
            } else {
                $message = "Sorry, only JPG, JPEG, PNG, GIF & WEBP files are allowed.";
                $message_type = "error";
            }
        } else {
            $message = "File is not an image.";
            $message_type = "error";
        }
    }
    
    // If there was an error with image upload, stop further processing
    if ($message_type === 'error') {
        // Show error message and continue
    } else {
        // Check if game code already exists
        $check_sql = "SELECT id FROM games WHERE code = '$code'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result->num_rows > 0) {
            $message = "Game code already exists!";
            $message_type = "error";
        } else {
            // Prepare SQL with or without image
            if (!empty($dynamic_images)) {
                $sql = "INSERT INTO games (name, code, description, open_time, close_time, result_time, game_mode, min_bet, max_bet, status, dynamic_images) 
                        VALUES ('$name', '$code', '$description', '$open_time', '$close_time', '$result_time', '$game_mode', '$min_bet', '$max_bet', '$status', '$dynamic_images')";
            } else {
                $sql = "INSERT INTO games (name, code, description, open_time, close_time, result_time, game_mode, min_bet, max_bet, status) 
                        VALUES ('$name', '$code', '$description', '$open_time', '$close_time', '$result_time', '$game_mode', '$min_bet', '$max_bet', '$status')";
            }
            
            if ($conn->query($sql) === TRUE) {
                $message = "Game added successfully!";
                $message_type = "success";
            } else {
                $message = "Error adding game: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Update game
if (isset($_POST['update_game'])) {
    $game_id = $_POST['game_id'];
    $name = $conn->real_escape_string($_POST['name']);
    $description = $conn->real_escape_string($_POST['description']);
    $open_time = $_POST['open_time'];
    $close_time = $_POST['close_time'];
    $result_time = $_POST['result_time'];
    $game_mode = $_POST['game_mode'];
    $min_bet = $_POST['min_bet'];
    $max_bet = $_POST['max_bet'];
    $status = $_POST['status'];
    
    // Handle image upload
    $dynamic_images = '';
    if (isset($_FILES['dynamic_images']) && $_FILES['dynamic_images']['error'] == 0) {
        $uploadDir = '../uploads/imgs/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = time() . '_' . basename($_FILES['dynamic_images']['name']);
        $targetFilePath = $uploadDir . $fileName;
        
        // Check if image file is an actual image
        $imageFileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
        $check = getimagesize($_FILES['dynamic_images']['tmp_name']);
        
        if ($check !== false) {
            // Allow certain file formats
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($imageFileType, $allowedTypes)) {
                // Check file size (5MB maximum)
                if ($_FILES['dynamic_images']['size'] <= 5000000) {
                    if (move_uploaded_file($_FILES['dynamic_images']['tmp_name'], $targetFilePath)) {
                        $dynamic_images = 'uploads/imgs/' . $fileName;
                        
                        // Delete old image if exists
                        $old_image_sql = "SELECT dynamic_images FROM games WHERE id = $game_id";
                        $old_image_result = $conn->query($old_image_sql);
                        if ($old_image_result && $old_image_result->num_rows > 0) {
                            $old_game = $old_image_result->fetch_assoc();
                            if (!empty($old_game['dynamic_images']) && file_exists('../' . $old_game['dynamic_images'])) {
                                unlink('../' . $old_game['dynamic_images']);
                            }
                        }
                    } else {
                        $message = "Sorry, there was an error uploading your file.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Sorry, your file is too large. Maximum size is 5MB.";
                    $message_type = "error";
                }
            } else {
                $message = "Sorry, only JPG, JPEG, PNG, GIF & WEBP files are allowed.";
                $message_type = "error";
            }
        } else {
            $message = "File is not an image.";
            $message_type = "error";
        }
    }
    
    // If there was an error with image upload, stop further processing
    if ($message_type === 'error') {
        // Show error message and continue
    } else {
        // Prepare SQL with or without image update
        if (!empty($dynamic_images)) {
            $sql = "UPDATE games SET 
                    name = '$name', 
                    description = '$description', 
                    open_time = '$open_time', 
                    close_time = '$close_time', 
                    result_time = '$result_time', 
                    game_mode = '$game_mode', 
                    min_bet = '$min_bet', 
                    max_bet = '$max_bet', 
                    status = '$status',
                    dynamic_images = '$dynamic_images' 
                    WHERE id = $game_id";
        } else {
            $sql = "UPDATE games SET 
                    name = '$name', 
                    description = '$description', 
                    open_time = '$open_time', 
                    close_time = '$close_time', 
                    result_time = '$result_time', 
                    game_mode = '$game_mode', 
                    min_bet = '$min_bet', 
                    max_bet = '$max_bet', 
                    status = '$status' 
                    WHERE id = $game_id";
        }
        
        if ($conn->query($sql) === TRUE) {
            $message = "Game updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating game: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Delete game
if (isset($_GET['delete'])) {
    $game_id = $_GET['delete'];
    
    // Check if there are any active sessions for this game
    $check_sql = "SELECT id FROM game_sessions WHERE game_id = $game_id AND status != 'completed'";
    $check_result = $conn->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        $message = "Cannot delete game with active sessions!";
        $message_type = "error";
    } else {
        // Get game image path before deleting
        $image_sql = "SELECT dynamic_images FROM games WHERE id = $game_id";
        $image_result = $conn->query($image_sql);
        if ($image_result && $image_result->num_rows > 0) {
            $game = $image_result->fetch_assoc();
            // Delete image file if exists
            if (!empty($game['dynamic_images']) && file_exists('../' . $game['dynamic_images'])) {
                unlink('../' . $game['dynamic_images']);
            }
        }
        
        $sql = "DELETE FROM games WHERE id = $game_id";
        if ($conn->query($sql) === TRUE) {
            $message = "Game deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting game: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Get games list
$games = [];
$sql = "SELECT * FROM games ORDER BY open_time, name";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $games[] = $row;
    }
}

// Get game types for reference
$game_types = [];
$sql_types = "SELECT * FROM game_types WHERE status = 'active'";
$result_types = $conn->query($sql_types);
if ($result_types && $result_types->num_rows > 0) {
    while ($row = $result_types->fetch_assoc()) {
        $game_types[] = $row;
    }
}

// Get game for editing
$edit_game = null;
if (isset($_GET['edit'])) {
    $game_id = $_GET['edit'];
    $sql = "SELECT * FROM games WHERE id = $game_id";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $edit_game = $result->fetch_assoc();
    }
}

include 'includes/header.php';
?>



        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="welcome">
                    <h1>Games Management</h1>
                    <p>Create and manage matka games</p>
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

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?php echo !isset($_GET['edit']) ? 'active' : ''; ?>" data-tab="games-list">Games List</button>
                <button class="tab <?php echo isset($_GET['edit']) ? 'active' : ''; ?>" data-tab="game-form">
                    <?php echo isset($_GET['edit']) ? 'Edit Game' : 'Add New Game'; ?>
                </button>
            </div>

            <!-- Games List Tab -->
            <div class="tab-content <?php echo !isset($_GET['edit']) ? 'active' : ''; ?>" id="games-list">
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-list"></i> All Games</h2>
                        <a href="admin_games.php" class="view-all">Refresh</a>
                    </div>
                    
                    <?php if (!empty($games)): ?>
                        <!-- Desktop Table View -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Code</th>
                                        <th>Open Time</th>
                                        <th>Close Time</th>
                                        <th>Result Time</th>
                                        <th>Min Bet</th>
                                        <th>Max Bet</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                        <tr>
                                            <td><?php echo $game['id']; ?></td>
                                            <td><?php echo $game['name']; ?></td>
                                            <td><?php echo $game['code']; ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['open_time'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['close_time'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($game['result_time'])); ?></td>
                                            <td>$<?php echo number_format($game['min_bet'], 2); ?></td>
                                            <td>$<?php echo number_format($game['max_bet'], 2); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $game['status']; ?>">
                                                    <?php echo ucfirst($game['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="admin_games.php?edit=<?php echo $game['id']; ?>" class="btn btn-secondary btn-sm">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <a href="admin_games.php?delete=<?php echo $game['id']; ?>" class="btn btn-danger btn-sm" 
                                                       onclick="return confirm('Are you sure you want to delete this game?')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile Card View -->
                        <div class="games-cards">
                            <?php foreach ($games as $game): ?>
                                <div class="game-card">
                                    <div class="game-row">
                                        <span class="game-label">ID:</span>
                                        <span class="game-value"><?php echo $game['id']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Name:</span>
                                        <span class="game-value"><?php echo $game['name']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Code:</span>
                                        <span class="game-value"><?php echo $game['code']; ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Open Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['open_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Close Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['close_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Result Time:</span>
                                        <span class="game-value"><?php echo date('h:i A', strtotime($game['result_time'])); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Min Bet:</span>
                                        <span class="game-value">$<?php echo number_format($game['min_bet'], 2); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Max Bet:</span>
                                        <span class="game-value">$<?php echo number_format($game['max_bet'], 2); ?></span>
                                    </div>
                                    <div class="game-row">
                                        <span class="game-label">Status:</span>
                                        <span class="game-value">
                                            <span class="status status-<?php echo $game['status']; ?>">
                                                <?php echo ucfirst($game['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="game-actions">
                                        <a href="admin_games.php?edit=<?php echo $game['id']; ?>" class="btn btn-secondary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="admin_games.php?delete=<?php echo $game['id']; ?>" class="btn btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this game?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-3">
                            <p>No games found. <a href="admin_games.php" class="view-all">Add your first game</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Game Form Tab -->
            <div class="tab-content <?php echo isset($_GET['edit']) ? 'active' : ''; ?>" id="game-form">
                <div class="dashboard-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas <?php echo isset($_GET['edit']) ? 'fa-edit' : 'fa-plus'; ?>"></i>
                            <?php echo isset($_GET['edit']) ? 'Edit Game' : 'Add New Game'; ?>
                        </h2>
                        <a href="admin_games.php" class="view-all">Back to List</a>
                    </div>
                    
                    <form method="POST" id="gameForm" enctype="multipart/form-data">
                        <?php if (isset($_GET['edit'])): ?>
                            <input type="hidden" name="game_id" value="<?php echo $edit_game['id']; ?>">
                        <?php endif; ?>
                        
                        <!-- Image Upload Section -->
                        <div class="image-upload-container">
                            <div class="image-note">
                                <i class="fas fa-exclamation-circle"></i>
                                <strong>Please make sure the image should be in landscape for better resolution</strong>
                            </div>
                            
                            <?php if (isset($edit_game) && !empty($edit_game['dynamic_images'])): ?>
                                <div class="current-image">
                                    <label class="form-label">Current Image:</label>
                                    <div>
                                        <img src="../<?php echo $edit_game['dynamic_images']; ?>" alt="<?php echo $edit_game['name']; ?>" 
                                             onerror="this.style.display='none'">
                                    </div>
                                    <div class="form-text">Current game image</div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label class="form-label" for="dynamic_images">Game Image</label>
                                <input type="file" class="form-control" id="dynamic_images" name="dynamic_images" 
                                       accept="image/*" onchange="previewImage(this)">
                                <div class="form-text">Upload a landscape image for the game (JPG, PNG, GIF, WEBP - Max 5MB)</div>
                            </div>
                            
                            <img id="imagePreview" class="image-preview" src="#" alt="Image preview">
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="name">Game Name *</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo isset($edit_game) ? $edit_game['name'] : ''; ?>" 
                                       required>
                                <div class="form-text">Display name for the game</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="code">Game Code *</label>
                                <input type="text" class="form-control" id="code" name="code" 
                                       value="<?php echo isset($edit_game) ? $edit_game['code'] : ''; ?>" 
                                       <?php echo isset($edit_game) ? 'readonly' : 'required'; ?>>
                                <div class="form-text">Unique code (e.g., KALYAN, MUMBAI)</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($edit_game) ? $edit_game['description'] : ''; ?></textarea>
                            <div class="form-text">Brief description of the game</div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="open_time">Open Time *</label>
                                <input type="time" class="form-control" id="open_time" name="open_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['open_time'] : '09:30'; ?>" 
                                       required>
                                <div class="form-text">When betting opens</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="close_time">Close Time *</label>
                                <input type="time" class="form-control" id="close_time" name="close_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['close_time'] : '11:30'; ?>" 
                                       required>
                                <div class="form-text">When betting closes</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="result_time">Result Time *</label>
                                <input type="time" class="form-control" id="result_time" name="result_time" 
                                       value="<?php echo isset($edit_game) ? $edit_game['result_time'] : '12:00'; ?>" 
                                       required>
                                <div class="form-text">When results are declared</div>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="game_mode">Game Mode *</label>
                                <select class="form-control" id="game_mode" name="game_mode" required>
                                    <option value="open" <?php echo (isset($edit_game) && $edit_game['game_mode'] == 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="close" <?php echo (isset($edit_game) && $edit_game['game_mode'] == 'close') ? 'selected' : ''; ?>>Close</option>
                                </select>
                                <div class="form-text">Betting mode for the game</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="min_bet">Minimum Bet *</label>
                                <input type="number" class="form-control" id="min_bet" name="min_bet" 
                                       value="<?php echo isset($edit_game) ? $edit_game['min_bet'] : '5.00'; ?>" 
                                       step="0.01" min="0" required>
                                <div class="form-text">Minimum bet amount</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="max_bet">Maximum Bet *</label>
                                <input type="number" class="form-control" id="max_bet" name="max_bet" 
                                       value="<?php echo isset($edit_game) ? $edit_game['max_bet'] : '10000.00'; ?>" 
                                       step="0.01" min="0" required>
                                <div class="form-text">Maximum bet amount</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-control" id="status" name="status" required>
                                <option value="active" <?php echo (isset($edit_game) && $edit_game['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($edit_game) && $edit_game['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo (isset($edit_game) && $edit_game['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                            <div class="form-text">Game availability status</div>
                        </div>
                        
                        <div class="form-group mt-3">
                            <?php if (isset($_GET['edit'])): ?>
                                <button type="submit" name="update_game" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Game
                                </button>
                                <a href="admin_games.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            <?php else: ?>
                                <button type="submit" name="add_game" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Add Game
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 576 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        // Image preview functionality
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
                preview.src = '#';
            }
        }
        
        // Form validation
        document.getElementById('gameForm')?.addEventListener('submit', function(e) {
            const openTime = document.getElementById('open_time').value;
            const closeTime = document.getElementById('close_time').value;
            const resultTime = document.getElementById('result_time').value;
            
            if (openTime >= closeTime) {
                alert('Open time must be before close time!');
                e.preventDefault();
                return;
            }
            
            if (closeTime >= resultTime) {
                alert('Close time must be before result time!');
                e.preventDefault();
                return;
            }
            
            const minBet = parseFloat(document.getElementById('min_bet').value);
            const maxBet = parseFloat(document.getElementById('max_bet').value);
            
            if (minBet >= maxBet) {
                alert('Minimum bet must be less than maximum bet!');
                e.preventDefault();
                return;
            }
            
            // Image file validation
            const imageInput = document.getElementById('dynamic_images');
            if (imageInput.files.length > 0) {
                const file = imageInput.files[0];
                const fileSize = file.size / 1024 / 1024; // in MB
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, JPEG, PNG, GIF, or WEBP).');
                    e.preventDefault();
                    return;
                }
                
                if (fileSize > 5) {
                    alert('Image size must be less than 5MB.');
                    e.preventDefault();
                    return;
                }
            }
        });
        
        // Update time every minute
        function updateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeElement = document.querySelector('.current-time span');
            if (timeElement) {
                timeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        
        // Initial call
        updateTime();
        
        // Update every minute
        setInterval(updateTime, 60000);
    </script>
</body>
</html>
