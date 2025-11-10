<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("location: login.php");
    exit;
}
// Debug mode detection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== MODE DEBUG ===");
    error_log("POST mode value: " . (isset($_POST['mode']) ? $_POST['mode'] : 'NOT SET'));
    error_log("Game type: " . $game_type);
    error_log("=== END DEBUG ===");
}
// MOVE THE MESSAGE RETRIEVAL AND CLEARING TO HERE - AFTER ALL BET PROCESSING
// Get messages from session and then clear them
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';

// Clear the messages from session
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

$game_type = isset($_GET['type']) ? $_GET['type'] : 'single_ank';
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Add this before your HTML to see POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST Data: " . print_r($_POST, true));
    error_log("GET Data: " . print_r($_GET, true));
}

// Update the page title based on game type
$page_titles = [
    'single_ank' => 'Single Ank',
    'jodi' => 'Jodi',
    'single_patti' => 'Single Patti',
    'double_patti' => 'Double Patti',
    'triple_patti' => 'Triple Patti',
    'sp_motor' => 'SP Motor',
    'dp_motor' => 'DP Motor',
     'sp_game' => 'SP Game', 
     'dp_game' => 'DP Game',
    'sp_set' => 'SP Set' ,
     'dp_set' => 'DP Set',
     'tp_set' => 'TP Set',
    'common' => 'Common',
    'series' => 'Series' ,
    'rown' => 'Rown',
    'eki' => 'Eki',      // ADD THIS
    'bkki' => 'Bkki',
    'abr_cut' => 'Abr-Cut'  
];

$page_title = isset($page_titles[$game_type]) ? $page_titles[$game_type] : 'Single Ank';

// Initialize user data
$is_logged_in = true;
$user_id = $_SESSION['user_id'];

// Fetch user balance and username from database
$user = getUserData($user_id);
$username = $user['username'];
$user_balance = $user['balance'];
$balance = $user_balance;

// Get game types for dropdown
$game_types = getGameTypes();

// Get game type ID based on the current game type - IMPROVED VERSION
$game_type_id = 1; // Default to single ank
$game_type_upper = strtoupper($game_type);

foreach ($game_types as $type) {
    // Try exact match first, then try to match with underscores
    $type_code_upper = strtoupper($type['code']);
    $type_code_normalized = strtoupper(str_replace('_', '', $type['code']));
    $game_type_normalized = strtoupper(str_replace('_', '', $game_type));
    
    if ($type_code_upper === $game_type_upper || 
        $type_code_normalized === $game_type_normalized) {
        $game_type_id = $type['id'];
        error_log("DEBUG: Matched game type '$game_type' to ID: $game_type_id with code: " . $type['code']);
        break;
    }
}

// Special case mappings for inconsistent naming
if ($game_type_id === 1) {
    $special_mappings = [
        'single_ank' => 'SINGLE_ANK',
        'jodi' => 'JODI', 
        'single_patti' => 'SINGLE_PATTI',
        'double_patti' => 'DOUBLE_PATTI',
        'triple_patti' => 'TRIPLE_PATTI',
        'sp_motor' => 'SP_MOTOR',
        'dp_motor' => 'DP_MOTOR',
        'sp_game' => 'SP',
        'dp_game' => 'DP',
        'sp_set' => 'SP_SET',
        'dp_set' => 'DP_SET',
        'tp_set' => 'TP_SET',
        'common' => 'COMMON',
        'series' => 'SERIES',
        'rown' => 'ROWN',
    'eki' => 'EKI',      // ADD THIS
    'bkki' => 'BKKI'    
    ];
    
    if (isset($special_mappings[$game_type])) {
        foreach ($game_types as $type) {
            if (strtoupper($type['code']) === $special_mappings[$game_type]) {
                $game_type_id = $type['id'];
                error_log("DEBUG: Special mapping - '$game_type' to ID: $game_type_id");
                break;
            }
        }
    }
}

error_log("Final game_type_id for '$game_type': $game_type_id");

// ANK 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_single_ank_bet'])) {
    $selected_digit = isset($_POST['selected_digit']) ? $_POST['selected_digit'] : '';
    $single_ank_outcomes_json = isset($_POST['single_ank_outcomes']) ? $_POST['single_ank_outcomes'] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'hello';
    
    $active_outcomes = json_decode($single_ank_outcomes_json, true);
    
    if (empty($selected_digit)) {
        $_SESSION['error_message'] = "Please select a digit";
    } elseif (!in_array($selected_digit, ['0','1','2','3','4','5','6','7','8','9'])) {
        $_SESSION['error_message'] = "Please select a valid digit (0-9)";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "Please add at least one bet to the table";
    } else {
        $total_bet_amount = 0;
        foreach ($active_outcomes as $outcome) {
            $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
            $total_bet_amount += $outcome_amount;
        }

        if (!is_numeric($total_bet_amount) || $total_bet_amount <= 0) {
            $_SESSION['error_message'] = "Invalid total bet amount calculated";
            header("Location: " . $_SERVER['PHP_SELF'] . "?type=" . urlencode($game_type));
            exit();
        }

        error_log("Single Ank Bet - Outcomes: " . count($active_outcomes) . ", Total: $total_bet_amount");
                
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Single Ank Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($active_outcomes as $outcome) {
                    $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                    $outcome_digit = isset($outcome['digit']) ? $outcome['digit'] : '';
                    
                    if ($outcome_amount <= 0 || empty($outcome_digit)) {
                        $failed_bets++;
                        error_log("Invalid outcome data: " . json_encode($outcome));
                        continue;
                    }
                    
                    $individual_bet_data = [
                        'selected_digit' => $selected_digit,
                        'single_ank_outcomes' => [$outcome],
                        'amount_per_outcome' => $outcome_amount,
                        'total_amount' => $outcome_amount,
                        'game_type' => 'single_ank',
                        'outcome_digit' => $outcome_digit
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $outcome_amount, $individual_bet_data, $bet_mode, 'single_ank');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Single Ank bet recorded - Digit: $outcome_digit, Amount: $outcome_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record single ank bet for outcome: " . $outcome_digit . " with amount: " . $outcome_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Single Ank bet placed successfully! " . 
                        $successful_bets . " individual bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = 0;
                        foreach ($active_outcomes as $index => $outcome) {
                            if ($index >= $successful_bets) {
                                $failed_amount += isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                            }
                        }
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, 'Refund for failed single ank bets');
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All single ank bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, 'All single ank bet recordings failed');
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for Single Ank bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
  $redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// JODI - INDIVIDUAL OUTCOMES - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_jodi_bet'])) {
    $selected_digit1 = isset($_POST['selected_digit1']) ? $_POST['selected_digit1'] : '';
    $selected_digit2 = isset($_POST['selected_digit2']) ? $_POST['selected_digit2'] : '';
    $jodi_outcomes_json = isset($_POST['jodi_outcomes']) ? $_POST['jodi_outcomes'] : '[]';
  $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';    
    $active_outcomes = json_decode($jodi_outcomes_json, true);
    
    if (empty($selected_digit1) || empty($selected_digit2)) {
        $_SESSION['error_message'] = "Please select both digits";
    } elseif (!in_array($selected_digit1, ['0','1','2','3','4','5','6','7','8','9']) || 
               !in_array($selected_digit2, ['0','1','2','3','4','5','6','7','8','9'])) {
        $_SESSION['error_message'] = "Please select valid digits (0-9)";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "Please add at least one bet to the table";
    } else {
        // FIX: Calculate total by summing individual outcome amounts
        $total_bet_amount = 0;
        foreach ($active_outcomes as $outcome) {
            $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
            $total_bet_amount += $outcome_amount;
        }

        if (!is_numeric($total_bet_amount) || $total_bet_amount <= 0) {
            $_SESSION['error_message'] = "Invalid total bet amount calculated";
            header("Location: " . $_SERVER['PHP_SELF'] . "?type=" . urlencode($game_type));
            exit();
        }

        error_log("Jodi Bet - Outcomes: " . count($active_outcomes) . ", Total: $total_bet_amount");
                
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Jodi Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($active_outcomes as $outcome) {
                    $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                    $outcome_jodi = isset($outcome['jodi']) ? $outcome['jodi'] : '';
                    
                    if ($outcome_amount <= 0 || empty($outcome_jodi)) {
                        $failed_bets++;
                        error_log("Invalid outcome data: " . json_encode($outcome));
                        continue;
                    }
                    
                    $individual_bet_data = [
                        'selected_digit1' => $selected_digit1,
                        'selected_digit2' => $selected_digit2,
                        'jodi_outcomes' => [$outcome],
                        'amount_per_outcome' => $outcome_amount,
                        'total_amount' => $outcome_amount,
                        'game_type' => 'jodi',
                        'outcome_jodi' => $outcome_jodi
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $outcome_amount, $individual_bet_data, $bet_mode, 'jodi');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Jodi bet recorded - Jodi: $outcome_jodi, Amount: $outcome_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record jodi bet for outcome: " . $outcome_jodi . " with amount: " . $outcome_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Jodi bet placed successfully! " . 
                        $successful_bets . " individual bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = 0;
                        foreach ($active_outcomes as $index => $outcome) {
                            if ($index >= $successful_bets) {
                                $failed_amount += isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                            }
                        }
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, 'Refund for failed jodi bets');
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All jodi bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, 'All jodi bet recordings failed');
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for Jodi bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
   // Replace the existing redirect in Jodi section  
$redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// PATTI GAMES (Single, Double, Triple) - INDIVIDUAL OUTCOMES - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_patti_bet'])) {
    $patti_type = isset($_POST['patti_type']) ? $_POST['patti_type'] : '';
    $selected_digits = isset($_POST['selected_digits']) ? $_POST['selected_digits'] : '';
    $patti_outcomes_json = isset($_POST['patti_outcomes']) ? $_POST['patti_outcomes'] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    $active_outcomes = json_decode($patti_outcomes_json, true);
    
    if (empty($patti_type)) {
        $_SESSION['error_message'] = "Please select a patti type";
    } elseif (empty($selected_digits)) {
        $_SESSION['error_message'] = "Please select digits";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "Please add at least one bet to the table";
    } else {
        // FIX: Calculate total by summing individual outcome amounts
        $total_bet_amount = 0;
        foreach ($active_outcomes as $outcome) {
            $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
            $total_bet_amount += $outcome_amount;
        }

        if (!is_numeric($total_bet_amount) || $total_bet_amount <= 0) {
            $_SESSION['error_message'] = "Invalid total bet amount calculated";
            header("Location: " . $_SERVER['PHP_SELF'] . "?type=" . urlencode($game_type));
            exit();
        }

        error_log("Patti Bet - Type: $patti_type, Outcomes: " . count($active_outcomes) . ", Total: $total_bet_amount");
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, ucfirst($patti_type) . ' Patti Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($active_outcomes as $outcome) {
                    $outcome_amount = isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                    $outcome_patti = isset($outcome['patti']) ? $outcome['patti'] : '';
                    $outcome_type = isset($outcome['type']) ? $outcome['type'] : '';
                    
                    if ($outcome_amount <= 0 || empty($outcome_patti)) {
                        $failed_bets++;
                        error_log("Invalid outcome data: " . json_encode($outcome));
                        continue;
                    }
                    
                    $individual_bet_data = [
                        'patti_type' => $patti_type,
                        'selected_digits' => $selected_digits,
                        'patti_outcomes' => [$outcome],
                        'amount_per_outcome' => $outcome_amount,
                        'total_amount' => $outcome_amount,
                        'game_type' => $patti_type . '_patti',
                        'outcome_patti' => $outcome_patti,
                        'outcome_type' => $outcome_type
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $outcome_amount, $individual_bet_data, $bet_mode, $patti_type . '_patti');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Patti bet recorded - Patti: $outcome_patti, Type: $outcome_type, Amount: $outcome_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record patti bet for outcome: " . $outcome_patti . " with amount: " . $outcome_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = ucfirst($patti_type) . " Patti bet placed successfully! " . 
                        $successful_bets . " individual bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = 0;
                        foreach ($active_outcomes as $index => $outcome) {
                            if ($index >= $successful_bets) {
                                $failed_amount += isset($outcome['amount']) ? (float)$outcome['amount'] : 0;
                            }
                        }
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, 'Refund for failed patti bets');
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All patti bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, 'All patti bet recordings failed');
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for Patti bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
// Replace the existing redirect in Patti Games section
$redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// MOTOR GAMES (SP Motor & DP Motor) - INDIVIDUAL OUTCOMES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['place_sp_motor_bet']) || isset($_POST['place_dp_motor_bet']))) {
    $is_sp_motor = isset($_POST['place_sp_motor_bet']);
    $game_type_str = $is_sp_motor ? 'sp_motor' : 'dp_motor';
    $game_name = $is_sp_motor ? 'SP Motor' : 'DP Motor';
    
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $selected_digits = isset($_POST['selected_digits']) ? $_POST['selected_digits'] : '';
    $pana_combinations_json = isset($_POST['pana_combinations']) ? $_POST['pana_combinations'] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    $active_panas = json_decode($pana_combinations_json, true);
    
    // Common validation for both motor games
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } elseif (empty($selected_digits)) {
        $_SESSION['error_message'] = "Please select digits";
    } elseif (empty($active_panas)) {
        $_SESSION['error_message'] = "Please select at least one pana combination";
    } else {
        // SP Motor specific validation
        if ($is_sp_motor) {
            if (count(array_unique(str_split($selected_digits))) != strlen($selected_digits)) {
                $_SESSION['error_message'] = "All digits must be different for SP Motor";
            }
        }
        // DP Motor specific validation
        else {
            if (!preg_match('/^[0-9]{4,9}$/', $selected_digits)) {
                $_SESSION['error_message'] = "Please enter 4-9 valid digits (0-9) for DP Motor";
            }
        }
        
        if (!isset($_SESSION['error_message'])) {
            $bet_amount = (float)$raw_bet_amount;
            $total_bet_amount = $bet_amount * count($active_panas);
            
            if ($total_bet_amount < 5) {
                $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
            }
            elseif ($balance >= $total_bet_amount) {
                if (deductFromWallet($user_id, $total_bet_amount, $game_name . ' Bet placed')) {
                    $successful_bets = 0;
                    $failed_bets = 0;
                    
                    foreach ($active_panas as $pana) {
                        $individual_bet_data = [
                            'selected_digits' => $selected_digits,
                            'pana_combinations' => [$pana],
                            'amount_per_pana' => $bet_amount,
                            'total_amount' => $bet_amount,
                            'game_type' => $game_type_str,
                            'outcome_pana' => $pana
                        ];
                        
                        $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, $game_type_str);
                        
                        if ($bet_id) {
                            $successful_bets++;
                            error_log("$game_name bet recorded - Pana: $pana, Amount: $bet_amount, Bet ID: $bet_id");
                        } else {
                            $failed_bets++;
                            error_log("Failed to record $game_name bet for pana: " . $pana . " with amount: " . $bet_amount);
                        }
                    }
                    
                    if ($successful_bets > 0) {
                        $_SESSION['success_message'] = "$game_name bet placed successfully! " . 
                            $successful_bets . " individual pana bets recorded. INR " . 
                            number_format($total_bet_amount, 2) . " deducted from your wallet.";
                        
                        if ($failed_bets > 0) {
                            $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                            $failed_amount = $bet_amount * $failed_bets;
                            if ($failed_amount > 0) {
                                refundBetAmount($user_id, $failed_amount, "Refund for failed $game_name bets");
                            }
                        }
                        
                        $user = getUserData($user_id);
                        $balance = $user['balance'];
                        $user_balance = $balance;
                    } else {
                        $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                        error_log("All $game_name bet recordings failed for user $user_id");
                        refundBetAmount($user_id, $total_bet_amount, "All $game_name bet recordings failed");
                    }
                } else {
                    $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                }
            } else {
                $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                    number_format($total_bet_amount, 2) . " but only have INR " . 
                    number_format($balance, 2) . " in your account.";
            }
        }
    }
    
   // Replace the existing redirect in Motor Games section
$redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// SP & DP GAMES - INDIVIDUAL OUTCOMES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['place_sp_game_bet']) || isset($_POST['place_dp_game_bet']))) {
    $is_sp_game = isset($_POST['place_sp_game_bet']);
    $game_type_str = $is_sp_game ? 'sp_game' : 'dp_game';
    $game_name = $is_sp_game ? 'SP Game' : 'DP Game';
    $outcomes_field = $is_sp_game ? 'sp_outcomes' : 'dp_outcomes';
    
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $selected_digit = isset($_POST['selected_digit']) ? $_POST['selected_digit'] : '';
    $outcomes_json = isset($_POST[$outcomes_field]) ? $_POST[$outcomes_field] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    $active_outcomes = json_decode($outcomes_json, true);
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } elseif (empty($selected_digit)) {
        $_SESSION['error_message'] = "Please select a digit";
    } elseif (!in_array($selected_digit, ['0','1','2','3','4','5','6','7','8','9'])) {
        $_SESSION['error_message'] = "Please select a valid digit (0-9)";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "Please select at least one outcome";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($active_outcomes);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, $game_name . ' Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($active_outcomes as $outcome) {
                    $individual_bet_data = [
                        'selected_digit' => $selected_digit,
                        $outcomes_field => [$outcome],
                        'amount_per_outcome' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => $game_type_str,
                        'outcome_value' => $outcome
                    ];
                    
                    $record_game_type = $is_sp_game ? 'sp' : 'dp_game';
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, $record_game_type);
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("$game_name bet recorded - Outcome: $outcome, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record $game_name bet for outcome: " . $outcome . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "$game_name bet placed successfully! " . 
                        $successful_bets . " individual outcomes recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed $game_name bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All $game_name bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All $game_name bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
  // Replace the existing redirect in SP & DP Games section
$redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// SET GAMES (SP Set, DP Set, TP Set) - INDIVIDUAL OUTCOMES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['place_sp_set_bet']) || isset($_POST['place_dp_set_bet']) || isset($_POST['place_tp_set_bet']))) {
    $is_sp_set = isset($_POST['place_sp_set_bet']);
    $is_dp_set = isset($_POST['place_dp_set_bet']);
    $is_tp_set = isset($_POST['place_tp_set_bet']);
    
    $game_type_str = $is_sp_set ? 'sp_set' : ($is_dp_set ? 'dp_set' : 'tp_set');
    $game_name = $is_sp_set ? 'SP Set' : ($is_dp_set ? 'DP Set' : 'TP Set');
    $outcomes_field = $is_sp_set ? 'sp_set_outcomes' : ($is_dp_set ? 'dp_set_outcomes' : 'tp_set_outcomes');
    
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $selected_value = $is_tp_set ? (isset($_POST['selected_digit']) ? $_POST['selected_digit'] : '') : (isset($_POST['selected_digits']) ? $_POST['selected_digits'] : '');
    $outcomes_json = isset($_POST[$outcomes_field]) ? $_POST[$outcomes_field] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    $active_outcomes = json_decode($outcomes_json, true);
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } elseif (empty($selected_value)) {
        $_SESSION['error_message'] = $is_tp_set ? "Please select a digit" : "Please select digits";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "Please select at least one outcome";
    } else {
        // Additional validation for specific set types
        if ($is_sp_set && !preg_match('/^[0-9]{3}$/', $selected_value)) {
            $_SESSION['error_message'] = "Please enter 3 valid digits (0-9) for SP Set";
        } elseif ($is_dp_set && !preg_match('/^[0-9]{3}$/', $selected_value)) {
            $_SESSION['error_message'] = "Please enter 3 valid digits (0-9) for DP Set";
        } elseif ($is_tp_set && !in_array($selected_value, ['0','1','2','3','4','5','6','7','8','9'])) {
            $_SESSION['error_message'] = "Please select a valid digit (0-9) for TP Set";
        }
        
        if (!isset($_SESSION['error_message'])) {
            $bet_amount = (float)$raw_bet_amount;
            $total_bet_amount = $bet_amount * count($active_outcomes);
            
            if ($total_bet_amount < 5) {
                $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
            }
            elseif ($balance >= $total_bet_amount) {
                if (deductFromWallet($user_id, $total_bet_amount, $game_name . ' Bet placed')) {
                    $successful_bets = 0;
                    $failed_bets = 0;
                    
                    foreach ($active_outcomes as $outcome) {
                        if ($is_tp_set) {
                            $individual_bet_data = [
                                'selected_digit' => $selected_value,
                                $outcomes_field => [$outcome],
                                'amount_per_outcome' => $bet_amount,
                                'total_amount' => $bet_amount,
                                'game_type' => $game_type_str,
                                'outcome_value' => $outcome
                            ];
                        } else {
                            $individual_bet_data = [
                                'selected_digits' => $selected_value,
                                $outcomes_field => [$outcome],
                                'amount_per_outcome' => $bet_amount,
                                'total_amount' => $bet_amount,
                                'game_type' => $game_type_str,
                                'outcome_value' => $outcome
                            ];
                        }
                        
                        $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, $game_type_str);
                        
                        if ($bet_id) {
                            $successful_bets++;
                            error_log("$game_name bet recorded - Outcome: $outcome, Amount: $bet_amount, Bet ID: $bet_id");
                        } else {
                            $failed_bets++;
                            error_log("Failed to record $game_name bet for outcome: " . $outcome . " with amount: " . $bet_amount);
                        }
                    }
                    
                    if ($successful_bets > 0) {
                        $_SESSION['success_message'] = "$game_name bet placed successfully! " . 
                            $successful_bets . " individual outcomes recorded. INR " . 
                            number_format($total_bet_amount, 2) . " deducted from your wallet.";
                        
                        if ($failed_bets > 0) {
                            $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                            $failed_amount = $bet_amount * $failed_bets;
                            if ($failed_amount > 0) {
                                refundBetAmount($user_id, $failed_amount, "Refund for failed $game_name bets");
                            }
                        }
                        
                        $user = getUserData($user_id);
                        $balance = $user['balance'];
                        $user_balance = $balance;
                    } else {
                        $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                        error_log("All $game_name bet recordings failed for user $user_id");
                        refundBetAmount($user_id, $total_bet_amount, "All $game_name bet recordings failed");
                    }
                } else {
                    $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                    error_log("Wallet deduction failed for $game_name bet - user $user_id");
                }
            } else {
                $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                    number_format($total_bet_amount, 2) . " but only have INR " . 
                    number_format($balance, 2) . " in your account.";
            }
        }
    }
    
// Replace the existing redirect in SP & DP Games section
$redirect_params = $_GET;
$redirect_params['type'] = $game_type;
$redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
header("Location: " . $redirect_url);
exit();
}

// ENHANCED COMMON GAME WITH SP/DP/SPDPT OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_common_bet'])) {
    $common_type = isset($_POST['common_type']) ? $_POST['common_type'] : 'spdpt';
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $selected_digit = isset($_POST['selected_digit']) ? $_POST['selected_digit'] : '';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    // Define pannas for each type
    $sp_pannas = [
        // 36 Single Patti pannas
        '123', '124', '125', '126', '127', '128', '129', '134', '135', '136',
        '137', '138', '139', '145', '146', '147', '148', '149', '156', '157',
        '158', '159', '167', '168', '169', '178', '179', '189', '234', '235',
        '236', '237', '238', '239', '245', '246', '247', '248', '249', '256',
        '257', '258', '259', '267', '268', '269', '278', '279', '289', '345',
        '346', '347', '348', '349', '356', '357', '358', '359', '367', '368',
        '369', '378', '379', '389', '456', '457', '458', '459', '467', '468',
        '469', '478', '479', '489', '567', '568', '569', '578', '579', '589',
        '678', '679', '689', '789'
    ];

    $dp_pannas = [
        // 18 Double Patti pannas
        '112', '113', '114', '115', '116', '117', '118', '119',
        '122', '133', '144', '155', '166', '177', '188', '199',
        '223', '224', '225', '226', '227', '228', '229', '233',
        '244', '255', '266', '277', '288', '299', '334', '335',
        '336', '337', '338', '339', '344', '355', '366', '377',
        '388', '399', '445', '446', '447', '448', '449', '455',
        '466', '477', '488', '499', '556', '557', '558', '559',
        '566', '577', '588', '599', '667', '668', '669', '677',
        '688', '699', '778', '779', '788', '799', '889', '899'
    ];

    $spdpt_pannas = [
        // Your existing 55 SPDPT pannas
        '127', '136', '145', '190', '235', '280', '370', '479', '460', '569',
        '389', '578', '128', '137', '146', '236', '245', '290', '380', '470',
        '489', '560', '678', '579', '129', '138', '147', '156', '237', '246',
        '345', '390', '480', '570', '589', '679', '120', '139', '148', '157',
        '238', '247', '256', '346', '490', '580', '670', '689', '130', '149',
        '158', '167', '239', '248', '257', '347', '356', '590', '680', '789'
    ];
    
    // Select pannas based on common type
    switch($common_type) {
        case 'sp':
            $pannas = $sp_pannas;
            $type_name = 'SP';
            break;
        case 'dp':
            $pannas = $dp_pannas;
            $type_name = 'DP';
            break;
        case 'spdpt':
        default:
            $pannas = $spdpt_pannas;
            $type_name = 'SPDPT';
            break;
    }
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } elseif (empty($selected_digit)) {
        $_SESSION['error_message'] = "Please select a digit";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($pannas);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Common ' . $type_name . ' Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($pannas as $panna) {
                    $individual_bet_data = [
                        'selected_digit' => $selected_digit,
                        'common_type' => $common_type,
                        'common_outcomes' => [$panna],
                        'amount_per_panna' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => 'common',
                        'outcome_panna' => $panna,
                        'type_name' => $type_name
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, 'common');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Common $type_name bet recorded - Panna: $panna, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record Common $type_name bet for panna: " . $panna . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Common $type_name bet placed successfully! " . 
                        $successful_bets . " panna bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed Common $type_name bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All Common $type_name bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All Common $type_name bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for Common $type_name bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
    $redirect_params = $_GET;
    $redirect_params['type'] = $game_type;
    $redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
    header("Location: " . $redirect_url);
    exit();
}
// ROWN GAME - SIMPLIFIED (Fixed 10 pannas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_rown_bet'])) {
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    // Fixed Rown panna combinations
    $rown_pannas = ['123', '234', '345', '456', '567', '678', '789', '890', '901', '012'];
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($rown_pannas);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Rown Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($rown_pannas as $panna) {
                    $individual_bet_data = [
                        'rown_pannas' => [$panna],
                        'amount_per_panna' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => 'rown',
                        'outcome_panna' => $panna
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, 'rown');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Rown bet recorded - Panna: $panna, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record rown bet for panna: " . $panna . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Rown bet placed successfully! " . 
                        $successful_bets . " panna bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed rown bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All rown bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All rown bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for rown bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
    $redirect_params = $_GET;
    $redirect_params['type'] = $game_type;
    $redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
    header("Location: " . $redirect_url);
    exit();
}
// ABR-CUT GAME - CORRECT 90 PANNAS (with proper 0=10 formatting)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_abr_cut_bet'])) {
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $abr_cut_outcomes_json = isset($_POST['abr_cut_outcomes']) ? $_POST['abr_cut_outcomes'] : '[]';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    $active_outcomes = json_decode($abr_cut_outcomes_json, true);
    
    // CORRECT 90 Abr-Cut pannas (with proper 0=10 formatting)
    $correct_abr_cut_pannas = [
        '127', '136', '145', '190', '235', '280', '370', '479', '460', '569',
        '389', '578', '128', '137', '146', '236', '245', '290', '380', '470',
        '489', '560', '678', '579', '129', '138', '147', '156', '237', '246',
        '345', '390', '480', '570', '589', '679', '120', '139', '148', '157',
        '238', '247', '256', '346', '490', '580', '670', '689', '130', '149',
        '158', '167', '239', '248', '257', '347', '356', '590', '680', '789',
        // Additional 30 pannas to make it 90
        '123', '124', '125', '126', '134', '135', '234', '238', '239', '245',
        '246', '247', '248', '249', '256', '257', '258', '259', '267', '268',
        '269', '278', '279', '289', '348', '349', '358', '359', '367', '368'
    ];
    
    // Validate that we have exactly 90 pannas
    if (count($correct_abr_cut_pannas) !== 90) {
        error_log("ERROR: Abr-Cut pannas count is " . count($correct_abr_cut_pannas) . ", expected 90");
        $_SESSION['error_message'] = "System error: Invalid panna count. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?type=abr_cut");
        exit();
    }
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } elseif (empty($active_outcomes)) {
        $_SESSION['error_message'] = "No panna outcomes found";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($active_outcomes);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Abr-Cut Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($active_outcomes as $outcome) {
                    $individual_bet_data = [
                        'abr_cut_outcomes' => [$outcome],
                        'amount_per_outcome' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => 'abr_cut',
                        'outcome_panna' => $outcome
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, 'abr_cut');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Abr-Cut bet recorded - Panna: $outcome, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record abr-cut bet for panna: " . $outcome . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Abr-Cut bet placed successfully! " . 
                        $successful_bets . " panna bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed abr-cut bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All abr-cut bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All abr-cut bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for abr-cut bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
    $redirect_params = $_GET;
    $redirect_params['type'] = $game_type;
    $redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
    header("Location: " . $redirect_url);
    exit();
}
// EKI GAME - ODD DIGITS - 10 separate panna combinations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_eki_bet'])) {
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    // 10 separate odd digit panna combinations
    $eki_pannas = [
        '137', '579', '139', '359', '157', 
        '179', '379', '159', '135', '357'
    ];
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($eki_pannas);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Eki Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($eki_pannas as $panna) {
                    $individual_bet_data = [
                        'eki_pannas' => [$panna],
                        'amount_per_panna' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => 'eki',
                        'outcome_panna' => $panna
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, 'eki');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Eki bet recorded - Panna: $panna, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record eki bet for panna: " . $panna . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Eki bet placed successfully! " . 
                        $successful_bets . " panna bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed eki bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All eki bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All eki bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for eki bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
    $redirect_params = $_GET;
    $redirect_params['type'] = $game_type;
    $redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
    header("Location: " . $redirect_url);
    exit();
}
// BKKI GAME - EVEN DIGITS - 10 separate panna combinations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_bkki_bet'])) {
    $raw_bet_amount = isset($_POST['bet_amount']) ? trim($_POST['bet_amount']) : '';
    $bet_mode = isset($_POST['mode']) ? $_POST['mode'] : 'open';
    
    // 10 separate even digit panna combinations
    $bkki_pannas = [
        '028', '046', '246', '268', '468', 
        '048', '068', '248', '024', '468'
    ];
    
    if (empty($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a bet amount";
    } elseif (!is_numeric($raw_bet_amount)) {
        $_SESSION['error_message'] = "Please enter a valid numeric amount";
    } elseif ((float)$raw_bet_amount <= 0) {
        $_SESSION['error_message'] = "Bet amount must be greater than zero";
    } else {
        $bet_amount = (float)$raw_bet_amount;
        $total_bet_amount = $bet_amount * count($bkki_pannas);
        
        if ($total_bet_amount < 5) {
            $_SESSION['error_message'] = "Minimum total bet is 5 RPS. Your total bet is only INR " . number_format($total_bet_amount, 2);
        }
        elseif ($balance >= $total_bet_amount) {
            if (deductFromWallet($user_id, $total_bet_amount, 'Bkki Bet placed')) {
                $successful_bets = 0;
                $failed_bets = 0;
                
                foreach ($bkki_pannas as $panna) {
                    $individual_bet_data = [
                        'bkki_pannas' => [$panna],
                        'amount_per_panna' => $bet_amount,
                        'total_amount' => $bet_amount,
                        'game_type' => 'bkki',
                        'outcome_panna' => $panna
                    ];
                    
                    $bet_id = recordBet($user_id, $game_type_id, $bet_amount, $individual_bet_data, $bet_mode, 'bkki');
                    
                    if ($bet_id) {
                        $successful_bets++;
                        error_log("Bkki bet recorded - Panna: $panna, Amount: $bet_amount, Bet ID: $bet_id");
                    } else {
                        $failed_bets++;
                        error_log("Failed to record bkki bet for panna: " . $panna . " with amount: " . $bet_amount);
                    }
                }
                
                if ($successful_bets > 0) {
                    $_SESSION['success_message'] = "Bkki bet placed successfully! " . 
                        $successful_bets . " panna bets recorded. INR " . 
                        number_format($total_bet_amount, 2) . " deducted from your wallet.";
                    
                    if ($failed_bets > 0) {
                        $_SESSION['success_message'] .= " (" . $failed_bets . " bets failed)";
                        $failed_amount = $bet_amount * $failed_bets;
                        if ($failed_amount > 0) {
                            refundBetAmount($user_id, $failed_amount, "Refund for failed bkki bets");
                        }
                    }
                    
                    $user = getUserData($user_id);
                    $balance = $user['balance'];
                    $user_balance = $balance;
                } else {
                    $_SESSION['error_message'] = "Failed to record any of your bets. Please try again.";
                    error_log("All bkki bet recordings failed for user $user_id");
                    refundBetAmount($user_id, $total_bet_amount, "All bkki bet recordings failed");
                }
            } else {
                $_SESSION['error_message'] = "Failed to deduct amount from your wallet. Please try again.";
                error_log("Wallet deduction failed for bkki bet - user $user_id");
            }
        } else {
            $_SESSION['error_message'] = "Insufficient funds! You need INR " . 
                number_format($total_bet_amount, 2) . " but only have INR " . 
                number_format($balance, 2) . " in your account.";
        }
    }
    
    $redirect_params = $_GET;
    $redirect_params['type'] = $game_type;
    $redirect_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($redirect_params);
    header("Location: " . $redirect_url);
    exit();
}
// Function to get user data
function getUserData($user_id) {
    global $conn;
    
    $sql = "SELECT username, balance FROM users WHERE id = ?";
    $user = ['username' => '', 'balance' => 0];
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($user['username'], $user['balance']);
        $stmt->fetch();
        $stmt->close();
    }
    
    return $user;
}

