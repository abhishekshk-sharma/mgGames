<?php
// chart.php
require_once 'config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("location: login.php");
    exit;
}

// Fetch user data
$is_logged_in = true;
$user_balance = 0;
$username = '';
$user_id = $_SESSION['user_id'];

$sql = "SELECT username, balance FROM users WHERE id = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($username, $user_balance);
    $stmt->fetch();
    $stmt->close();
}

// Fetch game types and payout data from database
$payout_data = [];
$game_types_sql = "SELECT id, name, code, description, payout_ratio, min_selection, max_selection 
                   FROM game_types 
                   WHERE status = 'active' 
                   ORDER BY payout_ratio DESC";
$result = $conn->query($game_types_sql);

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $payout_data[] = $row;
    }
}

include 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RB Games - Payout Chart</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #c1436dff;
            --secondary: #0fb4c9ff;
            --accent: #00cec9;
            --dark: #7098a3ff;
            --light: #f5f6fa;
            --success: #00A650;
            --warning: #FF9800;
            --danger: #D32F2F;
            --card-bg: #1A1A1A;
        }

        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: var(--light);
            min-height: 100vh;
        }

        main {
            flex: 1;
            margin-top: 80px;
            padding: 2rem;
        }

        .section-title {
            font-size: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            padding-bottom: 10px;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: 2px;
        }

        .chart-container {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .payout-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2rem;
            background: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .payout-table th, .payout-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .payout-table th {
            background: rgba(255, 60, 126, 0.2);
            color: var(--primary);
            font-weight: 600;
            text-transform: uppercase;
        }

        .payout-table tr:last-child td {
            border-bottom: none;
        }

        .payout-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .payout-value {
            font-weight: 600;
            color: var(--success);
        }

        .game-type {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .game-icon {
            font-size: 1.2rem;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }

        .game-code {
            font-size: 0.8rem;
            color: #888;
            margin-left: 5px;
        }

        .selection-info {
            font-size: 0.8rem;
            color: #aaa;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .payout-table {
                display: block;
                overflow-x: auto;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

    <!-- Main Content -->
    <main>
        <h2 class="section-title">Game Payout Chart</h2>
        <!-- Detailed Payout Table -->
        <div class="chart-container">
            <h3>Detailed Payout Information</h3>
            <table class="payout-table">
                <thead>
                    <tr>
                        <th>Game Type</th>
                        <th>Description</th>
                        <th>Payout Rate</th>
                        <th>Win Example</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payout_data)): ?>
                        <?php foreach ($payout_data as $game_type): ?>
                            <?php
                            // Get appropriate icon based on game type
                            $icon = getGameTypeIcon($game_type['code']);
                            $payout_ratio = floatval($game_type['payout_ratio']);
                            $bet_amount = 10; // Example bet amount
                            $win_amount = $bet_amount * $payout_ratio;
                           
                            ?>
                            <tr>
                                <td>
                                    <div class="game-type">
                                        <div class="game-icon"><?php echo $icon; ?></div>
                                        <div>
                                            <span><?php echo htmlspecialchars($game_type['name']); ?></span>
                                            <span class="game-code">(<?php echo $game_type['code']; ?>)</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($game_type['description'] ?? 'No description available'); ?>
                                </td>
                                <td class="payout-value">1:<?php echo $payout_ratio; ?></td>
                                <td>Bet ₹<?php echo $bet_amount; ?> → Win ₹<?php echo number_format($win_amount, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #888;">
                                No payout data available.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<script src="includes/script.js"></script>

    <script>
        // Chart.js implementation with dynamic data
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('payoutChart').getContext('2d');
            
            // PHP data for JavaScript
            const gameTypes = <?php echo json_encode(array_column($payout_data, 'name')); ?>;
            const payoutRatios = <?php echo json_encode(array_column($payout_data, 'payout_ratio')); ?>;
            
            const payoutChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: gameTypes,
                    datasets: [{
                        label: 'Payout Ratio (1:X)',
                        data: payoutRatios,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.7)',
                            'rgba(54, 162, 235, 0.7)',
                            'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)',
                            'rgba(153, 102, 255, 0.7)',
                            'rgba(255, 159, 64, 0.7)',
                            'rgba(199, 199, 199, 0.7)',
                            'rgba(83, 102, 255, 0.7)',
                            'rgba(40, 159, 64, 0.7)',
                            'rgba(255, 99, 132, 0.7)',
                            'rgba(54, 162, 235, 0.7)',
                            'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)',
                            'rgba(153, 102, 255, 0.7)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)',
                            'rgba(83, 102, 255, 1)',
                            'rgba(40, 159, 64, 1)',
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: '#f5f6fa',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        title: {
                            display: true,
                            text: 'Game Payout Distribution',
                            color: '#f5f6fa',
                            font: {
                                size: 18
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.label}: 1:${context.raw}`;
                                }
                            }
                        }
                    }
                }
            });
        });

        // Profile dropdown toggle
        const profileIcon = document.getElementById('profile-icon');
        const dropdownMenu = document.getElementById('dropdown-menu');
        
        if (profileIcon) {
            profileIcon.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdownMenu.classList.toggle('show');
            });
            
            // Close dropdown when clicking elsewhere
            document.addEventListener('click', (e) => {
                if (!profileIcon.contains(e.target)) {
                    dropdownMenu.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>
<?php
// Helper function to get appropriate icons for game types
function getGameTypeIcon($code) {
    $icons = [
        'SINGLE_ANK' => '🔢',
        'JODI' => '🔣',
        'SINGLE_PATTI' => '🎯',
        'DOUBLE_PATTI' => '🎲',
        'TRIPLE_PATTI' => '🎰',
        'SP_MOTOR' => '🏍️',
        'DP_MOTOR' => '🏎️',
        'SP_SET' => '📊',
        'DP_SET' => '📈',
        'TP_SET' => '📉',
        'SP' => '🎯',
        'DP' => '🎲',
        'COMMON' => '🌐',
        'SERIES' => '📚'
    ];
    
    return $icons[$code] ?? '🎮';
}
?>
