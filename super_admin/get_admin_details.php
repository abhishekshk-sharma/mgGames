<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['super_admin_id']) || !isset($_GET['admin_id'])) {
    exit('Access denied');
}

$admin_id = intval($_GET['admin_id']);
$sql = "SELECT a.*, 
       bl.deposit_limit, bl.withdrawal_limit, bl.bet_limit, bl.pnl_ratio, bl.auto_forward_enabled,
       (SELECT COUNT(*) FROM users u WHERE u.referral_code = a.referral_code) as user_count,
       (SELECT COUNT(*) FROM bets b JOIN users u ON b.user_id = u.id WHERE u.referral_code = a.referral_code) as total_bets,
       (SELECT SUM(t.amount) FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.referral_code = a.referral_code AND t.type = 'deposit' AND t.status = 'completed') as total_deposits
       FROM admins a
       LEFT JOIN broker_limit bl ON a.id = bl.admin_id
       WHERE a.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if ($admin): ?>
    <div class="admin-details">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $admin['user_count']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $admin['total_bets']; ?></div>
                <div class="stat-label">Total Bets</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₹<?php echo number_format($admin['total_deposits'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Deposits</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['username']); ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" readonly>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['phone']); ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Referral Code</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['referral_code']); ?>" readonly>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Address</label>
            <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($admin['address']); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Aadhar Number</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['adhar']); ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">PAN Number</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['pan']); ?>" readonly>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">UPI ID</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['upiId']); ?>" readonly>
        </div>

        <?php if ($admin['adhar_upload']): ?>
        <div class="form-group">
            <label class="form-label">Aadhar Document</label>
            <div>
                <img src="<?php echo htmlspecialchars($admin['adhar_upload']); ?>" 
                     class="document-preview" 
                     onclick="resizeImage(this, 1.5)"
                     alt="Aadhar Document">
                <div style="margin-top: 0.5rem;">
                    <button type="button" class="btn btn-sm btn-outline" 
                            onclick="downloadDocument('<?php echo htmlspecialchars($admin['adhar_upload']); ?>', 'aadhar_<?php echo $admin['id']; ?>.jpg')">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($admin['pan_upload']): ?>
        <div class="form-group">
            <label class="form-label">PAN Document</label>
            <div>
                <img src="<?php echo htmlspecialchars($admin['pan_upload']); ?>" 
                     class="document-preview" 
                     onclick="resizeImage(this, 1.5)"
                     alt="PAN Document">
                <div style="margin-top: 0.5rem;">
                    <button type="button" class="btn btn-sm btn-outline" 
                            onclick="downloadDocument('<?php echo htmlspecialchars($admin['pan_upload']); ?>', 'pan_<?php echo $admin['id']; ?>.jpg')">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($admin['deposit_limit']): ?>
        <div class="form-group">
            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                <i class="fas fa-chart-line"></i> Broker Limits
            </h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Deposit Limit</label>
                    <input type="text" class="form-control" value="₹<?php echo number_format($admin['deposit_limit']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Withdrawal Limit</label>
                    <input type="text" class="form-control" value="₹<?php echo number_format($admin['withdrawal_limit']); ?>" readonly>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bet Limit</label>
                    <input type="text" class="form-control" value="₹<?php echo number_format($admin['bet_limit']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">PNL Ratio</label>
                    <input type="text" class="form-control" value="<?php echo $admin['pnl_ratio'] ? htmlspecialchars($admin['pnl_ratio']) : 'Not Set'; ?>" readonly>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
        <i class="fas fa-exclamation-triangle"></i> Admin not found
    </div>
<?php endif; ?>