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

// Function to get detailed leaderboard data
function getDetailedLeaderboardData($conn, $event_id, $category = '', $apparatus = '') {
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
                o.org_name,
                a.apparatus_name,
                s.score_d1, s.score_d2, s.score_d3, s.score_d4,
                s.score_a1, s.score_a2, s.score_a3,
                s.score_e1, s.score_e2, s.score_e3,
                s.technical_deduction,
                s.created_at as score_time
              FROM scores s
              JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
              JOIN teams t ON g.team_id = t.team_id
              LEFT JOIN organizations o ON t.organization_id = o.org_id
              JOIN apparatus a ON s.apparatus_id = a.apparatus_id
              WHERE {$where_clause}
              ORDER BY g.gymnast_name, a.apparatus_name";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate scores and add calculations
    $leaderboard = [];
    foreach ($results as $row) {
        $score = new Score(
            $row['score_d1'], $row['score_d2'], $row['score_d3'], $row['score_d4'],
            $row['score_a1'], $row['score_a2'], $row['score_a3'],
            $row['score_e1'], $row['score_e2'], $row['score_e3'],
            $row['technical_deduction'], $row['gymnast_id']
        );
        
        // Add calculated values
        $row['d1_d2_avg'] = $score->getAverageD1andD2();
        $row['d3_d4_avg'] = $score->getAverageD3andD4();
        $row['total_d'] = $score->totalScoreD();
        $row['middle_a'] = $score->getMiddleAScore();
        $row['middle_e'] = $score->getMiddleEScore();
        $row['a_deduction'] = 10 - $row['middle_a'];
        $row['e_deduction'] = 10 - $row['middle_e'];
        $row['final_score'] = $score->getFinalScore();
        
        $leaderboard[] = $row;
    }
    
    // Sort by final score descending
    usort($leaderboard, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });
    
    return $leaderboard;
}