// Function to get game types
function getGameTypes() {
    global $conn;
    
    $game_types = [];
    $sql = "SELECT id, name, code, payout_ratio FROM game_types WHERE status = 'active'";
    
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $game_types[] = $row;
        }
        $result->free();
    }
    
    return $game_types;
}

// Function to deduct from wallet - IMPROVED VERSION
function deductFromWallet($user_id, $amount, $description) {
    global $conn;
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Validate amount
        if (!is_numeric($amount) || $amount <= 0) {
            throw new Exception("Invalid amount: $amount");
        }
        
        // Get current balance with proper locking
        $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($current_balance);
        $stmt->fetch();
        $stmt->close();
        
        // Log current balance and deduction attempt
        error_log("Wallet Deduction - User: $user_id, Current Balance: $current_balance, Deduction Amount: $amount");
        
        if ($current_balance < $amount) {
            $conn->rollback();
            error_log("Insufficient balance - User: $user_id, Balance: $current_balance, Needed: $amount");
            return false;
        }
        
        // Deduct from balance with proper type casting
        $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $deduct_amount = (float)$amount;
        $stmt->bind_param("di", $deduct_amount, $user_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            $conn->rollback();
            $stmt->close();
            error_log("Wallet update failed - User: $user_id, Amount: $amount");
            return false;
        }
        $stmt->close();
        
        // Record transaction with proper amounts
        $type = 'bet';
        $balance_after = $current_balance - $amount;
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
        $stmt->bind_param("isddis", $user_id, $type, $amount, $current_balance, $balance_after, $description);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        
        // Log successful deduction
        error_log("Wallet deduction successful - User: $user_id, Amount: $amount, New Balance: $balance_after");
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error deducting from wallet: " . $e->getMessage());
        return false;
    }
}

// Function to refund bet amount
function refundBetAmount($user_id, $amount, $description) {
    global $conn;
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Get current balance
        $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($current_balance);
        $stmt->fetch();
        $stmt->close();
        
        // Add to balance
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // Record transaction
        $type = 'refund';
        $balance_after = $current_balance + $amount;
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')");
        $stmt->bind_param("isddis", $user_id, $type, $amount, $current_balance, $balance_after, $description);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error refunding amount: " . $e->getMessage());
        return false;
    }
}
// NEW FUNCTION: Get or create game ID based on dynamic matka data
function getOrCreateGameId($game_name, $open_time, $close_time) {
    global $conn;
    
    // First, try to find existing game
    $sql = "SELECT id FROM games WHERE name = ? AND open_time = ? AND close_time = ? LIMIT 1";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sss", $game_name, $open_time, $close_time);
        $stmt->execute();
        $stmt->bind_result($game_id);
        
        if ($stmt->fetch()) {
            $stmt->close();
            error_log("DEBUG: Found existing game ID: $game_id for $game_name");
            return $game_id;
        }
        $stmt->close();
    }
    
    // If not found, create new game
    $sql = "INSERT INTO games (name, open_time, close_time, status, created_at) VALUES (?, ?, ?, 'active', NOW())";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sss", $game_name, $open_time, $close_time);
        if ($stmt->execute()) {
            $new_game_id = $stmt->insert_id;
            $stmt->close();
            error_log("DEBUG: Created new game ID: $new_game_id for $game_name");
            return $new_game_id;
        }
        $stmt->close();
    }
    
    // Fallback to default game ID if creation fails
    error_log("WARNING: Could not find or create game, using default ID 1");
    return 1;
}

// UPDATED FUNCTION: Get active game session for a specific game with dynamic name
function getActiveGameSession($game_id, $game_name = '') {
    global $conn;
    
    $today = date('Y-m-d');
    $sql = "SELECT id FROM game_sessions WHERE game_id = ? AND session_date = ? AND status = 'open' LIMIT 1";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("is", $game_id, $today);
        $stmt->execute();
        $stmt->bind_result($session_id);
        
        if ($stmt->fetch()) {
            $stmt->close();
            error_log("DEBUG: Found active session ID: $session_id for game ID: $game_id");
            return $session_id;
        }
        $stmt->close();
    }
    
    error_log("DEBUG: No active session found for game ID: $game_id, date: $today");
    return false;
}

// UPDATED FUNCTION: Create a new game session with dynamic game data
function createGameSession($game_id, $game_name = '') {
    global $conn;
    
    $session_date = date('Y-m-d');
    $sql = "INSERT INTO game_sessions (game_id, session_date, status, created_at) VALUES (?, ?, 'open', NOW())";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("is", $game_id, $session_date);
        if ($stmt->execute()) {
            $session_id = $stmt->insert_id;
            $stmt->close();
            error_log("DEBUG: Created new session ID: $session_id for game ID: $game_id, date: $session_date");
            return $session_id;
        }
        $stmt->close();
    }
    
    error_log("ERROR: Failed to create game session for game ID: $game_id");
    return false;
}

