<?php
require_once '../config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is super admin
if (!isset($_SESSION['super_admin_id'])) {
    die('Access denied');
}

$user_id = intval($_GET['user_id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$limit = intval($_GET['limit'] ?? 20);
$offset = ($page - 1) * $limit;

// Get filters
$trans_type_filter = sanitize_input($conn, $_GET['trans_type'] ?? '');
$trans_status_filter = sanitize_input($conn, $_GET['trans_status'] ?? '');
$trans_date_from = sanitize_input($conn, $_GET['trans_date_from'] ?? '');
$trans_date_to = sanitize_input($conn, $_GET['trans_date_to'] ?? '');

// Build query
$trans_where = "user_id = ?";
$trans_params = [$user_id];
$trans_types = "i";

if (!empty($trans_type_filter)) {
    $trans_where .= " AND type = ?";
    $trans_params[] = $trans_type_filter;
    $trans_types .= "s";
}

if (!empty($trans_status_filter)) {
    $trans_where .= " AND status = ?";
    $trans_params[] = $trans_status_filter;
    $trans_types .= "s";
}

if (!empty($trans_date_from)) {
    $trans_where .= " AND DATE(created_at) >= ?";
    $trans_params[] = $trans_date_from;
    $trans_types .= "s";
}

if (!empty($trans_date_to)) {
    $trans_where .= " AND DATE(created_at) <= ?";
    $trans_params[] = $trans_date_to;
    $trans_types .= "s";
}

// Get total count
$trans_count_sql = "SELECT COUNT(*) as total FROM transactions WHERE $trans_where";
$stmt = $conn->prepare($trans_count_sql);
$stmt->bind_param($trans_types, ...$trans_params);
$stmt->execute();
$trans_count_result = $stmt->get_result();
$total_records = $trans_count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Get data
$trans_sql = "SELECT * FROM transactions 
             WHERE $trans_where 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?";

$trans_params[] = $limit;
$trans_params[] = $offset;
$trans_types .= "ii";

$stmt = $conn->prepare($trans_sql);
$stmt->bind_param($trans_types, ...$trans_params);
$stmt->execute();
$result = $stmt->get_result();
$transactions = $result->fetch_all(MYSQLI_ASSOC);

// Output HTML
?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Balance Before</th>
                <th>Balance After</th>
                <th>Description</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($transactions)): ?>
                <?php foreach ($transactions as $trans): ?>
                    <tr>
                        <td><?php echo date('M j, Y g:i A', strtotime($trans['created_at'])); ?></td>
                        <td>
                            <span class="status status-<?php echo $trans['type']; ?>">
                                <?php echo strtoupper($trans['type']); ?>
                            </span>
                        </td>
                        <td>₹<?php echo number_format($trans['amount'], 2); ?></td>
                        <td>₹<?php echo number_format($trans['balance_before'], 2); ?></td>
                        <td>₹<?php echo number_format($trans['balance_after'], 2); ?></td>
                        <td><?php echo htmlspecialchars($trans['description']); ?></td>
                        <td>
                            <span class="status status-<?php echo $trans['status']; ?>">
                                <?php echo strtoupper($trans['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted);">
                        No transactions found
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <div class="limit-selector" style="margin-right: auto;">
        <label class="info-label">Show:</label>
        <select onchange="changeTransactionLimit(this.value)" class="form-control" style="width: auto; padding: 0.3rem;">
            <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
            <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20</option>
            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
        </select>
    </div>

    <?php if ($page > 1): ?>
        <span class="page-link" onclick="loadTransactionData(1)">
            <i class="fas fa-angle-double-left"></i>
        </span>
        <span class="page-link" onclick="loadTransactionData(<?php echo $page - 1; ?>)">
            <i class="fas fa-angle-left"></i>
        </span>
    <?php else: ?>
        <span class="page-link disabled">
            <i class="fas fa-angle-double-left"></i>
        </span>
        <span class="page-link disabled">
            <i class="fas fa-angle-left"></i>
        </span>
    <?php endif; ?>

    <span class="page-info">
        Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
        (<?php echo $total_records; ?> records)
    </span>

    <?php if ($page < $total_pages): ?>
        <span class="page-link" onclick="loadTransactionData(<?php echo $page + 1; ?>)">
            <i class="fas fa-angle-right"></i>
        </span>
        <span class="page-link" onclick="loadTransactionData(<?php echo $total_pages; ?>)">
            <i class="fas fa-angle-double-right"></i>
        </span>
    <?php else: ?>
        <span class="page-link disabled">
            <i class="fas fa-angle-right"></i>
        </span>
        <span class="page-link disabled">
            <i class="fas fa-angle-double-right"></i>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>