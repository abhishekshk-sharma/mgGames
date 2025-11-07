<?php
// get_chart_data.php
require_once '../config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set header for JSON response
header('Content-Type: application/json');

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get parameters from POST request
$period = $_POST['period'] ?? 'week';
$referral_code = $_POST['referral_code'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';

// Log received parameters for debugging
error_log("Chart Data Request - Period: $period, Referral: $referral_code, Start: $start_date, End: $end_date");

// Validate referral code
if (empty($referral_code)) {
    echo json_encode(['success' => false, 'message' => 'Invalid referral code']);
    exit;
}

// Verify admin owns this referral code
$stmt = $conn->prepare("SELECT id FROM admins WHERE referral_code = ? AND id = ?");
$stmt->execute([$referral_code, $_SESSION['admin_id']]);
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid referral code for this admin']);
    exit;
}

// Set date range based on period
if ($period === 'custom' && !empty($start_date) && !empty($end_date)) {
    // Use provided custom dates
    $date_format = 'M j';
} else {
    switch ($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $end_date = date('Y-m-d');
            $date_format = 'D';
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
            $date_format = 'M j';
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            $date_format = 'M j';
            break;
        default:
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $end_date = date('Y-m-d');
            $date_format = 'D';
    }
}

// Validate dates
if (empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}

// Generate dates array
$dates = [];
$current = strtotime($start_date);
$last = strtotime($end_date);

if ($current === false || $last === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

while ($current <= $last) {
    $dates[] = date($date_format, $current);
    $current = strtotime('+1 day', $current);
}

// Get revenue data from database
try {
    $sql = "SELECT 
            DATE(t.created_at) as date,
            SUM(CASE WHEN type = 'deposit' AND t.status = 'completed' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type = 'withdrawal' AND t.status = 'completed' THEN amount ELSE 0 END) as withdrawals,
            SUM(CASE WHEN type = 'winning' THEN amount ELSE 0 END) as winnings
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE u.referral_code = ? 
            AND DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY DATE(t.created_at)
            ORDER BY date ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $conn->error);
    }
    
    $stmt->execute([$referral_code, $start_date, $end_date]);
    $result = $stmt->get_result();

    // Initialize arrays with zeros
    $deposits = array_fill(0, count($dates), 0);
    $withdrawals = array_fill(0, count($dates), 0);
    $winnings = array_fill(0, count($dates), 0);

    while ($row = $result->fetch_assoc()) {
        $date_formatted = date($date_format, strtotime($row['date']));
        $day_index = array_search($date_formatted, $dates);
        if ($day_index !== false) {
            $deposits[$day_index] = floatval($row['deposits']);
            $withdrawals[$day_index] = floatval($row['withdrawals']);
            $winnings[$day_index] = floatval($row['winnings']);
        }
    }

    // Return successful JSON response
    echo json_encode([
        'success' => true,
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'dates' => $dates,
        'deposits' => $deposits,
        'withdrawals' => $withdrawals,
        'winnings' => $winnings
    ]);

} catch (Exception $e) {
    error_log("Chart data error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>