// NEW FUNCTION: Get game details dynamically
function getGameDetails($game_id) {
    global $conn;
    
    $sql = "SELECT name, open_time, close_time FROM games WHERE id = ?";
    $game_details = ['name' => 'Unknown Game', 'open_time' => '00:00:00', 'close_time' => '23:59:59'];
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $game_id);
        $stmt->execute();
        $stmt->bind_result($game_details['name'], $game_details['open_time'], $game_details['close_time']);
        $stmt->fetch();
        $stmt->close();
    }
    
    return $game_details;
}
// Function to record bet - UPDATED FOR DYNAMIC MATKA DATA
function recordBet($user_id, $game_type_id, $amount, $bet_data, $mode, $game_type = 'single_ank') {
    global $conn;
    
    try {
        // DYNAMIC: Get game data from URL parameters or session
        $game_name = isset($_GET['game']) ? $_GET['game'] : 'Kalyan Matka';
        $open_time = isset($_GET['openTime']) ? $_GET['openTime'] : '15:30:00';
        $close_time = isset($_GET['closeTime']) ? $_GET['closeTime'] : '17:30:00';
        
        // Get or create game ID based on dynamic data
        $game_id = getOrCreateGameId($game_name, $open_time, $close_time);
        
        // Get or create active game session for today with dynamic game
        $game_session_id = getActiveGameSession($game_id, $game_name);
        if (!$game_session_id) {
            // Create a new session if none exists
            $game_session_id = createGameSession($game_id, $game_name);
            if (!$game_session_id) {
                throw new Exception("Could not create or find active game session");
            }
        }
        
        $numbers_played = json_encode($bet_data);
        
        // CRITICAL: Use the passed $game_type_id parameter 
        $potential_win = calculatePotentialWin($game_type_id, $amount, $bet_data);
        
        // Use the correct game_type enum value based on the actual game being played
        $game_type_enum = mapGameTypeToEnum($game_type);
        
        // IMPORTANT: Use the $mode parameter in the SQL query (5th parameter)
        $stmt = $conn->prepare("INSERT INTO bets (user_id, game_session_id, game_type_id, game_type, bet_mode, numbers_played, amount, potential_win, status, game_name, open_time, close_time, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        // Log the bet details for debugging - including the mode
        error_log("Recording bet - User: $user_id, GameTypeID: $game_type_id, Amount: $amount, Mode: $mode, PotentialWin: $potential_win, GameTypeEnum: $game_type_enum, Game: $game_name, Open: $open_time, Close: $close_time");
        
        // Bind parameters correctly - $mode is the 5th parameter
        $stmt->bind_param("iiisssddsss", $user_id, $game_session_id, $game_type_id, $game_type_enum, $mode, $numbers_played, $amount, $potential_win, $game_name, $open_time, $close_time);
        
        if ($stmt->execute()) {
            $bet_id = $stmt->insert_id;
            $stmt->close();
            
            // Log successful bet recording with mode info
            error_log("Bet recorded successfully - ID: $bet_id, User: $user_id, Amount: $amount, Mode: $mode, Game Type ID: $game_type_id, Game Type: $game_type, Potential Win: $potential_win, Game: $game_name");
            
            return $bet_id;
        } else {
            error_log("SQL Error in recordBet: " . $stmt->error);
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        error_log("Error recording bet: " . $e->getMessage());
        return false;
    }
}
// NEW FUNCTION: Map game type string to enum value for database - ENHANCED
function mapGameTypeToEnum($game_type) {
    $mapping = [
        'single_ank' => 'single_ank',
        'jodi' => 'jodi',
        'single_patti' => 'single_patti',
        'double_patti' => 'double_patti',
        'triple_patti' => 'triple_patti',
        'sp_motor' => 'sp_motor',
        'dp_motor' => 'dp_motor',
        'sp' => 'sp',
        'sp_game' => 'sp',
        'dp_game' => 'dp',
        'sp_set' => 'sp_set',
        'dp_set' => 'dp_set',
        'tp_set' => 'tp_set',
        'common' => 'common',
        'series' => 'series',
           'abr_cut' => 'abr_cut',
          'rown' => 'rown',
           'eki' => 'eki',      // ADD THIS
        'bkki' => 'bkki' 
    ];
    
    return isset($mapping[$game_type]) ? $mapping[$game_type] : 'single_ank';
}


// Function to calculate potential win - IMPROVED VERSION
function calculatePotentialWin($game_type_id, $amount, $bet_data) {
    global $conn;
    
    // Get payout ratio from database based on game_type_id
    $payout_ratio = 1.0; // Default fallback
    
    $sql = "SELECT payout_ratio FROM game_types WHERE id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $game_type_id);
        $stmt->execute();
        $stmt->bind_result($db_payout_ratio);
        if ($stmt->fetch()) {
            $payout_ratio = $db_payout_ratio;
            error_log("DEBUG: Found payout ratio $payout_ratio for game_type_id: $game_type_id");
        } else {
            // Try to get by game type code if ID not found
            $game_type_code = isset($bet_data['game_type']) ? $bet_data['game_type'] : 'single_ank';
            error_log("WARNING: No payout ratio found for game_type_id: $game_type_id, trying with code: $game_type_code");
            
            $sql2 = "SELECT payout_ratio FROM game_types WHERE code = ?";
            if ($stmt2 = $conn->prepare($sql2)) {
                $code_to_search = strtoupper($game_type_code);
                $stmt2->bind_param("s", $code_to_search);
                $stmt2->execute();
                $stmt2->bind_result($db_payout_ratio2);
                if ($stmt2->fetch()) {
                    $payout_ratio = $db_payout_ratio2;
                    error_log("DEBUG: Found payout ratio $payout_ratio for game_type_code: $code_to_search");
                }
                $stmt2->close();
            }
        }
        $stmt->close();
    } else {
        error_log("ERROR: Failed to prepare statement for payout ratio lookup");
    }
    
    // Calculate potential win
    $potential_win = $amount * $payout_ratio;
    
    error_log("Potential Win Calculation - Game Type ID: $game_type_id, Amount: $amount, Payout Ratio: $payout_ratio, Potential Win: $potential_win");
    
    return $potential_win;
}

include 'includes/header.php';          

?>


<link rel="stylesheet" href="style.css">

    <!-- Main Content -->
    <main>
<section class="game-info-header">
    <div class="game-header-content">
               <?php
                $game_name = isset($_GET['game']) ? htmlspecialchars($_GET['game']) : 'Kalyan Matka';
                $open_time = isset($_GET['openTime']) ? htmlspecialchars($_GET['openTime']) : '15:30:00';
                $close_time = isset($_GET['closeTime']) ? htmlspecialchars($_GET['closeTime']) : '17:30:00';
                ?>
                
                <a href="index.php?game=<?php echo urlencode($game_name); ?>&openTime=<?php echo urlencode($open_time); ?>&closeTime=<?php echo urlencode($close_time); ?>" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Games
                </a>
                <div class="game-details">
                    <h2><?php echo $game_name  ?></h2>
                    <p>Time: <?php echo $open_time; ?> IST to <?php echo $close_time; ?> IST</p>
                </div>
        <div class="game-timers">
            <div class="timer-box">
                <div class="timer-label">Open In</div>
                <div class="timer-value" id="open-timer-single">00:00:00</div>
            </div>
            <div class="timer-box">
                <div class="timer-label">Close In</div>
                <div class="timer-value" id="close-timer-single">00:00:00</div>
            </div>
        </div>
    </div>
</section>

  <div class="betting-container">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?php echo $error_message; ?></div>
    <?php endif; ?>
    
<!-- Updated Betting Header - FIXED -->
<div class="betting-header">
    <div class="header-left">
        <!-- SHOW MODE TOGGLE FOR ALL GAMES EXCEPT JODI -->
        <?php if ($game_type !== 'jodi'): ?>
        <div class="control-group">
            <div class="control-label">Open / Close</div>
            <div class="open-close-toggle">
                <div class="toggle-option active" id="open-toggle">Open</div>
                <div class="toggle-option" id="close-toggle">Close</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="header-center">
        <h2 class="betting-title"><?php echo $page_title; ?></h2>
        <div class="betting-date" id="current-date"><?php echo date('d-m-Y'); ?></div>
    </div>
    
    <div class="header-right">
        <div class="control-group">
            <div class="control-label">Game Type</div>
            <select class="bet-type-select" id="bet-type" name="bet_type">
                <?php foreach ($game_types as $type): ?>
                    <option value="<?php echo $type['id']; ?>" 
                            data-code="<?php echo $type['code']; ?>"
                            <?php echo $type['code'] === $game_type ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
            
      
    
            

<div class="betting-grid <?php echo $game_type . '-grid'; ?>" id="numbers-grid">


<?php
    if ($game_type === 'jodi') {
        // Display 00-99 for Jodi
        for ($i = 0; $i < 100; $i++) {
            echo '<div class="bet-number" data-number="' . sprintf('%02d', $i) . '">' . sprintf('%02d', $i) . '</div>';
        }
    } elseif ($game_type === 'single_patti') {
        // Display 000-999 for Single Patti
        for ($i = 0; $i < 1000; $i++) {
            echo '<div class="bet-number" data-number="' . sprintf('%03d', $i) . '">' . sprintf('%03d', $i) . '</div>';
        }
    } elseif ($game_type === 'double_patti') {
        // Define double patti numbers (example - you'll need to define your actual double patti combinations)
      $double_patti_numbers = [
    // 100 series
    '100', '112', '113', '114', '115', '116', '117', '118', '119', '122',
    '133', '144', '155', '166', '177', '188', '199',
    // 200 series
    '200', '223', '224', '225', '226', '227', '228', '229', '233', '244',
    '255', '266', '277', '288', '299',
    // 300 series
    '300', '334', '335', '336', '337', '338', '339', '344', '355', '366',
    '377', '388', '399',
    // 400 series
    '400', '445', '446', '447', '448', '449', '455', '466', '477', '488',
    '499',
    // 500 series
    '500', '556', '557', '558', '559', '566', '577', '588', '599',
    // 600 series
    '600', '667', '668', '669', '677', '688', '699',
    // 700 series
    '700', '778', '779', '788', '799',
    // 800 series
    '800', '889', '899',
    // 900 series
    '900', '999'
  ];
            // Add all your double patti combinations here
        
        
        foreach ($double_patti_numbers as $number) {
            echo '<div class="bet-number" data-number="' . $number . '">' . $number . '</div>';
        }
    } elseif ($game_type === 'triple_patti') {
        // Display triple patti numbers (all same digits)
        for ($i = 0; $i < 10; $i++) {
            $number = str_repeat($i, 3);
            echo '<div class="bet-number" data-number="' . $number . '">' . $number . '</div>';
        }
    } else {
        // Display 0-9 for Single Ank and other games
        // for ($i = 0; $i < 10; $i++) {
        //     echo '<div class="bet-number" data-number="' . $i . '">' . $i . '</div>';
        // }
    }
    ?>
</div>  


<!-- Single Ank Interface -->
<div class="single-ank-interface" style="<?php echo $game_type === 'single_ank' ? 'display: block;' : 'display: none;'; ?>">
    <div class="single-ank-container">
        
        
        <div class="set-info">
            <p>Select digit, enter amount and add bets to the table</p>
        </div>
        
        <div class="single-ank-controls">
            <div class="control-group">
                <label for="single-ank-digit">Select Digit (0-9):</label>
                <select id="single-ank-digit">
                    <option value="">Select a digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="single-ank-amount">Bet Amount:</label>
                <input type="number" id="single-ank-amount" min="1" value="1">
            </div>
            
            <button type="button" class="action-btn add-bet-btn" id="add-single-ank-bet">Add Bet</button>
        </div>
        
        <div class="bets-table-container">
            <table class="bets-table">
                <thead>
                    <tr>
                        <th>Digit</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="single-ank-bets-tbody">
                    <!-- Bets will be added here dynamically -->
                </tbody>
            </table>
        </div>
        
        <div class="single-ank-summary">
            <p>Total Bets: <span id="single-ank-bets-count">0</span></p>
            <p>Total Bet Amount: <span id="single-ank-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="single-ank-form">
            <input type="hidden" name="selected_digit" id="form-single-ank-digit">
            <input type="hidden" name="single_ank_outcomes" id="form-single-ank-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-single-ank-bet-amount">
            <input type="hidden" name="mode" id="form-single-ank-mode" value="">
            <input type="hidden" name="place_single_ank_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-single-ank-bet-btn" disabled>
                PLACE SINGLE ANK BET
            </button>
        </form>
    </div>
</div>
<!-- Jodi Interface -->
<!-- Jodi Interface - FIXED VERSION -->
<div class="jodi-interface" style="<?php echo $game_type === 'jodi' ? 'display: block;' : 'display: none;'; ?>">
    <div class="jodi-container">
        
        <div class="set-info">
            <p>Select two digits, enter amount and add bets to the table</p>
        </div>
        
        <!-- ADD MODE TOGGLE FOR JODI -->
        <div class="jodi-mode-controls" style="margin-bottom: 20px;">
            <div class="control-group">
                <div class="control-label">Open / Close</div>
                <div class="open-close-toggle">
                    <div class="toggle-option active" id="jodi-open-toggle">Open</div>
                    <div class="toggle-option" id="jodi-close-toggle">Close</div>
                </div>
            </div>
        </div>
        
        <div class="jodi-controls">
            <div class="control-group">
                <label for="jodi-digit1">First Digit (0-9):</label>
                <select id="jodi-digit1">
                    <option value="">Select first digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="jodi-digit2">Second Digit (0-9):</label>
                <select id="jodi-digit2">
                    <option value="">Select second digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="jodi-amount">Bet Amount:</label>
                <input type="number" id="jodi-amount" min="10" value="1">
            </div>
            
            <button type="button" class="action-btn add-bet-btn" id="add-jodi-bet">Add Bet</button>
        </div>
        
        <div class="bets-table-container">
            <table class="bets-table">
                <thead>
                    <tr>
                        <th>Jodi</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="jodi-bets-tbody">
                    <!-- Bets will be added here dynamically -->
                </tbody>
            </table>
        </div>
        
        <div class="jodi-summary">
            <p>Total Bets: <span id="jodi-bets-count">0</span></p>
            <p>Total Bet Amount: <span id="jodi-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="jodi-form">
            <input type="hidden" name="selected_digit1" id="form-jodi-digit1">
            <input type="hidden" name="selected_digit2" id="form-jodi-digit2">
            <input type="hidden" name="jodi_outcomes" id="form-jodi-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-jodi-bet-amount">
            <input type="hidden" name="mode" id="form-jodi-mode" value="open">
            <input type="hidden" name="place_jodi_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-jodi-bet-btn" disabled>
                PLACE JODI BET
            </button>
        </form>
    </div>
</div>

<!-- Patti Interface for Single, Double, Triple Patti -->
<div class="patti-interface" style="<?php echo in_array($game_type, ['single_patti', 'double_patti', 'triple_patti']) ? 'display: block;' : 'display: none;'; ?>">
    <div class="patti-container">        
        <div class="set-info">
            <p id="patti-instructions">Enter digits and add bets to the table</p>
            <button type="button" class="action-btn show-all-btn" id="show-all-pannas-btn" style="display: none;">
                Show All Pannas
            </button>
        </div>
        
        <div class="patti-controls">
            <div class="control-group" id="single-patti-controls" style="display: none;">
                <label for="single-digits-input">Enter Three Different Digits (0-9):</label>
                <div id="single-patti-validation" class="validation-message" style="color:#bd3e4d"></div>
                <input type="text" id="single-digits-input" maxlength="3" placeholder="e.g., 123" oninput="validateSinglePattiDigits(this)">
            </div>
            
            <div class="control-group" id="double-patti-controls" style="display: none;">
                <label for="double-digits-input">Enter Three Digits for Double Patti:</label>
                <div id="double-patti-validation" class="validation-message" style="color:#bd3e4d"></div>
                <input type="text" id="double-digits-input" maxlength="3" placeholder="e.g., 112, 233, 455" oninput="validateDoublePattiDigits(this)">
            </div>
            
            <div class="control-group" id="triple-patti-controls" style="display: none;">
                <label for="triple-digit-input">Enter One Digit (0-9):</label>
                <div id="triple-patti-validation" class="validation-message"></div>
                <input type="text" id="triple-digit-input" maxlength="1" placeholder="e.g., 3" oninput="validateTriplePattiDigit(this)">
            </div>
            
            <div class="control-group">
                <label for="patti-amount">Bet Amount:</label>
                <input type="number" id="patti-amount" min="1" value="1">
            </div>
            
            <button type="button" class="action-btn add-bet-btn" id="add-patti-bet">Add Bet</button>
        </div>
        
        <div class="bets-table-container">
            <table class="bets-table">
                <thead>
                    <tr>
                        <th>Patti</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="patti-bets-tbody">
                    <!-- Bets will be added here dynamically -->
                </tbody>
            </table>
        </div>
        
        <div class="patti-summary">
            <p>Total Bets: <span id="patti-bets-count">0</span></p>
            <p>Total Bet Amount: <span id="patti-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <!-- FIXED PATTI FORM -->
        <form method="POST" id="patti-form">
            <!-- Add hidden field to store the actual patti type -->
            <input type="hidden" name="patti_type" id="form-patti-type">
            <input type="hidden" name="selected_digits" id="form-patti-digits">
            <input type="hidden" name="patti_outcomes" id="form-patti-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-patti-bet-amount">
            <input type="hidden" name="mode" id="form-patti-mode" value="open">
            <input type="hidden" name="place_patti_bet" value="1">

            <button type="submit" class="action-btn place-bet-btn" id="place-patti-bet-btn" disabled>
                PLACE <?php echo strtoupper(str_replace('_', ' ', $page_title)); ?> BET
            </button>
        </form>
    </div>
</div>
<!-- Single Patti Modal -->
 <!-- Double Patti Modal -->
<div id="single-patti-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>All Single Patti Outcomes (120 Combinations)</h3>
            <span class="close-modal">&times;</span>
        </div>
        <div class="modal-body">
            <div class="panna-grid" id="single-patti-outcomes-grid">
                <!-- Single patti outcomes will be loaded here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="action-btn close-modal-btn">Close</button>
        </div>
    </div>
</div>
<div id="double-patti-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>All Double Patti Outcomes (90 Combinations)</h3>
            <span class="close-modal">&times;</span>
        </div>
        <div class="modal-body">
            <div class="panna-grid" id="double-patti-outcomes-grid">
                <!-- Double patti outcomes will be loaded here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="action-btn close-modal-btn">Close</button>
        </div>
    </div>
</div>

 <!-- SP Motor Interface -->
<div class="sp-motor-interface" style="display: none;">
    <div class="sp-motor-container">

        <div class="sp-motor-controls">
            <div class="control-group">
                <label for="sp-motor-digits">Enter 4-9 Different Digits:</label>
                     <div id="digit-validation" class="validation-message" style="color:#bd3e4d"></div>
                <input type="text" id="sp-motor-digits" maxlength="9" oninput="validateSpMotorDigits(this)" placeholder="e.g., 1234 (4-9 different digits)">
           
             
            </div>
            
            <div class="control-group">
                <label for="sp-motor-amount">Amount per Pana:</label>
                <input type="number" id="sp-motor-amount" min="1" value="1" oninput="updateSpMotorTotal()">
            </div>
        </div>
        
        <div id="pana-combinations-container" class="pana-combinations">
            <div class="no-digits">Please enter 4-9 different digits to generate pana combinations</div>
        </div>
        
        <div class="sp-motor-summary">
            <p>Total Panas: <span id="pana-count">0</span></p>
            <p>Amount per Pana: <span id="amount-per-pana">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="sp-motor-form">
            <input type="hidden" name="selected_digits" id="form-selected-digits">
            <input type="hidden" name="pana_combinations" id="form-pana-combinations" value="[]">
            <input type="hidden" name="bet_amount" id="form-sp-motor-bet-amount">
            <input type="hidden" name="mode" id="form-sp-motor-mode" value="open">
            <input type="hidden" name="place_sp_motor_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-sp-motor-bet-btn" disabled>
                PLACE SP MOTOR BET
            </button>
        </form>
    </div>
</div>

<!-- DP Motor Interface -->
<div class="dp-motor-interface" style="display: none;">
    <div class="dp-motor-container">
            <div id="dp-digit-validation" class="validation-message" style="color:#bd3e4d"></div>
        <div class="dp-motor-controls">
            <div class="control-group">
                <label for="dp-motor-digits">Enter 4-9 Digits (Duplicates Allowed):</label>
                <input type="text" id="dp-motor-digits" maxlength="9" placeholder="e.g., 1234 (4-9 digits)">
            
               
            </div>
            
            <div class="control-group">
                <label for="dp-motor-amount">Amount per Pana:</label>
                <input type="number" id="dp-motor-amount" min="1" value="1">
            </div>
        </div>
        
        <div id="dp-pana-combinations-container" class="pana-combinations">
            <div class="no-digits">Please enter 4-9 digits to generate DP Motor pana combinations</div>
        </div>
        
        <div class="dp-motor-summary">
            <p>Total Panas: <span id="dp-pana-count">0</span></p>
            <p>Amount per Pana: <span id="dp-amount-per-pana">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="dp-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="dp-motor-form">
            <input type="hidden" name="selected_digits" id="form-dp-selected-digits">
            <input type="hidden" name="pana_combinations" id="form-dp-pana-combinations" value="[]">
            <input type="hidden" name="bet_amount" id="form-dp-motor-bet-amount">
            <input type="hidden" name="mode" id="form-dp-motor-mode" value="open">
            <input type="hidden" name="place_dp_motor_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-dp-motor-bet-btn" disabled>
                PLACE DP MOTOR BET
            </button>
        </form>
    </div>
</div>

<!-- SP Game Interface -->
<div class="sp-game-interface" style="display: none;">
    <div class="sp-game-container">
   
        <div class="sp-game-controls">
            <div class="control-group">
                <label for="sp-game-digit">Select One Digit (0-9, 0 means 10):</label>
                <select id="sp-game-digit" onchange="generateSpOutcomes()">
                    <option value="">Select a digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo $i === 0 ? '(10)' : ''; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="sp-game-amount">Amount per SP:</label>
                <input type="number" id="sp-game-amount" min="1" value="1" oninput="updateSpGameTotal()">
            </div>
        </div>
        
        <div id="sp-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please select a digit to generate SP outcomes</div>
        </div>
        
        <div class="sp-game-summary">
            <p>Total SP Outcomes: <span id="sp-outcomes-count">0</span></p>
            <p>Amount per SP: <span id="sp-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="sp-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="sp-game-form">
            <input type="hidden" name="selected_digit" id="form-selected-digit">
            <input type="hidden" name="sp_outcomes" id="form-sp-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-sp-game-bet-amount">
            <input type="hidden" name="mode" id="form-sp-game-mode" value="open">
            <input type="hidden" name="place_sp_game_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-sp-game-bet-btn" disabled>
                PLACE SP GAME BET
            </button>
        </form>
    </div>
</div>

<!-- DP Game Interface -->
<div class="dp-game-interface" style="display: none;">
    <div class="dp-game-container">

        <div class="dp-game-controls">
            <div class="control-group">
                <label for="dp-game-digit">Select One Digit (0-9):</label>
                <select id="dp-game-digit" onchange="generateDpOutcomes()">
                    <option value="">Select a digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="dp-game-amount">Amount per DP:</label>
                <input type="number" id="dp-game-amount" min="1" value="1" oninput="updateDpGameTotal()">
            </div>
        </div>
        
        <div id="dp-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please select a digit to generate DP outcomes</div>
        </div>
        
        <div class="dp-game-summary">
            <p>Total DP Outcomes: <span id="dp-outcomes-count">0</span></p>
            <p>Amount per DP: <span id="dp-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="dp-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="dp-game-form">
            <input type="hidden" name="selected_digit" id="form-dp-selected-digit">
            <input type="hidden" name="dp_outcomes" id="form-dp-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-dp-game-bet-amount">
            <input type="hidden" name="mode" id="form-dp-game-mode" value="open">
            <input type="hidden" name="place_dp_game_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-dp-game-bet-btn" disabled>
                PLACE DP GAME BET
            </button>
        </form>
    </div>
</div>

<!-- SP Set Interface -->
<div class="sp-set-interface" style="display: none;">
    <div class="sp-set-container">
        <h3>SP Set</h3>
        
        <div class="set-info">
            <p>Enter 3 different digits from different sets (0,5 | 1,6 | 2,7 | 3,8 | 4,9)</p>
        </div>
        

        
        <div class="sp-set-controls">
            <div class="control-group">
                <label for="sp-set-digits">Enter 3 Different Digits (from different sets):</label>
                <input type="text" id="sp-set-digits" maxlength="3" oninput="validateSpSetDigits(this)" placeholder="e.g., 123">
              
            </div>
            
            <div class="control-group">
                <label for="sp-set-amount">Amount per Pana:</label>
            <div id="sp-set-validation" class="validation-message" style="color:#bd3e4d"></div>
                <input type="number" id="sp-set-amount" min="1" value="1" oninput="updateSpSetTotal()">
            </div>
        </div>
        
        <div id="sp-set-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please enter 3 valid digits to generate SP Set outcomes</div>
        </div>
        
        <div class="sp-set-summary">
            <p>Total Outcomes: <span id="sp-set-outcomes-count">0</span></p>
            <p>Amount per Outcome: <span id="sp-set-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="sp-set-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="sp-set-form">
            <input type="hidden" name="selected_digits" id="form-sp-set-digits">
            <input type="hidden" name="sp_set_outcomes" id="form-sp-set-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-sp-set-bet-amount">
            <input type="hidden" name="mode" id="form-sp-set-mode" value="open">
            <input type="hidden" name="place_sp_set_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-sp-set-bet-btn" disabled>
                PLACE SP SET BET
            </button>
        </form>
    </div>
</div>
<!-- DP Set Interface HTML - FIXED VERSION -->
<div class="dp-set-interface" style="display: none;">
    <div class="dp-set-container">
        <h3>DP Set</h3>
        
        <div class="set-info">
            <p>Enter 3 different digits from different sets (0,5 | 1,6 | 2,7 | 3,8 | 4,9)</p>
        </div>
          <div class="digit-info-small">
                    Example: 123 (valid - all from different sets), 805 (DP example - 0,5 same set, 8 different set)
                </div>
                       <div id="dp-set-validation" class="validation-message" style="color:#bd3e4d"></div>

        
        <div class="dp-set-controls">
            <div class="control-group">
                <input type="text" id="dp-set-digits" maxlength="3" oninput="validateDpSetDigits(this)" placeholder="e.g., 123">
              
            </div>
            
            <div class="control-group">
                <label for="dp-set-amount">Amount per DP Set:</label>
                <input type="number" id="dp-set-amount" min="1" value="1" oninput="updateDpSetTotal()">
            </div>
        </div>
        
        <div id="dp-set-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please enter 3 valid digits to generate DP Set outcomes</div>
        </div>
        
        <div class="dp-set-summary">
            <p>Total DP Outcomes: <span id="dp-set-outcomes-count">0</span></p>
            <p>Amount per DP: <span id="dp-set-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="dp-set-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="dp-set-form">
            <input type="hidden" name="selected_digits" id="form-dp-set-digits">
            <input type="hidden" name="dp_set_outcomes" id="form-dp-set-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-dp-set-bet-amount">
            <input type="hidden" name="mode" id="form-dp-set-mode" value="open">
            <input type="hidden" name="place_dp_set_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-dp-set-bet-btn" disabled>
                PLACE DP SET BET
            </button>
        </form>
    </div>
</div>
<!-- TP Set Interface -->
<div class="tp-set-interface" style="display: none;">
    <div class="tp-set-container">
        <h3>TP Set</h3>
        
        <div class="set-info">
            <p><strong>TP Set Rule:</strong> Three same digits create 4 outcomes with the digit and its pair</p>
            
        </div>
        
        <div class="tp-set-controls">
            <div class="control-group">
                <label for="tp-set-digit">Select One Digit (0-9):</label>
                <select id="tp-set-digit" onchange="generateTpSetOutcomes()">
                    <option value="">Select a digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
             
             
            </div>
            
            <div class="control-group">
                <label for="tp-set-amount">Amount per TP Set:</label>
                <div id="tp-set-validation" class="validation-message" style="color:#bd3e4d"></div>
                <input type="number" id="tp-set-amount" min="1" value="1" oninput="updateTpSetTotal()">
            </div>
        </div>
        
        <div id="tp-set-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please select a digit to generate TP Set outcomes</div>
        </div>
        
        <div class="tp-set-summary">
            <p>Total TP Outcomes: <span id="tp-set-outcomes-count">0</span></p>
            <p>Amount per TP: <span id="tp-set-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="tp-set-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="tp-set-form">
            <input type="hidden" name="selected_digit" id="form-tp-set-digit">
            <input type="hidden" name="tp_set_outcomes" id="form-tp-set-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-tp-set-bet-amount">
            <input type="hidden" name="mode" id="form-tp-set-mode" value="open">
            <input type="hidden" name="place_tp_set_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-tp-set-bet-btn" disabled>
                PLACE TP SET BET
            </button>
        </form>
    </div>
</div>
<!--  Common -->
<div class="common-interface" style="<?php echo $game_type === 'common' ? 'display: block;' : 'display: none;'; ?>">
    <div class="common-container">
        <h3>Common Game</h3>
        
        <div class="set-info">
            <p>Select digit and choose SP, DP, or SPDPT type</p>
            <p><strong>SP:</strong> 36 Single Patti | <strong>DP:</strong> 18 Double Patti | <strong>SPDPT:</strong> 55 All Pannas</p>
        </div>
        
        <div class="common-controls">
            <div class="control-group">
                <label for="common-digit">Select One Digit (0-9, 0 means 10):</label>
                <select id="common-digit" onchange="generateCommonOutcomes()">
                    <option value="">Select a digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo $i === 0 ? '(10)' : ''; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label for="common-type">Select Common Type:</label>
                <select id="common-type" onchange="generateCommonOutcomes()">
                    <option value="spdpt">SPDPT (55 Pannas)</option>
                    <option value="sp">SP (36 Single Patti)</option>
                    <option value="dp">DP (18 Double Patti)</option>
                </select>
            </div>
            
            <div class="control-group">
                <label for="common-amount">Amount per Pana:</label>
                <input type="number" id="common-amount" min="1" value="1" oninput="updateCommonTotal()">
                <div id="common-validation" class="validation-message" style="color:#bd3e4d"></div>
            </div>
        </div>
        
        <div id="common-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please select a digit and type to generate Common outcomes</div>
        </div>
        
        <div class="common-summary">
            <p>Selected Type: <span id="common-type-display">SPDPT</span></p>
            <p>Total Outcomes: <span id="common-outcomes-count">0</span></p>
            <p>Amount per Outcome: <span id="common-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="common-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="common-form">
            <input type="hidden" name="selected_digit" id="form-common-digit">
            <input type="hidden" name="common_type" id="form-common-type" value="spdpt">
            <input type="hidden" name="common_outcomes" id="form-common-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-common-bet-amount">
            <input type="hidden" name="mode" id="form-common-mode" value="open">
            <input type="hidden" name="place_common_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-common-bet-btn" disabled>
                PLACE COMMON BET
            </button>
        </form>
    </div>
</div>

<!-- Series Game Interface -->
<div class="series-interface" style="display: none;">
    <div class="series-container">
        <h3>Series Game</h3>
        
        <div class="set-info">
            <p>Select two different digits (1-10) - 0 means 10</p>
            <p><strong>Rule:</strong> All ascending panna combinations that include both selected digits</p>
        </div>
        
        <div class="series-controls">
            <div class="control-group">
                <label for="series-digit1">Select First Digit (0-9, 0 means 10):</label>
                <select id="series-digit1" onchange="validateSeriesDigits()">
                    <option value="">Select first digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo $i === 0 ? '(10)' : ''; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="control-group">
                <div id="series-validation" class="validation-message" style="color:#bd3e4d"></div>
                <label for="series-digit2">Select Second Digit (0-9, 0 means 10):</label>
                 
                <select id="series-digit2" onchange="validateSeriesDigits()">
                    <option value="">Select second digit</option>
                    <?php for ($i = 0; $i < 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo $i === 0 ? '(10)' : ''; ?></option>
                    <?php endfor; ?>
                </select>
               
            
            </div>
            
            <div class="control-group">
                <label for="series-amount">Amount per Pana:</label>
                <input type="number" id="series-amount" min="1" value="1" oninput="updateSeriesTotal()">
            </div>
        </div>
        
        <div id="series-outcomes-container" class="pana-combinations">
            <div class="no-digits">Please select two different digits to generate Series outcomes</div>
        </div>
        
        <div class="series-summary">
            <p>Total Outcomes: <span id="series-outcomes-count">0</span></p>
            <p>Amount per Outcome: <span id="series-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="series-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="series-form">
            <input type="hidden" name="selected_digit1" id="form-series-digit1">
            <input type="hidden" name="selected_digit2" id="form-series-digit2">
            <input type="hidden" name="series_outcomes" id="form-series-outcomes" value="[]">
            <input type="hidden" name="bet_amount" id="form-series-bet-amount">
            <input type="hidden" name="mode" id="form-series-mode" value="open">
            <input type="hidden" name="place_series_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-series-bet-btn" disabled>
                PLACE SERIES BET
            </button>
        </form>
    </div>
</div>
<!-- Rown Game Interface -->
<div class="rown-interface" style="<?php echo $game_type === 'rown' ? 'display: block;' : 'display: none;'; ?>">
    <div class="rown-container">
        <h3>Rown Game</h3>
        
        <div class="set-info">
            <p><strong>10 Fixed Consecutive Panna Combinations</strong></p>
            <p>Enter amount per panna to bet on all 10 consecutive pannas</p>
        </div>
        
        <div class="rown-controls">
            <div class="control-group">
                <label for="rown-amount">Amount per Panna:</label>
                <input type="number" id="rown-amount" min="1" value="1" oninput="updateRownTotal()">
                <div id="rown-validation" class="validation-message" style="color:#bd3e4d"></div>
            </div>
        </div>
        
        <!-- Display All Rown Numbers in a Table -->
        <div class="rown-table-container">
            <h4 class="rown-table-title">Rown Panna Combinations</h4>
            <table class="rown-panna-table">
                <thead>
                    <tr>
                        <th>Sr. No.</th>
                        <th>Panna</th>
                        <th>Sum</th>
                        <th>Bet Amount</th>
                    </tr>
                </thead>
                <tbody id="rown-panna-table-body">
                    <tr class="rown-panna-row" data-panna="123">
                        <td class="rown-sr-no">1</td>
                        <td class="rown-panna-value">123</td>
                        <td class="rown-panna-sum">1+2+3=6</td>
                        <td class="rown-amount-display" id="amount-123">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="234">
                        <td class="rown-sr-no">2</td>
                        <td class="rown-panna-value">234</td>
                        <td class="rown-panna-sum">2+3+4=9</td>
                        <td class="rown-amount-display" id="amount-234">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="345">
                        <td class="rown-sr-no">3</td>
                        <td class="rown-panna-value">345</td>
                        <td class="rown-panna-sum">3+4+5=12</td>
                        <td class="rown-amount-display" id="amount-345">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="456">
                        <td class="rown-sr-no">4</td>
                        <td class="rown-panna-value">456</td>
                        <td class="rown-panna-sum">4+5+6=15</td>
                        <td class="rown-amount-display" id="amount-456">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="567">
                        <td class="rown-sr-no">5</td>
                        <td class="rown-panna-value">567</td>
                        <td class="rown-panna-sum">5+6+7=18</td>
                        <td class="rown-amount-display" id="amount-567">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="678">
                        <td class="rown-sr-no">6</td>
                        <td class="rown-panna-value">678</td>
                        <td class="rown-panna-sum">6+7+8=21</td>
                        <td class="rown-amount-display" id="amount-678">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="789">
                        <td class="rown-sr-no">7</td>
                        <td class="rown-panna-value">789</td>
                        <td class="rown-panna-sum">7+8+9=24</td>
                        <td class="rown-amount-display" id="amount-789">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="890">
                        <td class="rown-sr-no">8</td>
                        <td class="rown-panna-value">890</td>
                        <td class="rown-panna-sum">8+9+0=17</td>
                        <td class="rown-amount-display" id="amount-890">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="901">
                        <td class="rown-sr-no">9</td>
                        <td class="rown-panna-value">190</td>
                        <td class="rown-panna-sum">1+9+0=10</td>
                        <td class="rown-amount-display" id="amount-901">-</td>
                    </tr>
                    <tr class="rown-panna-row" data-panna="012">
                        <td class="rown-sr-no">10</td>
                        <td class="rown-panna-value">120</td>
                        <td class="rown-panna-sum">1+2+0=3</td>
                        <td class="rown-amount-display" id="amount-012">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="rown-summary">
            <p>Total Pannas: <span id="rown-outcomes-count">10</span></p>
            <p>Amount per Panna: <span id="rown-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="rown-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="rown-form">
            <input type="hidden" name="bet_amount" id="form-rown-bet-amount">
            <input type="hidden" name="mode" id="form-rown-mode" value="open">
            <input type="hidden" name="place_rown_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-rown-bet-btn" disabled>
                PLACE ROWN BET
            </button>
        </form>
    </div>
</div>
<!-- Abr-Cut Game Interface -->
<div class="abr-cut-interface" style="<?php echo $game_type === 'abr_cut' ? 'display: block;' : 'display: none;'; ?>">
    <div class="abr-cut-container">
        <h3>Abr-Cut Game</h3>
        
        <div class="set-info">
            <p><strong>90 Pannas (Removed 30 Pannas from 120 SP Pannas)</strong></p>
            <p><strong>Removed:</strong> 10 Rown + 10 Eki + 10 Bkki = 30 Pannas</p>
            <p><strong>Note:</strong> Digit 0 means 10 in panna combinations</p>
        </div>
        
        <div class="abr-cut-controls">
            <div class="control-group">
                <label for="abr-cut-amount">Amount per Pana:</label>
                <input type="number" id="abr-cut-amount" min="1" value="1" oninput="updateAbrCutTotal()">
                <div id="abr-cut-validation" class="validation-message" style="color:#bd3e4d"></div>
            </div>
        </div>
        
        <!-- Display Abr-Cut Pannas in Grid Format -->
        <div id="abr-cut-outcomes-container" class="pana-combinations">
            <div class="no-digits">Loading Abr-Cut pannas...</div>
        </div>
        
        <div class="abr-cut-summary">
            <p>Total Pannas: <span id="abr-cut-outcomes-count">90</span></p>
            <p>Amount per Panna: <span id="abr-cut-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="abr-cut-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="abr-cut-form">
            <input type="hidden" name="bet_amount" id="form-abr-cut-bet-amount">
            <input type="hidden" name="abr_cut_outcomes" id="form-abr-cut-outcomes" value="[]">
            <input type="hidden" name="mode" id="form-abr-cut-mode" value="open">
            <input type="hidden" name="place_abr_cut_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-abr-cut-bet-btn" disabled>
                PLACE ABR-CUT BET
            </button>
        </form>
    </div>
</div>
<!-- Eki Game Interface -->
<div class="eki-interface" style="<?php echo $game_type === 'eki' ? 'display: block;' : 'display: none;'; ?>">
    <div class="eki-container">
        <h3>Eki Game</h3>
        
        <div class="set-info">
            <p><strong>10 Odd Digit Panna Combinations</strong></p>
            <p>Enter amount per panna to bet on all 10 odd digit pannas</p>
        </div>
        
        <div class="eki-controls">
            <div class="control-group">
                <label for="eki-amount">Amount per Panna:</label>
                <input type="number" id="eki-amount" min="1" value="1" oninput="updateEkiTotal()">
                <div id="eki-validation" class="validation-message" style="color:#bd3e4d"></div>
            </div>
        </div>
        
        <!-- Display All Eki Numbers in a Table -->
        <div class="eki-table-container">
            <h4 class="eki-table-title">Eki Panna Combinations - 10 Odd Digit Pannas</h4>
            <table class="eki-panna-table">
                <thead>
                    <tr>
                        <th>Sr. No.</th>
                        <th>Panna</th>
                        <th>Sum</th>
                        <th>Bet Amount</th>
                    </tr>
                </thead>
                <tbody id="eki-panna-table-body">
                    <tr class="eki-panna-row" data-panna="137">
                        <td class="eki-sr-no">1</td>
                        <td class="eki-panna-value">137</td>
                        <td class="eki-panna-sum">1+3+7=11</td>
                        <td class="eki-amount-display" id="eki-amount-137">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="579">
                        <td class="eki-sr-no">2</td>
                        <td class="eki-panna-value">579</td>
                        <td class="eki-panna-sum">5+7+9=21</td>
                        <td class="eki-amount-display" id="eki-amount-579">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="139">
                        <td class="eki-sr-no">3</td>
                        <td class="eki-panna-value">139</td>
                        <td class="eki-panna-sum">1+3+9=13</td>
                        <td class="eki-amount-display" id="eki-amount-139">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="359">
                        <td class="eki-sr-no">4</td>
                        <td class="eki-panna-value">359</td>
                        <td class="eki-panna-sum">3+5+9=17</td>
                        <td class="eki-amount-display" id="eki-amount-359">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="157">
                        <td class="eki-sr-no">5</td>
                        <td class="eki-panna-value">157</td>
                        <td class="eki-panna-sum">1+5+7=13</td>
                        <td class="eki-amount-display" id="eki-amount-157">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="179">
                        <td class="eki-sr-no">6</td>
                        <td class="eki-panna-value">179</td>
                        <td class="eki-panna-sum">1+7+9=17</td>
                        <td class="eki-amount-display" id="eki-amount-179">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="379">
                        <td class="eki-sr-no">7</td>
                        <td class="eki-panna-value">379</td>
                        <td class="eki-panna-sum">3+7+9=19</td>
                        <td class="eki-amount-display" id="eki-amount-379">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="159">
                        <td class="eki-sr-no">8</td>
                        <td class="eki-panna-value">159</td>
                        <td class="eki-panna-sum">1+5+9=15</td>
                        <td class="eki-amount-display" id="eki-amount-159">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="135">
                        <td class="eki-sr-no">9</td>
                        <td class="eki-panna-value">135</td>
                        <td class="eki-panna-sum">1+3+5=9</td>
                        <td class="eki-amount-display" id="eki-amount-135">-</td>
                    </tr>
                    <tr class="eki-panna-row" data-panna="357">
                        <td class="eki-sr-no">10</td>
                        <td class="eki-panna-value">357</td>
                        <td class="eki-panna-sum">3+5+7=15</td>
                        <td class="eki-amount-display" id="eki-amount-357">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="eki-summary">
            <p>Total Pannas: <span id="eki-outcomes-count">10</span></p>
            <p>Amount per Panna: <span id="eki-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="eki-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="eki-form">
            <input type="hidden" name="bet_amount" id="form-eki-bet-amount">
            <input type="hidden" name="mode" id="form-eki-mode" value="open">
            <input type="hidden" name="place_eki_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-eki-bet-btn" disabled>
                PLACE EKI BET
            </button>
        </form>
    </div>
</div>
<!-- Bkki Game Interface -->
<div class="bkki-interface" style="<?php echo $game_type === 'bkki' ? 'display: block;' : 'display: none;'; ?>">
    <div class="bkki-container">
        <h3>Bkki Game</h3>
        
        <div class="set-info">
            <p><strong>10 Even Digit Panna Combinations (0 means 10)</strong></p>
            <p>Enter amount per panna to bet on all 10 even digit pannas</p>
        </div>
        
        <div class="bkki-controls">
            <div class="control-group">
                <label for="bkki-amount">Amount per Panna:</label>
                <input type="number" id="bkki-amount" min="1" value="1" oninput="updateBkkiTotal()">
                <div id="bkki-validation" class="validation-message" style="color:#bd3e4d"></div>
            </div>
        </div>
        
        <!-- Display All Bkki Numbers in a Table -->
        <div class="bkki-table-container">
            <h4 class="bkki-table-title">Bkki Panna Combinations - 10 Even Digit Pannas (0=10)</h4>
            <table class="bkki-panna-table">
                <thead>
                    <tr>
                        <th>Sr. No.</th>
                        <th>Panna</th>
                        <th>Sum (0=10)</th>
                        <th>Bet Amount</th>
                    </tr>
                </thead>
                <tbody id="bkki-panna-table-body">
                    <tr class="bkki-panna-row" data-panna="028">
                        <td class="bkki-sr-no">1</td>
                        <td class="bkki-panna-value">280</td>
                        <td class="bkki-panna-sum">10+2+8=20</td>
                        <td class="bkki-amount-display" id="bkki-amount-028">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="046">
                        <td class="bkki-sr-no">2</td>
                        <td class="bkki-panna-value">460</td>
                        <td class="bkki-panna-sum">10+4+6=20</td>
                        <td class="bkki-amount-display" id="bkki-amount-046">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="246">
                        <td class="bkki-sr-no">3</td>
                        <td class="bkki-panna-value">246</td>
                        <td class="bkki-panna-sum">2+4+6=12</td>
                        <td class="bkki-amount-display" id="bkki-amount-246">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="268">
                        <td class="bkki-sr-no">4</td>
                        <td class="bkki-panna-value">268</td>
                        <td class="bkki-panna-sum">2+6+8=16</td>
                        <td class="bkki-amount-display" id="bkki-amount-268">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="468">
                        <td class="bkki-sr-no">5</td>
                        <td class="bkki-panna-value">468</td>
                        <td class="bkki-panna-sum">4+6+8=18</td>
                        <td class="bkki-amount-display" id="bkki-amount-468">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="048">
                        <td class="bkki-sr-no">6</td>
                        <td class="bkki-panna-value">480</td>
                        <td class="bkki-panna-sum">10+4+8=22</td>
                        <td class="bkki-amount-display" id="bkki-amount-048">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="068">
                        <td class="bkki-sr-no">7</td>
                        <td class="bkki-panna-value">680</td>
                        <td class="bkki-panna-sum">10+6+8=24</td>
                        <td class="bkki-amount-display" id="bkki-amount-068">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="248">
                        <td class="bkki-sr-no">8</td>
                        <td class="bkki-panna-value">248</td>
                        <td class="bkki-panna-sum">2+4+8=14</td>
                        <td class="bkki-amount-display" id="bkki-amount-248">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="024">
                        <td class="bkki-sr-no">9</td>
                        <td class="bkki-panna-value">240</td>
                        <td class="bkki-panna-sum">10+2+4=16</td>
                        <td class="bkki-amount-display" id="bkki-amount-024">-</td>
                    </tr>
                    <tr class="bkki-panna-row" data-panna="468">
                        <td class="bkki-sr-no">10</td>
                        <td class="bkki-panna-value">260</td>
                        <td class="bkki-panna-sum">2+6+10=18</td>
                        <td class="bkki-amount-display" id="bkki-amount-468">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="bkki-summary">
            <p>Total Pannas: <span id="bkki-outcomes-count">10</span></p>
            <p>Amount per Panna: <span id="bkki-amount-per-outcome">INR 0.00</span></p>
            <p>Total Bet Amount: <span id="bkki-total-bet-amount">INR 0.00</span></p>
        </div>
        
        <form method="POST" id="bkki-form">
            <input type="hidden" name="bet_amount" id="form-bkki-bet-amount">
            <input type="hidden" name="mode" id="form-bkki-mode" value="open">
            <input type="hidden" name="place_bkki_bet" value="1">
            
            <button type="submit" class="action-btn place-bet-btn" id="place-bkki-bet-btn" disabled>
                PLACE BKKI BET
            </button>
        </form>
    </div>
</div>


   
   
            <div class="last-results">
                <h3 class="results-title">Last Result</h3>
                <div class="results-list">
                    <?php foreach ($recent_results as $result): ?>
                        <div class="result-item">
                            <span><?php echo date('d-m-Y', strtotime($result['result_date'])); ?></span>
                            <span><?php echo $result['result_numbers']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
            <div class="footer-links">
                <a href="#">Terms & Conditions</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Responsible Gaming</a>
                <a href="#">Contact Us</a>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; 2023 MG Games. All rights reserved.</p>
        </div>
    </footer>
<script src="includes/script.js"></script>
<script>
// Global mode management
let currentMode = 'open';

// Consolidated Mode Toggle System - FIXED VERSION
function initializeModeToggle() {
    // Initialize main header toggle
    const openToggle = document.getElementById('open-toggle');
    const closeToggle = document.getElementById('close-toggle');
    
    if (openToggle && closeToggle) {
        openToggle.classList.add('active');
        closeToggle.classList.remove('active');
        currentMode = 'open';
        
        openToggle.addEventListener('click', function() {
            if (!this.classList.contains('active')) {
                setActiveMode('open');
            }
        });
        
        closeToggle.addEventListener('click', function() {
            if (!this.classList.contains('active')) {
                setActiveMode('close');
            }
        });
    }
    
    // Initialize Jodi mode toggle
    initializeJodiModeToggle();
    
    // Update all mode fields
    updateAllModeFields(currentMode);
}

// Jodi Mode Toggle System - FIXED
function initializeJodiModeToggle() {
    const openToggle = document.getElementById('jodi-open-toggle');
    const closeToggle = document.getElementById('jodi-close-toggle');
    
    if (openToggle && closeToggle) {
        openToggle.classList.add('active');
        closeToggle.classList.remove('active');
        
        openToggle.addEventListener('click', function() {
            if (!this.classList.contains('active')) {
                setActiveMode('open');
            }
        });
        
        closeToggle.addEventListener('click', function() {
            if (!this.classList.contains('active')) {
                setActiveMode('close');
            }
        });
    }
}

// Set active mode across all toggles
function setActiveMode(mode) {
    currentMode = mode;
    
    // Update main header toggle
    const mainOpenToggle = document.getElementById('open-toggle');
    const mainCloseToggle = document.getElementById('close-toggle');
    
    if (mainOpenToggle && mainCloseToggle) {
        if (mode === 'open') {
            mainOpenToggle.classList.add('active');
            mainCloseToggle.classList.remove('active');
        } else {
            mainCloseToggle.classList.add('active');
            mainOpenToggle.classList.remove('active');
        }
    }
    
    // Update Jodi toggle
    const jodiOpenToggle = document.getElementById('jodi-open-toggle');
    const jodiCloseToggle = document.getElementById('jodi-close-toggle');
    
    if (jodiOpenToggle && jodiCloseToggle) {
        if (mode === 'open') {
            jodiOpenToggle.classList.add('active');
            jodiCloseToggle.classList.remove('active');
        } else {
            jodiCloseToggle.classList.add('active');
            jodiOpenToggle.classList.remove('active');
        }
    }
    
    // Update all mode fields
    updateAllModeFields(mode);
}

// Update ALL mode hidden fields
function updateAllModeFields(mode) {
    const modeFields = [
        'form-single-ank-mode',
        'form-jodi-mode', 
        'form-patti-mode',
        'form-sp-motor-mode',
        'form-dp-motor-mode',
        'form-sp-game-mode',
        'form-dp-game-mode',
        'form-sp-set-mode',
        'form-dp-set-mode',
        'form-tp-set-mode',
        'form-common-mode',
        'form-series-mode',
        'form-rown-mode',
        'form-abr-cut-mode',
        'form-eki-mode',
        'form-bkki-mode'
    ];
    
    modeFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = mode;
            console.log(`Updated ${fieldId} to: ${mode}`);
        }
    });
}