$leaderboard_data = [];
if ($selected_event) {
    $leaderboard_data = getDetailedLeaderboardData($conn, $selected_event['event_id'], $selected_category, $selected_apparatus);
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
    <title>Live Leaderboard - Rhythmic Gymnastics Scoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #1A1B23;
            color: white;
            min-height: 100vh;
        }

        .header {
            background: #1A1B23;
            padding: 1rem 0;
            border-bottom: 1px solid #2D2E3F;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #10B981;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #2D2E3F;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #3D3E4F;
            transform: translateX(-2px);
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .filters-section {
            background: #2D2E3F;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            border: 1px solid #3D3E4F;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: white;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group select {
            padding: 1rem;
            border: 2px solid #3D3E4F;
            border-radius: 8px;
            font-size: 1rem;
            background: #1A1B23;
            color: white;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        .leaderboard-container {
            background: #2D2E3F;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #3D3E4F;
            margin-bottom: 2rem;
        }

        .leaderboard-header {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            padding: 2rem;
            text-align: center;
        }

        .leaderboard-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .event-info {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .table-container {
            overflow-x: auto;
            background: #1A1B23;
        }

        .detailed-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
        }

        .detailed-table th {
            background: #2D2E3F;
            padding: 1rem 0.75rem;
            text-align: center;
            font-weight: 600;
            color: #A3A3A3;
            border-bottom: 2px solid #3D3E4F;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detailed-table td {
            padding: 1rem 0.75rem;
            text-align: center;
            border-bottom: 1px solid #2D2E3F;
            font-size: 0.9rem;
        }

        .detailed-table tbody tr:hover {
            background: #2D2E3F;
        }

        .section-header {
            background: #3D3E4F;
            color: #8B5CF6;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .rank-cell {
            font-weight: 700;
            font-size: 1.1rem;
            color: #8B5CF6;
        }

        .gymnast-cell {
            text-align: left !important;
            font-weight: 600;
            color: white;
        }

        .club-cell {
            text-align: left !important;
            color: #A3A3A3;
            font-size: 0.85rem;
        }

        .apparatus-cell {
            font-weight: 600;
            color: #10B981;
        }

        .score-cell {
            font-weight: 500;
            color: #F3F4F6;
        }

        .final-score-cell {
            font-weight: 700;
            font-size: 1.1rem;
            color: #8B5CF6;
            background: #2D2E3F;
        }

        .difficulty-section {
            background: rgba(59, 130, 246, 0.1);
        }

        .execution-section {
            background: rgba(16, 185, 129, 0.1);
        }

        .artistry-section {
            background: rgba(245, 158, 11, 0.1);
        }

        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            color: #A3A3A3;
        }

        .no-data h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: white;
        }

        .refresh-info {
            text-align: center;
            padding: 1.5rem;
            color: #A3A3A3;
            background: #2D2E3F;
            border-top: 1px solid #3D3E4F;
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .header-content {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .container {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .detailed-table {
                min-width: 800px;
            }
            
            .detailed-table th,
            .detailed-table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.8rem;
            }
        }

        .rank-1 { color: #F59E0B; }
        .rank-2 { color: #6B7280; }
        .rank-3 { color: #CD7C2F; }

        .zero-score {
            color: #6B7280;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">🏆</div>
                <div class="logo-text">Live Leaderboard</div>
            </div>
            <div class="live-indicator">
                <div class="live-dot"></div>
                LIVE SCORING
            </div>
        </div>
    </header>

    <div class="container">
        <a href="index.php" class="back-btn">← Back to Competitions</a>

        <div class="page-header">
            <h1 class="page-title">Detailed Scoring Leaderboard</h1>
        </div>

        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
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
            <div class="table-container">
                <table class="detailed-table">
                    <thead>
                        <tr>
                            <th rowspan="2">BIL</th>
                            <th rowspan="2">GYMNAST</th>
                            <th rowspan="2">CLUB</th>
                            <th rowspan="2">APPARATUS</th>
                            <th colspan="7" class="section-header difficulty-section">DIFFICULTY</th>
                            <th colspan="7" class="section-header execution-section">EXECUTION</th>
                            <th rowspan="2" class="section-header artistry-section">FINAL SCORE</th>
                        </tr>
                        <tr>
                            <!-- Difficulty columns -->
                            <th class="difficulty-section">D1</th>
                            <th class="difficulty-section">D2</th>
                            <th class="difficulty-section">D1-D2</th>
                            <th class="difficulty-section">D3</th>
                            <th class="difficulty-section">D4</th>
                            <th class="difficulty-section">D3-D4</th>
                            <th class="difficulty-section">Total D</th>
                            <!-- Execution columns -->
                            <th class="execution-section">A1</th>
                            <th class="execution-section">A2</th>
                            <th class="execution-section">A3</th>
                            <th class="execution-section">10-A(A)</th>
                            <th class="execution-section">E1</th>
                            <th class="execution-section">E2</th>
                            <th class="execution-section">E3</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($leaderboard_data as $data): 
                        ?>
                        <tr>
                            <td class="rank-cell rank-<?php echo ($rank <= 3) ? $rank : ''; ?>"><?php echo $rank; ?></td>
                            <td class="gymnast-cell"><?php echo htmlspecialchars($data['gymnast_name']); ?></td>
                            <td class="club-cell">
                                <?php echo htmlspecialchars($data['org_name'] ?? $data['team_name']); ?>
                                <br><small><?php echo htmlspecialchars($data['gymnast_category']); ?></small>
                            </td>
                            <td class="apparatus-cell"><?php echo htmlspecialchars($data['apparatus_name']); ?></td>
                            
                            <!-- Difficulty scores -->
                            <td class="score-cell <?php echo ($data['score_d1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d1'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_d2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d2'], 2); ?></td>
                            <td class="score-cell"><?php echo number_format($data['d1_d2_avg'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_d3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d3'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_d4'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d4'], 2); ?></td>
                            <td class="score-cell"><?php echo number_format($data['d3_d4_avg'], 2); ?></td>
                            <td class="score-cell"><?php echo number_format($data['total_d'], 2); ?></td>
                            
                            <!-- Execution scores -->
                            <td class="score-cell <?php echo ($data['score_a1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a1'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_a2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a2'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_a3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a3'], 2); ?></td>
                            <td class="score-cell"><?php echo number_format($data['a_deduction'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_e1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e1'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_e2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e2'], 2); ?></td>
                            <td class="score-cell <?php echo ($data['score_e3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e3'], 2); ?></td>
                            
                            <!-- Final score -->
                            <td class="final-score-cell"><?php echo number_format($data['final_score'], 2); ?></td>
                        </tr>
                        <?php 
                        $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <h3>No scores available yet</h3>
                <p>Scores will appear here as judges enter them during the competition.</p>
            </div>
            <?php endif; ?>

            <div class="refresh-info">
                📱 Page automatically refreshes every 15 seconds for live updates • Last updated: <?php echo date('H:i:s'); ?>
            </div>
        </div>
        <?php else: ?>
        <div class="leaderboard-container">
            <div class="no-data">
                <h3>Select an Event</h3>
                <p>Choose an active event above to view the detailed scoring leaderboard.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 15 seconds for live updates
        setTimeout(function() {
            window.location.reload();
        }, 15000);

        // Smooth scrolling for table on mobile
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer) {
            tableContainer.style.scrollBehavior = 'smooth';
        }

        // Highlight selected filters
        document.querySelectorAll('select').forEach(select => {
            if (select.value) {
                select.style.borderColor = '#8B5CF6';
                select.style.background = '#2D2E3F';
            }
        });
    </script>
</body>
</html>