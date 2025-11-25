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
$betting_game_filter = sanitize_input($conn, $_GET['betting_game'] ?? '');
$betting_status_filter = sanitize_input($conn, $_GET['betting_status'] ?? '');
$betting_date_from = sanitize_input($conn, $_GET['betting_date_from'] ?? '');
$betting_date_to = sanitize_input($conn, $_GET['betting_date_to'] ?? '');

// Build query
$betting_where = "b.user_id = ?";
$betting_params = [$user_id];
$betting_types = "i";

if (!empty($betting_game_filter)) {
    $betting_where .= " AND b.game_name LIKE ?";
    $betting_params[] = "%$betting_game_filter%";
    $betting_types .= "s";
}

if (!empty($betting_status_filter)) {
    $betting_where .= " AND b.status = ?";
    $betting_params[] = $betting_status_filter;
    $betting_types .= "s";
}

if (!empty($betting_date_from)) {
    $betting_where .= " AND DATE(b.placed_at) >= ?";
    $betting_params[] = $betting_date_from;
    $betting_types .= "s";
}

if (!empty($betting_date_to)) {
    $betting_where .= " AND DATE(b.placed_at) <= ?";
    $betting_params[] = $betting_date_to;
    $betting_types .= "s";
}

// Get total count
$bets_count_sql = "SELECT COUNT(*) as total FROM bets b WHERE $betting_where";
$stmt = $conn->prepare($bets_count_sql);
$stmt->bind_param($betting_types, ...$betting_params);
$stmt->execute();
$bets_count_result = $stmt->get_result();
$total_records = $bets_count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Get data
$bets_sql = "SELECT b.*, gt.name as game_type_name 
            FROM bets b 
            LEFT JOIN game_types gt ON b.game_type_id = gt.id 
            WHERE $betting_where 
            ORDER BY b.placed_at DESC 
            LIMIT ? OFFSET ?";

$betting_params[] = $limit;
$betting_params[] = $offset;
$betting_types .= "ii";

$stmt = $conn->prepare($bets_sql);
$stmt->bind_param($betting_types, ...$betting_params);
$stmt->execute();
$result = $stmt->get_result();
$bets = $result->fetch_all(MYSQLI_ASSOC);

// Output HTML
?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Game</th>
                <th>Type</th>
                <th>Numbers</th>
                <th>Amount</th>
                <th>Potential Win</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($bets)): ?>
                <?php foreach ($bets as $bet): ?>
                    <tr>
                        <td><?php echo date('M j, Y g:i A', strtotime($bet['placed_at'])); ?></td>
                        <td><?php echo htmlspecialchars($bet['game_name']); ?></td>
                        <td><?php echo str_replace('_', ' ', strtoupper($bet['game_type'])); ?></td>
                        <td>
                            <?php 
                            $numbers = json_decode($bet['numbers_played'], true);
                            echo $numbers ? implode(', ', array_keys($numbers)) : '-';
                            ?>
                        </td>
                        <td>₹<?php echo number_format($bet['amount'], 2); ?></td>
                        <td>₹<?php echo number_format($bet['potential_win'], 2); ?></td>
                        <td>
                            <span class="status status-<?php echo $bet['status']; ?>">
                                <?php echo strtoupper($bet['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted);">
                        No betting history found
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
        <select onchange="changeBettingLimit(this.value)" class="form-control" style="width: auto; padding: 0.3rem;">
            <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
            <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20</option>
            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
        </select>
    </div>

    <?php if ($page > 1): ?>
        <span class="page-link" onclick="loadBettingData(1)">
            <i class="fas fa-angle-double-left"></i>
        </span>
        <span class="page-link" onclick="loadBettingData(<?php echo $page - 1; ?>)">
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
        <span class="page-link" onclick="loadBettingData(<?php echo $page + 1; ?>)">
            <i class="fas fa-angle-right"></i>
        </span>
        <span class="page-link" onclick="loadBettingData(<?php echo $total_pages; ?>)">
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