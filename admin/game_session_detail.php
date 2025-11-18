<?php
// game_session_detail.php
require_once '../config.php';

// Set timezone to match your local time
date_default_timezone_set('Asia/Kolkata'); // Change to your timezone

// Start session at the very top
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header("location: login.php");
    exit;
}

// Get admin details
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];

$stmt = $conn->prepare("SELECT referral_code FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$referral_code_result = $stmt->get_result();
$referral_code_data = $referral_code_result->fetch_assoc();
$referral_code = $referral_code_data['referral_code'];

// Get admin bet limits and PNL ratio
$limit_stmt = $conn->prepare("SELECT bet_limit, pnl_ratio FROM broker_limit WHERE admin_id = ?");
$limit_stmt->execute([$admin_id]);
$limit_result = $limit_stmt->get_result();
$admin_limits = $limit_result->fetch_assoc();

$bet_limit = $admin_limits['bet_limit'] ?? 100;
$pnl_ratio = $admin_limits['pnl_ratio'];

// Parse PNL ratio if set
$admin_ratio = 0;
$forward_ratio = 0;
if ($pnl_ratio && strpos($pnl_ratio, ':') !== false) {
    $ratio_parts = explode(':', $pnl_ratio);
    $admin_ratio = intval($ratio_parts[0]);
    $forward_ratio = intval($ratio_parts[1]);
}

// Get session parameters
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if (!$session_id || !$game_id || !$date) {
    header("location: todays_active_games.php");
    exit;
}

// Get session details with game close time
$session_sql = "SELECT gs.*, g.name as game_name, g.open_time, g.close_time, g.result_time
                FROM game_sessions gs
                JOIN games g ON gs.game_id = g.id
                WHERE gs.id = ?";
$stmt_session = $conn->prepare($session_sql);
$stmt_session->bind_param('i', $session_id);
$stmt_session->execute();
$session_result = $stmt_session->get_result();
$session = $session_result->fetch_assoc();

if (!$session) {
    header("location: todays_active_games.php");
    exit;
}

// Calculate time status
$current_time = time();
$open_time_str = $session['open_time'];
$close_time_str = $session['close_time'];
$open_timestamp = strtotime($date . ' ' . $open_time_str);
$close_timestamp = strtotime($date . ' ' . $close_time_str);

$is_before_open = ($current_time < $open_timestamp);
$is_after_open = ($current_time >= $open_timestamp);
$is_after_close = ($current_time > $close_timestamp);

// Handle bulk forwarding actions with time restrictions
if (isset($_POST['action'])) {
    $number_pattern = trim($_POST['number_pattern']);
    $bet_mode = $_POST['bet_mode'] ?? '';
    
    // Check if action is allowed based on time and bet mode
    $action_allowed = false;
    
    if ($_POST['action'] == 'forward_all_numbers') {
        $reason = $_POST['reason'] ?? 'Bulk forward decision';
        
        // For OPEN bets: only allowed before opening time
        if ($bet_mode === 'open' && $is_before_open) {
            $action_allowed = true;
        }
        // For CLOSE bets: only allowed between opening and closing time
        elseif ($bet_mode === 'close' && $is_after_open && !$is_after_close) {
            $action_allowed = true;
        }
        
        if ($action_allowed) {
            // Calculate total amount for this number pattern and bet mode ONLY
            $amount_sql = "SELECT SUM(b.amount) as total_amount, b.numbers_played
                          FROM bets b 
                          JOIN users u ON b.user_id = u.id 
                          WHERE b.game_session_id = ? 
                          AND u.referral_code = ?
                          AND b.bet_mode = ?  
                          AND b.numbers_played LIKE ?";
            $stmt_amount = $conn->prepare($amount_sql);
            $search_pattern = '%"' . $number_pattern . '"%';
            $stmt_amount->bind_param('isss', $session_id, $referral_code, $bet_mode, $search_pattern); // Changed to 4 parameters
            $stmt_amount->execute();
            $amount_result = $stmt_amount->get_result();
            $bets_data = [];
            $total_amount = 0;
            
            while ($row = $amount_result->fetch_assoc()) {
                $bets_data[] = $row;
                $total_amount += $row['total_amount'];
            }
            
            if ($total_amount > 0) {
                // Calculate the forwarded amount based on current configuration
                $forwarded_amount = 0;
                $applicable_limit = (string)$bet_limit;

                foreach ($bets_data as $bet_data) {
                    $numbers = json_decode($bet_data['numbers_played'], true);
                    if (is_array($numbers)) {
                        if (isset($numbers[$number_pattern])) {
                            $pattern_amount = $numbers[$number_pattern];
                            // Forward bet_limit amount
                            $forwarded_amount += min($pattern_amount, $bet_limit);
                        } elseif (isset($numbers['selected_digits']) && isset($numbers['amount_per_pana'])) {
                            // For SP Motor
                            $amount_per_pana = $numbers['amount_per_pana'];
                            if (isset($numbers['pana_combinations']) && in_array($number_pattern, $numbers['pana_combinations'])) {
                                // Forward bet_limit amount
                                $forwarded_amount += min($amount_per_pana, $bet_limit);
                            }
                        }
                    }
                }
                
                if ($forwarded_amount > 0) {
                    // Insert forwarding decision with bet mode and applicable limit
                    $insert_stmt = $conn->prepare("INSERT INTO number_forwarding_decisions (admin_id, game_session_id, number_pattern, bet_mode, decision, amount, applicable_limit, decision_reason) VALUES (?, ?, ?, ?, 'forwarded', ?, ?, ?)");
                    $insert_stmt->bind_param('iisssss', $admin_id, $session_id, $number_pattern, $bet_mode, $forwarded_amount, $applicable_limit, $reason);
                    $insert_stmt->execute();
                }
            }
        }
        
    }
    elseif ($_POST['action'] == 'accept_all_numbers') {
        $reason = $_POST['reason'] ?? 'Bulk accept decision';
        
        // For OPEN bets: only allowed before opening time
        if ($bet_mode === 'open' && $is_before_open) {
            $action_allowed = true;
        }
        // For CLOSE bets: only allowed between opening and closing time
        elseif ($bet_mode === 'close' && $is_after_open && !$is_after_close) {
            $action_allowed = true;
        }
        
        if ($action_allowed) {
            // Get the last forwarded decision for this pattern and bet mode
            $forwarded_sql = "SELECT amount, applicable_limit FROM number_forwarding_decisions 
                             WHERE admin_id = ? AND game_session_id = ? 
                             AND number_pattern = ? AND bet_mode = ? AND decision = 'forwarded' 
                             ORDER BY created_at DESC LIMIT 1";
            $stmt_forwarded = $conn->prepare($forwarded_sql);
            $stmt_forwarded->bind_param('iiss', $admin_id, $session_id, $number_pattern, $bet_mode);
            $stmt_forwarded->execute();
            $forwarded_result = $stmt_forwarded->get_result();
            $forwarded_data = $forwarded_result->fetch_assoc();
            
            if ($forwarded_data) {
                $forwarded_amount = $forwarded_data['amount'];
                $applicable_limit = $forwarded_data['applicable_limit'];
                
                // Insert acceptance decision with the same applicable limit
                $insert_stmt = $conn->prepare("INSERT INTO number_forwarding_decisions (admin_id, game_session_id, number_pattern, bet_mode, decision, amount, applicable_limit, decision_reason) VALUES (?, ?, ?, ?, 'accepted', ?, ?, ?)");
                $insert_stmt->bind_param('iisssss', $admin_id, $session_id, $number_pattern, $bet_mode, $forwarded_amount, $applicable_limit, $reason);
                $insert_stmt->execute();
            }
        }
    }
    
    header("Location: game_session_detail.php?" . $_SERVER['QUERY_STRING'] . "&number_search=" . urlencode($number_pattern));
    exit;
}

// Auto-finalize decisions when time restrictions hit
if ($is_after_open || $is_after_close) {
    $finalize_check_sql = "SELECT COUNT(*) as pending_count 
                          FROM number_forwarding_decisions 
                          WHERE game_session_id = ? AND finalized_at IS NULL";
    $stmt_finalize = $conn->prepare($finalize_check_sql);
    $stmt_finalize->bind_param('i', $session_id);
    $stmt_finalize->execute();
    $finalize_result = $stmt_finalize->get_result();
    $pending_count = $finalize_result->fetch_assoc()['pending_count'];
    
    if ($pending_count > 0) {
        $finalize_sql = "UPDATE number_forwarding_decisions 
                        SET finalized_at = NOW() 
                        WHERE game_session_id = ? AND finalized_at IS NULL";
        $stmt_finalize_update = $conn->prepare($finalize_sql);
        $stmt_finalize_update->bind_param('i', $session_id);
        $stmt_finalize_update->execute();
    }
}

// Get filter parameters
$filter_game_type = isset($_GET['game_type']) ? $_GET['game_type'] : '';
$filter_bet_mode = isset($_GET['bet_mode']) ? $_GET['bet_mode'] : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$number_search = isset($_GET['number_search']) ? trim($_GET['number_search']) : '';

// Pagination parameters
$records_per_page = isset($_GET['records']) ? (int)$_GET['records'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build SQL for bets
$bets_sql = "SELECT b.*, u.username, u.email, u.phone
             FROM bets b
             JOIN users u ON b.user_id = u.id
             WHERE b.game_session_id = ?
             AND u.referral_code = ?";

$count_sql = "SELECT COUNT(*) as total
              FROM bets b
              JOIN users u ON b.user_id = u.id
              WHERE b.game_session_id = ?
              AND u.referral_code = ?";

$params = [$session_id, $referral_code];
$param_types = 'is';

// Apply filters
if ($filter_game_type) {
    $bets_sql .= " AND b.game_type = ?";
    $count_sql .= " AND b.game_type = ?";
    $params[] = $filter_game_type;
    $param_types .= 's';
}

if ($filter_bet_mode) {
    $bets_sql .= " AND b.bet_mode = ?";
    $count_sql .= " AND b.bet_mode = ?";
    $params[] = $filter_bet_mode;
    $param_types .= 's';
}

if ($search_term) {
    $bets_sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR b.status LIKE ? OR b.numbers_played LIKE ?)";
    $count_sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR b.status LIKE ? OR b.numbers_played LIKE ?)";
    $search_like = "%$search_term%";
    $params = array_merge($params, [$search_like, $search_like, $search_like, $search_like]);
    $param_types .= str_repeat('s', 4);
}

