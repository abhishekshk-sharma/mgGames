<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['super_admin_id']) || !isset($_GET['admin_id'])) {
    exit('Access denied');
}

$admin_id = intval($_GET['admin_id']);
$sql = "SELECT * FROM admins WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if ($admin): ?>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Username *</label>
            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Phone *</label>
            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($admin['phone']); ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Referral Code</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['referral_code']); ?>" readonly>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($admin['address']); ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Aadhar Number</label>
            <input type="text" name="adhar" class="form-control" value="<?php echo htmlspecialchars($admin['adhar']); ?>">
        </div>
        <div class="form-group">
            <label class="form-label">PAN Number</label>
            <input type="text" name="pan" class="form-control" value="<?php echo htmlspecialchars($admin['pan']); ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">UPI ID</label>
        <input type="text" name="upiId" class="form-control" value="<?php echo htmlspecialchars($admin['upiId']); ?>">
    </div>
<?php else: ?>
    <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
        <i class="fas fa-exclamation-triangle"></i> Admin not found
    </div>
<?php endif; ?>