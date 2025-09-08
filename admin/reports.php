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
    <title>Reports & Analytics - Gymnastics Scoring System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #F8FAFC;
            color: #334155;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #E2E8F0;
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            margin-bottom: 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
        }

        .logo-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1E293B;
        }

        .nav-menu {
            padding: 0 1rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            margin-bottom: 0.25rem;
            border-radius: 10px;
            color: #64748B;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .nav-item:hover {
            background: #F1F5F9;
            color: #334155;
        }

        .nav-item.active {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
        }

        .nav-item.active .nav-icon {
            color: white;
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: #64748B;
        }

        .nav-text {
            font-size: 0.9rem;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 2rem;
            left: 1rem;
            right: 1rem;
        }

        .sign-out-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 10px;
            color: #EF4444;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #FEE2E2;
            background: #FEF2F2;
        }

        .sign-out-btn:hover {
            background: #FEE2E2;
            border-color: #EF4444;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            border-bottom: 1px solid #E2E8F0;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748B;
            font-size: 0.875rem;
        }

        .breadcrumb-separator {
            color: #CBD5E1;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .export-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .content-area {
            padding: 2rem;
        }

        /* Event Selection */
        .event-selection-section {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .section-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 1.125rem;
        }

        .section-content {
            padding: 2rem;
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            background: #F8FAFC;
            border: 2px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .event-card:hover {
            border-color: #8B5CF6;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.15);
        }

        .event-card.selected {
            border-color: #8B5CF6;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
        }

        .event-card h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .event-meta {
            font-size: 0.875rem;
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        .event-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 0.5rem;
        }

        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-upcoming {
            background: #FEF3C7;
            color: #92400E;
        }

        .status-completed {
            background: #E5E7EB;
            color: #374151;
        }

        .event-card.selected .status-active,
        .event-card.selected .status-upcoming,
        .event-card.selected .status-completed {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid #E2E8F0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.gymnasts { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.teams { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .stat-icon.judges { background: linear-gradient(135deg, #F59E0B, #D97706); }
        .stat-icon.scores { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }
        .stat-icon.categories { background: linear-gradient(135deg, #EF4444, #DC2626); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Report Sections */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 2rem;
        }

        .report-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }

        .report-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            background: #F8FAFC;
        }

        .report-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 1.125rem;
        }

        .report-content {
            padding: 2rem;
        }

        .table-container {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th {
            background: #F8FAFC;
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #E2E8F0;
            font-size: 0.875rem;
            position: sticky;
            top: 0;
        }

        .report-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #F1F5F9;
            font-size: 0.875rem;
        }

        .report-table tr:hover {
            background: #F8FAFC;
        }

        .rank-badge {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            color: white;
        }

        .rank-1 { background: #F59E0B; }
        .rank-2 { background: #6B7280; }
        .rank-3 { background: #CD7C2F; }
        .rank-other { background: #8B5CF6; }

        .category-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            background: #8B5CF6;
            color: white;
        }

        .score-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.875rem;
            font-weight: 600;
            background: #10B981;
            color: white;
        }

        .no-reports {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748B;
        }

        .no-reports h3 {
            color: #1E293B;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .reports-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .content-area {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .events-grid {
                grid-template-columns: 1fr;
            }
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748B;
            cursor: pointer;
        }

        @media (max-width: 1024px) {
            .mobile-menu-btn {
                display: block;
            }
        }

        .event-info-banner {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            text-align: center;
        }

        .event-info-banner h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .event-info-banner p {
            opacity: 0.9;
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">🤸</div>
                    <div class="logo-text">GymnasticsScore</div>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="../dashboard.php" class="nav-item">
                    <div class="nav-icon">📊</div>
                    <div class="nav-text">Dashboard</div>
                </a>

                <a href="events.php" class="nav-item">
                    <div class="nav-icon">🏆</div>
                    <div class="nav-text">Events Module</div>
                </a>

                <a href="judges.php" class="nav-item">
                    <div class="nav-icon">👨‍⚖️</div>
                    <div class="nav-text">Judges Module</div>
                </a>

                <a href="athletes.php" class="nav-item">
                    <div class="nav-icon">🤸‍♂️</div>
                    <div class="nav-text">Athletes Module</div>
                </a>

                <a href="teams.php" class="nav-item">
                    <div class="nav-icon">👥</div>
                    <div class="nav-text">Teams Module</div>
                </a>

                <a href="organizations.php" class="nav-item">
                    <div class="nav-icon">🏢</div>
                    <div class="nav-text">Organizations</div>
                </a>

                <a href="reports.php" class="nav-item active">
                    <div class="nav-icon">📈</div>
                    <div class="nav-text">Reports Module</div>
                </a>

                <a href="../leaderboard.php" class="nav-item">
                    <div class="nav-icon">🏅</div>
                    <div class="nav-text">Live Scores</div>
                </a>

                <?php if ($_SESSION['role'] == 'super_admin'): ?>
                <a href="system-management.php" class="nav-item">
                    <div class="nav-icon">⚙️</div>
                    <div class="nav-text">Administration</div>
                </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="sign-out-btn">
                    <div class="nav-icon">🚪</div>
                    <div class="nav-text">Sign Out</div>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>
                    <h1>Reports & Analytics</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Reports</span>
                    </div>
                </div>
                <div class="header-right">
                    <?php if ($selected_event): ?>
                    <button class="export-btn" onclick="window.print()">
                        🖨️ Print Report
                    </button>
                    <button class="export-btn" onclick="exportToCSV()">
                        📊 Export CSV
                    </button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="content-area">
                <?php if (!$event_id): ?>
                <!-- Event Selection -->
                <div class="event-selection-section">
                    <div class="section-header">
                        <h2 class="section-title">Select Event for Report Generation</h2>
                    </div>
                    <div class="section-content">
                        <div class="events-grid">
                            <?php foreach ($events as $event): ?>
                                <a href="?event_id=<?php echo $event['event_id']; ?>" class="event-card">
                                    <h3><?php echo htmlspecialchars($event['event_name']); ?></h3>
                                    <div class="event-meta">📅 <?php echo date('M d, Y', strtotime($event['event_date'])); ?></div>
                                    <div class="event-meta">📍 <?php echo htmlspecialchars($event['location'] ?? 'Location TBA'); ?></div>
                                    <span class="event-status status-<?php echo $event['status']; ?>">
                                        <?php echo ucfirst($event['status']); ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>

                <!-- Event Info Banner -->
                <div class="event-info-banner">
                    <h2><?php echo htmlspecialchars($selected_event['event_name']); ?></h2>
                    <p>
                        📅 <?php echo date('M d, Y', strtotime($selected_event['event_date'])); ?> • 
                        📍 <?php echo htmlspecialchars($selected_event['location'] ?? 'Location TBA'); ?> • 
                        Status: <?php echo ucfirst($selected_event['status']); ?>
                    </p>
                </div>

                <!-- Event Summary Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon gymnasts">🤸‍♂️</div>
                        </div>
                        <div class="stat-number"><?php echo $report_data['summary']['total_gymnasts']; ?></div>
                        <div class="stat-label">Total Gymnasts</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon teams">👥</div>
                        </div>
                        <div class="stat-number"><?php echo $report_data['summary']['total_teams']; ?></div>
                        <div class="stat-label">Participating Teams</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon judges">👨‍⚖️</div>
                        </div>
                        <div class="stat-number"><?php echo $report_data['summary']['total_judges']; ?></div>
                        <div class="stat-label">Active Judges</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon scores">📝</div>
                        </div>
                        <div class="stat-number"><?php echo $report_data['summary']['total_scores']; ?></div>
                        <div class="stat-label">Scores Recorded</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon categories">🏷️</div>
                        </div>
                        <div class="stat-number"><?php echo $report_data['summary']['total_categories']; ?></div>
                        <div class="stat-label">Categories</div>
                    </div>
                </div>

                <!-- Reports Grid -->
                <div class="reports-grid">
                    <!-- Top Performers -->
                    <div class="report-card">
                        <div class="report-header">
                            <h3 class="report-title">Top Performers</h3>
                        </div>
                        <div class="report-content">
                            <div class="table-container">
                                <table class="report-table">
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
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="rank-badge <?php echo $rank <= 3 ? "rank-{$rank}" : "rank-other"; ?>">
                                                    <?php echo $rank; ?>
                                                </span>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($performer['name']); ?></strong></td>
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

                    <!-- Team Performance -->
                    <div class="report-card">
                        <div class="report-header">
                            <h3 class="report-title">Team Performance</h3>
                        </div>
                        <div class="report-content">
                            <div class="table-container">
                                <table class="report-table">
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
                                                <div style="width: 100%; height: 20px; background: #E2E8F0; border-radius: 10px; overflow: hidden;">
                                                    <div style="width: <?php echo min(100, ($team['total_scores'] / max(1, $report_data['summary']['total_scores'])) * 100 * 10); ?>%; height: 100%; background: #8B5CF6; border-radius: 10px;"></div>
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
                    <div class="report-card">
                        <div class="report-header">
                            <h3 class="report-title">Judge Performance</h3>
                        </div>
                        <div class="report-content">
                            <div class="table-container">
                                <table class="report-table">
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
                    <div class="report-card">
                        <div class="report-header">
                            <h3 class="report-title">Apparatus Statistics</h3>
                        </div>
                        <div class="report-content">
                            <div class="table-container">
                                <table class="report-table">
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
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

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

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !menuBtn.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        // Auto-refresh every 60 seconds for live events
        <?php if ($selected_event && $selected_event['status'] == 'active'): ?>
        setTimeout(() => {
            window.location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>