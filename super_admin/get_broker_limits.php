<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['super_admin_id']) || !isset($_GET['admin_id'])) {
    exit('Access denied');
}

$admin_id = intval($_GET['admin_id']);
$sql = "SELECT * FROM broker_limit WHERE admin_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$limits = $result->fetch_assoc();

// Get admin info for display
$admin_sql = "SELECT username FROM admins WHERE id = ?";
$admin_stmt = $conn->prepare($admin_sql);
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();
?>

<div class="form-group">
    <p style="margin-bottom: 1rem; color: var(--text-muted);">
        Setting broker limits for: <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
    </p>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label">Deposit Limit (₹) *</label>
        <input type="number" name="deposit_limit" class="form-control" 
               value="<?php echo $limits ? $limits['deposit_limit'] : 100000; ?>" 
               min="0" required>
    </div>
    <div class="form-group">
        <label class="form-label">Withdrawal Limit (₹) *</label>
        <input type="number" name="withdrawal_limit" class="form-control" 
               value="<?php echo $limits ? $limits['withdrawal_limit'] : 100000; ?>" 
               min="0" required>
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label class="form-label">Bet Limit (₹) *</label>
        <input type="number" name="bet_limit" class="form-control" 
               value="<?php echo $limits ? $limits['bet_limit'] : 100; ?>" 
               min="0" required>
    </div>
    <div class="form-group">
        <label class="form-label">PNL Ratio (e.g., 60:40)</label>
        <input type="text" name="pnl_ratio" class="form-control" 
               value="<?php echo $limits ? htmlspecialchars($limits['pnl_ratio']) : ''; ?>" 
               placeholder="60:40">
        <small style="color: var(--text-muted);">Format: AdminShare:ForwardShare</small>
    </div>
</div>

<div class="form-group">
    <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
        <input type="checkbox" name="auto_forward_enabled" value="1" 
               <?php echo ($limits && $limits['auto_forward_enabled']) ? 'checked' : 'checked'; ?>>
        Enable Auto Forwarding
    </label>
</div>