// Get total count for pagination
$stmt_count = $conn->prepare($count_sql);
if (count($params) > 2) {
    $stmt_count->bind_param($param_types, ...$params);
} else {
    $stmt_count->bind_param('is', $session_id, $referral_code);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add ordering and pagination to main query
$bets_sql .= " ORDER BY b.placed_at DESC LIMIT ? OFFSET ?";
$bets_params = $params;
$bets_param_types = $param_types;
$bets_params[] = $records_per_page;
$bets_params[] = $offset;
$bets_param_types .= 'ii';

// Get filtered bets
$stmt_bets = $conn->prepare($bets_sql);
if (count($bets_params) > 2) {
    $stmt_bets->bind_param($bets_param_types, ...$bets_params);
} else {
    $stmt_bets->bind_param('isii', $session_id, $referral_code, $records_per_page, $offset);
}
$stmt_bets->execute();
$bets_result = $stmt_bets->get_result();
$bets = [];

if ($bets_result && $bets_result->num_rows > 0) {
    while ($row = $bets_result->fetch_assoc()) {
        $bets[] = $row;
    }
}

// Get all bets for statistics (without filters)
$all_bets_sql = "SELECT b.*, u.username, u.email, u.phone
                 FROM bets b
                 JOIN users u ON b.user_id = u.id
                 WHERE b.game_session_id = ?
                 AND u.referral_code = ?
                 ORDER BY b.placed_at DESC";
$stmt_all_bets = $conn->prepare($all_bets_sql);
$stmt_all_bets->bind_param('is', $session_id, $referral_code);
$stmt_all_bets->execute();
$all_bets_result = $stmt_all_bets->get_result();
$all_bets = [];

if ($all_bets_result && $all_bets_result->num_rows > 0) {
    while ($row = $all_bets_result->fetch_assoc()) {
        $all_bets[] = $row;
    }
}

// Use all bets for statistics calculations
$bets_for_stats = $all_bets;

// Get number forwarding decisions with bet_mode
$decisions_sql = "SELECT * FROM number_forwarding_decisions 
                  WHERE admin_id = ? AND game_session_id = ? 
                  ORDER BY created_at DESC";
$stmt_decisions = $conn->prepare($decisions_sql);
$stmt_decisions->bind_param('ii', $admin_id, $session_id);
$stmt_decisions->execute();
$decisions_result = $stmt_decisions->get_result();
$number_decisions = [];

if ($decisions_result && $decisions_result->num_rows > 0) {
    while ($row = $decisions_result->fetch_assoc()) {
        $number_decisions[] = $row;
    }
}

// Calculate current decisions - FIXED LOGIC FOR RE-ACCEPT
$current_decisions = [];
foreach ($number_decisions as $decision) {
    $decision_key = $decision['number_pattern'] . '_' . ($decision['bet_mode'] ?? '');
    
    // Only consider the latest decision for each number pattern and bet mode
    if (!isset($current_decisions[$decision_key])) {
        $current_decisions[$decision_key] = $decision;
    } else {
        // If we have a newer decision, use that instead
        if (strtotime($decision['created_at']) > strtotime($current_decisions[$decision_key]['created_at'])) {
            $current_decisions[$decision_key] = $decision;
        }
    }
}

// Calculate advanced statistics with forwarding decisions
$total_bets = count($bets_for_stats);
$total_amount = 0;
$total_potential_win = 0;
$won_bets = 0;
$lost_bets = 0;
$pending_bets = 0;
$total_payout = 0;

// Reset these variables
$admin_actual_bet_amount = 0;
$admin_actual_payout = 0;
$forwarded_total = 0;
$forwarded_amounts = [];
$user_bet_counts = [];
$game_type_stats = [];
$bet_mode_stats = [];
$number_frequency = [];
$number_amounts = [];

// Pre-fetch game types payout ratios for efficiency
$game_type_ratios = [];
$stmt_game_types = $conn->prepare("SELECT id, payout_ratio FROM game_types");
$stmt_game_types->execute();
$game_types_result = $stmt_game_types->get_result();
while ($game_type_row = $game_types_result->fetch_assoc()) {
    $game_type_ratios[$game_type_row['id']] = $game_type_row['payout_ratio'];
}

// STEP 1: Calculate initial statistics based on bet_limit/pnl_ratio (without considering admin decisions)
foreach ($bets_for_stats as $bet) {
    $bet_amount = $bet['amount'];
    $bet_potential_win = $bet['potential_win'];
    
    // Calculate automatic forwarding based on bet_limit/pnl_ratio
    $bet_admin_amount = $bet_amount;
    $bet_forwarded_amount = 0;
    
    $bet_numbers = json_decode($bet['numbers_played'], true);
    
    if (is_array($bet_numbers)) {
        if (isset($bet_numbers['selected_digits'])) {
            // For SP Motor
            if (isset($bet_numbers['pana_combinations'])) {
                $amount_per_pana = $bet_numbers['amount_per_pana'] ?? 0;
                foreach ($bet_numbers['pana_combinations'] as $pana) {
                    if ($pnl_ratio) {
                        // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                        $forwarded = ($amount_per_pana * $forward_ratio) / 100;
                    } else {
                        // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                        $forwarded = max(0, $amount_per_pana - $bet_limit);
                    }
                    
                    $bet_admin_amount -= $forwarded;
                    $bet_forwarded_amount += $forwarded;
                    
                    if (!isset($forwarded_amounts[$pana])) {
                        $forwarded_amounts[$pana] = 0;
                    }
                    $forwarded_amounts[$pana] += $forwarded;
                }
            }
        } else {
            // For single number bets
            foreach ($bet_numbers as $number => $amount) {
                if ($pnl_ratio) {
                    // PNL Ratio: Admin keeps admin_ratio%, forwards forward_ratio%
                    $forwarded = ($amount * $forward_ratio) / 100;
                } else {
                    // Bet Limit: Admin keeps min(amount, bet_limit), forwards the rest
                    $forwarded = max(0, $amount - $bet_limit);
                }
                
                $bet_admin_amount -= $forwarded;
                $bet_forwarded_amount += $forwarded;
                
                if (!isset($forwarded_amounts[$number])) {
                    $forwarded_amounts[$number] = 0;
                }
                $forwarded_amounts[$number] += $forwarded;
            }
        }
    }
    
    // Update initial statistics
    $total_amount += $bet_amount;
    $total_potential_win += $bet_potential_win;
    $admin_actual_bet_amount += $bet_admin_amount;
    $forwarded_total += $bet_forwarded_amount;
    
    // Count user activity (using original amounts for display)
    $user_id = $bet['user_id'];
    if (!isset($user_bet_counts[$user_id])) {
        $user_bet_counts[$user_id] = [
            'username' => $bet['username'],
            'count' => 0,
            'total_amount' => 0
        ];
    }
    $user_bet_counts[$user_id]['count']++;
    $user_bet_counts[$user_id]['total_amount'] += $bet_amount;
    
    // Calculate actual payouts for won bets with proper payout ratio
    if ($bet['status'] == 'won') {
        $won_bets++;
        
        // Get the payout ratio for this game type
        $game_type_id = $bet['game_type_id'];
        $payout_ratio = $game_type_ratios[$game_type_id] ?? 9.00; // Default to 9 if not found
        
        // Calculate admin's actual payout based on their actual bet amount and payout ratio
        if ($bet_amount > 0) {
            $admin_payout_ratio_amount = $bet_admin_amount / $bet_amount;
            $admin_win_amount = $bet_amount * $admin_payout_ratio_amount;
            $admin_payout = $admin_win_amount * $payout_ratio;
            
            $total_payout += $admin_payout;
            $admin_actual_payout += $admin_payout;
        }
        
    } elseif ($bet['status'] == 'lost') {
        $lost_bets++;
    } elseif ($bet['status'] == 'pending') {
        $pending_bets++;
    }
    
    // Count game types
    $game_type = $bet['game_type'];
    if (!isset($game_type_stats[$game_type])) {
        $game_type_stats[$game_type] = 0;
    }
    $game_type_stats[$game_type]++;
    
    // Count bet modes
    $bet_mode = $bet['bet_mode'];
    if (!isset($bet_mode_stats[$bet_mode])) {
        $bet_mode_stats[$bet_mode] = 0;
    }
    $bet_mode_stats[$bet_mode]++;
    
    // Analyze numbers played for display (using original amounts)
    $numbers = json_decode($bet['numbers_played'], true);
    if (is_array($numbers)) {
        if (isset($numbers['selected_digits'])) {
            $digits = $numbers['selected_digits'];
            $amount_per_pana = $numbers['amount_per_pana'] ?? 0;
            
            if (!isset($number_amounts[$digits])) {
                $number_amounts[$digits] = 0;
            }
            $number_amounts[$digits] += $amount_per_pana * count($numbers['pana_combinations'] ?? []);
            
            if (isset($numbers['pana_combinations'])) {
                foreach ($numbers['pana_combinations'] as $pana) {
                    if (!isset($number_frequency[$pana])) {
                        $number_frequency[$pana] = 0;
                    }
                    $number_frequency[$pana]++;
                    
                    if (!isset($number_amounts[$pana])) {
                        $number_amounts[$pana] = 0;
                    }
                    $number_amounts[$pana] += $amount_per_pana;
                }
            }
        } else {
            foreach ($numbers as $number => $amount) {
                if (!isset($number_frequency[$number])) {
                    $number_frequency[$number] = 0;
                }
                $number_frequency[$number]++;
                
                if (!isset($number_amounts[$number])) {
                    $number_amounts[$number] = 0;
                }
                $number_amounts[$number] += $amount;
            }
        }
    }
}

// STEP 2: Apply admin decisions by simply adding/subtracting applicable_limit amounts
foreach ($current_decisions as $decision) {
    $decision_amount = $decision['amount'];
    
    if ($decision['decision'] == 'forwarded') {
        // Add to forwarded amount, subtract from admin's exposure
        $forwarded_total += $decision_amount;
        $admin_actual_bet_amount -= $decision_amount;
        
        // Update forwarded_amounts for display
        $pattern = $decision['number_pattern'];
        if (!isset($forwarded_amounts[$pattern])) {
            $forwarded_amounts[$pattern] = 0;
        }
        $forwarded_amounts[$pattern] += $decision_amount;
        
    } elseif ($decision['decision'] == 'accepted') {
        // RE-ACCEPT ACTION: Simply remove the forwarding (don't add extra amount to admin)
        // Subtract from forwarded amount, but DON'T add to admin's exposure
        // $forwarded_total -= $decision_amount;
        // $admin_actual_bet_amount remains the same (we don't add extra amount)
        
        // Update forwarded_amounts for display
        $pattern = $decision['number_pattern'];
        if (isset($forwarded_amounts[$pattern])) {
            $forwarded_amounts[$pattern] -= $decision_amount;
            if ($forwarded_amounts[$pattern] <= 0) {
                unset($forwarded_amounts[$pattern]);
            }
        }
    } 
}

// Ensure amounts don't go negative
$admin_actual_bet_amount = max(0, $admin_actual_bet_amount);
$forwarded_total = max(0, $forwarded_total);

// Calculate admin's final profit/loss
$admin_profit_loss = $admin_actual_bet_amount - $admin_actual_payout;

// For display in most played numbers
if ($pnl_ratio) {
    arsort($number_amounts);
    $most_frequent_numbers = array_slice($number_amounts, 0, 15, true);
} else {
    $most_frequent_numbers = [];
    foreach ($number_amounts as $number => $amount) {
        $most_frequent_numbers[$number] = min($amount, $bet_limit);
    }
    arsort($most_frequent_numbers);
    $most_frequent_numbers = array_slice($most_frequent_numbers, 0, 15, true);
}

// Sort users by bet count
uasort($user_bet_counts, function($a, $b) {
    return $b['count'] - $a['count'];
});
$top_users = array_slice($user_bet_counts, 0, 10, true);

// Calculate percentages
$win_rate = $total_bets > 0 ? ($won_bets / $total_bets) * 100 : 0;
$loss_rate = $total_bets > 0 ? ($lost_bets / $total_bets) * 100 : 0;
$pending_rate = $total_bets > 0 ? ($pending_bets / $total_bets) * 100 : 0;


// Calculate final profit/loss based on scenario
if ($pnl_ratio) {
    // For PNL ratio mode, calculate admin's share of the profit/loss
    $admin_share = $admin_actual_bet_amount - $admin_actual_payout;
    $profit_loss = $admin_share;
} else {
    // For bet limit mode, use the regular profit/loss calculation
    $profit_loss = $admin_profit_loss;
}

// Determine which profit/loss to display
$display_profit_loss = $profit_loss;

// Build URL with parameters
function buildUrl($params = []) {
    global $session_id, $game_id, $date, $filter_game_type, $filter_bet_mode, $search_term, $records_per_page, $number_search;
    
    $base_params = [
        'session_id' => $session_id,
        'game_id' => $game_id,
        'date' => $date,
        'game_type' => $filter_game_type,
        'bet_mode' => $filter_bet_mode,
        'search' => $search_term,
        'records' => $records_per_page,
        'number_search' => $number_search
    ];
    
    return '?' . http_build_query(array_merge($base_params, $params));
}

// Calculate time remaining
$time_to_open = $open_timestamp - $current_time;
$time_to_close = $close_timestamp - $current_time;

$open_hours_remaining = floor($time_to_open / 3600);
$open_minutes_remaining = floor(($time_to_open % 3600) / 60);

$close_hours_remaining = floor($time_to_close / 3600);
$close_minutes_remaining = floor(($time_to_close % 3600) / 60);

// Create properly formatted time strings for display
$time_to_open_display = '';
if ($open_hours_remaining > 0) {
    $time_to_open_display .= $open_hours_remaining . 'h ';
}
$time_to_open_display .= $open_minutes_remaining . 'm';

$time_to_close_display = '';
if ($close_hours_remaining > 0) {
    $time_to_close_display .= $close_hours_remaining . 'h ';
}
$time_to_close_display .= $close_minutes_remaining . 'm';

// Function to check if actions are allowed for a specific bet mode
function isActionAllowed($bet_mode, $is_before_open, $is_after_open, $is_after_close) {
    if ($bet_mode === 'open') {
        return $is_before_open; // Only allow before opening time
    } elseif ($bet_mode === 'close') {
        return $is_after_open && !$is_after_close; // Only allow between opening and closing time
    }
    return false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Analytics - RB Games Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
        :root {
            --primary: #ff3c7e;
            --secondary: #0fb4c9;
            --accent: #00cec9;
            --dark: #1a1a2e;
            --darker: #16213e;
            --success: #00b894;
            --warning: #fdcb6e;
            --danger: #d63031;
            --text-light: #f5f6fa;
            --text-muted: rgba(255, 255, 255, 0.7);
            --card-bg: rgba(26, 26, 46, 0.8);
            --border-color: rgba(255, 60, 126, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--dark) 0%, var(--darker) 100%);
            color: var(--text-light);
            min-height: 100vh;
            line-height: 1.6;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2.2rem;
            margin-left: 260px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.2rem;
            padding-bottom: 1.2rem;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 1px solid var(--border-color);
            padding: 0.7rem 1.3rem;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .dashboard-section {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            margin-bottom: 2.2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            text-align: center;
            position: relative;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }

        .stat-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        .progress-won { background: var(--success); }
        .progress-lost { background: var(--danger); }
        .progress-pending { background: var(--warning); }

        .numbers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .number-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.8rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .number-item:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .number-value {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }

        .number-count {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .user-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }

        .user-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .user-stats {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .table-container {
            overflow-x: auto;
            margin: 0 -1.8rem;
            padding: 0 1.8rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th, .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .status {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-won {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .status-lost {
            background: rgba(214, 48, 49, 0.2);
            color: var(--danger);
            border: 1px solid rgba(214, 48, 49, 0.3);
        }

        .status-pending {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .profit { color: var(--success); }
        .loss { color: var(--danger); }

        .session-info {
            background: rgba(11, 180, 201, 0.1);
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid rgba(11, 180, 201, 0.2);
            margin-bottom: 2rem;
        }

        .session-results {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .result-badge {
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-open {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
        }

        .result-close {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
        }

        .result-jodi {
            background: rgba(11, 180, 201, 0.2);
            color: var(--secondary);
            border: 1px solid rgba(11, 180, 201, 0.3);
        }

        .result-pending {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        /* New styles for enhanced features */
        .filter-badge {
            display: inline-block;
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
            border: 1px solid var(--primary);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            margin: 0.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            text-decoration: none;
        }
        
        .filter-badge:hover {
            background: rgba(255, 60, 126, 0.3);
            transform: translateY(-1px);
        }
        
        .filter-badge.active {
            background: var(--primary);
            color: white;
        }
        
        .search-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input {
            flex: 1;
            min-width: 200px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.7rem 1rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .search-input::placeholder {
            color: var(--text-muted);
        }
        
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #ff2b6d;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }
        
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding: 1rem 0;
            border-top: 1px solid var(--border-color);
        }
        
        .pagination-info {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .page-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-light);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-block;
        }
        
        .page-btn:hover:not(.disabled) {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-numbers {
            display: flex;
            gap: 0.5rem;
        }
        
        .page-number {
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-light);
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .page-number.active {
            background: var(--primary);
            color: white;
        }
        
        .page-number:not(.active):hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .records-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .active-filters {
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(11, 180, 201, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(11, 180, 201, 0.2);
        }
        
        .active-filter-tag {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            font-size: 0.8rem;
        }
        
        .active-filter-tag .remove {
            margin-left: 0.5rem;
            cursor: pointer;
            color: var(--danger);
            text-decoration: none;
        }
        
        .forward-badge {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
            text-align: center;
        }
        
        .number-item.over-limit {
            border-color: #ffc107;
            background: rgba(255, 193, 7, 0.1);
        }
        
        .number-amount {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }
        
        .scenario-indicator {
            background: rgba(11, 180, 201, 0.1);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid rgba(11, 180, 201, 0.2);
        }

        /* Number-based forwarding styles */
        .time-restriction-banner {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .time-restriction-banner.closed {
            background: linear-gradient(135deg, var(--danger), #c0392b);
        }
        
        .time-restriction-banner.warning {
            background: linear-gradient(135deg, var(--warning), #e67e22);
        }
        
        .banner-icon {
            font-size: 1.5rem;
            margin-right: 0.5rem;
        }
        
        .time-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }
        
        .time-info.closed {
            border-left-color: var(--danger);
            background: rgba(220, 53, 69, 0.1);
        }
        
        .finalized-badge {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .action-disabled {
            opacity: 0.6;
            cursor: not-allowed !important;
        }
        
        .action-disabled:hover {
            transform: none !important;
        }
        
        .disabled-tooltip {
            position: relative;
        }
        
        .disabled-tooltip::after {
            content: "Actions disabled after game closing time";
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        
        .disabled-tooltip:hover::after {
            opacity: 1;
        }
        
        .number-control-panel {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 2px solid var(--primary);
        }
        
        .number-search-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        
        .number-search-group {
            flex: 1;
            min-width: 200px;
        }
        
        .number-search-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .number-search-input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 1rem;
            font-family: monospace;
        }
        
        .bulk-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .bulk-btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .bulk-btn.forward {
            background: var(--warning);
            color: black;
        }
        
        .bulk-btn.forward:hover {
            background: #e6b800;
            transform: translateY(-2px);
        }
        
        .bulk-btn.accept {
            background: var(--success);
            color: white;
        }
        
        .bulk-btn.accept:hover {
            background: #00a382;
            transform: translateY(-2px);
        }
        
        .bulk-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .search-results {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .search-result-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.7rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }
        
        .number-badge {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-family: monospace;
            font-weight: 600;
        }
        
        .decision-status {
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-forwarded {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }
        
        .status-accepted {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }
        
        .decision-history {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .decision-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .decision-item:last-child {
            border-bottom: none;
        }
        
        .decision-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .decision-amount {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .amount-forwarded {
            color: var(--warning);
        }
        
        .amount-accepted {
            color: var(--success);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        
        .modal-content {
            background: var(--card-bg);
            margin: 10% auto;
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            border: 1px solid var(--border-color);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .close-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .numbers-grid {
                grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            }
            
            .users-grid {
                grid-template-columns: 1fr;
            }
            
            .session-results {
                flex-direction: column;
            }
            
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-input {
                min-width: auto;
            }
            
            .pagination {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .pagination-controls {
                justify-content: center;
            }
            
            .number-search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-actions {
                flex-direction: column;
            }
            
            .decision-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        /* Add new styles for time restriction indicators */
        .time-restriction-banner.open-closed {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .time-restriction-banner.close-closed {
            background: linear-gradient(135deg, #e67e22, #d35400);
        }
        
        .time-restriction-banner.all-closed {
            background: linear-gradient(135deg, var(--danger), #c0392b);
        }
        
        .bet-mode-indicator {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }
        
        .bet-mode-open {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }
        
        .bet-mode-close {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }
        
        .action-time-info {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            border-left: 4px solid var(--warning);
        }
        

        .status-forwarded {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
            border: 1px solid rgba(253, 203, 110, 0.3);
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-accepted {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 184, 148, 0.3);
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
    
    .game-type-numbers {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1.2rem;
    transition: all 0.3s ease;
}

.game-type-numbers:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 60, 126, 0.3);
}

.game-type-numbers h4 {
    color: var(--secondary);
    font-size: 1.1rem;
}

.game-type-numbers .numbers-grid {
    margin-top: 0.8rem;
}

.game-type-numbers .number-item {
    padding: 0.6rem;
    font-size: 0.9rem;
}

.game-type-numbers .number-value {
    font-size: 1rem;
    margin-bottom: 0.2rem;
}

.game-type-numbers .number-amount {
    font-size: 0.75rem;
}

</style>

</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Same sidebar content -->
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="welcome">
                    <h1>Session Analytics</h1>
                    <p>
                        <?php echo htmlspecialchars($session['game_name']); ?> | 
                        <?php echo date('M j, Y', strtotime($date)); ?> |
                        <?php echo date('h:i A', strtotime($session['open_time'])); ?> - 
                        <?php echo date('h:i A', strtotime($session['close_time'])); ?>
                    </p>
                </div>
                <a href="todays_active_games.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Today's Games
                </a>
            </div>

            <!-- Time Restriction Banner -->
            <?php if ($is_after_close): ?>
                <div class="time-restriction-banner all-closed">
                    <i class="fas fa-lock banner-icon"></i>
                    <strong>GAME COMPLETELY CLOSED - ALL DECISIONS FINALIZED</strong>
                    <p style="margin-top: 0.5rem; opacity: 0.9;">
                        This game closed at <?php echo date('h:i A', $close_timestamp); ?>. 
                        All forwarding decisions for both OPEN and CLOSE bets are now permanent.
                    </p>
                </div>
            <?php elseif ($is_after_open): ?>
                <div class="time-restriction-banner open-closed">
                    <i class="fas fa-lock banner-icon"></i>
                    <strong>OPEN BETS LOCKED - CLOSE BETS STILL ACTIVE</strong>
                    <p style="margin-top: 0.5rem; opacity: 0.9;">
                        Game opened at <?php echo date('h:i A', $open_timestamp); ?>. 
                        OPEN bets are now finalized. CLOSE bets can be managed until <?php echo date('h:i A', $close_timestamp); ?>.
                    </p>
                </div>
            <?php else: ?>
                <?php if ($time_to_open < 3600): ?>
                    <div class="time-restriction-banner warning">
                        <i class="fas fa-clock banner-icon"></i>
                        <strong>GAME OPENING SOON - ACT QUICKLY ON OPEN BETS!</strong>
                        <p style="margin-top: 0.5rem; opacity: 0.9;">
                            Game opens in <?php echo $time_to_open_display; ?> at <?php echo date('h:i A', $open_timestamp); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Game Time Information -->
            <div class="time-info <?php echo $is_after_close ? 'closed' : ''; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong>Open Time:</strong> 
                        <?php echo date('h:i A', strtotime($session['open_time'])); ?>
                        <?php if ($is_before_open): ?>
                            <span style="color: var(--warning); margin-left: 0.5rem;">
                                (in <?php echo $time_to_open_display; ?>)
                            </span>
                        <?php elseif ($is_after_open): ?>
                            <span style="color: var(--success); margin-left: 0.5rem;">
                                <i class="fas fa-check"></i> PASSED
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Close Time:</strong> 
                        <?php echo date('h:i A', strtotime($session['close_time'])); ?>
                        <?php if (!$is_after_close): ?>
                            <span style="color: var(--warning); margin-left: 0.5rem;">
                                (in <?php echo $time_to_close_display; ?>)
                            </span>
                        <?php else: ?>
                            <span style="color: var(--danger); margin-left: 0.5rem;">
                                <i class="fas fa-check"></i> PASSED
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Current Time:</strong> 
                        <?php echo date('h:i A', $current_time); ?>
                    </div>
                </div>
            </div>

            <!-- Action Time Restrictions Info -->
            <div class="action-time-info">
                <h4><i class="fas fa-clock"></i> Action Time Restrictions</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 0.5rem;">
                    <div>
                        <strong>OPEN Bets:</strong>
                        <span class="bet-mode-indicator bet-mode-open">OPEN</span>
                        <br>
                        <small>Can be managed: Before opening time only</small>
                        <br>
                        <small style="color: <?php echo $is_before_open ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo $is_before_open ? '✓ Actions allowed' : '✗ Actions disabled'; ?>
                        </small>
                    </div>
                    <div>
                        <strong>CLOSE Bets:</strong>
                        <span class="bet-mode-indicator bet-mode-close">CLOSE</span>
                        <br>
                        <small>Can be managed: Between opening and closing time</small>
                        <br>
                        <small style="color: <?php echo ($is_after_open && !$is_after_close) ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo ($is_after_open && !$is_after_close) ? '✓ Actions allowed' : '✗ Actions disabled'; ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Scenario Indicator -->
            <div class="scenario-indicator">
                <h4><i class="fas fa-cog"></i> Current Configuration</h4>
                <?php if ($pnl_ratio): ?>
                    <p><strong>PNL Ratio Mode:</strong> <?php echo $pnl_ratio; ?> (Admin:<?php echo $admin_ratio; ?>% | Forward:<?php echo $forward_ratio; ?>%)</p>
                    <p>Profit/Loss sharing enabled. Your share: <?php echo $admin_ratio; ?>%</p>
                <?php else: ?>
                    <p><strong>Bet Limit Mode:</strong> ₹<?php echo number_format($bet_limit); ?> per number</p>
                    <p>Individual number bets capped at limit. Excess amounts forwarded.</p>
                <?php endif; ?>
            </div>

            

            <!-- Session Information -->
            <div class="session-info">
                <h3><i class="fas fa-info-circle"></i> Session Information</h3>
                <div class="session-results">
                    <?php if ($session['open_result']): ?>
                        <div class="result-badge result-open">
                            <i class="fas fa-sun"></i>
                            Open Result: <?php echo $session['open_result']; ?>
                        </div>
                    <?php else: ?>
                        <div class="result-badge result-pending">
                            <i class="fas fa-clock"></i>
                            Open Result: Pending
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($session['close_result']): ?>
                        <div class="result-badge result-close">
                            <i class="fas fa-moon"></i>
                            Close Result: <?php echo $session['close_result']; ?>
                        </div>
                    <?php else: ?>
                        <div class="result-badge result-pending">
                            <i class="fas fa-clock"></i>
                            Close Result: Pending
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($session['jodi_result']): ?>
                        <div class="result-badge result-jodi">
                            <i class="fas fa-link"></i>
                            Jodi Result: <?php echo $session['jodi_result']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Key Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-value"><?php echo number_format($total_bets); ?></div>
        <div class="stat-card-title">Total Bets</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value">₹<?php echo number_format($total_amount, 2); ?></div>
        <div class="stat-card-title">Total Bet Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value">₹<?php echo number_format($total_payout, 2); ?></div>
        <div class="stat-card-title">Total Payout</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value <?php echo $admin_profit_loss >= 0 ? 'profit' : 'loss'; ?>">
            ₹<?php echo number_format(abs($admin_profit_loss), 2); ?>
        </div>
        <div class="stat-card-title">
            <?php if ($pending_bets > 0): ?>
                Current P&L
            <?php else: ?>
                Final P&L
            <?php endif; ?>
        </div>
    </div>  
    
    <!-- Show forwarded amount -->
    <div class="stat-card">
        <div class="stat-card-value" style="color: #ffc107;">
            ₹<?php echo number_format($forwarded_total, 2); ?>
        </div>
        <div class="stat-card-title">Forwarded Amount</div>
    </div>
    
    <!-- Show admin's actual bet amount -->
    <div class="stat-card">
        <div class="stat-card-value" style="color: var(--secondary);">
            ₹<?php echo number_format($admin_actual_bet_amount, 2); ?>
        </div>
        <div class="stat-card-title">Your Actual Exposure</div>
    </div>
</div>
    
<!-- Active Filters -->
            <?php if ($filter_game_type || $filter_bet_mode || $search_term): ?>
            <div class="active-filters">
                <h4><i class="fas fa-filter"></i> Active Filters</h4>
                <?php if ($filter_game_type): ?>
                    <span class="active-filter-tag">
                        Game Type: <?php echo ucfirst(str_replace('_', ' ', $filter_game_type)); ?>
                        <a href="<?php echo buildUrl(['game_type' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($filter_bet_mode): ?>
                    <span class="active-filter-tag">
                        Bet Mode: <?php echo ucfirst($filter_bet_mode); ?>
                        <a href="<?php echo buildUrl(['bet_mode' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <?php if ($search_term): ?>
                    <span class="active-filter-tag">
                        Search: "<?php echo htmlspecialchars($search_term); ?>"
                        <a href="<?php echo buildUrl(['search' => '', 'page' => 1]); ?>" class="remove">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                <a href="<?php echo buildUrl(['game_type' => '', 'bet_mode' => '', 'search' => '', 'page' => 1]); ?>" class="btn btn-outline" style="margin-left: 1rem;">
                    <i class="fas fa-times"></i> Clear All
                </a>
            </div>
            <?php endif; ?>

            <!-- Bet Distribution -->
            <div class="dashboard-section">
                <h2 class="section-title"><i class="fas fa-chart-pie"></i> Bet Distribution</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                    <div>
                        <h4>Status Distribution</h4>
                        <div class="progress-bar">
                            <div class="progress-fill progress-won" style="width: <?php echo $win_rate; ?>%"></div>
                            <div class="progress-fill progress-lost" style="width: <?php echo $loss_rate; ?>%"></div>
                            <div class="progress-fill progress-pending" style="width: <?php echo $pending_rate; ?>%"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">
                            <span>Won: <?php echo number_format($win_rate, 1); ?>%</span>
                            <span>Lost: <?php echo number_format($loss_rate, 1); ?>%</span>
                            <span>Pending: <?php echo number_format($pending_rate, 1); ?>%</span>
                        </div>
                    </div>
                    
                    <div>
                        <h4>Game Types <small>(Click to filter)</small></h4>
                        <?php foreach ($game_type_stats as $type => $count): ?>
                            <a href="<?php echo buildUrl(['game_type' => $type, 'page' => 1]); ?>" 
                               class="filter-badge <?php echo $filter_game_type == $type ? 'active' : ''; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                <span style="margin-left: 0.3rem; font-weight: 600;"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <div>
                        <h4>Bet Modes <small>(Click to filter)</small></h4>
                        <?php foreach ($bet_mode_stats as $mode => $count): ?>
                            <a href="<?php echo buildUrl(['bet_mode' => $mode, 'page' => 1]); ?>" 
                               class="filter-badge <?php echo $filter_bet_mode == $mode ? 'active' : ''; ?>">
                                <?php echo ucfirst($mode); ?>
                                <span style="margin-left: 0.3rem; font-weight: 600;"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

<!-- Most Frequent Numbers by Game Type -->
<?php if (!empty($number_amounts)): ?>
<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-sort-amount-down"></i> Most Played Numbers by Game Type</h2>
    
    <?php
    // Group numbers by game type
    $numbers_by_game_type = [];
    
    foreach ($bets_for_stats as $bet) {
        $game_type = $bet['game_type'];
        $numbers = json_decode($bet['numbers_played'], true);
        
        if (is_array($numbers)) {
            if (isset($numbers['selected_digits'])) {
                // For SP Motor
                $digits = $numbers['selected_digits'];
                $amount_per_pana = $numbers['amount_per_pana'] ?? 0;
                
                if (!isset($numbers_by_game_type[$game_type][$digits])) {
                    $numbers_by_game_type[$game_type][$digits] = 0;
                }
                $numbers_by_game_type[$game_type][$digits] += $amount_per_pana * count($numbers['pana_combinations'] ?? []);
                
                if (isset($numbers['pana_combinations'])) {
                    foreach ($numbers['pana_combinations'] as $pana) {
                        if (!isset($numbers_by_game_type[$game_type][$pana])) {
                            $numbers_by_game_type[$game_type][$pana] = 0;
                        }
                        $numbers_by_game_type[$game_type][$pana] += $amount_per_pana;
                    }
                }
            } else {
                // For single number bets
                foreach ($numbers as $number => $amount) {
                    if (!isset($numbers_by_game_type[$game_type][$number])) {
                        $numbers_by_game_type[$game_type][$number] = 0;
                    }
                    $numbers_by_game_type[$game_type][$number] += $amount;
                }
            }
        }
    }
    
    // Apply PNL ratio or bet limit for display
    $display_numbers_by_game_type = [];
    foreach ($numbers_by_game_type as $game_type => $numbers) {
        $display_numbers_by_game_type[$game_type] = [];
        
        foreach ($numbers as $number => $amount) {
            if ($pnl_ratio) {
                // For PNL ratio, show the full amount but indicate sharing
                $display_numbers_by_game_type[$game_type][$number] = $amount;
            } else {
                // For bet limit, show min(amount, bet_limit)
                $display_numbers_by_game_type[$game_type][$number] = min($amount, $bet_limit);
            }
        }
        
        // Sort by amount descending and take top 10
        arsort($display_numbers_by_game_type[$game_type]);
        $display_numbers_by_game_type[$game_type] = array_slice($display_numbers_by_game_type[$game_type], 0, 10, true);
    }
    ?>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin-top: 1.5rem;">
        <?php foreach ($display_numbers_by_game_type as $game_type => $numbers): ?>
            <?php if (!empty($numbers)): ?>
                <div class="game-type-numbers">
                    <h4 style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                        <i class="fas fa-dice"></i> 
                        <?php echo ucfirst(str_replace('_', ' ', $game_type)); ?>
                        <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;">
                            (<?php echo count($numbers); ?> numbers)
                        </span>
                    </h4>
                    
                    <div class="numbers-grid" style="grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 0.6rem;">
                        <?php foreach ($numbers as $number => $amount): ?>
                            <?php 
                            $is_over_limit = !$pnl_ratio && isset($forwarded_amounts[$number]);
                            $original_amount = $is_over_limit ? ($amount + $forwarded_amounts[$number]) : $amount;
                            ?>
                            <div class="number-item <?php echo $is_over_limit ? 'over-limit' : ''; ?>">
                                <div class="number-value"><?php echo htmlspecialchars($number); ?></div>
                                <div class="number-amount" style="font-size: 0.8rem;">
                                    ₹<?php echo number_format($amount, 2); ?>
                                    <?php if ($is_over_limit): ?>
                                        <br><small style="color: #ffc107; font-size: 0.7rem;">
                                            (of ₹<?php echo number_format($original_amount, 2); ?>)
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php 
                    // Calculate forwarded amounts for this game type
                    $game_type_forwarded = [];
                    foreach ($forwarded_amounts as $number => $amount) {
                        if ($amount > 0 && isset($numbers_by_game_type[$game_type][$number])) {
                            $game_type_forwarded[] = $number . ': ₹' . number_format($amount, 2);
                        }
                    }
                    ?>
                    
                    <?php if (!empty($game_type_forwarded)): ?>
                        <div class="forward-badge" style="margin-top: 1rem; padding: 0.7rem; font-size: 0.85rem;">
                            <i class="fas fa-share-alt"></i>
                            <strong>Forwarded:</strong> 
                            <?php echo implode(' | ', $game_type_forwarded); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <!-- Global Forwarding Information -->
    <?php if (!empty($forwarded_amounts)): ?>
        <div class="forward-badge" style="margin-top: 1.5rem;">
            <i class="fas fa-share-alt"></i>
            <strong>Total Forwarded Amounts:</strong> 
            <?php 
            $forwarded_details = [];
            foreach ($forwarded_amounts as $number => $amount) {
                if ($amount > 0) {
                    $forwarded_details[] = $number . ': ₹' . number_format($amount, 2);
                }
            }
            echo implode(' | ', $forwarded_details);
            ?>
        </div>
    <?php endif; ?>
    
    <?php if ($pnl_ratio): ?>
        <div class="forward-badge" style="margin-top: 1rem;">
            <i class="fas fa-handshake"></i>
            <strong>PNL Sharing Active:</strong> 
            Your Share: <?php echo $admin_ratio; ?>% | 
            Forwarded Share: <?php echo $forward_ratio; ?>% |
            Total Forwarded: ₹<?php echo number_format($forwarded_total, 2); ?>
        </div>
    <?php else: ?>
        <div class="forward-badge" style="margin-top: 1rem;">
            <i class="fas fa-share-alt"></i>
            <strong>Bet Limit Active:</strong> 
            Limit: ₹<?php echo number_format($bet_limit); ?> per number |
            Total Forwarded: ₹<?php echo number_format($forwarded_total, 2); ?>
        </div>
    <?php endif; ?>
</div>  
<?php endif; ?>

            <!-- Top Users -->
            <?php if (!empty($top_users)): ?>
            <div class="dashboard-section">
                <h2 class="section-title"><i class="fas fa-users"></i> Top Users</h2>
                <div class="users-grid">
                    <?php foreach ($top_users as $user_id => $user_data): ?>
                        <div class="user-item">
                            <div class="user-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                            <div class="user-stats">
                                <div>Bets: <?php echo $user_data['count']; ?></div>
                                <div>Amount: ₹<?php echo number_format($user_data['total_amount'], 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

<!-- Number-Based Forwarding Control -->
            <div class="dashboard-section">
                <h2 class="section-title">
                    <i class="fas fa-exchange-alt"></i> Number-Based Risk Management
                    <?php if ($is_after_close): ?>
                        <span class="finalized-badge">
                            <i class="fas fa-lock"></i> ALL FINALIZED
                        </span>
                    <?php elseif ($is_after_open): ?>
                        <span class="finalized-badge" style="background: rgba(230, 126, 34, 0.2); color: #e67e22; border-color: #e67e22;">
                            <i class="fas fa-lock"></i> OPEN BETS FINALIZED
                        </span>
                    <?php endif; ?>
                </h2>
                
                <div class="number-control-panel">
                    <form method="GET" class="number-search-form">
                        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                        <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
                        <input type="hidden" name="date" value="<?php echo $date; ?>">
                        
                        <div class="number-search-group">
                            <label class="number-search-label">Search Bet Numbers</label>
                            <input type="text" 
                                   name="number_search" 
                                   class="number-search-input" 
                                   placeholder="Enter number (e.g., 5, 15, 128, etc.)" 
                                   value="<?php echo htmlspecialchars($number_search); ?>"
                                   pattern="[0-9]+"
                                   title="Enter only numbers">
                            <small style="color: var(--text-muted); margin-top: 0.5rem; display: block;">
                                Enter specific numbers to manage risk (e.g., 5 for all bets on number 5)
                            </small>
                        </div>
                        
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i> Search Numbers
                        </button>
                        
                        <?php if ($number_search): ?>
                            <a href="<?php echo buildUrl(['number_search' => '']); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>

                    <?php if ($number_search): ?>
    <?php
    // Get bets matching the number search, separated by bet mode
    $number_bets_sql = "SELECT b.*, u.username 
                       FROM bets b 
                       JOIN users u ON b.user_id = u.id 
                       WHERE b.game_session_id = ? 
                       AND u.referral_code = ?
                       AND b.numbers_played LIKE ?
                       AND b.bet_mode = ?  
                       ORDER BY b.placed_at DESC";
    $stmt_number_bets = $conn->prepare($number_bets_sql);
    $search_pattern = '%"' . $number_search . '"%';
    
    // For OPEN bets section
    $number_bets_sql_open = "SELECT b.*, u.username 
                            FROM bets b 
                            JOIN users u ON b.user_id = u.id 
                            WHERE b.game_session_id = ? 
                            AND u.referral_code = ?
                            AND b.numbers_played LIKE ?
                            AND b.bet_mode = 'open'
                            ORDER BY b.placed_at DESC";
    $stmt_number_bets_open = $conn->prepare($number_bets_sql_open);
    $search_pattern = '%"' . $number_search . '"%';
    $stmt_number_bets_open->bind_param('iss', $session_id, $referral_code, $search_pattern);
    $stmt_number_bets_open->execute();
    $number_bets_result_open = $stmt_number_bets_open->get_result();
    
    $matching_bets_open = [];
    $total_matching_amount_open = 0;
    
    if ($number_bets_result_open && $number_bets_result_open->num_rows > 0) {
        while ($row = $number_bets_result_open->fetch_assoc()) {
            $matching_bets_open[] = $row;
            $total_matching_amount_open += $row['amount'];
        }
    }
    
    // For CLOSE bets section  
    $number_bets_sql_close = "SELECT b.*, u.username 
                             FROM bets b 
                             JOIN users u ON b.user_id = u.id 
                             WHERE b.game_session_id = ? 
                             AND u.referral_code = ?
                             AND b.numbers_played LIKE ?
                             AND b.bet_mode = 'close'
                             ORDER BY b.placed_at DESC";
    $stmt_number_bets_close = $conn->prepare($number_bets_sql_close);
    $stmt_number_bets_close->bind_param('iss', $session_id, $referral_code, $search_pattern);
    $stmt_number_bets_close->execute();
    $number_bets_result_close = $stmt_number_bets_close->get_result();
    
    $matching_bets_close = [];
    $total_matching_amount_close = 0;
    
    if ($number_bets_result_close && $number_bets_result_close->num_rows > 0) {
        while ($row = $number_bets_result_close->fetch_assoc()) {
            $matching_bets_close[] = $row;
            $total_matching_amount_close += $row['amount'];
        }
    }
    ?>
    
    <div class="number-search-results">
        <h3>Results for Number "<?php echo htmlspecialchars($number_search); ?>"</h3>
        
        <!-- OPEN Bets Section -->
        <div class="bet-mode-section">
            <h4>
                <span class="bet-mode-indicator bet-mode-open">OPEN</span>
                OPEN Bets
                <?php if (!$is_before_open): ?>
                    <span class="finalized-badge" style="background: rgba(231, 76, 60, 0.2); color: #e74c3c; border-color: #e74c3c;">
                        <i class="fas fa-lock"></i> FINALIZED
                    </span>
                <?php endif; ?>
            </h4>
            
            <div class="bet-mode-stats">
                <div class="stat-card mini">
                    <div class="stat-value"><?php echo count($matching_bets_open); ?></div>
                    <div class="stat-label">Total OPEN Bets</div>
                </div>
                <div class="stat-card mini">
                    <div class="stat-value">₹<?php echo number_format($total_matching_amount_open); ?></div>
                    <div class="stat-label">Total OPEN Amount</div>
                </div>
            </div>

            <?php if (count($matching_bets_open) > 0): ?>
                <div class="number-actions">
                    <?php if ($is_before_open): ?>
                        <form method="POST" style="display: inline-block;">
                            <input type="hidden" name="number_pattern" value="<?php echo htmlspecialchars($number_search); ?>">
                            <input type="hidden" name="bet_mode" value="open"> <!-- ADD THIS -->
                            <button type="submit" name="action" value="forward_all_numbers" class="btn btn-warning">
                                <i class="fas fa-share"></i> Forward All OPEN Bets (₹<?php echo number_format($total_matching_amount_open); ?>)
                            </button>
                        </form>
                        
                        <?php
                        // Check if there's a forwarded decision for this number and OPEN bet mode
                        $has_forwarded_open = false;
                        $forwarded_amount_open = 0;
                        foreach ($current_decisions as $decision) {
                            if ($decision['number_pattern'] == $number_search && 
                                ($decision['bet_mode'] ?? '') === 'open' && 
                                $decision['decision'] == 'forwarded') {
                                $has_forwarded_open = true;
                                $forwarded_amount_open = $decision['amount'];
                                break;
                            }
                        }
                        ?>
                        
                        <?php if ($has_forwarded_open): ?>
                            <form method="POST" style="display: inline-block;">
                                <input type="hidden" name="number_pattern" value="<?php echo htmlspecialchars($number_search); ?>">
                                <input type="hidden" name="bet_mode" value="open"> <!-- ADD THIS -->
                                <button type="submit" name="action" value="accept_all_numbers" class="btn btn-success">
                                    <i class="fas fa-check"></i> Accept OPEN Forwarded (₹<?php echo number_format($forwarded_amount_open); ?>)
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="action-disabled-message">
                            <i class="fas fa-lock"></i>
                            OPEN bets can no longer be managed (game has opened)
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="no-results">No OPEN bets found for this number.</p>
            <?php endif; ?>
        </div>
        
        <!-- CLOSE Bets Section -->
        <div class="bet-mode-section">
            <h4>
                <span class="bet-mode-indicator bet-mode-close">CLOSE</span>
                CLOSE Bets
                <?php if ($is_after_close): ?>
                    <span class="finalized-badge" style="background: rgba(231, 76, 60, 0.2); color: #e74c3c; border-color: #e74c3c;">
                        <i class="fas fa-lock"></i> FINALIZED
                    </span>
                <?php endif; ?>
            </h4>
            
            <div class="bet-mode-stats">
                <div class="stat-card mini">
                    <div class="stat-value"><?php echo count($matching_bets_close); ?></div>
                    <div class="stat-label">Total CLOSE Bets</div>
                </div>
                <div class="stat-card mini">
                    <div class="stat-value">₹<?php echo number_format($total_matching_amount_close); ?></div>
                    <div class="stat-label">Total CLOSE Amount</div>
                </div>
            </div>

            <?php if (count($matching_bets_close) > 0): ?>
                <div class="number-actions">
                    <?php if ($is_after_open && !$is_after_close): ?>
                        <form method="POST" style="display: inline-block;">
                            <input type="hidden" name="number_pattern" value="<?php echo htmlspecialchars($number_search); ?>">
                            <input type="hidden" name="bet_mode" value="close"> <!-- ADD THIS -->
                            <button type="submit" name="action" value="forward_all_numbers" class="btn btn-warning">
                                <i class="fas fa-share"></i> Forward All CLOSE Bets (₹<?php echo number_format($total_matching_amount_close); ?>)
                            </button>
                        </form>
                        
                        <?php
                        // Check if there's a forwarded decision for this number and CLOSE bet mode
                        $has_forwarded_close = false;
                        $forwarded_amount_close = 0;
                        foreach ($current_decisions as $decision) {
                            if ($decision['number_pattern'] == $number_search && 
                                ($decision['bet_mode'] ?? '') === 'close' && 
                                $decision['decision'] == 'forwarded') {
                                $has_forwarded_close = true;
                                $forwarded_amount_close = $decision['amount'];
                                break;
                            }
                        }
                        ?>
                        
                        <?php if ($has_forwarded_close): ?>
                            <form method="POST" style="display: inline-block;">
                                <input type="hidden" name="number_pattern" value="<?php echo htmlspecialchars($number_search); ?>">
                                <input type="hidden" name="bet_mode" value="close"> <!-- ADD THIS -->
                                <button type="submit" name="action" value="accept_all_numbers" class="btn btn-success">
                                    <i class="fas fa-check"></i> Accept CLOSE Forwarded (₹<?php echo number_format($forwarded_amount_close); ?>)
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($is_after_close): ?>
                        <div class="action-disabled-message">
                            <i class="fas fa-lock"></i>
                            CLOSE bets can no longer be managed (game has closed)
                        </div>
                    <?php else: ?>
                        <div class="action-disabled-message">
                            <i class="fas fa-clock"></i>
                            CLOSE bets can be managed after game opens
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="no-results">No CLOSE bets found for this number.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
                </div>
            </div>

            <!-- Admin Forwarding Decisions History -->
<div class="dashboard-section">
    <h2 class="section-title">
        <i class="fas fa-history"></i> Your Forwarding Decisions
    </h2>
    
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number Pattern</th>
                    <th>Bet Mode</th>
                    <th>Decision</th>
                    <th>Amount</th>
                    <th>Applicable Limit</th>
                    <th>Reason</th>
                    <th>Decision Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($number_decisions)): ?>
                    <?php foreach ($number_decisions as $decision): ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: bold;">
                                <?php echo htmlspecialchars($decision['number_pattern']); ?>
                            </td>
                            <td>
                                <span class="bet-mode-indicator bet-mode-<?php echo $decision['bet_mode'] ?? 'open'; ?>">
                                    <?php echo strtoupper($decision['bet_mode'] ?? 'OPEN'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($decision['decision'] == 'forwarded'): ?>
                                    <span class="status status-forwarded">
                                        <i class="fas fa-share-alt"></i> Forwarded
                                    </span>
                                <?php else: ?>
                                    <span class="status status-accepted">
                                        <i class="fas fa-check-circle"></i> Accepted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600;">
                                ₹<?php echo number_format($decision['amount'], 2); ?>
                            </td>
                            <td>
                                <?php 
                                $limit_display = $decision['applicable_limit'] ?? 'N/A';
                                if (strpos($limit_display, ':') !== false) {
                                    echo "PNL Ratio: " . $limit_display;
                                } else {
                                    echo "Bet Limit: ₹" . number_format($limit_display);
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($decision['decision_reason'] ?? 'No reason provided'); ?>
                            </td>
                            <td>
                                <?php echo date('M j, h:i A', strtotime($decision['created_at'])); ?>
                            </td>
                            <td>
                                <?php if ($decision['finalized_at']): ?>
                                    <span class="finalized-badge">
                                        <i class="fas fa-lock"></i> Finalized
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--success);">
                                        <i class="fas fa-pencil-alt"></i> Active
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            <i class="fas fa-info-circle"></i> No forwarding decisions made yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


            <!-- Individual Bets Table -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-list"></i> Individual Bets (<?php echo $total_records; ?>)</h2>
                </div>

                <!-- Search Box -->
                <div class="search-box">
                    <form method="GET" class="search-form">
                        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                        <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
                        <input type="hidden" name="date" value="<?php echo $date; ?>">
                        <input type="hidden" name="game_type" value="<?php echo $filter_game_type; ?>">
                        <input type="hidden" name="bet_mode" value="<?php echo $filter_bet_mode; ?>">
                        <input type="hidden" name="records" value="<?php echo $records_per_page; ?>">
                        
                        <input type="text" 
                               name="search" 
                               class="search-input" 
                               placeholder="Search by username, email, status, or numbers..." 
                               value="<?php echo htmlspecialchars($search_term); ?>">
                        
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i> Search
                        </button>
                        
                        <?php if ($search_term): ?>
                            <a href="<?php echo buildUrl(['search' => '', 'page' => 1]); ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (!empty($bets)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Numbers</th>
                                    <th>Amount</th>
                                    <th>Potential Win</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo $bet['username']; ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo $bet['email']; ?></div>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $bet['game_type'])); ?></td>
                                        <td><?php echo ucfirst($bet['bet_mode']); ?></td>
                                        <td style="max-width: 200px; word-break: break-word; font-family: monospace; font-size: 0.85rem;">
                                            <?php 
                                            $numbers = json_decode($bet['numbers_played'], true);
                                            if (is_array($numbers)) {
                                                if (isset($numbers['selected_digits'])) {
                                                    echo "Digits: " . $numbers['selected_digits'];
                                                    if (isset($numbers['pana_combinations'])) {
                                                        echo "<br>Panas: " . implode(', ', $numbers['pana_combinations']);
                                                    }
                                                } else {
                                                    foreach ($numbers as $number => $amount) {
                                                        echo $number . " (₹" . $amount . ")<br>";
                                                    }
                                                }
                                            } else {
                                                echo htmlspecialchars($bet['numbers_played']);
                                            }
                                            ?>
                                        </td>
                                        <td>₹<?php echo number_format($bet['amount'], 2); ?></td>
                                        <td>₹<?php echo number_format($bet['potential_win'], 2); ?></td>
                                        <td>
                                            <span class="status status-<?php echo $bet['status']; ?>">
                                                <?php echo ucfirst($bet['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('h:i A', strtotime($bet['placed_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> - 
                            <?php echo min($page * $records_per_page, $total_records); ?> of <?php echo number_format($total_records); ?> records
                        </div>
                        
                        <div class="pagination-controls">
                            <!-- Records per page -->
                            <select class="records-select" onchange="updateRecordsPerPage(this.value)">
                                <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10 per page</option>
                                <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20 per page</option>
                                <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50 per page</option>
                                <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100 per page</option>
                            </select>
                            
                            <!-- Previous button -->
                            <a href="<?php echo $page > 1 ? buildUrl(['page' => $page - 1]) : '#'; ?>" 
                               class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                            
                            <!-- Page numbers -->
                            <div class="page-numbers">
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="<?php echo buildUrl(['page' => $i]); ?>" 
                                       class="page-number <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                            
                            <!-- Next button -->
                            <a href="<?php echo $page < $total_pages ? buildUrl(['page' => $page + 1]) : '#'; ?>" 
                               class="page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <p style="text-align: center; padding: 2rem; color: var(--text-muted);">
                        <?php if ($filter_game_type || $filter_bet_mode || $search_term): ?>
                            No bets found matching your filters. <a href="<?php echo buildUrl(['game_type' => '', 'bet_mode' => '', 'search' => '', 'page' => 1]); ?>" style="color: var(--primary);">Clear filters</a> to see all bets.
                        <?php else: ?>
                            No bets placed for this session.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Forward Modal (Only show if game is not closed) -->
    <?php if (!$is_after_close): ?>
    <div id="forwardModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Forward All Bets</h3>
                <button class="close-modal" onclick="closeModal('forwardModal')">&times;</button>
            </div>
            <form method="POST" id="forwardForm">
                <input type="hidden" name="action" value="forward_all_numbers">
                <input type="hidden" name="number_pattern" id="forwardNumberPattern">
                
                <div class="form-group">
                    <label class="form-label">Number Pattern</label>
                    <input type="text" class="form-input" id="forwardNumberDisplay" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Total Amount to Forward</label>
                    <input type="text" class="form-input" id="forwardAmountDisplay" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <input type="text" name="reason" class="form-input" placeholder="Enter reason for forwarding...">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('forwardModal')">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--warning); color: black;">
                        <i class="fas fa-share-alt"></i> Confirm Forward
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Accept Modal -->
    <div id="acceptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Re-accept Forwarded Bets</h3>
                <button class="close-modal" onclick="closeModal('acceptModal')">&times;</button>
            </div>
            <form method="POST" id="acceptForm">
                <input type="hidden" name="action" value="accept_all_numbers">
                <input type="hidden" name="number_pattern" id="acceptNumberPattern">
                
                <div class="form-group">
                    <label class="form-label">Number Pattern</label>
                    <input type="text" class="form-input" id="acceptNumberDisplay" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <input type="text" name="reason" class="form-input" placeholder="Enter reason for re-accepting...">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('acceptModal')">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--success);">
                        <i class="fas fa-handshake"></i> Confirm Re-accept
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function updateRecordsPerPage(records) {
            const url = new URL(window.location.href);
            url.searchParams.set('records', records);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }
        
        <?php if (!$is_after_close): ?>
        function showForwardModal(numberPattern, amount) {
            document.getElementById('forwardNumberPattern').value = numberPattern;
            document.getElementById('forwardNumberDisplay').value = numberPattern;
            document.getElementById('forwardAmountDisplay').value = '₹' + amount.toLocaleString('en-IN');
            document.getElementById('forwardModal').style.display = 'block';
        }
        
        function showAcceptModal(numberPattern) {
            document.getElementById('acceptNumberPattern').value = numberPattern;
            document.getElementById('acceptNumberDisplay').value = numberPattern;
            document.getElementById('acceptModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        <?php endif; ?>

         // Add time restriction warnings
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we're in a time-restricted state
            const openBetsLocked = <?php echo $is_after_open ? 'true' : 'false'; ?>;
            const closeBetsLocked = <?php echo $is_after_close ? 'true' : 'false'; ?>;
            
            if (openBetsLocked || closeBetsLocked) {
                console.log('Time restrictions active:', {
                    openBetsLocked,
                    closeBetsLocked
                });
            }
        });
    </script>
</body>
</html>