// function toggleGameUI(gameType) {
//     const isSingleAnk = gameType === 'single_ank';
//     const isJodi = gameType === 'jodi';
//     const isPatti = ['single_patti', 'double_patti', 'triple_patti'].includes(gameType);
//     const isSpMotor = gameType === 'sp_motor';
//     const isDpMotor = gameType === 'dp_motor';
//     const isSpGame = gameType === 'sp_game';
//     const isDpGame = gameType === 'dp_game';
//     const isSpSet = gameType === 'sp_set';
//     const isDpSet = gameType === 'dp_set';
//     const isTpSet = gameType === 'tp_set'; 
//     const isCommon = gameType === 'common'; 
//     const isSeries = gameType === 'series'; 
//     const isRown = gameType === 'rown';
//     const isEki = gameType === 'eki';
//     const isBkki = gameType === 'bkki';
//     const isAbrCut = gameType === 'abr_cut';
    
//     const isSpecialGame = isSingleAnk || isJodi || isPatti || isSpMotor || isDpMotor || isSpGame || isDpGame || isSpSet || isDpSet || isTpSet || isCommon || isSeries || isRown || isEki || isBkki || isAbrCut;

//     const elementsToToggle = [
//         '.chips-container',
//         '#numbers-grid',
//         '.bet-total',
//         '.action-buttons',
//         '#bet-form:not(#single-ank-form):not(#jodi-form):not(#patti-form):not(#sp-motor-form):not(#dp-motor-form):not(#sp-game-form):not(#dp-game-form):not(#sp-set-form):not(#dp-set-form):not(#tp-set-form):not(#common-form):not(#series-form):not(#rown-form):not(#abr-cut-form):not(#eki-form):not(#bkki-form)'
//     ];
    
//     elementsToToggle.forEach(selector => {
//         const elements = document.querySelectorAll(selector);
//         elements.forEach(el => {
//             el.style.display = isSpecialGame ? 'none' : '';
//         });
//     });
    
    // Show Single Ank interface
    const singleAnkInterface = document.querySelector('.single-ank-interface');
    if (singleAnkInterface) {
        singleAnkInterface.style.display = isSingleAnk ? 'block' : 'none';
    }
    
    // Show Jodi interface
    const jodiInterface = document.querySelector('.jodi-interface');
    if (jodiInterface) {
        jodiInterface.style.display = isJodi ? 'block' : 'none';
    }
    
    // Show Patti interface
    const pattiInterface = document.querySelector('.patti-interface');
    if (pattiInterface) {
        pattiInterface.style.display = isPatti ? 'block' : 'none';
    }
    
    // Show SP Motor interface
    const spMotorInterface = document.querySelector('.sp-motor-interface');
    if (spMotorInterface) {
        spMotorInterface.style.display = isSpMotor ? 'block' : 'none';
    }
    
    // Show DP Motor interface
    const dpMotorInterface = document.querySelector('.dp-motor-interface');
    if (dpMotorInterface) {
        dpMotorInterface.style.display = isDpMotor ? 'block' : 'none';
    }
    
    // Show SP Game interface
    const spGameInterface = document.querySelector('.sp-game-interface');
    if (spGameInterface) {
        spGameInterface.style.display = isSpGame ? 'block' : 'none';
    }
    
    // Show DP Game interface
    const dpGameInterface = document.querySelector('.dp-game-interface');
    if (dpGameInterface) {
        dpGameInterface.style.display = isDpGame ? 'block' : 'none';
    }
    
    // Show SP Set interface
    const spSetInterface = document.querySelector('.sp-set-interface');
    if (spSetInterface) {
        spSetInterface.style.display = isSpSet ? 'block' : 'none';
    }
    
    // Show DP Set interface
    const dpSetInterface = document.querySelector('.dp-set-interface');
    if (dpSetInterface) {
        dpSetInterface.style.display = isDpSet ? 'block' : 'none';
    }
    
    // Show TP Set interface
    const tpSetInterface = document.querySelector('.tp-set-interface');
    if (tpSetInterface) {
        tpSetInterface.style.display = isTpSet ? 'block' : 'none';
    }
    
    // Show Common interface
    const commonInterface = document.querySelector('.common-interface');
    if (commonInterface) {
        commonInterface.style.display = isCommon ? 'block' : 'none';
    } 
    
    // Show Series interface
    const seriesInterface = document.querySelector('.series-interface');
    if (seriesInterface) {
        seriesInterface.style.display = isSeries ? 'block' : 'none';
    }
    
    // Show Rown interface
    const rownInterface = document.querySelector('.rown-interface');
    if (rownInterface) {
        rownInterface.style.display = isRown ? 'block' : 'none';
    }
    
    // Show Abr-Cut interface
    const abrCutInterface = document.querySelector('.abr-cut-interface');
    if (abrCutInterface) {
        abrCutInterface.style.display = isAbrCut ? 'block' : 'none';
    }
    
    // Show Eki interface
    const ekiInterface = document.querySelector('.eki-interface');
    if (ekiInterface) {
        ekiInterface.style.display = isEki ? 'block' : 'none';
    }
    
    // Show Bkki interface
    const bkkiInterface = document.querySelector('.bkki-interface');
    if (bkkiInterface) {
        bkkiInterface.style.display = isBkki ? 'block' : 'none';
    }
    
    // Initialize mode toggles based on game type
    if (isJodi) {
        initializeJodiModeToggle();
    } else {
        initializeModeToggle();
    }
}

// Also update the bet type change handler to handle all games
document.addEventListener('DOMContentLoaded', function() {
    const gameType = '<?php echo $game_type; ?>';
    toggleGameUI(gameType);
    
    // Handle bet type changes
    document.getElementById('bet-type').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const newGameType = selectedOption.dataset.code;
        const newGameTypeId = selectedOption.value;
        
        // Update the hidden game type field
        document.getElementById('form-game-type-id').value = newGameTypeId;
        
        // Redirect to the new game type
        window.location.href = `<?php echo $_SERVER['PHP_SELF']; ?>?type=${newGameType}`;
    });
});

// Update the updateNumbersGrid function to handle all special games
function updateNumbersGrid(gameType) {
    const grid = document.getElementById('numbers-grid');
    
    // For all special games, we don't need to populate the number grid
    const specialGames = ['single_ank', 'jodi', 'single_patti', 'double_patti', 'triple_patti', 'sp_motor', 'dp_motor', 'sp_game', 'dp_game', 'sp_set', 'dp_set', 'tp_set', 'common', 'series', 'rown', 'eki', 'bkki', 'abr_cut'];
    
    if (specialGames.includes(gameType)) {
        grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 2rem;">' +
                         `<p>Use the ${gameType.toUpperCase().replace(/_/g, ' ')} interface below to place your bets</p>` +
                         '</div>';
        return;
    }
    
    // ... rest of your existing grid population code ...
}

// Force mode update on window load
window.addEventListener('load', function() {
    updateAllModeFields(currentMode);
});
</script>

   <!-- // Single Ank -->

   <script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-3fhDOQ5ZJp+M+K7Ry5R8iW6vzH3zjw6K0gE2WvP0Qj8="
        crossorigin="anonymous"></script>

<script>
         
        let singleAnkBets = [];

        $(document).ready( function(){
            alert(1);
        });

        function initializeSingleAnkGame() {
            // Add bet button
            document.getElementById('add-single-ank-bet').addEventListener('click', function() {
                addSingleAnkBet();
            });
            
            // Update single ank total when amount changes
            document.getElementById('single-ank-amount').addEventListener('input', updateSingleAnkTotal);
            
            // Initialize mode toggle for Single Ank
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-single-ank-mode').value = mode;
                });
            });
        }

        function addSingleAnkBet() {
            const selectedDigit = document.getElementById('single-ank-digit').value;
            const amount = parseFloat(document.getElementById('single-ank-amount').value) || 0;
            
            if (!selectedDigit) {
                return;
            }
            
            if (amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }
            
            // Add bet to the array
            const bet = {
                id: Date.now(), // Unique ID for removal
                digit: selectedDigit,
                amount: amount
            };
            
            singleAnkBets.push(bet);
            updateSingleAnkBetsTable();
            updateSingleAnkTotal();
            
            // Clear the inputs for next bet
            document.getElementById('single-ank-digit').value = '';
            document.getElementById('single-ank-amount').value = '1';
        }

        function removeSingleAnkBet(betId) {
            singleAnkBets = singleAnkBets.filter(bet => bet.id !== betId);
            updateSingleAnkBetsTable();
            updateSingleAnkTotal();
        }

        function updateSingleAnkBetsTable() {
            const tbody = document.getElementById('single-ank-bets-tbody');
            tbody.innerHTML = '';
            
            singleAnkBets.forEach(bet => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${bet.digit}</td>
                    <td>INR ${bet.amount.toFixed(2)}</td>
                    <td>
                        <button type="button" class="remove-bet-btn" onclick="removeSingleAnkBet(${bet.id})">
                            Remove
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
            
            document.getElementById('single-ank-bets-count').textContent = singleAnkBets.length;
        }

        function updateSingleAnkTotal() {
            const totalAmount = singleAnkBets.reduce((sum, bet) => sum + bet.amount, 0);
            
            document.getElementById('single-ank-total-bet-amount').textContent = `INR ${totalAmount.toFixed(2)}`;
            document.getElementById('form-single-ank-bet-amount').value = totalAmount;
            
            // Update form with all outcomes
            const outcomes = singleAnkBets.map(bet => ({
                digit: bet.digit,
                amount: bet.amount
            }));
            
            document.getElementById('form-single-ank-outcomes').value = JSON.stringify(outcomes);
            
            // Set the selected digit (use the first bet's digit or empty if no bets)
            if (singleAnkBets.length > 0) {
                document.getElementById('form-single-ank-digit').value = singleAnkBets[0].digit;
            } else {
                document.getElementById('form-single-ank-digit').value = '';
            }
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-single-ank-bet-btn');
            
            if (totalAmount > 0 && totalAmount < 5) {
                betButton.disabled = true;
            } else if (singleAnkBets.length === 0) {
                betButton.disabled = true;
            } else if (totalAmount <= 0) {
                betButton.disabled = true;
            } else {
                betButton.disabled = false;
            }
        }

        // Update the toggleGameUI function to handle Single Ank game
        function toggleGameUI(gameType) {
            const isSingleAnk = gameType === 'single_ank';
            const isSpecialGame = ['sp_motor', 'dp_motor', 'sp_game', 'dp_game', 'sp_set', 'dp_set', 'tp_set', 'common', 'series', 'single_ank'].includes(gameType);

            const elementsToToggle = [
                '.chips-container',
                '#numbers-grid',
                '.bet-total',
                '.action-buttons',
                '#bet-form:not(#single-ank-form)'
            ];
            
            elementsToToggle.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    el.style.display = isSpecialGame ? 'none' : '';
                });
            });
            
            // Show/hide regular betting interface
            const regularInterface = document.querySelector('.regular-betting-interface');
            if (regularInterface) {
                regularInterface.style.display = isSpecialGame ? 'none' : 'block';
            }
            
            // Show Single Ank interface if it's Single Ank game
            const singleAnkInterface = document.querySelector('.single-ank-interface');
            if (singleAnkInterface) {
                singleAnkInterface.style.display = isSingleAnk ? 'block' : 'none';
                
                // Initialize single ank game if it's shown
                if (isSingleAnk) {
                    initializeSingleAnkGame();
                }
            }
            
            // Show other interfaces for other special games
            const spMotorInterface = document.querySelector('.sp-motor-interface');
            if (spMotorInterface) {
                spMotorInterface.style.display = (gameType === 'sp_motor') ? 'block' : 'none';
            }
            
            const dpMotorInterface = document.querySelector('.dp-motor-interface');
            if (dpMotorInterface) {
                dpMotorInterface.style.display = (gameType === 'dp_motor') ? 'block' : 'none';
            }
            
            const spGameInterface = document.querySelector('.sp-game-interface');
            if (spGameInterface) {
                spGameInterface.style.display = (gameType === 'sp_game') ? 'block' : 'none';
            }
            
            const dpGameInterface = document.querySelector('.dp-game-interface');
            if (dpGameInterface) {
                dpGameInterface.style.display = (gameType === 'dp_game') ? 'block' : 'none';
            }
            
            const spSetInterface = document.querySelector('.sp-set-interface');
            if (spSetInterface) {
                spSetInterface.style.display = (gameType === 'sp_set') ? 'block' : 'none';
            }
            
            const dpSetInterface = document.querySelector('.dp-set-interface');
            if (dpSetInterface) {
                dpSetInterface.style.display = (gameType === 'dp_set') ? 'block' : 'none';
            }
            
            const tpSetInterface = document.querySelector('.tp-set-interface');
            if (tpSetInterface) {
                tpSetInterface.style.display = (gameType === 'tp_set') ? 'block' : 'none';
            }
            
            const commonInterface = document.querySelector('.common-interface');
            if (commonInterface) {
                commonInterface.style.display = (gameType === 'common') ? 'block' : 'none';
            }
            
            const seriesInterface = document.querySelector('.series-interface');
            if (seriesInterface) {
                seriesInterface.style.display = (gameType === 'series') ? 'block' : 'none';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const gameType = '<?php echo $game_type; ?>';
            toggleGameUI(gameType);
            
            if (gameType === 'single_ank') {
                initializeSingleAnkGame();
            }
        });
</script>

    <!-- // Jodi -->
