<?php
require_once 'config/database.php';
require_once 'classes/Score.php';

startSecureSession();

$database = new Database();
$conn = $database->getConnection();

// Get all active events
$events_query = "SELECT * FROM events WHERE status = 'active' ORDER BY event_date DESC";
$events_stmt = $conn->prepare($events_query);
$events_stmt->execute();
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_event = null;
$selected_category = '';
$selected_apparatus = '';

if (isset($_GET['event_id']) && !empty($_GET['event_id'])) {
    $event_id = $_GET['event_id'];
    
    // Get event details
    $event_query = "SELECT * FROM events WHERE event_id = :event_id";
    $event_stmt = $conn->prepare($event_query);
    $event_stmt->bindParam(':event_id', $event_id);
    $event_stmt->execute();
    $selected_event = $event_stmt->fetch(PDO::FETCH_ASSOC);
    
    $selected_category = $_GET['category'] ?? '';
    $selected_apparatus = $_GET['apparatus'] ?? '';
}

// Function to get leaderboard data
function getLeaderboardData($conn, $event_id, $category = '', $apparatus = '') {
    $where_conditions = ["s.event_id = :event_id"];
    $params = [':event_id' => $event_id];
    
    if (!empty($category)) {
        $where_conditions[] = "g.gymnast_category = :category";
        $params[':category'] = $category;
    }
    
    if (!empty($apparatus)) {
        $where_conditions[] = "s.apparatus_id = :apparatus_id";
        $params[':apparatus_id'] = $apparatus;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "SELECT 
                g.gymnast_id,
                g.gymnast_name,
                g.gymnast_category,
                t.team_name,
                a.apparatus_name,
                s.score_d1, s.score_d2, s.score_d3, s.score_d4,
                s.score_a1, s.score_a2, s.score_a3,
                s.score_e1, s.score_e2, s.score_e3,
                s.technical_deduction,
                s.created_at as score_time
              FROM scores s
              JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
              JOIN teams t ON g.team_id = t.team_id
              JOIN apparatus a ON s.apparatus_id = a.apparatus_id
              WHERE {$where_clause}
              ORDER BY g.gymnast_name, a.apparatus_name";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate final scores and group by gymnast
    $leaderboard = [];
    foreach ($results as $row) {
        $score = new Score(
            $row['score_d1'], $row['score_d2'], $row['score_d3'], $row['score_d4'],
            $row['score_a1'], $row['score_a2'], $row['score_a3'],
            $row['score_e1'], $row['score_e2'], $row['score_e3'],
            $row['technical_deduction'], $row['gymnast_id']
        );
        
        $gymnast_key = $row['gymnast_id'];
        if (!isset($leaderboard[$gymnast_key])) {
            $leaderboard[$gymnast_key] = [
                'gymnast_name' => $row['gymnast_name'],
                'team_name' => $row['team_name'],
                'category' => $row['gymnast_category'],
                'total_score' => 0,
                'apparatus_scores' => []
            ];
        }
        
        $final_score = $score->getFinalScore();
        $leaderboard[$gymnast_key]['apparatus_scores'][$row['apparatus_name']] = $final_score;
        $leaderboard[$gymnast_key]['total_score'] += $final_score;
    }
    
    // Sort by total score descending
    uasort($leaderboard, function($a, $b) {
        return $b['total_score'] <=> $a['total_score'];
    });
    
    return $leaderboard;
}

$leaderboard_data = [];
if ($selected_event) {
    $leaderboard_data = getLeaderboardData($conn, $selected_event['event_id'], $selected_category, $selected_apparatus);
}

// Get categories and apparatus for filters
$categories = [];
$apparatus_list = [];
if ($selected_event) {
    $cat_query = "SELECT DISTINCT g.gymnast_category FROM gymnasts g 
                  JOIN scores s ON g.gymnast_id = s.gymnast_id 
                  WHERE s.event_id = :event_id";
    $cat_stmt = $conn->prepare($cat_query);
    $cat_stmt->bindParam(':event_id', $selected_event['event_id']);
    $cat_stmt->execute();
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $app_query = "SELECT * FROM apparatus ORDER BY apparatus_name";
    $app_stmt = $conn->prepare($app_query);
    $app_stmt->execute();
    $apparatus_list = $app_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Leaderboard - Gymnastics Scoring</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
        }

        .header {
            background: #2c3e50;
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.3rem;
            font-weight: bold;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #e74c3c;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        .event-selector {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #555;
        }

        select {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
        }

        select:focus {
            outline: none;
            border-color: #3498db;
        }

        .leaderboard-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .leaderboard-header {
            background: #34495e;
            color: white;
            padding: 1.5rem;
            text-align: center;
        }

        .leaderboard-title {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .event-info {
            opacity: 0.9;
            font-size: 1rem;
        }

        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leaderboard-table th {
            background: #ecf0f1;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #bdc3c7;
        }

        .leaderboard-table td {
            padding: 1rem;
            border-bottom: 1px solid #ecf0f1;
        }

        .leaderboard-table tr:hover {
            background: #f8f9fa;
        }

        .rank {
            font-weight: bold;
            font-size: 1.2rem;
            text-align: center;
            width: 60px;
        }

        .rank-1 { color: #f39c12; }
        .rank-2 { color: #95a5a6; }
        .rank-3 { color: #e67e22; }

        .gymnast-name {
            font-weight: bold;
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .team-name {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .score {
            font-weight: bold;
            font-size: 1.1rem;
            text-align: center;
        }

        .total-score {
            background: #3498db;
            color: white;
            padding: 0.5rem;
            border-radius: 5px;
            font-size: 1.2rem;
        }

        .apparatus-score {
            text-align: center;
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .back-btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .back-btn:hover {
            background: #2980b9;
        }

        /* Mobile Responsive - Priority: Name and Score */
        @media (max-width: 768px) {
            .leaderboard-table {
                font-size: 0.9rem;
            }
            
            .leaderboard-table th,
            .leaderboard-table td {
                padding: 0.8rem 0.5rem;
            }
            
            /* Hide less important columns on mobile */
            .apparatus-columns {
                display: none;
            }
            
            .gymnast-info {
                max-width: 150px;
            }
            
            .gymnast-name {
                font-size: 1rem;
                display: block;
            }
            
            .team-name {
                font-size: 0.8rem;
                display: block;
                margin-top: 0.2rem;
            }
            
            .total-score {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .leaderboard-table th,
            .leaderboard-table td {
                padding: 0.6rem 0.3rem;
            }
            
            .rank {
                width: 40px;
            }
            
            .gymnast-name {
                font-size: 0.9rem;
            }
            
            .team-name {
                font-size: 0.75rem;
            }
        }

        .refresh-info {
            text-align: center;
            padding: 1rem;
            color: #7f8c8d;
            font-size: 0.9rem;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                🏆 Live Leaderboard
            </div>
            <div class="live-indicator">
                <div class="live-dot"></div>
                LIVE
            </div>
        </div>
    </header>

    <div class="container">
        <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>

        <div class="event-selector">
            <form method="GET" action="">
                <div class="filters">
                    <div class="filter-group">
                        <label for="event_id">Select Event:</label>
                        <select name="event_id" id="event_id" onchange="this.form.submit()">
                            <option value="">Choose an event...</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['event_id']; ?>" 
                                        <?php echo ($selected_event && $selected_event['event_id'] == $event['event_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($event['event_name']); ?> - <?php echo $event['event_date']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($selected_event): ?>
                    <div class="filter-group">
                        <label for="category">Filter by Category:</label>
                        <select name="category" id="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                        <?php echo ($selected_category == $category) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="apparatus">Filter by Apparatus:</label>
                        <select name="apparatus" id="apparatus" onchange="this.form.submit()">
                            <option value="">All Apparatus</option>
                            <?php foreach ($apparatus_list as $apparatus): ?>
                                <option value="<?php echo $apparatus['apparatus_id']; ?>" 
                                        <?php echo ($selected_apparatus == $apparatus['apparatus_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($apparatus['apparatus_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($selected_event): ?>
        <div class="leaderboard-container">
            <div class="leaderboard-header">
                <div class="leaderboard-title"><?php echo htmlspecialchars($selected_event['event_name']); ?></div>
                <div class="event-info">
                    <?php echo $selected_event['event_date']; ?> • <?php echo htmlspecialchars($selected_event['location'] ?? 'Location TBA'); ?>
                    <?php if ($selected_category): ?>
                        • Category: <?php echo htmlspecialchars($selected_category); ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($leaderboard_data)): ?>
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th class="rank">Rank</th>
                        <th>Gymnast</th>
                        <th class="score">Total Score</th>
                        <th class="apparatus-columns">Floor</th>
                        <th class="apparatus-columns">Pommel Horse</th>
                        <th class="apparatus-columns">Rings</th>
                        <th class="apparatus-columns">Vault</th>
                        <th class="apparatus-columns">Parallel Bars</th>
                        <th class="apparatus-columns">Horizontal Bar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($leaderboard_data as $data): 
                    ?>
                    <tr>
                        <td class="rank rank-<?php echo $rank; ?>"><?php echo $rank; ?></td>
                        <td class="gymnast-info">
                            <div class="gymnast-name"><?php echo htmlspecialchars($data['gymnast_name']); ?></div>
                            <div class="team-name"><?php echo htmlspecialchars($data['team_name']); ?></div>
                        </td>
                        <td class="score">
                            <span class="total-score"><?php echo number_format($data['total_score'], 2); ?></span>
                        </td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Floor Exercise']) ? number_format($data['apparatus_scores']['Floor Exercise'], 2) : '-'; ?></td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Pommel Horse']) ? number_format($data['apparatus_scores']['Pommel Horse'], 2) : '-'; ?></td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Still Rings']) ? number_format($data['apparatus_scores']['Still Rings'], 2) : '-'; ?></td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Vault']) ? number_format($data['apparatus_scores']['Vault'], 2) : '-'; ?></td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Parallel Bars']) ? number_format($data['apparatus_scores']['Parallel Bars'], 2) : '-'; ?></td>
                        <td class="apparatus-score apparatus-columns"><?php echo isset($data['apparatus_scores']['Horizontal Bar']) ? number_format($data['apparatus_scores']['Horizontal Bar'], 2) : '-'; ?></td>
                    </tr>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <h3>No scores available yet</h3>
                <p>Scores will appear here as judges enter them during the competition.</p>
            </div>
            <?php endif; ?>

            <div class="refresh-info">
                📱 Page automatically refreshes every 15 seconds for live updates
            </div>
        </div>
        <?php else: ?>
        <div class="leaderboard-container">
            <div class="no-data">
                <h3>Select an Event</h3>
                <p>Choose an active event above to view the live leaderboard.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 15 seconds for live updates
        setTimeout(function() {
            window.location.reload();
        }, 15000);

        // Show loading indicator during refresh
        let refreshTimer = 15;
        setInterval(function() {
            refreshTimer--;
            if (refreshTimer <= 0) {
                refreshTimer = 15;
            }
        }, 1000);
    </script>
</body>
</html>