<?php
require_once '../config/database.php';
require_once '../classes/Score.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$selected_event = null;
$report_data = [];

// Get all events for selection
$events_query = "SELECT * FROM events ORDER BY event_date DESC";
$events_stmt = $conn->prepare($events_query);
$events_stmt->execute();
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

$event_id = $_GET['event_id'] ?? null;

if ($event_id) {
    // Get event details
    $event_query = "SELECT * FROM events WHERE event_id = :event_id";
    $event_stmt = $conn->prepare($event_query);
    $event_stmt->bindParam(':event_id', $event_id);
    $event_stmt->execute();
    $selected_event = $event_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_event) {
        // Generate comprehensive report data
        
        // 1. Event Summary
        $summary_query = "SELECT 
                            COUNT(DISTINCT g.gymnast_id) as total_gymnasts,
                            COUNT(DISTINCT t.team_id) as total_teams,
                            COUNT(DISTINCT s.judge_id) as total_judges,
                            COUNT(s.score_id) as total_scores,
                            COUNT(DISTINCT g.gymnast_category) as total_categories
                          FROM scores s
                          JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                          JOIN teams t ON g.team_id = t.team_id
                          WHERE s.event_id = :event_id";
        $summary_stmt = $conn->prepare($summary_query);
        $summary_stmt->bindParam(':event_id', $event_id);
        $summary_stmt->execute();
        $report_data['summary'] = $summary_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. Top Performers by Category
        $performers_query = "SELECT g.gymnast_name, g.gymnast_category, t.team_name,
                                    s.score_d1, s.score_d2, s.score_d3, s.score_d4,
                                    s.score_a1, s.score_a2, s.score_a3,
                                    s.score_e1, s.score_e2, s.score_e3,
                                    s.technical_deduction, a.apparatus_name
                             FROM scores s
                             JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                             JOIN teams t ON g.team_id = t.team_id
                             JOIN apparatus a ON s.apparatus_id = a.apparatus_id
                             WHERE s.event_id = :event_id
                             ORDER BY g.gymnast_category, g.gymnast_name";
        $performers_stmt = $conn->prepare($performers_query);
        $performers_stmt->bindParam(':event_id', $event_id);
        $performers_stmt->execute();
        $performers_data = $performers_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate final scores and group by gymnast
        $gymnast_totals = [];
        foreach ($performers_data as $row) {
            $score = new Score(
                $row['score_d1'], $row['score_d2'], $row['score_d3'], $row['score_d4'],
                $row['score_a1'], $row['score_a2'], $row['score_a3'],
                $row['score_e1'], $row['score_e2'], $row['score_e3'],
                $row['technical_deduction'], 0
            );
            
            $gymnast_key = $row['gymnast_name'];
            if (!isset($gymnast_totals[$gymnast_key])) {
                $gymnast_totals[$gymnast_key] = [
                    'name' => $row['gymnast_name'],
                    'category' => $row['gymnast_category'],
                    'team' => $row['team_name'],
                    'total_score' => 0,
                    'apparatus_count' => 0
                ];
            }
            
            $gymnast_totals[$gymnast_key]['total_score'] += $score->getFinalScore();
            $gymnast_totals[$gymnast_key]['apparatus_count']++;
        }
        
        // Sort by total score
        uasort($gymnast_totals, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        
        $report_data['top_performers'] = array_slice($gymnast_totals, 0, 10);
        
        // 3. Team Rankings
        $team_query = "SELECT t.team_name, t.team_id,
                              COUNT(DISTINCT g.gymnast_id) as gymnast_count,
                              COUNT(s.score_id) as total_scores
                       FROM teams t
                       JOIN gymnasts g ON t.team_id = g.team_id
                       LEFT JOIN scores s ON g.gymnast_id = s.gymnast_id AND s.event_id = :event_id
                       WHERE EXISTS (SELECT 1 FROM scores s2 WHERE s2.gymnast_id = g.gymnast_id AND s2.event_id = :event_id)
                       GROUP BY t.team_id, t.team_name
                       ORDER BY total_scores DESC";
        $team_stmt = $conn->prepare($team_query);
        $team_stmt->bindParam(':event_id', $event_id);
        $team_stmt->execute();
        $report_data['team_rankings'] = $team_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 4. Judge Performance
        $judge_query = "SELECT u.full_name as judge_name,
                               COUNT(s.score_id) as scores_given,
                               COUNT(DISTINCT a.apparatus_id) as apparatus_assigned,
                               AVG(s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                                   s.score_a1 + s.score_a2 + s.score_a3 + 
                                   s.score_e1 + s.score_e2 + s.score_e3) as avg_total_component
                        FROM users u
                        JOIN scores s ON u.user_id = s.judge_id
                        JOIN judge_assignments ja ON u.user_id = ja.judge_id AND ja.event_id = s.event_id
                        JOIN apparatus a ON ja.apparatus_id = a.apparatus_id
                        WHERE s.event_id = :event_id AND u.role = 'judge'
                        GROUP BY u.user_id, u.full_name
                        ORDER BY scores_given DESC";
        $judge_stmt = $conn->prepare($judge_query);
        $judge_stmt->bindParam(':event_id', $event_id);
        $judge_stmt->execute();
        $report_data['judge_performance'] = $judge_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 5. Apparatus Statistics
        $apparatus_query = "SELECT a.apparatus_name,
                                   COUNT(s.score_id) as total_performances,
                                   AVG(s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4) as avg_d_score,
                                   AVG((s.score_a1 + s.score_a2 + s.score_a3) / 3) as avg_a_score,
                                   AVG((s.score_e1 + s.score_e2 + s.score_e3) / 3) as avg_e_score,
                                   AVG(s.technical_deduction) as avg_deduction
                            FROM apparatus a
                            JOIN scores s ON a.apparatus_id = s.apparatus_id
                            WHERE s.event_id = :event_id
                            GROUP BY a.apparatus_id, a.apparatus_name
                            ORDER BY a.apparatus_name";
        $apparatus_stmt = $conn->prepare($apparatus_query);
        $apparatus_stmt->bindParam(':event_id', $event_id);
        $apparatus_stmt->execute();
        $report_data['apparatus_stats'] = $apparatus_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Gymnastics Scoring</title>
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
            background: #34495e;
            color: white;
            padding: 1rem 0;
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            margin: 0.25rem;
        }

        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            background: #34495e;
            color: white;
            padding: 1.5rem;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .card-body {
            padding: 2rem;
        }

        .event-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .event-card {
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .event-card:hover {
            border-color: #34495e;
            background: #f8f9fa;
        }

        .event-card.selected {
            border-color: #34495e;
            background: #34495e;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 2rem;
        }

        .table-container {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.8rem;
            text-align: left;
            border-bottom: 1px solid #e1e8ed;
            font-size: 0.9rem;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            top: 0;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .rank-1 { background: #fff8dc; border-left: 4px solid #f39c12; }
        .rank-2 { background: #f0f8ff; border-left: 4px solid #95a5a6; }
        .rank-3 { background: #fff5ee; border-left: 4px solid #e67e22; }

        .score-badge {
            background: #3498db;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: bold;
        }

        .category-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            background: #27ae60;
            color: white;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e1e8ed;
            border-radius: 10px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: #3498db;
            transition: width 0.3s ease;
        }

        .export-buttons {
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .report-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table th,
            .table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            
            .export-buttons {
                flex-direction: column;
            }
        }

        .chart-container {
            width: 100%;
            height: 300px;
            margin: 1rem 0;
            background: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Reports & Analytics</h1>
            <div>
                <a href="events.php" class="btn btn-secondary">Events</a>
                <a href="../dashboard.php" class="btn btn-primary">Dashboard</a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if (!$event_id): ?>
        <!-- Event Selection -->
        <div class="card">
            <div class="card-header">Select Event for Report Generation</div>
            <div class="card-body">
                <div class="event-selector">
                    <?php foreach ($events as $event): ?>
                        <a href="?event_id=<?php echo $event['event_id']; ?>" class="event-card">
                            <h3><?php echo htmlspecialchars($event['event_name']); ?></h3>
                            <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($event['event_date'])); ?></p>
                            <p><strong>Status:</strong> <?php echo ucfirst($event['status']); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($event['location'] ?? 'TBA'); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Report Header -->
        <div class="card">
            <div class="card-header">
                Event Report: <?php echo htmlspecialchars($selected_event['event_name']); ?>
            </div>
            <div class="card-body">
                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($selected_event['event_date'])); ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($selected_event['location'] ?? 'TBA'); ?></p>
                <p><strong>Status:</strong> <?php echo ucfirst($selected_event['status']); ?></p>
                
                <div class="export-buttons">
                    <button onclick="window.print()" class="btn btn-primary">Print Report</button>
                    <button onclick="exportToCSV()" class="btn btn-success">Export CSV</button>
                </div>
            </div>
        </div>

        <!-- Event Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $report_data['summary']['total_gymnasts']; ?></div>
                <div class="stat-label">Total Gymnasts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $report_data['summary']['total_teams']; ?></div>
                <div class="stat-label">Participating Teams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $report_data['summary']['total_judges']; ?></div>
                <div class="stat-label">Active Judges</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $report_data['summary']['total_scores']; ?></div>
                <div class="stat-label">Scores Recorded</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $report_data['summary']['total_categories']; ?></div>
                <div class="stat-label">Categories</div>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="report-grid">
            <!-- Top Performers -->
            <div class="card">
                <div class="card-header">Top Performers</div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Gymnast</th>
                                    <th>Category</th>
                                    <th>Team</th>
                                    <th>Total Score</th>
                                    <th>Events</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rank = 1;
                                foreach ($report_data['top_performers'] as $performer): 
                                $rank_class = $rank <= 3 ? "rank-{$rank}" : "";
                                ?>
                                <tr class="<?php echo $rank_class; ?>">
                                    <td><strong><?php echo $rank; ?></strong></td>
                                    <td><?php echo htmlspecialchars($performer['name']); ?></td>
                                    <td>
                                        <span class="category-badge">
                                            <?php echo htmlspecialchars($performer['category']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($performer['team']); ?></td>
                                    <td>
                                        <span class="score-badge">
                                            <?php echo number_format($performer['total_score'], 2); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $performer['apparatus_count']; ?></td>
                                </tr>
                                <?php 
                                $rank++;
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Team Rankings -->
            <div class="card">
                <div class="card-header">Team Performance</div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Gymnasts</th>
                                    <th>Total Scores</th>
                                    <th>Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['team_rankings'] as $team): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($team['team_name']); ?></strong></td>
                                    <td><?php echo $team['gymnast_count']; ?></td>
                                    <td><?php echo $team['total_scores']; ?></td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, ($team['total_scores'] / max(1, $report_data['summary']['total_scores'])) * 100 * 10); ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Judge Performance -->
            <div class="card">
                <div class="card-header">Judge Performance</div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Judge</th>
                                    <th>Scores Given</th>
                                    <th>Apparatus</th>
                                    <th>Avg Components</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['judge_performance'] as $judge): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($judge['judge_name']); ?></strong></td>
                                    <td><?php echo $judge['scores_given']; ?></td>
                                    <td><?php echo $judge['apparatus_assigned']; ?></td>
                                    <td><?php echo number_format($judge['avg_total_component'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Apparatus Statistics -->
            <div class="card">
                <div class="card-header">Apparatus Statistics</div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Apparatus</th>
                                    <th>Performances</th>
                                    <th>Avg D Score</th>
                                    <th>Avg A Score</th>
                                    <th>Avg E Score</th>
                                    <th>Avg Deduction</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['apparatus_stats'] as $apparatus): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($apparatus['apparatus_name']); ?></strong></td>
                                    <td><?php echo $apparatus['total_performances']; ?></td>
                                    <td><?php echo number_format($apparatus['avg_d_score'], 2); ?></td>
                                    <td><?php echo number_format($apparatus['avg_a_score'], 2); ?></td>
                                    <td><?php echo number_format($apparatus['avg_e_score'], 2); ?></td>
                                    <td><?php echo number_format($apparatus['avg_deduction'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function exportToCSV() {
            const event_name = "<?php echo addslashes($selected_event['event_name'] ?? ''); ?>";
            const csv_data = generateCSVData();
            
            const blob = new Blob([csv_data], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', `report_${event_name.replace(/[^a-zA-Z0-9]/g, '_')}.csv`);
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function generateCSVData() {
            let csv = 'Event Report\n';
            csv += 'Event Name,"<?php echo addslashes($selected_event['event_name'] ?? ''); ?>"\n';
            csv += 'Date,"<?php echo $selected_event['event_date'] ?? ''; ?>"\n';
            csv += 'Location,"<?php echo addslashes($selected_event['location'] ?? ''); ?>"\n\n';
            
            csv += 'Summary Statistics\n';
            csv += 'Total Gymnasts,<?php echo $report_data['summary']['total_gymnasts'] ?? 0; ?>\n';
            csv += 'Total Teams,<?php echo $report_data['summary']['total_teams'] ?? 0; ?>\n';
            csv += 'Total Judges,<?php echo $report_data['summary']['total_judges'] ?? 0; ?>\n';
            csv += 'Total Scores,<?php echo $report_data['summary']['total_scores'] ?? 0; ?>\n\n';
            
            csv += 'Top Performers\n';
            csv += 'Rank,Gymnast,Category,Team,Total Score,Events\n';
            <?php 
            if (isset($report_data['top_performers'])) {
                $rank = 1;
                foreach ($report_data['top_performers'] as $performer) {
                    echo 'csv += "' . $rank . ',' . 
                         addslashes($performer['name']) . ',' . 
                         addslashes($performer['category']) . ',' . 
                         addslashes($performer['team']) . ',' . 
                         number_format($performer['total_score'], 2) . ',' . 
                         $performer['apparatus_count'] . '\\n";' . "\n";
                    $rank++;
                }
            }
            ?>
            
            return csv;
        }

        // Auto-refresh every 60 seconds for live events
        <?php if ($selected_event && $selected_event['status'] == 'active'): ?>
        setTimeout(() => {
            window.location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>