<script>
    // Jodi Mode Toggle System
    function initializeJodiModeToggle() {
        const openToggle = document.getElementById('jodi-open-toggle');
        const closeToggle = document.getElementById('jodi-close-toggle');
        
        if (openToggle && closeToggle) {
            // Set initial active state
            openToggle.classList.add('active');
            closeToggle.classList.remove('active');
            
            // Add click events
            openToggle.addEventListener('click', function() {
                if (!this.classList.contains('active')) {
                    openToggle.classList.add('active');
                    closeToggle.classList.remove('active');
                    document.getElementById('form-jodi-mode').value = 'open';
                    console.log('Jodi Mode changed to: open');
                }
            });
            
            closeToggle.addEventListener('click', function() {
                if (!this.classList.contains('active')) {
                    closeToggle.classList.add('active');
                    openToggle.classList.remove('active');
                    document.getElementById('form-jodi-mode').value = 'close';
                    console.log('Jodi Mode changed to: close');
                }
            });
        }
    }

        // Update the initializeJodiGame function to include mode toggle
        function initializeJodiGame() {
            // Add bet button
            document.getElementById('add-jodi-bet').addEventListener('click', function() {
                addJodiBet();
            });
            
            // Update jodi total when amount changes
            document.getElementById('jodi-amount').addEventListener('input', updateJodiTotal);
            
            // Initialize mode toggle for Jodi
            initializeJodiModeToggle();
    }
                let jodiBets = [];

            function initializeJodiGame() {
                // Add bet button
                document.getElementById('add-jodi-bet').addEventListener('click', function() {
                    addJodiBet();
                });
                
                // Update jodi total when amount changes
                document.getElementById('jodi-amount').addEventListener('input', updateJodiTotal);
                
                // Initialize mode toggle for Jodi
                document.querySelectorAll('.toggle-option').forEach(option => {
                    option.addEventListener('click', function() {
                        const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                        document.getElementById('form-jodi-mode').value = mode;
                    });
                });
            }

            function addJodiBet() {
                const selectedDigit1 = document.getElementById('jodi-digit1').value;
                const selectedDigit2 = document.getElementById('jodi-digit2').value;
                const amount = parseFloat(document.getElementById('jodi-amount').value) || 0;
                
                if (!selectedDigit1 || !selectedDigit2) {
                    return;
                }
                
                if (amount <= 0) {
                    alert('Please enter a valid amount');
                    return;
                }
                
                // Create jodi number (two digits)
                const jodiNumber = selectedDigit1.toString() + selectedDigit2.toString();
                
                // Add bet to the array
                const bet = {
                    id: Date.now(), // Unique ID for removal
                    digit1: selectedDigit1,
                    digit2: selectedDigit2,
                    jodi: jodiNumber,
                    amount: amount
                };
                
                jodiBets.push(bet);
                updateJodiBetsTable();
                updateJodiTotal();
                
                // Clear the inputs for next bet
                document.getElementById('jodi-digit1').value = '';
                document.getElementById('jodi-digit2').value = '';
                document.getElementById('jodi-amount').value = '1';
            }

            function removeJodiBet(betId) {
                jodiBets = jodiBets.filter(bet => bet.id !== betId);
                updateJodiBetsTable();
                updateJodiTotal();
            }

            function updateJodiBetsTable() {
                const tbody = document.getElementById('jodi-bets-tbody');
                tbody.innerHTML = '';
                
                jodiBets.forEach(bet => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${bet.jodi}</td>
                        <td>INR ${bet.amount.toFixed(2)}</td>
                        <td>
                            <button type="button" class="remove-bet-btn" onclick="removeJodiBet(${bet.id})">
                                Remove
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                document.getElementById('jodi-bets-count').textContent = jodiBets.length;
            }

            function updateJodiTotal() {
                const totalAmount = jodiBets.reduce((sum, bet) => sum + bet.amount, 0);
                
                document.getElementById('jodi-total-bet-amount').textContent = `INR ${totalAmount.toFixed(2)}`;
                document.getElementById('form-jodi-bet-amount').value = totalAmount;
                
                // Update form with all outcomes
                const outcomes = jodiBets.map(bet => ({
                    digit1: bet.digit1,
                    digit2: bet.digit2,
                    jodi: bet.jodi,
                    amount: bet.amount
                }));
                
                document.getElementById('form-jodi-outcomes').value = JSON.stringify(outcomes);
                
                // Set the selected digits (use the first bet's digits or empty if no bets)
                if (jodiBets.length > 0) {
                    document.getElementById('form-jodi-digit1').value = jodiBets[0].digit1;
                    document.getElementById('form-jodi-digit2').value = jodiBets[0].digit2;
                } else {
                    document.getElementById('form-jodi-digit1').value = '';
                    document.getElementById('form-jodi-digit2').value = '';
                }
                
                // Enable/disable bet button
                const betButton = document.getElementById('place-jodi-bet-btn');
                
                if (totalAmount > 0 && totalAmount < 5) {
                    betButton.disabled = true;
                } else if (jodiBets.length === 0) {
                    betButton.disabled = true;
                } else if (totalAmount <= 0) {
                    betButton.disabled = true;
                } else {
                    betButton.disabled = false;
                }
            }

            // Update the toggleGameUI function to handle Jodi game
            function toggleGameUI(gameType) {
                const isSingleAnk = gameType === 'single_ank';
                const isJodi = gameType === 'jodi';
                const isSpecialGame = ['sp_motor', 'dp_motor', 'sp_game', 'dp_game', 'sp_set', 'dp_set', 'tp_set', 'common', 'series', 'single_ank', 'jodi'].includes(gameType);

                const elementsToToggle = [
                    '.chips-container',
                    '#numbers-grid',
                    '.bet-total',
                    '.action-buttons',
                    '#bet-form:not(#single-ank-form):not(#jodi-form)'
                ];
                
                elementsToToggle.forEach(selector => {
                    const elements = document.querySelectorAll(selector);
                    elements.forEach(el => {
                        el.style.display = isSpecialGame ? 'none' : '';
                    });
                });
                
                // Show/hide regular betting interface
                const regularInterface = document.querySelector('.regular-betting-interface');
                if (regularInterface) {
                    regularInterface.style.display = isSpecialGame ? 'none' : 'block';
                }
                
                // Show Single Ank interface if it's Single Ank game
                const singleAnkInterface = document.querySelector('.single-ank-interface');
                if (singleAnkInterface) {
                    singleAnkInterface.style.display = isSingleAnk ? 'block' : 'none';
                    
                    // Initialize single ank game if it's shown
                    if (isSingleAnk) {
                        initializeSingleAnkGame();
                    }
                }
                
                // Show Jodi interface if it's Jodi game
                const jodiInterface = document.querySelector('.jodi-interface');
                if (jodiInterface) {
                    jodiInterface.style.display = isJodi ? 'block' : 'none';
                    
                    // Initialize jodi game if it's shown
                    if (isJodi) {
                        initializeJodiGame();
                    }
                }
                
                // Show other interfaces for other special games
                const spMotorInterface = document.querySelector('.sp-motor-interface');
                if (spMotorInterface) {
                    spMotorInterface.style.display = (gameType === 'sp_motor') ? 'block' : 'none';
                }
                
                const dpMotorInterface = document.querySelector('.dp-motor-interface');
                if (dpMotorInterface) {
                    dpMotorInterface.style.display = (gameType === 'dp_motor') ? 'block' : 'none';
                }
                
                const spGameInterface = document.querySelector('.sp-game-interface');
                if (spGameInterface) {
                    spGameInterface.style.display = (gameType === 'sp_game') ? 'block' : 'none';
                }
                
                const dpGameInterface = document.querySelector('.dp-game-interface');
                if (dpGameInterface) {
                    dpGameInterface.style.display = (gameType === 'dp_game') ? 'block' : 'none';
                }
                
                const spSetInterface = document.querySelector('.sp-set-interface');
                if (spSetInterface) {
                    spSetInterface.style.display = (gameType === 'sp_set') ? 'block' : 'none';
                }
                
                const dpSetInterface = document.querySelector('.dp-set-interface');
                if (dpSetInterface) {
                    dpSetInterface.style.display = (gameType === 'dp_set') ? 'block' : 'none';
                }
                
                const tpSetInterface = document.querySelector('.tp-set-interface');
                if (tpSetInterface) {
                    tpSetInterface.style.display = (gameType === 'tp_set') ? 'block' : 'none';
                }
                
                const commonInterface = document.querySelector('.common-interface');
                if (commonInterface) {
                    commonInterface.style.display = (gameType === 'common') ? 'block' : 'none';
                }
                
                const seriesInterface = document.querySelector('.series-interface');
                if (seriesInterface) {
                    seriesInterface.style.display = (gameType === 'series') ? 'block' : 'none';
                }
            }

            // Initialize when page loads
            document.addEventListener('DOMContentLoaded', function() {
                const gameType = '<?php echo $game_type; ?>';
                toggleGameUI(gameType);
                
                if (gameType === 'single_ank') {
                    initializeSingleAnkGame();
                } else if (gameType === 'jodi') {
                    initializeJodiGame();
                }
            });
</script>

<!--Single double triple patti script -->
<script>
        // Patti Game Logic
        let pattiBets = [];
        let currentPattiType = '';

        function initializePattiGame() {
            // Set current patti type based on URL
            currentPattiType = '<?php echo $game_type; ?>';
            document.getElementById('form-patti-type').value = currentPattiType;
            
            // Update UI based on patti type
            updatePattiControls();
            
            // Add bet button
            document.getElementById('add-patti-bet').addEventListener('click', function() {
                addPattiBet();
            });
            
            // Update patti total when amount changes
            document.getElementById('patti-amount').addEventListener('input', updatePattiTotal);
            
            // Initialize mode toggle for Patti
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-patti-mode').value = mode;
                });
            });
        }

  function updatePattiControls() {
    // Hide all controls first
    document.querySelectorAll('[id$="-controls"]').forEach(control => {
        control.style.display = 'none';
    });
    
    const showAllBtn = document.getElementById('show-all-pannas-btn');
    
    // Show appropriate controls based on patti type
    switch(currentPattiType) {
        case 'single_patti':
            document.getElementById('single-patti-controls').style.display = 'block';
            document.getElementById('patti-instructions').textContent = 'Enter three different digits (0-9) to form a Single Patti';
            showAllBtn.style.display = 'block';
            showAllBtn.textContent = 'Show All Single Pannas (120)';
            // Set the patti type for form submission
            document.getElementById('form-patti-type').value = 'single';
            break;
        case 'double_patti':
            document.getElementById('double-patti-controls').style.display = 'block';
            document.getElementById('patti-instructions').textContent = 'Enter three digits with exactly two same digits (e.g., 112, 233, 455)';
            showAllBtn.style.display = 'block';
            showAllBtn.textContent = 'Show All Double Pannas (90)';
            // Set the patti type for form submission
            document.getElementById('form-patti-type').value = 'double';
            break;
        case 'triple_patti':
            document.getElementById('triple-patti-controls').style.display = 'block';
            document.getElementById('patti-instructions').textContent = 'Enter one digit to create Triple Patti (e.g., 111, 222)';
            showAllBtn.style.display = 'none';
            // Set the patti type for form submission
            document.getElementById('form-patti-type').value = 'triple';
            break;
    }
 }

        // Single Patti Validation
        function validateSinglePattiDigits(input) {
            const value = input.value.replace(/[^0-9]/g, '');
            input.value = value;
            
            const validationEl = document.getElementById('single-patti-validation');
            
            if (value.length !== 3) {
                validationEl.textContent = "Please enter exactly 3 digits";
                return false;
            }
            
            const digits = value.split('');
            const uniqueDigits = [...new Set(digits)];
            
            if (uniqueDigits.length !== 3) {
                validationEl.textContent = "All three digits must be different";
                return false;
            }
            
            validationEl.textContent = "";
            return true;
        }

      function validateDoublePattiDigits(input) {
    const value = input.value.replace(/[^0-9]/g, '');
    input.value = value;
    
    const validationEl = document.getElementById('double-patti-validation');
    
    if (value.length !== 3) {
        validationEl.textContent = "Please enter exactly 3 digits";
        return false;
    }
    
    const digits = value.split('');
    
    // Check if it's a valid double patti (exactly two same digits and one different)
    const digitCount = {};
    digits.forEach(digit => {
        digitCount[digit] = (digitCount[digit] || 0) + 1;
    });
    
    const counts = Object.values(digitCount);
    
    // Valid double patti should have exactly one digit with count 2 and one digit with count 1
    if (!(counts.includes(2) && counts.includes(1) && counts.length === 2)) {
        validationEl.textContent = "Double Patti must have exactly two same digits and one different digit";
        return false;
    }
    
    validationEl.textContent = "";
    return true;
   }

   // Get Double Patti type for display
  function getDoublePattiType(pattiNumber) {
    const digits = pattiNumber.split('');
    
    if (digits[0] === digits[1] && digits[1] === digits[2]) {
        return "Triple Patti";
    } else if (digits[0] === digits[1]) {
        return "Double Patti (AAB)";
    } else if (digits[1] === digits[2]) {
        return "Double Patti (ABB)";
    } else if (digits[0] === digits[2]) {
        return "Double Patti (ABA)";
    } else {
        return "Single Patti";
    }
  }


        // Triple Patti Validation
        function validateTriplePattiDigit(input) {
            const value = input.value.replace(/[^0-9]/g, '');
            input.value = value;
            
            const validationEl = document.getElementById('triple-patti-validation');
            
            if (value.length !== 1) {
                validationEl.textContent = "Please enter exactly 1 digit";
                return false;
            }
            
            validationEl.textContent = "";
            return true;
        }

       // Update the addPattiBet function for Double Patti case
  function addPattiBet() {
    let pattiNumber = '';
    let validationMessage = '';
    let isValid = true;
    let pattiType = currentPattiType.replace('_', ' ');
    
    switch(currentPattiType) {
        case 'single_patti':
            const singleDigits = document.getElementById('single-digits-input').value;
            isValid = validateSinglePattiDigits({value: singleDigits});
            if (!isValid) {
                validationMessage = document.getElementById('single-patti-validation').textContent;
            } else if (singleDigits.length !== 3) {
                validationMessage = "Please enter exactly 3 digits";
                isValid = false;
            } else {
                pattiNumber = singleDigits;
                pattiType = "Single Patti";
            }
            break;
            
        case 'double_patti':
            const doubleDigits = document.getElementById('double-digits-input').value;
            isValid = validateDoublePattiDigits({value: doubleDigits});
            if (!isValid) {
                validationMessage = document.getElementById('double-patti-validation').textContent;
            } else if (doubleDigits.length !== 3) {
                validationMessage = "Please enter exactly 3 digits";
                isValid = false;
            } else {
                pattiNumber = doubleDigits;
                pattiType = getDoublePattiType(doubleDigits);
                
                // Ensure it's actually a double patti (not triple)
                if (pattiType === "Triple Patti") {
                    validationMessage = "This is a Triple Patti. Please use Triple Patti game for this combination.";
                    isValid = false;
                } else if (pattiType === "Single Patti") {
                    validationMessage = "This is a Single Patti. All digits must be different for Single Patti.";
                    isValid = false;
                }
            }
            break;
            
        case 'triple_patti':
            const tripleDigit = document.getElementById('triple-digit-input').value;
            isValid = validateTriplePattiDigit({value: tripleDigit});
            if (!isValid) {
                validationMessage = document.getElementById('triple-patti-validation').textContent;
            } else if (tripleDigit.length !== 1) {
                validationMessage = "Please enter exactly 1 digit";
                isValid = false;
            } else {
                // Create triple patti (digit repeated three times)
                pattiNumber = tripleDigit + tripleDigit + tripleDigit;
                pattiType = "Triple Patti";
            }
            break;
    }
    
    if (!isValid && validationMessage) {
        return;
    }
    
    const amount = parseFloat(document.getElementById('patti-amount').value) || 0;
    
    if (amount <= 0) {
        alert('Please enter a valid amount');
        return;
    }
    
    // Add bet to the array
    const bet = {
        id: Date.now(), // Unique ID for removal
        patti: pattiNumber,
        type: pattiType,
        amount: amount
    };
    
    pattiBets.push(bet);
    updatePattiBetsTable();
    updatePattiTotal();
    
    // Clear the inputs for next bet
    clearPattiInputs();
  }

        function clearPattiInputs() {
            document.getElementById('single-digits-input').value = '';
            document.getElementById('double-digits-input').value = '';
            document.getElementById('triple-digit-input').value = '';
            document.getElementById('patti-amount').value = '1';
            
            // Clear validation messages
            document.getElementById('single-patti-validation').textContent = '';
            document.getElementById('double-patti-validation').textContent = '';
            document.getElementById('triple-patti-validation').textContent = '';
        }

        function removePattiBet(betId) {
            pattiBets = pattiBets.filter(bet => bet.id !== betId);
            updatePattiBetsTable();
            updatePattiTotal();
        }
   function updatePattiBetsTable() {
    const tbody = document.getElementById('patti-bets-tbody');
    tbody.innerHTML = '';
    
    pattiBets.forEach(bet => {
        const row = document.createElement('tr');
        
        // Add CSS class based on patti type for visual indication
        let rowClass = '';
        if (bet.type.includes('AAB')) rowClass = 'double-patti-aab';
        else if (bet.type.includes('ABB')) rowClass = 'double-patti-abb';
        else if (bet.type.includes('ABA')) rowClass = 'double-patti-aba';
        
        row.innerHTML = `
            <td class="${rowClass}">${bet.patti}</td>
            <td>${bet.type}</td>
            <td>INR ${bet.amount.toFixed(2)}</td>
            <td>
                <button type="button" class="remove-bet-btn" onclick="removePattiBet(${bet.id})">
                    Remove
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
    
    document.getElementById('patti-bets-count').textContent = pattiBets.length;
   }
     //Update the updatePattiTotal function to ensure patti type is set
 function updatePattiTotal() {
    const totalAmount = pattiBets.reduce((sum, bet) => sum + bet.amount, 0);
    
    document.getElementById('patti-total-bet-amount').textContent = `INR ${totalAmount.toFixed(2)}`;
    document.getElementById('form-patti-bet-amount').value = totalAmount;
    
    // Update form with all outcomes
    const outcomes = pattiBets.map(bet => ({
        patti: bet.patti,
        type: bet.type,
        amount: bet.amount
    }));
    
    document.getElementById('form-patti-outcomes').value = JSON.stringify(outcomes);
    
    // Set the selected digits (for form submission)
    if (pattiBets.length > 0) {
        document.getElementById('form-patti-digits').value = pattiBets[0].patti;
    } else {
        document.getElementById('form-patti-digits').value = '';
    }
    
    // Make sure patti type is set
    if (currentPattiType === 'single_patti') {
        document.getElementById('form-patti-type').value = 'single';
    } else if (currentPattiType === 'double_patti') {
        document.getElementById('form-patti-type').value = 'double';
    } else if (currentPattiType === 'triple_patti') {
        document.getElementById('form-patti-type').value = 'triple';
    }
    
    // Enable/disable bet button
    const betButton = document.getElementById('place-patti-bet-btn');
    
    if (totalAmount > 0 && totalAmount < 5) {
        betButton.disabled = true;
    } else if (pattiBets.length === 0) {
        betButton.disabled = true;
    } else if (totalAmount <= 0) {
        betButton.disabled = true;
    } else {
        betButton.disabled = false;
    }
 }
        // Update the toggleGameUI function to handle Patti games
        function toggleGameUI(gameType) {
            const isSingleAnk = gameType === 'single_ank';
            const isJodi = gameType === 'jodi';
            const isPatti = ['single_patti', 'double_patti', 'triple_patti'].includes(gameType);
            const isSpecialGame = ['sp_motor', 'dp_motor', 'sp_game', 'dp_game', 'sp_set', 'dp_set', 'tp_set', 'common', 'series', 'single_ank', 'jodi', 'single_patti', 'double_patti', 'triple_patti'].includes(gameType);

            const elementsToToggle = [
                '.chips-container',
                '#numbers-grid',
                '.bet-total',
                '.action-buttons',
                '#bet-form:not(#single-ank-form):not(#jodi-form):not(#patti-form)'
            ];
            
            elementsToToggle.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    el.style.display = isSpecialGame ? 'none' : '';
                });
            });
            
            // Show/hide regular betting interface
            const regularInterface = document.querySelector('.regular-betting-interface');
            if (regularInterface) {
                regularInterface.style.display = isSpecialGame ? 'none' : 'block';
            }
            
            // Show Single Ank interface if it's Single Ank game
            const singleAnkInterface = document.querySelector('.single-ank-interface');
            if (singleAnkInterface) {
                singleAnkInterface.style.display = isSingleAnk ? 'block' : 'none';
                
                if (isSingleAnk) {
                    initializeSingleAnkGame();
                }
            }
            
            // Show Jodi interface if it's Jodi game
            const jodiInterface = document.querySelector('.jodi-interface');
            if (jodiInterface) {
                jodiInterface.style.display = isJodi ? 'block' : 'none';
                
                if (isJodi) {
                    initializeJodiGame();
                }
            }
            
            // Show Patti interface if it's any patti game
            const pattiInterface = document.querySelector('.patti-interface');
            if (pattiInterface) {
                pattiInterface.style.display = isPatti ? 'block' : 'none';
                
                if (isPatti) {
                    initializePattiGame();
                }
            }
            
            // Show other interfaces for other special games
            const spMotorInterface = document.querySelector('.sp-motor-interface');
            if (spMotorInterface) {
                spMotorInterface.style.display = (gameType === 'sp_motor') ? 'block' : 'none';
            }
            
            const dpMotorInterface = document.querySelector('.dp-motor-interface');
            if (dpMotorInterface) {
                dpMotorInterface.style.display = (gameType === 'dp_motor') ? 'block' : 'none';
            }
            
            const spGameInterface = document.querySelector('.sp-game-interface');
            if (spGameInterface) {
                spGameInterface.style.display = (gameType === 'sp_game') ? 'block' : 'none';
            }
            
            const dpGameInterface = document.querySelector('.dp-game-interface');
            if (dpGameInterface) {
                dpGameInterface.style.display = (gameType === 'dp_game') ? 'block' : 'none';
            }
            
            const spSetInterface = document.querySelector('.sp-set-interface');
            if (spSetInterface) {
                spSetInterface.style.display = (gameType === 'sp_set') ? 'block' : 'none';
            }
            
            const dpSetInterface = document.querySelector('.dp-set-interface');
            if (dpSetInterface) {
                dpSetInterface.style.display = (gameType === 'dp_set') ? 'block' : 'none';
            }
            
            const tpSetInterface = document.querySelector('.tp-set-interface');
            if (tpSetInterface) {
                tpSetInterface.style.display = (gameType === 'tp_set') ? 'block' : 'none';
            }
            
            const commonInterface = document.querySelector('.common-interface');
            if (commonInterface) {
                commonInterface.style.display = (gameType === 'common') ? 'block' : 'none';
            }
            
            const seriesInterface = document.querySelector('.series-interface');
            if (seriesInterface) {
                seriesInterface.style.display = (gameType === 'series') ? 'block' : 'none';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const gameType = '<?php echo $game_type; ?>';
            toggleGameUI(gameType);
            
            if (gameType === 'single_ank') {
                initializeSingleAnkGame();
            } else if (gameType === 'jodi') {
                initializeJodiGame();
            } else if (['single_patti', 'double_patti', 'triple_patti'].includes(gameType)) {
                initializePattiGame();
            }
        });
        // Modal Logic for Patti Games
        function initializePattiGame() {
            // Set current patti type based on URL
            currentPattiType = '<?php echo $game_type; ?>';
            document.getElementById('form-patti-type').value = currentPattiType;
            
            // Update UI based on patti type
            updatePattiControls();
            
            // Add bet button
            document.getElementById('add-patti-bet').addEventListener('click', function() {
                addPattiBet();
            });
            
            // Show All Pannas button
            document.getElementById('show-all-pannas-btn').addEventListener('click', function() {
                showAllPannas();
            });
            
            // Update patti total when amount changes
            document.getElementById('patti-amount').addEventListener('input', updatePattiTotal);
            
            // Initialize mode toggle for Patti
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-patti-mode').value = mode;
                });
            });
            
            // Initialize modal close functionality
            initializeModal();
        }

        function updatePattiControls() {
            // Hide all controls first
            document.querySelectorAll('[id$="-controls"]').forEach(control => {
                control.style.display = 'none';
            });
            
            const showAllBtn = document.getElementById('show-all-pannas-btn');
            
            // Show appropriate controls based on patti type
            switch(currentPattiType) {
                case 'single_patti':
                    document.getElementById('single-patti-controls').style.display = 'block';
                    document.getElementById('patti-instructions').textContent = 'Enter three different digits (0-9) to form a Single Patti';
                    showAllBtn.style.display = 'block';
                    showAllBtn.textContent = 'Show All Single Pannas (120)';
                    break;
                case 'double_patti':
                    document.getElementById('double-patti-controls').style.display = 'block';
                    document.getElementById('patti-instructions').textContent = 'Enter three digits with exactly two same digits (e.g., 112, 233, 455)';
                    showAllBtn.style.display = 'block';
                    showAllBtn.textContent = 'Show All Double Pannas (90)';
                    break;
                case 'triple_patti':
                    document.getElementById('triple-patti-controls').style.display = 'block';
                    document.getElementById('patti-instructions').textContent = 'Enter one digit to create Triple Patti (e.g., 111, 222)';
                    showAllBtn.style.display = 'none';
                    break;
            }
        }

        function initializeModal() {
            // Close modal when clicking X
            document.querySelectorAll('.close-modal').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    this.closest('.modal').style.display = 'none';
                });
            });
            
            // Close modal when clicking close button
            document.querySelectorAll('.close-modal-btn').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    this.closest('.modal').style.display = 'none';
                });
            });
            
            // Close modal when clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
            });
        }

        function showAllPannas() {
            switch(currentPattiType) {
                case 'single_patti':
                    showSinglePattiOutcomes();
                    break;
                case 'double_patti':
                    showDoublePattiOutcomes();
                    break;
            }
        }

        function showSinglePattiOutcomes() {
            const outcomes = generateAllSinglePattiOutcomes();
            const grid = document.getElementById('single-patti-outcomes-grid');
            
            grid.innerHTML = '';
            outcomes.forEach(panna => {
                const pannaItem = document.createElement('div');
                pannaItem.className = 'panna-item';
                pannaItem.textContent = panna;
                grid.appendChild(pannaItem);
            });
            
            document.getElementById('single-patti-modal').style.display = 'block';
        }

        function showDoublePattiOutcomes() {
            const outcomes = generateAllDoublePattiOutcomes();
            const grid = document.getElementById('double-patti-outcomes-grid');
            
            grid.innerHTML = '';
            outcomes.forEach(panna => {
                const pannaItem = document.createElement('div');
                pannaItem.className = 'panna-item';
                pannaItem.textContent = panna;
                grid.appendChild(pannaItem);
            });
            
            document.getElementById('double-patti-modal').style.display = 'block';
        }

        function generateAllSinglePattiOutcomes() {
            const outcomes = [];
            
            // Generate all ascending 3-digit combinations with distinct digits
            // Using digits 0-9 where 0 means 10, but showing as 0 in display
            for (let i = 0; i <= 9; i++) {
                for (let j = i + 1; j <= 9; j++) {
                    for (let k = j + 1; k <= 9; k++) {
                        // Create the panna in ascending order
                        const panna = i.toString() + j.toString() + k.toString();
                        outcomes.push(panna);
                    }
                }
            }
            
            return outcomes.sort();
        }
        // Replace the generateAllDoublePattiOutcomes function with:
        function generateAllDoublePattiOutcomes() {
            // Complete list of 90 double patti outcomes
            return [
                // 100 series (17 outcomes)
                '100','110', '112', '113', '114', '115', '116', '117', '118', '119', 
                '122', '133', '144', '155', '166', '177', '188', '199',
                
                // 200 series (15 outcomes)
                '200', '220', '223', '224', '225', '226', '227', '228', '229',
                '233', '244', '255', '266', '277', '288', '299',
                
                // 300 series (13 outcomes)
                '300', '330', '334', '335', '336', '337', '338', '339',
                '344', '355', '366', '377', '388', '399',
                
                // 400 series (11 outcomes)
                '400', '440', '445', '446', '447', '448', '449',
                '455', '466', '477', '488', '499',
                
                // 500 series (9 outcomes)
                '500', '550', '556', '557', '558', '559',
                '566', '577', '588', '599',
                
                // 600 series (7 outcomes)
                '600', '660', '667', '668', '669',
                '677', '688', '699',
                
                // 700 series (5 outcomes)
                '700', '770', '778', '779',
                '788', '799',
                
                // 800 series (3 outcomes)
                '800', '880', '889', '899',
                
                // 900 series (2 outcomes)
                '900', '990'
            ].sort();
        }
        // And then in the validateDoublePattiDigits function, we change the validation to use the list:
        function validateDoublePattiDigits(input) {
            const value = input.value.replace(/[^0-9]/g, '');
            input.value = value;
            
            const validationEl = document.getElementById('double-patti-validation');
            
            if (value.length !== 3) {
                validationEl.textContent = "Please enter exactly 3 digits";
                return false;
            }
            
            // Check if it's a valid double patti from the fixed list of 90
            const validDoublePattis = generateAllDoublePattiOutcomes();
            if (!validDoublePattis.includes(value)) {
                validationEl.textContent = "Not a valid Double Patti. Please enter a valid Double Patti from the list.";
                return false;
            }
            
            validationEl.textContent = "";
            return true;
        }

        // Update the toggleGameUI function to initialize modal when patti game is shown
        function toggleGameUI(gameType) {
            const isSingleAnk = gameType === 'single_ank';
            const isJodi = gameType === 'jodi';
            const isPatti = ['single_patti', 'double_patti', 'triple_patti'].includes(gameType);
            const isSpecialGame = ['sp_motor', 'dp_motor', 'sp_game', 'dp_game', 'sp_set', 'dp_set', 'tp_set', 'common', 'series', 'single_ank', 'jodi', 'single_patti', 'double_patti', 'triple_patti'].includes(gameType);

            const elementsToToggle = [
                '.chips-container',
                '#numbers-grid',
                '.bet-total',
                '.action-buttons',
                '#bet-form:not(#single-ank-form):not(#jodi-form):not(#patti-form)'
            ];
            
            elementsToToggle.forEach(selector => {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    el.style.display = isSpecialGame ? 'none' : '';
                });
            });
            
            // Show/hide regular betting interface
            const regularInterface = document.querySelector('.regular-betting-interface');
            if (regularInterface) {
                regularInterface.style.display = isSpecialGame ? 'none' : 'block';
            }
            
            // Show Single Ank interface if it's Single Ank game
            const singleAnkInterface = document.querySelector('.single-ank-interface');
            if (singleAnkInterface) {
                singleAnkInterface.style.display = isSingleAnk ? 'block' : 'none';
                
                if (isSingleAnk) {
                    initializeSingleAnkGame();
                }
            }
            
            // Show Jodi interface if it's Jodi game
            const jodiInterface = document.querySelector('.jodi-interface');
            if (jodiInterface) {
                jodiInterface.style.display = isJodi ? 'block' : 'none';
                
                if (isJodi) {
                    initializeJodiGame();
                }
            }
            
            // Show Patti interface if it's any patti game
            const pattiInterface = document.querySelector('.patti-interface');
            if (pattiInterface) {
                pattiInterface.style.display = isPatti ? 'block' : 'none';
                
                if (isPatti) {
                    initializePattiGame();
                }
            }
            
            // Show other interfaces for other special games
            const spMotorInterface = document.querySelector('.sp-motor-interface');
            if (spMotorInterface) {
                spMotorInterface.style.display = (gameType === 'sp_motor') ? 'block' : 'none';
            }
            
            const dpMotorInterface = document.querySelector('.dp-motor-interface');
            if (dpMotorInterface) {
                dpMotorInterface.style.display = (gameType === 'dp_motor') ? 'block' : 'none';
            }
            
            const spGameInterface = document.querySelector('.sp-game-interface');
            if (spGameInterface) {
                spGameInterface.style.display = (gameType === 'sp_game') ? 'block' : 'none';
            }
            
            const dpGameInterface = document.querySelector('.dp-game-interface');
            if (dpGameInterface) {
                dpGameInterface.style.display = (gameType === 'dp_game') ? 'block' : 'none';
            }
            
            const spSetInterface = document.querySelector('.sp-set-interface');
            if (spSetInterface) {
                spSetInterface.style.display = (gameType === 'sp_set') ? 'block' : 'none';
            }
            
            const dpSetInterface = document.querySelector('.dp-set-interface');
            if (dpSetInterface) {
                dpSetInterface.style.display = (gameType === 'dp_set') ? 'block' : 'none';
            }
            
            const tpSetInterface = document.querySelector('.tp-set-interface');
            if (tpSetInterface) {
                tpSetInterface.style.display = (gameType === 'tp_set') ? 'block' : 'none';
            }
            
            const commonInterface = document.querySelector('.common-interface');
            if (commonInterface) {
                commonInterface.style.display = (gameType === 'common') ? 'block' : 'none';
            }
            
            const seriesInterface = document.querySelector('.series-interface');
            if (seriesInterface) {
                seriesInterface.style.display = (gameType === 'series') ? 'block' : 'none';
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const gameType = '<?php echo $game_type; ?>';
            toggleGameUI(gameType);
            
            if (gameType === 'single_ank') {
                initializeSingleAnkGame();
            } else if (gameType === 'jodi') {
                initializeJodiGame();
            } else if (['single_patti', 'double_patti', 'triple_patti'].includes(gameType)) {
                initializePattiGame();
            }
        });
</script>

<!--SP Motor -->
<script>
                function getSpMotorDigitValue(digit) {
                return digit === '0' ? 10 : parseInt(digit);
            }

            function formatSpMotorDigitForDisplay(digit) {
                return digit === 10 ? '0' : digit.toString();
            }

            function validateSpMotorDigits(input) {
                const value = input.value.replace(/[^0-9]/g, '');
                input.value = value;
                
                const validationEl = document.getElementById('digit-validation');
                const digits = value.split('').map(d => getSpMotorDigitValue(d));
                const uniqueDigits = [...new Set(digits)];
                
                if (value.length < 4) {
                    validationEl.textContent = "Please enter at least 4 digits";
                    updatePanaCombinations([]);
                    return;
                }
                
                if (value.length > 9) {
                    validationEl.textContent = "Maximum 9 digits allowed";
                    input.value = value.substring(0, 9);
                    return;
                }
                
                if (uniqueDigits.length !== digits.length) {
                    validationEl.textContent = "All digits must be different";
                    updatePanaCombinations([]);
                    return;
                }
                
                validationEl.textContent = "";
                
                // Generate pana combinations with 0 as 10 logic
                generatePanaCombinations(digits);
            }

            // Update SP Motor pana generation
            function generatePanaCombinations(digits) {
                let combinations = [];
                digits = digits.map(d => getSpMotorDigitValue(d)).sort((a, b) => a - b);
                
                const digitCount = digits.length;
                
                for (let i = 0; i < digitCount; i++) {
                    for (let j = i + 1; j < digitCount; j++) {
                        for (let k = j + 1; k < digitCount; k++) {
                            const combination = [digits[i], digits[j], digits[k]].sort((a, b) => a - b);
                            // Convert back to string for display, showing 0 for 10
                            const pana = combination.map(d => d === 10 ? '0' : d.toString()).join('');
                            if (!combinations.includes(pana)) {
                                combinations.push(pana);
                            }
                        }
                    }
                }
                
                combinations.sort();
                updatePanaCombinations(combinations);
            }

        // Expected outcomes for different digit lengths
        function getExpectedOutcomes(digitCount) {
            const outcomes = {
                4: 4,   // 4C3 = 4
                5: 10,  // 5C3 = 10
                6: 20,  // 6C3 = 20
                7: 35,  // 7C3 = 35
                8: 56,  // 8C3 = 56
                9: 84   // 9C3 = 84
            };
            return outcomes[digitCount] || 0;
        }

        let currentPanaCombinations = [];
        let removedPanas = [];

                // Update SP Motor display to show 0 as 10 in info
            function updatePanaCombinations(combinations) {
                const container = document.getElementById('pana-combinations-container');
                const digitsInput = document.getElementById('sp-motor-digits').value;
                
                // Convert display digits to show 0 as 10
                const displayDigits = digitsInput.split('').map(d => 
                    d === '0' ? '0(10)' : d
                ).join(', ');
                
                currentPanaCombinations = combinations;
                removedPanas = [];
                
                if (combinations.length === 0) {
                    if (digitsInput.length >= 4) {
                        container.innerHTML = '<div class="no-digits">Invalid digits - all must be different</div>';
                    } else {
                        container.innerHTML = '<div class="no-digits">Please enter 4-9 digits to generate pana combinations</div>';
                    }
                } else {
                    const digitCount = digitsInput.length;
                    const expectedOutcomes = getExpectedOutcomes(digitCount);
                    
                    let html = `
                        <div class="pana-header">
                            <h4>Generated Pana Combinations (${combinations.length}/${expectedOutcomes})</h4>
                            <div class="digit-info">From ${digitCount} digits: ${displayDigits}</div>
                            <div class="pana-actions-buttons">
                            
                            </div>
                        </div>
                        <div class="pana-list">
                    `;
                    
                    combinations.forEach(pana => {
                        // Calculate sum for display (treating 0 as 10)
                        const panaDigits = pana.split('').map(d => getSpMotorDigitValue(d));
                        const sum = panaDigits.reduce((a, b) => a + b, 0);
                        const sumDisplay = panaDigits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + sum;
                        
                        html += `
                            <div class="pana-item" data-pana="${pana}">
                                <div class="pana-value-container">
                                    <span class="pana-value">${pana}</span>
                                </div>
                                <div class="pana-actions">
                                   
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
                
                document.getElementById('pana-count').textContent = combinations.length;
                document.getElementById('form-selected-digits').value = digitsInput;
                updateSpMotorTotal();
            }

        // function togglePana(pana) {
        //     const panaItem = document.querySelector(`.pana-item[data-pana="${pana}"]`);
        //     const actionBtn = panaItem.querySelector('.pana-actions button');
            
        //     if (removedPanas.includes(pana)) {
        //         // Restore the pana
        //         removedPanas = removedPanas.filter(p => p !== pana);
        //         panaItem.classList.remove('removed');
        //         actionBtn.textContent = 'Remove';
        //         actionBtn.className = 'remove-pana';
        //         actionBtn.onclick = function() { togglePana(pana); };
        //     } else {
        //         // Remove the pana
        //         removedPanas.push(pana);
        //         panaItem.classList.add('removed');
        //         actionBtn.textContent = 'Restore';
        //         actionBtn.className = 'restore-pana';
        //         actionBtn.onclick = function() { togglePana(pana); };
        //     }
            
        //     updateSpMotorTotal();
        // }

        // function selectAllPanas() {
        //     removedPanas = [];
        //     document.querySelectorAll('.pana-item').forEach(item => {
        //         const pana = item.dataset.pana;
        //         const actionBtn = item.querySelector('.pana-actions button');
                
        //         item.classList.remove('removed');
        //         actionBtn.textContent = 'Remove';
        //         actionBtn.className = 'remove-pana';
        //         actionBtn.onclick = function() { togglePana(pana); };
        //     });
        //     updateSpMotorTotal();
        // }

        // function deselectAllPanas() {
        //     removedPanas = [...currentPanaCombinations];
        //     document.querySelectorAll('.pana-item').forEach(item => {
        //         const pana = item.dataset.pana;
        //         const actionBtn = item.querySelector('.pana-actions button');
                
        //         item.classList.add('removed');
        //         actionBtn.textContent = 'Restore';
        //         actionBtn.className = 'restore-pana';
        //         actionBtn.onclick = function() { togglePana(pana); };
        //     });
        //     updateSpMotorTotal();
        // }

        function updateSpMotorTotal() {
            const amount = parseFloat(document.getElementById('sp-motor-amount').value) || 0;
            const activePanaCount = currentPanaCombinations.length - removedPanas.length;
            const total = amount * activePanaCount;
            
            document.getElementById('pana-count').textContent = activePanaCount;
            document.getElementById('amount-per-pana').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-sp-motor-bet-amount').value = amount;
            
            // Update form with active pana combinations
            const activePanas = currentPanaCombinations.filter(pana => !removedPanas.includes(pana));
            document.getElementById('form-pana-combinations').value = JSON.stringify(activePanas);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-sp-motor-bet-btn');
            const validationEl = document.getElementById('digit-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activePanaCount === 0) {
                validationEl.textContent = "Please select at least one pana combination";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Update the input field to show digit limit
        document.addEventListener('DOMContentLoaded', function() {
            const digitsInput = document.getElementById('sp-motor-digits');
            digitsInput.placeholder = "e.g., 1234 (4-9 different digits)";
            
            document.getElementById('sp-motor-amount').addEventListener('input', updateSpMotorTotal);
            
            // Set mode toggle for SP Motor
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    document.querySelectorAll('.toggle-option').forEach(o => o.classList.remove('active'));
                    this.classList.add('active');
                    
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-bet-mode').value = mode;
                });
            });
        });
</script>

<!-- dp motor -->
<script>
            function getDpMotorDigitValue(digit) {
            return digit === '0' ? 10 : parseInt(digit);
        }

        function formatDpMotorDigitForDisplay(digit) {
            return digit === 10 ? '0' : digit.toString();
        }

        // Update DP Motor validation
        function validateDpMotorDigits(input) {
            const value = input.value.replace(/[^0-9]/g, '');
            input.value = value;
            
            const validationEl = document.getElementById('dp-digit-validation');
            const digits = value.split('').map(d => getDpMotorDigitValue(d));
            
            if (value.length < 4) {
                validationEl.textContent = "Please enter at least 4 digits";
                updateDpPanaCombinations([]);
                return;
            }
            
            if (value.length > 9) {
                validationEl.textContent = "Maximum 9 digits allowed";
                input.value = value.substring(0, 9);
                return;
            }
            
            validationEl.textContent = "";
            
            // Generate DP Motor pana combinations with 0 as 10 logic
            generateDpPanaCombinations(digits);
        }

        // Update DP Motor pana generation
        function generateDpPanaCombinations(digits) {
            let combinations = [];
            
            // Get unique digits (to handle duplicates) with 0 as 10
            const uniqueDigits = [...new Set(digits)];
            const uniqueCount = uniqueDigits.length;
            
            const expectedOutcomes = getDpExpectedOutcomes(uniqueCount);
            
            for (let i = 0; i < uniqueDigits.length; i++) {
                for (let j = 0; j < uniqueDigits.length; j++) {
                    if (i !== j) {
                        const combination1 = [uniqueDigits[i], uniqueDigits[i], uniqueDigits[j]].sort((a, b) => a - b);
                        const pana1 = combination1.map(d => d === 10 ? '0' : d.toString()).join('');
                        
                        const combination2 = [uniqueDigits[i], uniqueDigits[j], uniqueDigits[j]].sort((a, b) => a - b);
                        const pana2 = combination2.map(d => d === 10 ? '0' : d.toString()).join('');
                        
                        if (!combinations.includes(pana1)) {
                            combinations.push(pana1);
                        }
                        if (!combinations.includes(pana2)) {
                            combinations.push(pana2);
                        }
                    }
                }
            }
            
            combinations.sort();
            updateDpPanaCombinations(combinations, uniqueDigits, expectedOutcomes);
        }
        // Expected outcomes for different unique digit counts
        function getDpExpectedOutcomes(uniqueCount) {
            const outcomes = {
                4: 12,   // 4*(4-1) = 12
                5: 20,   // 5*(5-1) = 20
                6: 30,   // 6*(6-1) = 30
                7: 42,   // 7*(7-1) = 42
                8: 56,   // 8*(8-1) = 56
                9: 72    // 9*(9-1) = 72
            };
            return outcomes[uniqueCount] || 0;
        }

        // Function to count unique digits in a pana
        function getPanaType(pana) {
            const digits = pana.split('').map(Number);
            const uniqueDigits = [...new Set(digits)];
            
            if (uniqueDigits.length === 1) {
                return 'triple';
            } else if (uniqueDigits.length === 2) {
                return 'double';
            } else {
                return 'single';
            }
        }

        let currentDpPanaCombinations = [];
        let removedDpPanas = [];
        // Update DP Motor display
        function updateDpPanaCombinations(combinations, uniqueDigits = [], expectedOutcomes = 0) {
            const container = document.getElementById('dp-pana-combinations-container');
            const digitsInput = document.getElementById('dp-motor-digits').value;
            
            // Convert display digits to show 0 as 10
            const displayDigits = digitsInput.split('').map(d => 
                d === '0' ? '0(10)' : d
            ).join(', ');
            
            currentDpPanaCombinations = combinations;
            removedDpPanas = [];
            
            if (combinations.length === 0) {
                if (digitsInput.length >= 4) {
                    container.innerHTML = '<div class="no-digits">No valid combinations found</div>';
                } else {
                    container.innerHTML = '<div class="no-digits">Please enter 4-9 digits to generate pana combinations</div>';
                }
            } else {
                let html = `
                    <div class="pana-header">
                        <h4>DP Motor Pana Combinations (${combinations.length}/${expectedOutcomes})</h4>
                        <div class="digit-info">From digits: ${displayDigits}</div>
                        <div class="pana-actions-buttons">
                         
                     
                        </div>
                    </div>
                    <div class="pana-list">
                `;
                
                combinations.forEach(pana => {
                    const panaType = getPanaType(pana);
                    const typeClass = panaType === 'double' ? 'dp-pana' : panaType === 'triple' ? 'tp-pana' : '';
                    const typeText = panaType === 'double' ? 'DP' : panaType === 'triple' ? 'TP' : 'SP';
                    
                    // Calculate sum for display (treating 0 as 10)
                    const panaDigits = pana.split('').map(d => getDpMotorDigitValue(d));
                    const sum = panaDigits.reduce((a, b) => a + b, 0);
                    const sumDisplay = panaDigits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + sum;
                    
                    html += `
                        <div class="pana-item ${typeClass}" data-pana="${pana}">
                            <div class="pana-value-container">
                                <span class="pana-value">${pana}</span>
                              
                            </div>
                        
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            document.getElementById('dp-pana-count').textContent = combinations.length;
            document.getElementById('form-dp-selected-digits').value = digitsInput;
            updateDpMotorTotal();
        }

        

        function updateDpMotorTotal() {
            const amount = parseFloat(document.getElementById('dp-motor-amount').value) || 0;
            const activePanaCount = currentDpPanaCombinations.length - removedDpPanas.length;
            const total = amount * activePanaCount;
            
            document.getElementById('dp-pana-count').textContent = activePanaCount;
            document.getElementById('dp-amount-per-pana').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('dp-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-dp-motor-bet-amount').value = amount;
            
            // Update form with active pana combinations
            const activePanas = currentDpPanaCombinations.filter(pana => !removedDpPanas.includes(pana));
            document.getElementById('form-dp-pana-combinations').value = JSON.stringify(activePanas);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-dp-motor-bet-btn');
            const validationEl = document.getElementById('dp-digit-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activePanaCount === 0) {
                validationEl.textContent = "Please select at least one pana combination";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Initialize DP Motor
        document.addEventListener('DOMContentLoaded', function() {
            const dpDigitsInput = document.getElementById('dp-motor-digits');
            dpDigitsInput.placeholder = "e.g., 1234 (4-9 digits)";
            dpDigitsInput.addEventListener('input', function() {
                validateDpMotorDigits(this);
            });
            
            document.getElementById('dp-motor-amount').addEventListener('input', updateDpMotorTotal);
            
            // Set mode toggle for DP Motor
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-dp-motor-mode').value = mode;
                });
            });
        });
</script>

 <!-- // SP Game -->
<script>
        function getSpOutcomes(digit) {
            // All possible 3-digit combinations where sum ends with the selected digit
            const allCombinations = [];
            
            // Generate all possible 3-digit combinations (000-999)
            for (let i = 0; i <= 999; i++) {
                const numStr = i.toString().padStart(3, '0');
                const digits = numStr.split('').map(Number);
                const sum = digits.reduce((a, b) => a + b, 0);
                const lastDigit = sum % 10;
                
                // Check if sum ends with selected digit
                if (lastDigit === parseInt(digit)) {
                    allCombinations.push(numStr);
                }
            }
            
            // Filter to get exactly 12 diverse outcomes
            const spOutcomes = [];
            
            // Ensure we have diverse combinations (different patterns)
            const patterns = new Set();
            
            for (const combo of allCombinations) {
                if (spOutcomes.length >= 12) break;
                
                const pattern = combo.split('').sort().join(''); // Sort digits to identify pattern
                if (!patterns.has(pattern)) {
                    patterns.add(pattern);
                    spOutcomes.push(combo);
                }
            }
            
            // If we don't have enough diverse patterns, fill with remaining
            if (spOutcomes.length < 12) {
                for (const combo of allCombinations) {
                    if (spOutcomes.length >= 12) break;
                    if (!spOutcomes.includes(combo)) {
                        spOutcomes.push(combo);
                    }
                }
            }
            
            return spOutcomes.slice(0, 12);
        }

        // Specific outcomes for digit 1 (as per your example)
        const specificOutcomes = {
            1: ['128', '137', '146', '236', '245', '290', '380', '470', '489', '560', '678', '579'],
            2: ['129', '138', '147', '156', '237', '246', '679', '589', '345', '390', '480', '570'],
            3: ['139', '148', '157', '120', '238', '247', '256', '670', '346', '689', '490', '580'],
            4: ['149', '158', '167', '239', '248', '257', '130', '789', '347', '356', '590', '680'],
            5: ['159', '168', '140', '249', '258', '267', '230', '348', '357', '456', '690', '780'],
            6: ['169', '178', '259', '268', '123', '349', '358', '367', '150', '457', '790', '240'],
            7: ['124', '179', '269', '278', '359', '368', '160', '340', '458', '467', '890', '250'],
            8: ['125', '134', '170', '189', '260', '279', '350', '369', '378', '459', '567', '468'],
            9: ['126', '135', '180', '234', '270', '289', '360', '379', '450', '469', '478', '568'],
            0: ['127', '190', '280', '370', '460', '136', '479', '569', '389', '235', '145', '578']
        };

        function getSpOutcomes(digit) {
            return specificOutcomes[digit] || [];
        }

        let currentSpOutcomes = [];
        let removedSpOutcomes = [];

        function generateSpOutcomes() {
            const digitSelect = document.getElementById('sp-game-digit');
            const selectedDigit = digitSelect.value;
            
            if (!selectedDigit) {
                updateSpOutcomes([]);
                return;
            }
            
            const outcomes = getSpOutcomes(parseInt(selectedDigit));
            updateSpOutcomes(outcomes, selectedDigit);
        }

        function updateSpOutcomes(outcomes, selectedDigit) {
            const container = document.getElementById('sp-outcomes-container');
            
            currentSpOutcomes = outcomes;
            removedSpOutcomes = [];
            
            if (outcomes.length === 0) {
                container.innerHTML = '<div class="no-digits">Please select a digit to generate SP outcomes</div>';
            } else {
                let html = `
                    <div class="pana-header">
                        <h4>SP Outcomes for Digit ${selectedDigit} ${selectedDigit === '0' ? '(10)' : ''}</h4>
                        <div class="digit-info">12 SP outcomes where sum ends with ${selectedDigit}</div>
                        <div class="pana-actions-buttons">
                         
                        </div>
                    </div>
                    <div class="pana-list">
                `;
                
                outcomes.forEach((outcome) => {
                    const digits = outcome.split('').map(Number);
                    const sum = digits.reduce((a, b) => a + b, 0);
                    const sumText = digits.join('+') + '=' + sum;
                    
                    html += `
                        <div class="pana-item" data-outcome="${outcome}">
                            <div class="pana-value-container">
                                <span class="pana-value">${outcome}</span>
                                <span class="outcome-sum">${sumText}</span>
                            </div>
                        
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            // Update counts and totals
            document.getElementById('sp-outcomes-count').textContent = outcomes.length;
            document.getElementById('form-selected-digit').value = selectedDigit;
            updateSpGameTotal();
        }


        function updateSpGameTotal() {
            const amount = parseFloat(document.getElementById('sp-game-amount').value) || 0;
            const activeOutcomeCount = currentSpOutcomes.length - removedSpOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            document.getElementById('sp-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('sp-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('sp-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-sp-game-bet-amount').value = amount;
            
            // Update form with active outcomes
            const activeOutcomes = currentSpOutcomes.filter(outcome => !removedSpOutcomes.includes(outcome));
            document.getElementById('form-sp-outcomes').value = JSON.stringify(activeOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-sp-game-bet-btn');
            
            if (total > 0 && total < 5) {
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                betButton.disabled = true;
            } else if (amount <= 0) {
                betButton.disabled = true;
            } else {
                betButton.disabled = false;
            }
        }

        // Initialize SP Game
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('sp-game-amount').addEventListener('input', updateSpGameTotal);
            
            // Set mode toggle for SP Game
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-sp-game-mode').value = mode;
                });
            });
        });
</script>

<!-- // DP Game -->
<script>
   const dpOutcomesData = {
            0: ['118', '226', '244', '299', '334', '488', '668', '677','550'],
            1: ['100', '119', '227', '155', '335', '344', '399', '588','669'],
            2: ['200', '110', '228', '255', '336', '499', '660', '688','778'],
            3: ['300', '166', '229', '337', '355', '445', '599', '779','788'],
            4: ['400', '220', '266', '338', '446', '455', '699', '770','112'],
            5: ['500', '113', '122', '177', '339', '366', '447', '799','889'],
            6: ['600', '114', '277', '330', '448', '466', '556', '880','899'],
            7: ['700', '115', '133', '188', '223', '377', '449', '557','566'],
            8: ['800', '116', '224', '233', '288', '440', '477', '558','990'],
            9: ['900', '144', '225', '199', '388', '559', '577', '667','117']
        };

        function getDpOutcomes(digit) {
            return dpOutcomesData[digit] || [];
        }

        let currentDpOutcomes = [];
        let removedDpOutcomes = [];

        function generateDpOutcomes() {
            const digitSelect = document.getElementById('dp-game-digit');
            const selectedDigit = digitSelect.value;
            
            if (!selectedDigit) {
                updateDpOutcomes([]);
                return;
            }
            
            const outcomes = getDpOutcomes(parseInt(selectedDigit));
            updateDpOutcomes(outcomes, selectedDigit);
        }

        function updateDpOutcomes(outcomes, selectedDigit) {
            const container = document.getElementById('dp-outcomes-container');
            
            currentDpOutcomes = outcomes;
            removedDpOutcomes = [];
            
            if (outcomes.length === 0) {
                container.innerHTML = '<div class="no-digits">Please select a digit to generate DP outcomes</div>';
            } else {
                let html = `
                    <div class="pana-header">
                        <h4>DP Outcomes for Digit ${selectedDigit}</h4>
                    </div>
                    <div class="pana-list">
                `;
                
                outcomes.forEach((outcome) => {
                    const digits = outcome.split('').map(Number);
                    const sum = digits.reduce((a, b) => a + b, 0);
                    const sumText = digits.join('+') + '=' + sum;
                    
                    html += `
                        <div class="pana-item" data-outcome="${outcome}">
                            <div class="pana-value-container">
                                <span class="pana-value">${outcome}</span>
                                <span class="outcome-sum">${sumText}</span>
                            </div>
                        
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            // Update counts and totals
            document.getElementById('dp-outcomes-count').textContent = outcomes.length;
            document.getElementById('form-dp-selected-digit').value = selectedDigit;
            updateDpGameTotal();
        }


        function updateDpGameTotal() {
            const amount = parseFloat(document.getElementById('dp-game-amount').value) || 0;
            const activeOutcomeCount = currentDpOutcomes.length - removedDpOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            document.getElementById('dp-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('dp-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('dp-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-dp-game-bet-amount').value = amount;
            
            // Update form with active outcomes
            const activeOutcomes = currentDpOutcomes.filter(outcome => !removedDpOutcomes.includes(outcome));
            document.getElementById('form-dp-outcomes').value = JSON.stringify(activeOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-dp-game-bet-btn');
            
            if (total > 0 && total < 5) {
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                betButton.disabled = true;
            } else if (amount <= 0) {
                betButton.disabled = true;
            } else {
                betButton.disabled = false;
            }
        }

        // Initialize DP Game
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('dp-game-amount').addEventListener('input', updateDpGameTotal);
            
            // Set mode toggle for DP Game
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-dp-game-mode').value = mode;
                });
            });
        });
</script>

<!-- // SP Set  -->
<script>
                    // SP Set Configuration
            const spSetMapping = {
                0: 1, 5: 1,  // Set 1: 0,5 (0 means 10 in calculation)
                1: 2, 6: 2,  // Set 2: 1,6  
                2: 3, 7: 3,  // Set 3: 2,7
                3: 4, 8: 4,  // Set 4: 3,8
                4: 5, 9: 5   // Set 5: 4,9
            };

            // SP Set Helper Functions
            function getSpSetDigitValue(digit) {
                return digit === '0' ? 10 : parseInt(digit);
            }

            function getSpSetPair(digit) {
                // For set pairing, we use the original digit (0-9)
                const originalDigit = digit === 10 ? 0 : digit;
                
                for (const [d, set] of Object.entries(spSetMapping)) {
                    const numD = parseInt(d);
                    if (set === spSetMapping[originalDigit] && numD !== originalDigit) {
                        return numD;
                    }
                }
                return null;
            }

            // SP Set Validation
            function validateSpSetDigits(input) {
                const value = input.value.replace(/[^0-9]/g, '');
                input.value = value;
                
                const validationEl = document.getElementById('sp-set-validation');
                
                if (value.length !== 3) {
                    validationEl.textContent = "Please enter exactly 3 digits";
                    updateSpSetOutcomes([]);
                    return;
                }
                
                const digits = value.split('').map(d => getSpSetDigitValue(d));
                const uniqueDigits = [...new Set(digits)];
                
                if (uniqueDigits.length !== 3) {
                    validationEl.textContent = "All digits must be different";
                    updateSpSetOutcomes([]);
                    return;
                }
                
                // Check if digits are from different sets
                const usedSets = new Set();
                for (const digit of digits) {
                    // For set checking, convert 10 back to 0
                    const setDigit = digit === 10 ? 0 : digit;
                    const set = spSetMapping[setDigit];
                    if (usedSets.has(set)) {
                        const displayDigit = digit === 10 ? '0(10)' : digit;
                        validationEl.textContent = `Digits must be from different sets. ${displayDigit} is in set ${set}`;
                        updateSpSetOutcomes([]);
                        return;
                    }
                    usedSets.add(set);
                }
                
                validationEl.textContent = "";
                generateSpSetOutcomes(digits);
            }

            // SP Set Outcome Generation
            function generateSpSetOutcomes(digits) {
                // Store original digits for display
                const displayDigits = digits.map(d => d === 10 ? 0 : d);
                
                // Step 1: Get all 6 digits (original 3 + their set pairs)
                const allDigits = [...digits];
                for (const digit of digits) {
                    const pairDigit = getSpSetPair(digit);
                    if (pairDigit !== null && !allDigits.includes(pairDigit)) {
                        allDigits.push(pairDigit);
                    }
                }
                
                // Step 2: Calculate sum of original 3 digits (0 becomes 10)
                const originalSum = digits.reduce((a, b) => a + b, 0);
                const targetDigit1 = originalSum % 10;
                const targetDigit2 = getSpSetPair(targetDigit1);
                
                if (targetDigit2 === null) {
                    updateSpSetOutcomes([]);
                    return;
                }
                
                // Step 3: Generate all possible 3-digit combinations from the 6 digits
                let allCombinations = [];
                const digitCount = allDigits.length;
                
                for (let i = 0; i < digitCount; i++) {
                    for (let j = i + 1; j < digitCount; j++) {
                        for (let k = j + 1; k < digitCount; k++) {
                            const combination = [allDigits[i], allDigits[j], allDigits[k]].sort((a, b) => a - b);
                            const pana = combination.map(d => d === 10 ? '0' : d.toString()).join('');
                            
                            if (!allCombinations.includes(pana)) {
                                allCombinations.push(pana);
                            }
                        }
                    }
                }
                
                // Step 4: Filter combinations where sum ends with targetDigit1 OR targetDigit2
                const validOutcomes = allCombinations.filter(pana => {
                    const panaDigits = pana.split('').map(d => getSpSetDigitValue(d));
                    const outcomeSum = panaDigits.reduce((a, b) => a + b, 0);
                    const outcomeLastDigit = outcomeSum % 10;
                    
                    return outcomeLastDigit === targetDigit1 || outcomeLastDigit === targetDigit2;
                });
                
                // Step 5: Sort and limit to maximum 8 outcomes
                validOutcomes.sort();
                const outcomes = validOutcomes.slice(0, 8);
                
                updateSpSetOutcomes(outcomes, displayDigits, targetDigit1, targetDigit2, allDigits.map(d => d === 10 ? 0 : d), originalSum);
            }

            // SP Set Display
            function updateSpSetOutcomes(outcomes, originalDigits = [], target1 = null, target2 = null, allDigits = [], originalSum = 0) {
                const container = document.getElementById('sp-set-outcomes-container');
                
                currentSpSetOutcomes = outcomes;
                removedSpSetOutcomes = [];
                
                if (outcomes.length === 0) {
                    container.innerHTML = '<div class="no-digits">Please enter 3 valid digits to generate SP Set outcomes</div>';
                } else {
                    const displayDigitsText = originalDigits.map(d => d === 0 ? '0(10)' : d.toString()).join(', ');
                    const allDigitsText = allDigits.map(d => d === 0 ? '0(10)' : d.toString()).join(', ');
                    const sumDisplay = originalDigits.map(d => d === 0 ? '0(10)' : d.toString()).join('+') + '=' + originalSum;
                    
                    let html = `
                        <div class="pana-header">
                            <h4>SP Set Outcomes (${outcomes.length}/8)</h4>
                            <div class="digit-info">
                                <strong>Input:</strong> ${displayDigitsText} | <strong>Sum:</strong> ${sumDisplay}
                            </div>
                            <div class="digit-info">
                                <strong>Target Pair:</strong> ${target1}, ${target2} | <strong>All Digits:</strong> ${allDigitsText}
                            </div>
                     
                        </div>
                        <div class="pana-list">
                    `;
                    
                    outcomes.forEach((outcome) => {
                        const outcomeDigits = outcome.split('').map(d => getSpSetDigitValue(d));
                        const outcomeSum = outcomeDigits.reduce((a, b) => a + b, 0);
                        const outcomeLastDigit = outcomeSum % 10;
                        const sumText = outcomeDigits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + outcomeSum;
                        
                        const meetsCondition = outcomeLastDigit === target1 || outcomeLastDigit === target2;
                        const targetClass = meetsCondition ? 'target-pana' : '';
                        const targetText = meetsCondition ? `✓ Ends with ${outcomeLastDigit}` : '';
                        
                        html += `
                            <div class="pana-item ${targetClass}" data-outcome="${outcome}">
                                <div class="pana-value-container">
                                    <span class="pana-value">${outcome}</span>
                                    <span class="outcome-sum">${sumText}</span>
                                    ${targetText ? `<span class="target-indicator">${targetText}</span>` : ''}
                                </div>
                              
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
                
                document.getElementById('sp-set-outcomes-count').textContent = outcomes.length;
                document.getElementById('form-sp-set-digits').value = originalDigits.join('');
                updateSpSetTotal();
            }

        

            function updateSpSetTotal() {
                const amount = parseFloat(document.getElementById('sp-set-amount').value) || 0;
                const activeOutcomeCount = currentSpSetOutcomes.length - removedSpSetOutcomes.length;
                const total = amount * activeOutcomeCount;
                
                document.getElementById('sp-set-outcomes-count').textContent = activeOutcomeCount;
                document.getElementById('sp-set-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
                document.getElementById('sp-set-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
                document.getElementById('form-sp-set-bet-amount').value = amount;
                
                // Update form with active outcomes
                const activeOutcomes = currentSpSetOutcomes.filter(outcome => !removedSpSetOutcomes.includes(outcome));
                document.getElementById('form-sp-set-outcomes').value = JSON.stringify(activeOutcomes);
                
                // Enable/disable bet button
                const betButton = document.getElementById('place-sp-set-bet-btn');
                const validationEl = document.getElementById('sp-set-validation');
                
                if (total > 0 && total < 5) {
                    validationEl.textContent = "Minimum total bet is INR 5.00";
                    betButton.disabled = true;
                } else if (activeOutcomeCount === 0) {
                    validationEl.textContent = "Please select at least one outcome";
                    betButton.disabled = true;
                } else if (amount <= 0) {
                    validationEl.textContent = "Please enter a valid bet amount";
                    betButton.disabled = true;
                } else {
                    validationEl.textContent = "";
                    betButton.disabled = false;
                }
            }

            // Initialize SP Set
            document.addEventListener('DOMContentLoaded', function() {
                const spSetAmountInput = document.getElementById('sp-set-amount');
                if (spSetAmountInput) {
                    spSetAmountInput.addEventListener('input', updateSpSetTotal);
                }
                
                // Set mode toggle for SP Set
                document.querySelectorAll('.toggle-option').forEach(option => {
                    option.addEventListener('click', function() {
                        const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                        const formModeInput = document.getElementById('form-sp-set-mode');
                        if (formModeInput) {
                            formModeInput.value = mode;
                        }
                    });
                });
            });
</script>

  <!-- dp set -->
<script>
      // DP Set Configuration
            const dpSetMapping = {
                0: 1, 5: 1,  // Set 1: 0,5 (0 means 10 in calculation)
                1: 2, 6: 2,  // Set 2: 1,6  
                2: 3, 7: 3,  // Set 3: 2,7
                3: 4, 8: 4,  // Set 4: 3,8
                4: 5, 9: 5   // Set 5: 4,9
            };

            // DP Set Helper Functions
            function getDpSetDigitValue(digit) {
                return digit === '0' ? 10 : parseInt(digit);
            }

            function getDpSetPair(digit) {
                // For set pairing, we use the original digit (0-9)
                const originalDigit = digit === 10 ? 0 : digit;
                
                for (const [d, set] of Object.entries(dpSetMapping)) {
                    const numD = parseInt(d);
                    if (set === dpSetMapping[originalDigit] && numD !== originalDigit) {
                        return numD;
                    }
                }
                return null;
            }

            // DP Set Validation
            function validateDpSetDigits(input) {
                const value = input.value.replace(/[^0-9]/g, '');
                input.value = value;
                
                const validationEl = document.getElementById('dp-set-validation');
                
                if (value.length !== 3) {
                    validationEl.textContent = "Please enter exactly 3 digits";
                    updateDpSetOutcomes([]);
                    return;
                }
                
                const digits = value.split('').map(d => getDpSetDigitValue(d));
                const uniqueDigits = [...new Set(digits)];
                
                if (uniqueDigits.length !== 3) {
                    validationEl.textContent = "All digits must be different";
                    updateDpSetOutcomes([]);
                    return;
                }
                
                // Check DP Set rule: exactly two digits from same set and one from different set
                const setCounts = {};
                for (const digit of digits) {
                    const setDigit = digit === 10 ? 0 : digit;
                    const set = dpSetMapping[setDigit];
                    setCounts[set] = (setCounts[set] || 0) + 1;
                }
                
                const setEntries = Object.entries(setCounts);
                const hasTwoSameSet = setEntries.some(([set, count]) => count === 2);
                const hasOneDifferentSet = setEntries.some(([set, count]) => count === 1);
                
                if (!hasTwoSameSet || !hasOneDifferentSet) {
                    validationEl.textContent = "DP Set Rule: Two digits from same set + one digit from different set";
                    updateDpSetOutcomes([]);
                    return;
                }
                
                validationEl.textContent = "";
                generateDpSetOutcomes(digits);
            }

            // DP Set Outcome Generation
            function generateDpSetOutcomes(digits) {
                const displayDigits = digits.map(d => d === 10 ? 0 : d);
                
                // Step 1: Identify which set has two digits and which has one
                const setCounts = {};
                for (const digit of digits) {
                    const setDigit = digit === 10 ? 0 : digit;
                    const set = dpSetMapping[setDigit];
                    setCounts[set] = (setCounts[set] || 0) + 1;
                }
                
                let twoDigitSet = null;
                let singleDigitSet = null;
                let twoDigits = [];
                let singleDigit = null;
                
                for (const [set, count] of Object.entries(setCounts)) {
                    if (count === 2) {
                        twoDigitSet = parseInt(set);
                    } else if (count === 1) {
                        singleDigitSet = parseInt(set);
                    }
                }
                
                // Get the actual digits
                twoDigits = digits.filter(digit => {
                    const setDigit = digit === 10 ? 0 : digit;
                    return dpSetMapping[setDigit] === twoDigitSet;
                });
                
                singleDigit = digits.find(digit => {
                    const setDigit = digit === 10 ? 0 : digit;
                    return dpSetMapping[setDigit] === singleDigitSet;
                });
                
                // Step 2: Get the pair of the single digit
                const singleDigitPair = getDpSetPair(singleDigit);
                
                // Now we have 4 digits: twoDigits (2 digits) + singleDigit + singleDigitPair
                const allFourDigits = [...twoDigits, singleDigit, singleDigitPair];
                
                // Step 3: Calculate sum of original three digits (0 becomes 10)
                const originalSum = digits.reduce((a, b) => a + b, 0);
                const targetDigit1 = originalSum % 10;
                const targetDigit2 = getDpSetPair(targetDigit1);
                
                if (targetDigit2 === null) {
                    updateDpSetOutcomes([]);
                    return;
                }
                
                // Step 4: Generate the specific 6 outcomes pattern
                const outcomes = generateDpSpecificOutcomes(twoDigits, singleDigit, singleDigitPair);
                
                updateDpSetOutcomes(outcomes, displayDigits, allFourDigits.map(d => d === 10 ? 0 : d), targetDigit1, targetDigit2, originalSum);
            }

            // DP Set Specific Outcome Generation
            function generateDpSpecificOutcomes(twoDigits, singleDigit, singleDigitPair) {
                const [a, b] = twoDigits;
                const c = singleDigit;
                const d = singleDigitPair;
                
                const outcomes = [
                    [a, b, c].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join(''),
                    [a, a, c].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join(''),
                    [b, b, c].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join(''),
                    [a, a, d].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join(''),
                    [a, b, d].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join(''),
                    [b, b, d].sort((x, y) => x - y).map(d => d === 10 ? '0' : d.toString()).join('')
                ];
                
                return outcomes;
            }

            // DP Set Display
            function updateDpSetOutcomes(outcomes, originalDigits = [], allFourDigits = [], target1 = null, target2 = null, originalSum = 0) {
                const container = document.getElementById('dp-set-outcomes-container');
                
                currentDpSetOutcomes = outcomes;
                removedDpSetOutcomes = [];
                
                if (outcomes.length === 0) {
                    container.innerHTML = '<div class="no-digits">Please enter 3 valid digits to generate DP Set outcomes</div>';
                } else {
                    const displayDigitsText = originalDigits.map(d => d === 0 ? '0(10)' : d.toString()).join(', ');
                    const allDigitsText = allFourDigits.map(d => d === 0 ? '0(10)' : d.toString()).join(', ');
                    const sumDisplay = originalDigits.map(d => d === 0 ? '0(10)' : d.toString()).join('+') + '=' + originalSum;
                    
                    let html = `
                        <div class="pana-header">
                            <h4>DP Set Outcomes (${outcomes.length}/6)</h4>
                            <div class="digit-info">
                                <strong>Input:</strong> ${displayDigitsText} | <strong>Sum:</strong> ${sumDisplay}
                            </div>
                            <div class="digit-info">
                                <strong>Target Pair:</strong> ${target1}, ${target2} | <strong>All Digits:</strong> ${allDigitsText}
                            </div>
                        
                        </div>
                        <div class="pana-list">
                    `;
                    
                    outcomes.forEach((outcome) => {
                        const outcomeDigits = outcome.split('').map(d => getDpSetDigitValue(d));
                        const outcomeSum = outcomeDigits.reduce((a, b) => a + b, 0);
                        const outcomeLastDigit = outcomeSum % 10;
                        const sumText = outcomeDigits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + outcomeSum;
                        
                        const meetsCondition = outcomeLastDigit === target1 || outcomeLastDigit === target2;
                        const targetClass = meetsCondition ? 'target-pana' : '';
                        const targetText = meetsCondition ? `✓ Ends with ${outcomeLastDigit}` : '';
                        
                        html += `
                            <div class="pana-item ${targetClass}" data-outcome="${outcome}">
                                <div class="pana-value-container">
                                    <span class="pana-value">${outcome}</span>
                                    <span class="outcome-sum">${sumText}</span>
                                    ${targetText ? `<span class="target-indicator">${targetText}</span>` : ''}
                                </div>
                           
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    container.innerHTML = html;
                }
                
                document.getElementById('dp-set-outcomes-count').textContent = outcomes.length;
                document.getElementById('form-dp-set-digits').value = originalDigits.join('');
                updateDpSetTotal();
            }

         

        function updateDpSetTotal() {
            const amount = parseFloat(document.getElementById('dp-set-amount').value) || 0;
            const activeOutcomeCount = currentDpSetOutcomes.length - removedDpSetOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            document.getElementById('dp-set-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('dp-set-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('dp-set-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-dp-set-bet-amount').value = amount;
            
            // Update form with active outcomes
            const activeOutcomes = currentDpSetOutcomes.filter(outcome => !removedDpSetOutcomes.includes(outcome));
            document.getElementById('form-dp-set-outcomes').value = JSON.stringify(activeOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-dp-set-bet-btn');
            const validationEl = document.getElementById('dp-set-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                validationEl.textContent = "Please select at least one outcome";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Initialize DP Set
        document.addEventListener('DOMContentLoaded', function() {
            const dpSetAmountInput = document.getElementById('dp-set-amount');
            if (dpSetAmountInput) {
                dpSetAmountInput.addEventListener('input', updateDpSetTotal);
            }
            
            // Set mode toggle for DP Set
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    const formModeInput = document.getElementById('form-dp-set-mode');
                    if (formModeInput) {
                        formModeInput.value = mode;
                    }
                });
            });
        });
</script>

<!-- tp set -->
<script>
    // TP Set Configuration - CORRECTED FOR ALL DIGITS
    const tpSetMapping = {
        0: 5,  // Set 1: 0 pairs with 5
        1: 6,  // Set 2: 1 pairs with 6  
        2: 7,  // Set 3: 2 pairs with 7
        3: 8,  // Set 4: 3 pairs with 8
        4: 9,  // Set 5: 4 pairs with 9
        5: 0,  // Reverse mapping
        6: 1,  // Reverse mapping
        7: 2,  // Reverse mapping  
        8: 3,  // Reverse mapping
        9: 4   // Reverse mapping
    };

    // TP Set Helper Functions
    function getTpSetDigitValue(digit) {
        return digit === '0' ? 10 : parseInt(digit);
    }

    function getTpSetDisplayDigit(digit) {
        return digit === 10 ? '0 (10)' : digit.toString();
    }

    function getTpSetPair(digit) {
        const numDigit = parseInt(digit);
        return tpSetMapping[numDigit];
    }

    // Initialize TP Set digit selection
    document.addEventListener('DOMContentLoaded', function() {
        const tpSetDigitSelect = document.getElementById('tp-set-digit');
        if (tpSetDigitSelect) {
            // Update the option text for 0 to show (10)
            const zeroOption = tpSetDigitSelect.querySelector('option[value="0"]');
            if (zeroOption) {
                zeroOption.textContent = '0 (10)';
            }
        }
    });

    // TP Set Outcome Generation - CORRECTED FOR ALL DIGITS
    function generateTpSetOutcomes() {
        const digitSelect = document.getElementById('tp-set-digit');
        const selectedDigit = parseInt(digitSelect.value);
        
        if (isNaN(selectedDigit)) {
            updateTpSetOutcomes([]);
            return;
        }
        
        // Get the pair digit
        const pairDigit = getTpSetPair(selectedDigit);
        
        if (pairDigit === undefined) {
            console.error("Could not find pair for digit:", selectedDigit);
            updateTpSetOutcomes([]);
            return;
        }
        
        // Generate exactly 4 outcomes in ASCENDING ORDER
        let outcomes = [];
        
        // CORRECTED: Always generate in ascending order regardless of digit
        if (selectedDigit <= pairDigit) {
            outcomes = [
                [selectedDigit, selectedDigit, selectedDigit].join(''),
                [pairDigit, pairDigit, pairDigit].join(''),
                [selectedDigit, selectedDigit, pairDigit].join(''),
                [selectedDigit, pairDigit, pairDigit].join('')
            ];
        } else {
            // If pair digit is smaller, put it first for ascending order
            outcomes = [
                [pairDigit, pairDigit, pairDigit].join(''),
                [selectedDigit, selectedDigit, selectedDigit].join(''),
                [pairDigit, pairDigit, selectedDigit].join(''),
                [pairDigit, selectedDigit, selectedDigit].join('')
            ];
        }
        
        // Ensure all outcomes are 3-digit strings with leading zeros if needed
        outcomes = outcomes.map(outcome => {
            if (outcome.length === 1) return outcome + outcome + outcome;
            if (outcome.length === 2) return '0' + outcome;
            return outcome;
        });
        
        updateTpSetOutcomes(outcomes, selectedDigit, pairDigit);
    }

    // TP Set Display
    function updateTpSetOutcomes(outcomes, selectedDigit = null, pairDigit = null) {
        const container = document.getElementById('tp-set-outcomes-container');
        
        currentTpSetOutcomes = outcomes;
        removedTpSetOutcomes = [];
        
        if (outcomes.length === 0) {
            container.innerHTML = '<div class="no-digits">Please select a digit to generate TP Set outcomes</div>';
        } else {
            // Show 0 as 10 in display
            const displayDigit = selectedDigit === 0 ? '0 (10)' : selectedDigit.toString();
            const displayPair = pairDigit === 0 ? '0 (10)' : pairDigit.toString();
            
            // Calculate actual sum for display (showing 0 as 10 in calculation)
            const calcDigit = selectedDigit === 0 ? 10 : selectedDigit;
            const actualSum = calcDigit * 3;
            
            let html = `
                <div class="pana-header">
                    <h4>TP Set Outcomes (${outcomes.length}/4)</h4>
                    <div class="digit-info">
                        <strong>Selected Digit:</strong> ${displayDigit} | <strong>Digit Pair:</strong> ${displayPair}
                    </div>
                    <div class="digit-info">
                        <strong>Sum Calculation:</strong> ${calcDigit} + ${calcDigit} + ${calcDigit} = ${actualSum}
                    </div>
                    <div class="digit-info">
                        <strong>Rule:</strong> Three same digits create 4 outcomes with the digit and its pair
                    </div>
                </div>
                <div class="pana-list">
            `;
            
            outcomes.forEach((outcome) => {
                // Calculate sum for this outcome (treating 0 as 10)
                const outcomeDigits = outcome.split('').map(d => getTpSetDigitValue(d));
                const outcomeSum = outcomeDigits.reduce((a, b) => a + b, 0);
                const outcomeLastDigit = outcomeSum % 10;
                
                // Show calculation with 0 as 10 in display
                const sumText = outcomeDigits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + outcomeSum;
                
                html += `
                    <div class="pana-item" data-outcome="${outcome}">
                        <div class="pana-value-container">
                            <span class="pana-value">${outcome}</span>
                            <span class="outcome-sum">${sumText}</span>
                            <span class="target-indicator">Ends with ${outcomeLastDigit}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        document.getElementById('tp-set-outcomes-count').textContent = outcomes.length;
        document.getElementById('form-tp-set-digit').value = selectedDigit;
        updateTpSetTotal();
    }

    // TP Set Action Functions
    let currentTpSetOutcomes = [];
    let removedTpSetOutcomes = [];

    function updateTpSetTotal() {
        const amount = parseFloat(document.getElementById('tp-set-amount').value) || 0;
        const activeOutcomeCount = currentTpSetOutcomes.length - removedTpSetOutcomes.length;
        const total = amount * activeOutcomeCount;
        
        document.getElementById('tp-set-outcomes-count').textContent = activeOutcomeCount;
        document.getElementById('tp-set-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
        document.getElementById('tp-set-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
        document.getElementById('form-tp-set-bet-amount').value = amount;
        
        // Update form with active outcomes
        const activeOutcomes = currentTpSetOutcomes.filter(outcome => !removedTpSetOutcomes.includes(outcome));
        document.getElementById('form-tp-set-outcomes').value = JSON.stringify(activeOutcomes);
        
        // Enable/disable bet button
        const betButton = document.getElementById('place-tp-set-bet-btn');
        const validationEl = document.getElementById('tp-set-validation');
        
        if (total > 0 && total < 5) {
            validationEl.textContent = "Minimum total bet is INR 5.00";
            betButton.disabled = true;
        } else if (activeOutcomeCount === 0) {
            validationEl.textContent = "Please select at least one outcome";
            betButton.disabled = true;
        } else if (amount <= 0) {
            validationEl.textContent = "Please enter a valid bet amount";
            betButton.disabled = true;
        } else {
            validationEl.textContent = "";
            betButton.disabled = false;
        }
    }

    // Initialize TP Set amount listener
    document.addEventListener('DOMContentLoaded', function() {
        const tpSetAmountInput = document.getElementById('tp-set-amount');
        if (tpSetAmountInput) {
            tpSetAmountInput.addEventListener('input', updateTpSetTotal);
        }
        
        // Set mode toggle for TP Set
        document.querySelectorAll('.toggle-option').forEach(option => {
            option.addEventListener('click', function() {
                const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                const formModeInput = document.getElementById('form-tp-set-mode');
                if (formModeInput) {
                    formModeInput.value = mode;
                }
            });
        });
    });
</script>

<!-- common -->
<script>
        // Enhanced Common Game JavaScript with Digit-based Panna Generation (0=10 in panna logic)
        let currentCommonOutcomes = [];

        // Helper function to convert digit for calculation (0 becomes 10)
        function getDigitValue(digit) {
            return digit === '0' ? 10 : parseInt(digit);
        }

        // Helper function to convert digit for display (10 becomes 0)
        function getDisplayDigit(digit) {
            return digit === 10 ? '0' : digit.toString();
        }

        // Function to create proper panna with 0=10 logic
        function createPanna(digitsArray) {
            // Convert digits to numerical values for sorting (0 becomes 10)
            const numericDigits = digitsArray.map(d => getDigitValue(d.toString()));
            
            // Sort numerically (0 as 10 will be at the end)
            numericDigits.sort((a, b) => a - b);
            
            // Convert back to display format (10 becomes 0)
            const displayDigits = numericDigits.map(d => getDisplayDigit(d));
            
            return displayDigits.join('');
        }

        // Function to generate SP pannas (36) based on selected digit
        function generateSpPannas(selectedDigit) {
            const spPannas = [];
            const allDigits = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
            
            // Convert selected digit for calculation
            const selectedDigitValue = getDigitValue(selectedDigit);
            
            // Generate all possible 3-digit combinations that include the selected digit
            for (let i = 0; i < allDigits.length; i++) {
                for (let j = i + 1; j < allDigits.length; j++) {
                    for (let k = j + 1; k < allDigits.length; k++) {
                        const combination = [allDigits[i], allDigits[j], allDigits[k]];
                        
                        // Check if combination includes the selected digit (considering 0=10)
                        const includesSelectedDigit = combination.some(digit => 
                            getDigitValue(digit.toString()) === selectedDigitValue
                        );
                        
                        if (includesSelectedDigit) {
                            const panna = createPanna(combination);
                            if (!spPannas.includes(panna)) {
                                spPannas.push(panna);
                            }
                        }
                    }
                }
            }
            
            // Limit to 36 pannas and sort them
            return spPannas.sort().slice(0, 36);
        }

        // Function to generate DP pannas (18) based on selected digit
        function generateDpPannas(selectedDigit) {
            const dpPannas = [];
            const allDigits = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
            
            // Convert selected digit for calculation
            const selectedDigitValue = getDigitValue(selectedDigit);
            
            // Generate double patti combinations
            for (let i = 0; i < allDigits.length; i++) {
                for (let j = 0; j < allDigits.length; j++) {
                    if (i !== j) {
                        // Create double patti patterns: AAB, ABA, ABB
                        const patterns = [
                            [allDigits[i], allDigits[i], allDigits[j]], // AAB
                            [allDigits[i], allDigits[j], allDigits[j]]  // ABB
                        ];
                        
                        for (const pattern of patterns) {
                            // Check if pattern includes the selected digit (considering 0=10)
                            const includesSelectedDigit = pattern.some(digit => 
                                getDigitValue(digit.toString()) === selectedDigitValue
                            );
                            
                            if (includesSelectedDigit) {
                                const panna = createPanna(pattern);
                                // Ensure it's actually a double patti (not triple)
                                const uniqueDigits = [...new Set(pattern)];
                                if (uniqueDigits.length === 2 && !dpPannas.includes(panna)) {
                                    dpPannas.push(panna);
                                }
                            }
                        }
                    }
                }
            }
            
            // Limit to 18 pannas and sort them
            return dpPannas.sort().slice(0, 18);
        }

        // Function to generate SPDPT pannas (55) based on selected digit
        function generateSpdptPannas(selectedDigit) {
            // Combine SP and DP pannas to get 55 total
            const spPannas = generateSpPannas(selectedDigit);
            const dpPannas = generateDpPannas(selectedDigit);
            
            // Combine and remove duplicates
            const allPannas = [...new Set([...spPannas, ...dpPannas])];
            
            // Ensure we have exactly 55 pannas
            if (allPannas.length < 55) {
                // Add additional pannas if needed
                const additionalPannas = generateAdditionalPannas(selectedDigit, 55 - allPannas.length);
                allPannas.push(...additionalPannas);
            }
            
            return allPannas.sort().slice(0, 55);
        }

        // Helper function to generate additional pannas if needed
        function generateAdditionalPannas(selectedDigit, count) {
            const additional = [];
            const allDigits = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
            
            // Convert selected digit for calculation
            const selectedDigitValue = getDigitValue(selectedDigit);
            
            for (let i = 0; i < allDigits.length && additional.length < count; i++) {
                for (let j = i + 1; j < allDigits.length && additional.length < count; j++) {
                    for (let k = j + 1; k < allDigits.length && additional.length < count; k++) {
                        const combination = [allDigits[i], allDigits[j], allDigits[k]];
                        const panna = createPanna(combination);
                        
                        // Check if combination includes the selected digit (considering 0=10)
                        const includesSelectedDigit = combination.some(digit => 
                            getDigitValue(digit.toString()) === selectedDigitValue
                        );
                        
                        // Add if it includes selected digit and is not already in our lists
                        if (includesSelectedDigit && !additional.includes(panna)) {
                            additional.push(panna);
                        }
                    }
                }
            }
            
            return additional;
        }

        function generateCommonOutcomes() {
            const selectedDigit = document.getElementById('common-digit').value;
            const commonType = document.getElementById('common-type').value;
            
            if (!selectedDigit) {
                updateCommonOutcomes([], commonType);
                return;
            }
            
            let outcomes = [];
            let typeDisplay = '';
            
            switch(commonType) {
                case 'sp':
                    outcomes = generateSpPannas(selectedDigit);
                    typeDisplay = `SP (36 Single Patti for Digit ${selectedDigit === '0' ? '10' : selectedDigit})`;
                    break;
                case 'dp':
                    outcomes = generateDpPannas(selectedDigit);
                    typeDisplay = `DP (18 Double Patti for Digit ${selectedDigit === '0' ? '10' : selectedDigit})`;
                    break;
                case 'spdpt':
                default:
                    outcomes = generateSpdptPannas(selectedDigit);
                    typeDisplay = `SPDPT (55 Pannas for Digit ${selectedDigit === '0' ? '10' : selectedDigit})`;
                    break;
            }
            
            updateCommonOutcomes(outcomes, commonType, typeDisplay, selectedDigit);
        }

        function updateCommonOutcomes(outcomes, commonType, typeDisplay, selectedDigit) {
            const container = document.getElementById('common-outcomes-container');
            
            currentCommonOutcomes = outcomes;
            
            if (outcomes.length === 0) {
                container.innerHTML = '<div class="no-digits">Please select a digit and type to generate Common outcomes</div>';
            } else {
                let html = `
                    <div class="pana-header">
                        <h4>Common ${typeDisplay}</h4>
                        <div class="digit-info">${outcomes.length} panna combinations for digit ${selectedDigit === '0' ? '10' : selectedDigit}</div>
                
                    </div>
                    <div class="pana-list">
                `;
                
                outcomes.forEach((outcome, index) => {
                    // Calculate sum for display (0 means 10)
                    const digits = outcome.split('').map(d => getDigitValue(d));
                    const sum = digits.reduce((a, b) => a + b, 0);
                    
                    // Create sum display text showing 0 as 10
                    const sumText = digits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + sum;
                    
                    html += `
                        <div class="pana-item" data-outcome="${outcome}">
                            <div class="pana-value-container">
                                <span class="pana-value">${outcome}</span>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            document.getElementById('common-outcomes-count').textContent = outcomes.length;
            document.getElementById('common-type-display').textContent = typeDisplay;
            document.getElementById('form-common-digit').value = selectedDigit;
            document.getElementById('form-common-type').value = commonType;
            updateCommonTotal();
        }

        function updateCommonTotal() {
            const amount = parseFloat(document.getElementById('common-amount').value) || 0;
            const activeOutcomeCount = currentCommonOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            document.getElementById('common-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('common-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('common-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-common-bet-amount').value = amount;
            
            // Update form with all outcomes
            document.getElementById('form-common-outcomes').value = JSON.stringify(currentCommonOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-common-bet-btn');
            const validationEl = document.getElementById('common-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                validationEl.textContent = "Please select a digit and type to generate outcomes";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Initialize Common Game
        document.addEventListener('DOMContentLoaded', function() {
            const commonAmountInput = document.getElementById('common-amount');
            if (commonAmountInput) {
                commonAmountInput.addEventListener('input', updateCommonTotal);
            }
            
            // Set mode toggle for Common Game
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    const formModeInput = document.getElementById('form-common-mode');
                    if (formModeInput) {
                        formModeInput.value = mode;
                    }
                });
            });
            
            // Test examples to verify the logic
            console.log("Panna examples with 0=10 logic:");
            console.log("012 becomes:", createPanna([0, 1, 2])); // Should be "120"
            console.log("009 becomes:", createPanna([0, 0, 9])); // Should be "099" 
            console.log("078 becomes:", createPanna([0, 7, 8])); // Should be "780"
            console.log("005 becomes:", createPanna([0, 0, 5])); // Should be "055"
        });
</script>

<!-- series -->
<script>
        // Series Game Logic - Two digits, ascending panna containing both digits, 0=10
        let currentSeriesOutcomes = [];

        function validateSeriesDigits() {
            const digit1 = document.getElementById('series-digit1').value;
            const digit2 = document.getElementById('series-digit2').value;
            const validationEl = document.getElementById('series-validation');
            
            if (!digit1 || !digit2) {
                validationEl.textContent = "Please select both digits";
                updateSeriesOutcomes([]);
                return;
            }
            
            if (digit1 === digit2) {
                validationEl.textContent = "Please select two different digits";
                updateSeriesOutcomes([]);
                return;
            }
            
            validationEl.textContent = "";
            generateSeriesOutcomes(digit1, digit2);
        }

        function generateSeriesOutcomes(digit1, digit2) {
            if (!digit1 || !digit2 || digit1 === digit2) {
                updateSeriesOutcomes([]);
                return;
            }
            
            // Generate ascending panna combinations that contain both selected digits
            const outcomes = generateAscendingPannaWithTwoDigits(digit1, digit2);
            
            updateSeriesOutcomes(outcomes, digit1, digit2);
        }

        function generateAscendingPannaWithTwoDigits(digit1, digit2) {
            const outcomes = [];
            
            // Convert selected digits to numbers (0 means 10)
            const num1 = parseInt(digit1);
            const num2 = parseInt(digit2);
            
            // Generate all possible ascending 3-digit combinations with digits 1-10 (0 represents 10)
            for (let i = 1; i <= 10; i++) {
                for (let j = i; j <= 10; j++) {
                    for (let k = j; k <= 10; k++) {
                        // Convert 10 back to 0 for display in panna
                        const displayDigit1 = i === 10 ? 0 : i;
                        const displayDigit2 = j === 10 ? 0 : j;
                        const displayDigit3 = k === 10 ? 0 : k;
                        
                        const panna = displayDigit1.toString() + displayDigit2.toString() + displayDigit3.toString();
                        
                        // Check if panna contains both selected digits
                        let containsDigit1 = false;
                        let containsDigit2 = false;
                        
                        // For digit 0 (which means 10), we need to check for actual value 10
                        if (num1 === 0) {
                            containsDigit1 = (i === 10 || j === 10 || k === 10);
                        } else {
                            containsDigit1 = (i === num1 || j === num1 || k === num1);
                        }
                        
                        if (num2 === 0) {
                            containsDigit2 = (i === 10 || j === 10 || k === 10);
                        } else {
                            containsDigit2 = (i === num2 || j === num2 || k === num2);
                        }
                        
                        if (containsDigit1 && containsDigit2) {
                            outcomes.push(panna);
                        }
                    }
                }
            }
            
            return outcomes;
        }

        function updateSeriesOutcomes(outcomes, digit1, digit2) {
            const container = document.getElementById('series-outcomes-container');
            
            currentSeriesOutcomes = outcomes;
            
            if (outcomes.length === 0) {
                container.innerHTML = '<div class="no-digits">Please select two different digits to generate Series outcomes</div>';
            } else {
                const displayDigit1 = digit1 === '0' ? '0 (10)' : digit1;
                const displayDigit2 = digit2 === '0' ? '0 (10)' : digit2;
                
                let html = `
                    <div class="pana-header">
                        <h4>Series Outcomes for Digits ${displayDigit1} and ${displayDigit2}</h4>
                        <div class="digit-info">${outcomes.length} ascending panna combinations containing both digits ${displayDigit1} and ${displayDigit2}</div>
                    </div>
                    <div class="pana-list">
                `;
                
                outcomes.forEach((outcome) => {
                    // Calculate sum with 0=10 logic
                    const digits = outcome.split('').map(d => parseInt(d));
                    const sum = digits.reduce((total, digit) => total + (digit === 0 ? 10 : digit), 0);
                    
                    html += `
                        <div class="pana-item" data-outcome="${outcome}">
                            <div class="pana-value-container">
                                <span class="pana-value">${outcome}</span>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            document.getElementById('series-outcomes-count').textContent = outcomes.length;
            document.getElementById('form-series-digit1').value = digit1;
            document.getElementById('form-series-digit2').value = digit2;
            updateSeriesTotal();
        }

        function updateSeriesTotal() {
            const amount = parseFloat(document.getElementById('series-amount').value) || 0;
            const activeOutcomeCount = currentSeriesOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            document.getElementById('series-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('series-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('series-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-series-bet-amount').value = amount;
            
            // Update form with all outcomes
            document.getElementById('form-series-outcomes').value = JSON.stringify(currentSeriesOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-series-bet-btn');
            const validationEl = document.getElementById('series-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                validationEl.textContent = "Please select two different digits to generate outcomes";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Initialize Series Game
        document.addEventListener('DOMContentLoaded', function() {
            const seriesAmountInput = document.getElementById('series-amount');
            if (seriesAmountInput) {
                seriesAmountInput.addEventListener('input', updateSeriesTotal);
            }
            
            // Set mode toggle for Series Game
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    const formModeInput = document.getElementById('form-series-mode');
                    if (formModeInput) {
                        formModeInput.value = mode;
                    }
                });
            });
        });
</script>
<!-- rown -->
<script>
        // Rown Game Logic - Fixed 10 consecutive pannas with static HTML table
        let currentRownAmount = 0;

        function initializeRownGame() {
            // Set up event listeners for Rown game
            document.getElementById('rown-amount').addEventListener('input', updateRownTotal);
            
            // Set mode toggle for Rown Game
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    const formModeInput = document.getElementById('form-rown-mode');
                    if (formModeInput) {
                        formModeInput.value = mode;
                    }
                });
            });
            
            // Initial calculation
            updateRownTotal();
        }

        function updateRownTotal() {
            const amount = parseFloat(document.getElementById('rown-amount').value) || 0;
            const pannaCount = 10; // Fixed 10 pannas
            const total = amount * pannaCount;
            
            currentRownAmount = amount;
            
            // Update the table with current amount
            updateRownTableDisplay(amount);
            
            document.getElementById('rown-outcomes-count').textContent = pannaCount;
            document.getElementById('rown-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('rown-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-rown-bet-amount').value = amount;
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-rown-bet-btn');
            const validationEl = document.getElementById('rown-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        function updateRownTableDisplay(amount) {
            const rownRows = document.querySelectorAll('.rown-panna-row');
            
            rownRows.forEach(row => {
                const amountCell = row.querySelector('.rown-amount-display');
                const panna = row.dataset.panna;
                
                if (amount > 0) {
                    amountCell.textContent = `₹${amount.toFixed(2)}`;
                    amountCell.classList.add('has-bet');
                    row.classList.add('active-bet');
                } else {
                    amountCell.textContent = '-';
                    amountCell.classList.remove('has-bet');
                    row.classList.remove('active-bet');
                }
            });
        }
        </script>
        <script>

                // Clear form data after submission to prevent browser from remembering it
                window.addEventListener('load', function() {
                    if (window.history.replaceState) {
                        window.history.replaceState(null, null, window.location.href);
                    }
                    
                    // Clear any form data that might be remembered by the browser
                    document.getElementById('bet-form').reset();
                    
                    // Reset betting interface
                    selectedChipValue = 0;
                    totalBet = 0;
                    betHistory = [];
                    currentBets = {};
                    updateBetDisplay();
                    
                    // Clear any active chips
                    document.querySelectorAll('.chip').forEach(chip => {
                        chip.classList.remove('active');
                    });
                });
                
                // Initialize betting variables
                let selectedChipValue = 0;
                let totalBet = 0;
                let betHistory = [];
                let currentBets = {};
                
            
                
                // Update game type ID when bet type changes
            document.getElementById('bet-type').addEventListener('change', function() {
            document.getElementById('form-game-type-id').value = this.value;
        });
            
                
        // Set initial mode value
            document.getElementById('form-bet-mode').value = 'open';

        // Open/Close toggle
        document.querySelectorAll('.toggle-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.toggle-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                
                // Update the hidden form field with the selected mode
                const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                // alert(mode);
                document.getElementById('form-bet-mode').value = mode;
                
                console.log('Mode changed to:', mode); // For debugging
            });
        });

                // Form submission
                document.getElementById('bet-form').addEventListener('submit', function(e) {
                    if (totalBet === 0) {
                        e.preventDefault();
                        alert('Please place at least one bet');
                        return;
                    }
                    
                    // Changed condition from 5 different numbers to 5 RPS total
                    if (totalBet < 5) {
                        e.preventDefault();
                        alert('Minimum 5 RPS bet required to place a bet');
                        return;
                    }
                    
                    // Update form fields
                    document.getElementById('form-bet-amount').value = totalBet;
                    document.getElementById('form-bet-data').value = JSON.stringify(currentBets);
                    
                    // Check if user has sufficient balance
                    const balanceText = document.getElementById('balance-amount').textContent;
                    const balanceValue = parseFloat(balanceText.replace('₹', '').replace(/,/g, ''));
                    
                    if (totalBet > balanceValue) {
                        e.preventDefault();
                        alert('Insufficient funds! Please add money to your account.');
                        return;
                    }
                    
                    // Confirm before placing bet
                    if (!confirm(`Confirm bet placement for INR ${totalBet}?`)) {
                        e.preventDefault();
                    }
                });
                
                // Update bet display
                function updateBetDisplay() {
                    document.getElementById('total-bet').textContent = `INR ${totalBet}`;
                    
                    // Update number displays
                    document.querySelectorAll('.bet-number').forEach(numberElement => {
                        const number = numberElement.dataset.number;
                        if (currentBets[number]) {
                            numberElement.classList.add('bet-placed');
                            
                            // Create or update amount indicator
                            let amountIndicator = numberElement.querySelector('.bet-amount');
                            if (!amountIndicator) {
                                amountIndicator = document.createElement('div');
                                amountIndicator.className = 'bet-amount';
                                numberElement.appendChild(amountIndicator);
                            }
                            amountIndicator.textContent = currentBets[number];
                        } else {
                            numberElement.classList.remove('bet-placed');
                            const amountIndicator = numberElement.querySelector('.bet-amount');
                            if (amountIndicator) {
                                amountIndicator.remove();
                            }
                        }
                    });
                }
                // Timer functionality for single ank page
                function setupTimersSingleAnk(openTime, closeTime) {
                    function updateTimers() {
                        const now = new Date();
                        
                        // Get current IST time (UTC +5:30)
                        const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
                        const istTime = new Date(utc + (5.5 * 3600000));
                        
                        // Parse open and close times
                        const [openHours, openMinutes] = openTime.split(':').map(Number);
                        const [closeHours, closeMinutes] = closeTime.split(':').map(Number);
                        
                        // Create date objects for today's open and close times in IST
                        const openTimeToday = new Date(istTime);
                        openTimeToday.setHours(openHours, openMinutes, 0, 0);
                        
                        const closeTimeToday = new Date(istTime);
                        closeTimeToday.setHours(closeHours, closeMinutes, 0, 0);
                        
                        // If open time has already passed today, set it for tomorrow
                        if (openTimeToday < istTime) {
                            openTimeToday.setDate(openTimeToday.getDate() + 1);
                        }
                        
                        // If close time has already passed today, set it for tomorrow
                        if (closeTimeToday < istTime) {
                            closeTimeToday.setDate(closeTimeToday.getDate() + 1);
                        }
                        
                        // Calculate time differences
                        const openDiff = openTimeToday - istTime;
                        const closeDiff = closeTimeToday - istTime;
                        
                        // Format time strings
                        const openTimer = formatTimeDiff(openDiff);
                        const closeTimer = formatTimeDiff(closeDiff);
                        
                        // Update DOM elements
                        document.getElementById('open-timer-single').textContent = openTimer;
                        document.getElementById('close-timer-single').textContent = closeTimer;
                        
                        // Update status based on time
                        updateGameStatusSingle(openDiff, closeDiff);
                    }
                    
                    function updateGameStatusSingle(openDiff, closeDiff) {
                        const openTimerElement = document.getElementById('open-timer-single');
                        const closeTimerElement = document.getElementById('close-timer-single');
                        
                        if (openDiff <= 0) {
                            openTimerElement.style.color = 'var(--success)';
                        } else {
                            openTimerElement.style.color = 'var(--primary)';
                        }
                        
                        if (closeDiff <= 0) {
                            closeTimerElement.style.color = 'var(--danger)';
                        } else {
                            closeTimerElement.style.color = 'var(--primary)';
                        }
                    }
                    
                    function formatTimeDiff(diff) {
                        if (diff <= 0) {
                            return '00:00:00';
                        }
                        
                        const hours = Math.floor(diff / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        
                        return `${padZero(hours)}:${padZero(minutes)}:${padZero(seconds)}`;
                    }
                    
                    function padZero(num) {
                        return num.toString().padStart(2, '0');
                    }
                    
                    // Initialize and update timers every second
                    updateTimers();
                    setInterval(updateTimers, 1000);
                }

            // Get URL parameters for single ank page
                function getUrlParamsSingleAnk() {
                    const params = {};
                    const urlParams = new URLSearchParams(window.location.search);
                    
                    params.game = urlParams.get('game') || 'Kalyan Matka';
                    params.openTime = urlParams.get('openTime') || '15:30';
                    params.closeTime = urlParams.get('closeTime') || '17:30';
                    
                    return params;
                }

            // Initialize the timers when page loads
            document.addEventListener('DOMContentLoaded', function() {
            const params = getUrlParamsSingleAnk();
            setupTimersSingleAnk(params.openTime, params.closeTime);
        });
        // Add these global variables
            let currentPage = 1;
            const itemsPerPage = 100;
     let totalPages = 1;
</script>
<!-- // Abr-Cut Game Logic - Exact 90 pannas -->
<script>
        let currentAbrCutAmount = 0;

        // Exact 90 Abr-Cut pannas
        const CORRECT_ABR_CUT_PANNAS = [
            '127', '136', '145', '190', '235', '280', '370', '479', '460', '569',
            '389', '578', '128', '137', '146', '236', '245', '290', '380', '470',
            '489', '560', '678', '579', '129', '138', '147', '156', '237', '246',
            '345', '390', '480', '570', '589', '679', '120', '139', '148', '157',
            '238', '247', '256', '346', '490', '580', '670', '689', '130', '149',
            '158', '167', '239', '248', '257', '347', '356', '590', '680', '789',
            // Additional 30 pannas to make it 90
            '123', '124', '125', '126', '134', '135', '234', '238', '239', '245',
            '246', '247', '248', '249', '256', '257', '258', '259', '267', '268',
            '269', '278', '279', '289', '348', '349', '358', '359', '367', '368'
        ];

        // Helper function to convert digit for calculation (0 becomes 10)
        function getDigitValue(digit) {
            return digit === '0' ? 10 : parseInt(digit);
        }

        // Function to initialize Abr-Cut game
        function initializeAbrCutGame() {
            // Use the exact 90 Abr-Cut pannas
            currentAbrCutOutcomes = CORRECT_ABR_CUT_PANNAS;
            
            // Verify we have exactly 90 pannas
            if (currentAbrCutOutcomes.length !== 90) {
                console.error("Error: Expected 90 pannas, got", currentAbrCutOutcomes.length);
                // If not exactly 90, take first 90
                currentAbrCutOutcomes = currentAbrCutOutcomes.slice(0, 90);
            }
            
            // Populate the grid
            updateAbrCutOutcomes(currentAbrCutOutcomes);
            
            // Set up event listeners
            document.getElementById('abr-cut-amount').addEventListener('input', updateAbrCutTotal);
            
            // Set mode toggle for Abr-Cut
            document.querySelectorAll('.toggle-option').forEach(option => {
                option.addEventListener('click', function() {
                    const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                    document.getElementById('form-abr-cut-mode').value = mode;
                });
            });
            
            // Initial calculation
            updateAbrCutTotal();
            
            console.log("Abr-Cut Pannas Count:", currentAbrCutOutcomes.length);
        }

        // Function to update Abr-Cut outcomes in grid format
        function updateAbrCutOutcomes(outcomes) {
            const container = document.getElementById('abr-cut-outcomes-container');
            
            if (outcomes.length === 0) {
                container.innerHTML = '<div class="no-digits">No pannas available</div>';
            } else {
                let html = `
                    <div class="pana-header">
                        <h4>Abr-Cut Panna Combinations (${outcomes.length})</h4>
                        <div class="digit-info">90 panna combinations (9 pannas from each digit)</div>
                    </div>
                    <div class="pana-list">
                `;
                
                outcomes.forEach((outcome) => {
                    // Calculate sum for display (0 means 10)
                    const digits = outcome.split('').map(d => getDigitValue(d));
                    const sum = digits.reduce((a, b) => a + b, 0);
                    const sumText = digits.map(d => d === 10 ? '10' : d.toString()).join('+') + '=' + sum;
                    
                    html += `
                        <div class="pana-item" data-outcome="${outcome}">
                            <div class="pana-value-container">
                                <span class="pana-value">${outcome}</span>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
            
            document.getElementById('abr-cut-outcomes-count').textContent = outcomes.length;
        }

        // Function to update Abr-Cut total
        function updateAbrCutTotal() {
            const amount = parseFloat(document.getElementById('abr-cut-amount').value) || 0;
            const activeOutcomeCount = currentAbrCutOutcomes.length;
            const total = amount * activeOutcomeCount;
            
            currentAbrCutAmount = amount;
            
            document.getElementById('abr-cut-outcomes-count').textContent = activeOutcomeCount;
            document.getElementById('abr-cut-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
            document.getElementById('abr-cut-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
            document.getElementById('form-abr-cut-bet-amount').value = amount;
            
            // Update form with all outcomes
            document.getElementById('form-abr-cut-outcomes').value = JSON.stringify(currentAbrCutOutcomes);
            
            // Enable/disable bet button
            const betButton = document.getElementById('place-abr-cut-bet-btn');
            const validationEl = document.getElementById('abr-cut-validation');
            
            if (total > 0 && total < 5) {
                validationEl.textContent = "Minimum total bet is INR 5.00";
                betButton.disabled = true;
            } else if (activeOutcomeCount === 0) {
                validationEl.textContent = "No pannas available for betting";
                betButton.disabled = true;
            } else if (amount <= 0) {
                validationEl.textContent = "Please enter a valid bet amount";
                betButton.disabled = true;
            } else {
                validationEl.textContent = "";
                betButton.disabled = false;
            }
        }

        // Initialize when Abr-Cut page loads
        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.abr-cut-interface').style.display === 'block') {
                initializeAbrCutGame();
            }
        });
        </script>
        <!-- ekki bkki -->
        <script>
                // Eki Game Logic - 10 separate odd digit panna combinations
                let currentEkiAmount = 0;

                function initializeEkiGame() {
                    document.getElementById('eki-amount').addEventListener('input', updateEkiTotal);
                    
                    document.querySelectorAll('.toggle-option').forEach(option => {
                        option.addEventListener('click', function() {
                            const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                            const formModeInput = document.getElementById('form-eki-mode');
                            if (formModeInput) {
                                formModeInput.value = mode;
                            }
                        });
                    });
                    
                    updateEkiTotal();
                }

                function updateEkiTotal() {
                    const amount = parseFloat(document.getElementById('eki-amount').value) || 0;
                    const pannaCount = 10; // 10 total outcomes
                    const total = amount * pannaCount;
                    
                    currentEkiAmount = amount;
                    
                    updateEkiTableDisplay(amount);
                    
                    document.getElementById('eki-outcomes-count').textContent = pannaCount;
                    document.getElementById('eki-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
                    document.getElementById('eki-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
                    document.getElementById('form-eki-bet-amount').value = amount;
                    
                    const betButton = document.getElementById('place-eki-bet-btn');
                    const validationEl = document.getElementById('eki-validation');
                    
                    if (total > 0 && total < 5) {
                        validationEl.textContent = "Minimum total bet is INR 5.00";
                        betButton.disabled = true;
                    } else if (amount <= 0) {
                        validationEl.textContent = "Please enter a valid bet amount";
                        betButton.disabled = true;
                    } else {
                        validationEl.textContent = "";
                        betButton.disabled = false;
                    }
                }

                function updateEkiTableDisplay(amount) {
                    const ekiRows = document.querySelectorAll('.eki-panna-row');
                    
                    ekiRows.forEach(row => {
                        const amountCell = row.querySelector('.eki-amount-display');
                        const panna = row.dataset.panna;
                        
                        if (amount > 0) {
                            amountCell.textContent = `₹${amount.toFixed(2)}`;
                            amountCell.classList.add('has-bet');
                            row.classList.add('active-bet');
                        } else {
                            amountCell.textContent = '-';
                            amountCell.classList.remove('has-bet');
                            row.classList.remove('active-bet');
                        }
                    });
                }

                // Bkki Game Logic - 10 separate even digit panna combinations
                let currentBkkiAmount = 0;

                function initializeBkkiGame() {
                    document.getElementById('bkki-amount').addEventListener('input', updateBkkiTotal);
                    
                    document.querySelectorAll('.toggle-option').forEach(option => {
                        option.addEventListener('click', function() {
                            const mode = (this.id === 'open-toggle') ? 'open' : 'close';
                            const formModeInput = document.getElementById('form-bkki-mode');
                            if (formModeInput) {
                                formModeInput.value = mode;
                            }
                        });
                    });
                    
                    updateBkkiTotal();
                }

                function updateBkkiTotal() {
                    const amount = parseFloat(document.getElementById('bkki-amount').value) || 0;
                    const pannaCount = 10; // 10 total outcomes
                    const total = amount * pannaCount;
                    
                    currentBkkiAmount = amount;
                    
                    updateBkkiTableDisplay(amount);
                    
                    document.getElementById('bkki-outcomes-count').textContent = pannaCount;
                    document.getElementById('bkki-amount-per-outcome').textContent = `INR ${amount.toFixed(2)}`;
                    document.getElementById('bkki-total-bet-amount').textContent = `INR ${total.toFixed(2)}`;
                    document.getElementById('form-bkki-bet-amount').value = amount;
                    
                    const betButton = document.getElementById('place-bkki-bet-btn');
                    const validationEl = document.getElementById('bkki-validation');
                    
                    if (total > 0 && total < 5) {
                        validationEl.textContent = "Minimum total bet is INR 5.00";
                        betButton.disabled = true;
                    } else if (amount <= 0) {
                        validationEl.textContent = "Please enter a valid bet amount";
                        betButton.disabled = true;
                    } else {
                        validationEl.textContent = "";
                        betButton.disabled = false;
                    }
                }

                function updateBkkiTableDisplay(amount) {
                    const bkkiRows = document.querySelectorAll('.bkki-panna-row');
                    
                    bkkiRows.forEach(row => {
                        const amountCell = row.querySelector('.bkki-amount-display');
                        const panna = row.dataset.panna;
                        
                        if (amount > 0) {
                            amountCell.textContent = `₹${amount.toFixed(2)}`;
                            amountCell.classList.add('has-bet');
                            row.classList.add('active-bet');
                        } else {
                            amountCell.textContent = '-';
                            amountCell.classList.remove('has-bet');
                            row.classList.remove('active-bet');
                        }
                    });
                }
 </script>
<!-- timer js -->
<script>
    // Timer functionality for dynamic matka times
    function setupMatkaTimers(gameName, openTime, closeTime) {
        function updateTimers() {
            const now = new Date();
            
            // Get current IST time (UTC +5:30)
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const istTime = new Date(utc + (5.5 * 3600000));
            
            // Parse open and close times
            const [openHours, openMinutes, openSeconds] = openTime.split(':').map(Number);
            const [closeHours, closeMinutes, closeSeconds] = closeTime.split(':').map(Number);
            
            // Create date objects for today's open and close times in IST
            const openTimeToday = new Date(istTime);
            openTimeToday.setHours(openHours, openMinutes, openSeconds || 0, 0);
            
            const closeTimeToday = new Date(istTime);
            closeTimeToday.setHours(closeHours, closeMinutes, closeSeconds || 0, 0);
            
            // If open time has already passed today, set it for tomorrow
            if (openTimeToday < istTime) {
                openTimeToday.setDate(openTimeToday.getDate() + 1);
            }
            
            // If close time has already passed today, set it for tomorrow
            if (closeTimeToday < istTime) {
                closeTimeToday.setDate(closeTimeToday.getDate() + 1);
            }
            
            // Calculate time differences
            const openDiff = openTimeToday - istTime;
            const closeDiff = closeTimeToday - istTime;
            
            // Format time strings
            const openTimer = formatTimeDiff(openDiff);
            const closeTimer = formatTimeDiff(closeDiff);
            
            // Update DOM elements
            const openTimerElement = document.getElementById('open-timer-single');
            const closeTimerElement = document.getElementById('close-timer-single');
            
            if (openTimerElement) openTimerElement.textContent = openTimer;
            if (closeTimerElement) closeTimerElement.textContent = closeTimer;
            
            // Update status based on time and change colors
            updateGameStatus(openDiff, closeDiff);
            
            // Update page title with countdown when game is about to open/close
            updatePageTitle(gameName, openDiff, closeDiff);
        }
        
        function updateGameStatus(openDiff, closeDiff) {
            const openTimerElement = document.getElementById('open-timer-single');
            const closeTimerElement = document.getElementById('close-timer-single');
            
            if (!openTimerElement || !closeTimerElement) return;
            
            // Reset colors first
            openTimerElement.style.color = '';
            closeTimerElement.style.color = '';
            
            // Game status logic
            if (openDiff <= 0) {
                // Game is open
                openTimerElement.textContent = 'OPEN NOW';
                openTimerElement.style.color = '#4CAF50'; // Green
                openTimerElement.style.fontWeight = 'bold';
                
                // Change close timer to red when less than 30 minutes remaining
                if (closeDiff <= 30 * 60 * 1000) { // 30 minutes in milliseconds
                    closeTimerElement.style.color = '#f44336'; // Red
                    closeTimerElement.style.fontWeight = 'bold';
                    
                    // Blink effect when less than 5 minutes
                    if (closeDiff <= 5 * 60 * 1000) {
                        closeTimerElement.style.animation = closeTimerElement.style.animation ? '' : 'blink 1s infinite';
                    }
                }
            } else {
                // Game is not open yet
                openTimerElement.style.fontWeight = 'normal';
                closeTimerElement.style.fontWeight = 'normal';
                closeTimerElement.style.animation = '';
                
                // Change open timer to orange when less than 30 minutes to open
                if (openDiff <= 30 * 60 * 1000) {
                    openTimerElement.style.color = '#FF9800'; // Orange
                }
            }
        }
        
        function updatePageTitle(gameName, openDiff, closeDiff) {
            let titleSuffix = '';
            
            if (openDiff <= 0) {
                // Game is open - show close countdown
                if (closeDiff <= 5 * 60 * 1000) {
                    titleSuffix = ' - Closing soon!';
                } else if (closeDiff <= 30 * 60 * 1000) {
                    const minutes = Math.floor(closeDiff / (1000 * 60));
                    titleSuffix = ` - Closes in ${minutes}m`;
                }
            } else {
                // Game not open yet - show open countdown
                if (openDiff <= 30 * 60 * 1000) {
                    const minutes = Math.floor(openDiff / (1000 * 60));
                    titleSuffix = ` - Opens in ${minutes}m`;
                }
            }
            
            // Update page title
            const originalTitle = `${gameName} - ${document.querySelector('.betting-title').textContent}`;
            document.title = originalTitle + titleSuffix;
        }
        
        function formatTimeDiff(diff) {
            if (diff <= 0) {
                return '00:00:00';
            }
            
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            return `${padZero(hours)}:${padZero(minutes)}:${padZero(seconds)}`;
        }
        
        function padZero(num) {
            return num.toString().padStart(2, '0');
        }
        
        // Initialize and update timers every second
        updateTimers();
        const timerInterval = setInterval(updateTimers, 1000);
        
        // Return function to stop timers if needed
        return {
            stop: () => clearInterval(timerInterval)
        };
    }

    // Get URL parameters for matka data
    function getMatkaParams() {
        const urlParams = new URLSearchParams(window.location.search);
        
        return {
            game: urlParams.get('game') || 'Kalyan Matka',
            openTime: urlParams.get('openTime') || '15:30:00',
            closeTime: urlParams.get('closeTime') || '17:30:00'
        };
    }

    // Initialize the timers when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const params = getMatkaParams();
        const timerController = setupMatkaTimers(params.game, params.openTime, params.closeTime);
        
        // Store timer controller in case we need to stop it later
        window.matkaTimerController = timerController;
        
        // Also update any other elements that need dynamic time info
        updateDynamicTimeElements(params.openTime, params.closeTime);
    });

    // Function to update other time-related elements on the page
    function updateDynamicTimeElements(openTime, closeTime) {
        // Update any time displays in the betting interface
        const timeDisplays = document.querySelectorAll('.game-time-display');
        timeDisplays.forEach(display => {
            display.textContent = `${openTime} to ${closeTime} IST`;
        });
        
        // Update mode toggle based on current time
        updateModeToggleBasedOnTime(openTime, closeTime);
    }

   

    // Add CSS for blinking animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .timer-box .timer-value {
            transition: all 0.3s ease;
        }
        
        .game-timers {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .timer-box {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px 15px;
            text-align: center;
            min-width: 120px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .timer-box.active {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, 0.1);
        }
        
        .timer-label {
            font-size: 12px;
            color: #ccc;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .timer-value {
            font-size: 18px;
            font-weight: bold;
            color: #fff;
            font-family: 'Courier New', monospace;
        }
    `;
    document.head.appendChild(style);

    // Utility function to convert time to readable format
    function formatTimeForDisplay(timeString) {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 || 12;
        return `${displayHour}:${minutes} ${ampm}`;
    }

    // Export functions for global access (if needed)
    window.MatkaTimer = {
        setupTimers: setupMatkaTimers,
        getParams: getMatkaParams,
        formatTime: formatTimeForDisplay
    };
</script>

<!-- close matka js -->
<script>
    // Game Time Management Script
    function manageGameTimeLogic(openTime, closeTime, gameType = 'all') {
        function checkGameTime() {
            const now = new Date();
            
            // Get current IST time (UTC +5:30)
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const istTime = new Date(utc + (5.5 * 3600000));
            
            // Parse open and close times
            const [openHours, openMinutes, openSeconds] = openTime.split(':').map(Number);
            const [closeHours, closeMinutes, closeSeconds] = closeTime.split(':').map(Number);
            
            // Create date objects for today's open and close times in IST
            const openTimeToday = new Date(istTime);
            openTimeToday.setHours(openHours, openMinutes, openSeconds || 0, 0);
            
            const closeTimeToday = new Date(istTime);
            closeTimeToday.setHours(closeHours, closeMinutes, closeSeconds || 0, 0);
            
            // Get time differences in milliseconds
            const openDiff = openTimeToday - istTime;
            const closeDiff = closeTimeToday - istTime;
            
            // Determine game status
            const isOpenTimeCompleted = openDiff <= 0; // Open time has passed
            const isCloseTimeCompleted = closeDiff <= 0; // Close time has passed
            
            // console.log('Game Time Check:', {
            //     gameType: gameType,
            //     currentTime: istTime.toLocaleTimeString(),
            //     openTime: openTimeToday.toLocaleTimeString(),
            //     closeTime: closeTimeToday.toLocaleTimeString(),
            //     isOpenTimeCompleted,
            //     isCloseTimeCompleted
            // });

            // Update game states based on close time completion
            if (isCloseTimeCompleted) {
                // Close time completed - disable all games
                updateAllGameStates('closed');
                showMatkaClosedAlert();
            } else if (isOpenTimeCompleted) {
                // Open time completed but close time not reached
                if (gameType === 'jodi') {
                    // For Jodi: Close when open time starts
                    updateJodiGameStates('closed');
                } else {
                    // For other games: Allow betting until close time
                    updateAllGameStates('open');
                }
            } else {
                // Both open and close times not reached - all games open
                updateAllGameStates('open');
            }
        }
        
        function updateAllGameStates(state) {
            // Update toggle states for all games
            updateToggleStates(state);
            
            // Update place bet buttons for all games
            updatePlaceBetButtons(state);
            
            // Update mode inputs for all games
            updateAllModeInputs(state === 'closed' ? 'close' : 'open');
        }
        
        function updateJodiGameStates(state) {
            // Update toggle states for Jodi
            updateJodiToggleStates(state);
            
            // Update place bet buttons for Jodi
            updateJodiPlaceBetButtons(state);
            
            // Update mode inputs for Jodi
            updateJodiModeInputs(state === 'closed' ? 'close' : 'open');
        }
        
        function updateJodiToggleStates(state) {
            const openToggle = document.getElementById('open-toggle');
            const closeToggle = document.getElementById('close-toggle');
            
            if (!openToggle || !closeToggle) return;
            
            if (state === 'closed') {
                // Force close toggle for Jodi
                openToggle.classList.remove('active');
                closeToggle.classList.add('active');
            } else {
                // Allow normal toggle operation
                // No forced state change
            }
        }
        
        function updateToggleStates(state) {
            const openToggle = document.getElementById('open-toggle');
            const closeToggle = document.getElementById('close-toggle');
            
            if (!openToggle || !closeToggle) return;
            
            if (state === 'closed') {
                // Force close toggle for all games
                openToggle.classList.remove('active');
                closeToggle.classList.add('active');
            }
            // For 'open' state, don't force any toggle state
        }
        
        function updateJodiModeInputs(mode) {
            // Update only Jodi game mode inputs
            const jodiModeInputs = document.querySelectorAll('input[name="mode"][data-game-type="jodi"], input[id*="jodi-mode"], input[name="mode"]:not([data-game-type]):not([id*="single"]):not([id*="patti"])');
            
            // Also target forms that contain "jodi" in their ID or class
            const jodiForms = document.querySelectorAll('form[id*="jodi"], form[class*="jodi"], .jodi-form');
            
            jodiModeInputs.forEach(input => {
                if (input.type === 'hidden') {
                    input.value = mode;
                }
            });
            
            jodiForms.forEach(form => {
                const modeInput = form.querySelector('input[name="mode"]');
                if (modeInput) {
                    modeInput.value = mode;
                }
            });
        }
        
        function updateAllModeInputs(mode) {
            // Update all mode hidden inputs across different game forms
            const modeInputs = document.querySelectorAll('input[name="mode"], input[id*="mode"]');
            modeInputs.forEach(input => {
                if (input.type === 'hidden') {
                    input.value = mode;
                }
            });
        }
        
        function updateJodiPlaceBetButtons(state) {
            // Get all place bet buttons for Jodi games
            const jodiPlaceBetButtons = document.querySelectorAll('.place-bet-btn[data-game-type="jodi"], .jodi-bet-btn, button[type="submit"][id*="jodi"], form[id*="jodi"] .place-bet-btn, form[class*="jodi"] .place-bet-btn');
            
            jodiPlaceBetButtons.forEach(button => {
                if (state === 'closed') {
                    // Disable Jodi buttons
                    button.disabled = true;
                    button.style.opacity = '0.6';
                    button.style.cursor = 'not-allowed';
                    
                    // Update button text to indicate closed
                    const originalText = button.getAttribute('data-original-text') || button.textContent;
                    button.setAttribute('data-original-text', originalText);
                    button.innerHTML = 'JODI CLOSED';
                    
                    // Add closed styling
                    button.style.background = '#666';
                    button.style.borderColor = '#666';
                    button.classList.add('jodi-closed');
                } else {
                    // Enable Jodi buttons
                    button.disabled = false;
                    button.style.opacity = '1';
                    button.style.cursor = 'pointer';
                    
                    // Restore original text if it was changed
                    const originalText = button.getAttribute('data-original-text');
                    if (originalText) {
                        button.textContent = originalText;
                        button.removeAttribute('data-original-text');
                    }
                    
                    // Restore original styling
                    button.style.background = '';
                    button.style.borderColor = '';
                    button.classList.remove('jodi-closed');
                }
            });
        }
        
        function updatePlaceBetButtons(state) {
            // Get all place bet buttons (non-Jodi)
            const placeBetButtons = document.querySelectorAll('.place-bet-btn:not([data-game-type="jodi"]):not(.jodi-bet-btn), button[type="submit"]:not([id*="jodi"]), form:not([id*="jodi"]):not([class*="jodi"]) .place-bet-btn');
            
            placeBetButtons.forEach(button => {
                if (state === 'closed') {
                    // Disable buttons and show closed state
                    button.disabled = true;
                    button.style.opacity = '0.6';
                    button.style.cursor = 'not-allowed';
                    
                    // Update button text to indicate closed
                    const originalText = button.getAttribute('data-original-text') || button.textContent;
                    button.setAttribute('data-original-text', originalText);
                    button.innerHTML = 'MATKA CLOSED';
                    
                    // Add closed styling
                    button.style.background = '#666';
                    button.style.borderColor = '#666';
                } else {
                    // Enable buttons
                    button.disabled = false;
                    button.style.opacity = '1';
                    button.style.cursor = 'pointer';
                    
                    // Restore original text if it was changed
                    const originalText = button.getAttribute('data-original-text');
                    if (originalText) {
                        button.textContent = originalText;
                        button.removeAttribute('data-original-text');
                    }
                    
                    // Restore original styling
                    button.style.background = '';
                    button.style.borderColor = '';
                }
            });
        }
        
        function showMatkaClosedAlert() {
            // Only show alert once per session to avoid spamming
            if (!sessionStorage.getItem('matkaClosedAlertShown')) {
                alert('Matka is closed! Betting is no longer allowed for this session.');
                sessionStorage.setItem('matkaClosedAlertShown', 'true');
                
                // Clear the flag after 5 seconds to allow showing again if needed
                setTimeout(() => {
                    sessionStorage.removeItem('matkaClosedAlertShown');
                }, 5000);
            }
        }
        
        // Initialize and check every second
        checkGameTime();
        const timeCheckInterval = setInterval(checkGameTime, 500);
        
        // Return function to stop checking if needed
        return {
            stop: () => clearInterval(timeCheckInterval),
            checkNow: checkGameTime
        };
    }

    // Get game times from URL parameters or use defaults
    function getGameTimes() {
        const urlParams = new URLSearchParams(window.location.search);
        
        return {
            openTime: urlParams.get('openTime') || '15:30:00',
            closeTime: urlParams.get('closeTime') || '17:30:00'
        };
    }

    // Detect game type from page content or URL
    function detectGameType() {
        // Check URL for jodi indicator
        if (window.location.href.includes('jodi') || window.location.href.includes('jodi')) {
            return 'jodi';
        }
        
        // Check for jodi elements in DOM
        if (document.querySelector('[id*="jodi"], [class*="jodi"], .jodi-game')) {
            return 'jodi';
        }
        
        // Check page title or headings
        const pageText = document.body.innerText.toLowerCase();
        if (pageText.includes('jodi') && !pageText.includes('single') && !pageText.includes('patti')) {
            return 'jodi';
        }
        
        return 'all';
    }

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const gameTimes = getGameTimes();
        const gameType = detectGameType();
        
        window.gameTimeManager = manageGameTimeLogic(gameTimes.openTime, gameTimes.closeTime, gameType);
        
        console.log('Game Time Manager initialized with:', {
            ...gameTimes,
            gameType: gameType
        });
    });

    // Enhanced version that works with your existing timer setup
    document.addEventListener('DOMContentLoaded', function() {
        const params = window.getMatkaParams ? window.getMatkaParams() : getGameTimes();
        const gameType = detectGameType();
        
        // Use enhanced timer setup if available, otherwise use basic
        if (window.setupMatkaTimers) {
            window.enhancedTimerController = setupEnhancedMatkaTimers(
                params.game, 
                params.openTime, 
                params.closeTime,
                gameType
            );
        } else {
            window.gameTimeManager = manageGameTimeLogic(params.openTime, params.closeTime, gameType);
        }
    });

    // Setup enhanced matka timers with game type support
    function setupEnhancedMatkaTimers(gameName, openTime, closeTime, gameType = 'all') {
        const timerController = window.MatkaTimer ? 
            window.MatkaTimer.setupTimers(gameName, openTime, closeTime) : 
            null;
        
        const gameTimeManager = manageGameTimeLogic(openTime, closeTime, gameType);
        
        return {
            stop: () => {
                if (timerController) timerController.stop();
                gameTimeManager.stop();
            },
            checkNow: gameTimeManager.checkNow
        };
    }

    // Utility function to manually trigger a time check (for debugging)
    window.forceTimeCheck = function() {
        if (window.gameTimeManager && window.gameTimeManager.checkNow) {
            window.gameTimeManager.checkNow();
        }
    };

    // Add some CSS for the disabled state
    const disabledButtonStyles = `
        .place-bet-btn:disabled {
            background-color: #666 !important;
            border-color: #666 !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
        }
        
        .place-bet-btn.matka-closed {
            background: linear-gradient(135deg, #666, #888) !important;
            border: 2px solid #666 !important;
            color: #ccc !important;
        }
        
        .jodi-closed {
            background: linear-gradient(135deg, #8B0000, #A52A2A) !important;
            border: 2px solid #8B0000 !important;
            color: #fff !important;
        }
    `;

    const styleSheet = document.createElement('style');
    styleSheet.textContent = disabledButtonStyles;
    document.head.appendChild(styleSheet);
</script>

</body>
</html>