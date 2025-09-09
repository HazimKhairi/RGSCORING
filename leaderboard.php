<?php
require_once 'config/database.php';
require_once 'classes/Score.php';

startSecureSession();

$database = new Database();
$conn = $database->getConnection();

// Check if user is logged in
$is_logged_in = isLoggedIn();
$is_admin = $is_logged_in && hasRole('admin');

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
        $row['total_a'] = ($row['score_a1'] + $row['score_a2'] + $row['score_a3']) / 3;
        $row['total_e'] = ($row['score_e1'] + $row['score_e2'] + $row['score_e3']) / 3;
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
            background: #0F0F1A;
            color: white;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .header {
            background: #1A1B23;
            padding: 1rem 0;
            border-bottom: 1px solid #2D2E3F;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
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
        }

        .logo-text {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #10B981;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: #2D2E3F;
            border-radius: 8px;
            border: 1px solid #3D3E4F;
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #2D2E3F;
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .back-btn:hover {
            background: #3D3E4F;
            transform: translateX(-2px);
        }

        .page-header {
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* FIXED FILTERS SECTION */
        .filters-section {
            background: #1A1B23;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            border: 1px solid #2D2E3F;
            position: sticky;
            top: 80px;
            z-index: 999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .filters-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 140px;
            flex: 1;
            max-width: 200px;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #A3A3A3;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group select {
            padding: 0.75rem;
            border: 2px solid #2D2E3F;
            border-radius: 8px;
            font-size: 0.85rem;
            background: #0F0F1A;
            color: white;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        /* IMPROVED TABLE CONTAINER */
        .leaderboard-container {
            background: #1A1B23;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #2D2E3F;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }

        .leaderboard-header {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            padding: 1.5rem;
            text-align: center;
        }

        .leaderboard-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .event-info {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* ENHANCED TABLE WITH FIXED COLUMNS */
        .table-wrapper {
            position: relative;
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
            overflow-y: hidden;
            background: #0F0F1A;
            position: relative;
            max-height: 70vh;
            scrollbar-width: thin;
            scrollbar-color: #8B5CF6 #2D2E3F;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #2D2E3F;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #8B5CF6;
            border-radius: 4px;
        }

        .detailed-table {
            width: 100%;
            border-collapse: collapse;
            position: relative;
        }

        .public-view {
            min-width: 600px;
        }

        .detailed-view {
            min-width: 1200px;
        }

        /* IMPROVED STICKY COLUMNS */
        .detailed-table th,
        .detailed-table td {
            padding: 0.75rem 0.5rem;
            text-align: center;
            border-bottom: 1px solid #2D2E3F;
            font-size: 0.8rem;
            vertical-align: middle;
            white-space: nowrap;
        }

        .detailed-table th {
            background: #1A1B23;
            font-weight: 600;
            color: #A3A3A3;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 20;
        }

        /* FIXED RANK COLUMN */
        .detailed-table th:first-child,
        .detailed-table td:first-child {
            position: sticky;
            left: 0;
            background: #1A1B23;
            z-index: 30;
            box-shadow: 2px 0 4px rgba(0,0,0,0.1);
            border-right: 2px solid #2D2E3F;
        }

        /* FIXED GYMNAST NAME COLUMN */
        .detailed-table th:nth-child(2),
        .detailed-table td:nth-child(2) {
            position: sticky;
            left: 60px;
            background: #1A1B23;
            z-index: 25;
            min-width: 120px;
            text-align: left !important;
            border-right: 1px solid #2D2E3F;
        }

        /* FIXED FINAL SCORE COLUMN */
        .detailed-table th:last-child,
        .detailed-table td:last-child {
            position: sticky;
            right: 0;
            background: #1A1B23;
            z-index: 30;
            box-shadow: -2px 0 4px rgba(0,0,0,0.1);
            border-left: 2px solid #10B981;
            min-width: 80px;
        }

        .detailed-table tbody tr:hover {
            background: #2D2E3F;
        }

        .detailed-table tbody tr:hover td:first-child,
        .detailed-table tbody tr:hover td:nth-child(2),
        .detailed-table tbody tr:hover td:last-child {
            background: #2D2E3F;
        }

        .section-header {
            background: #2D2E3F !important;
            color: #8B5CF6;
            font-weight: 700;
            font-size: 0.7rem;
        }

        .rank-cell {
            font-weight: 700;
            font-size: 1rem;
            color: #8B5CF6;
            text-align: center !important;
        }

        .gymnast-cell {
            text-align: left !important;
            font-weight: 600;
            color: white;
            font-size: 0.85rem;
        }

        .club-cell {
            text-align: left !important;
            color: #A3A3A3;
            font-size: 0.75rem;
            min-width: 100px;
        }

        .apparatus-cell {
            font-weight: 600;
            color: #10B981;
            text-align: center !important;
            min-width: 80px;
        }

        .score-cell {
            font-weight: 500;
            color: #F3F4F6;
            text-align: center !important;
            min-width: 50px;
        }

        .total-score-cell {
            font-weight: 600;
            font-size: 0.9rem;
            color: #8B5CF6;
            background: rgba(139, 92, 246, 0.1);
            text-align: center !important;
        }

        .final-score-cell {
            font-weight: 700;
            font-size: 1.1rem;
            color: #10B981;
            background: rgba(16, 185, 129, 0.1);
            text-align: center !important;
        }

        .difficulty-section {
            background: rgba(59, 130, 246, 0.05) !important;
        }

        .artistry-section {
            background: rgba(245, 158, 11, 0.05) !important;
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
            color: #A3A3A3;
        }

        .no-data h3 {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: white;
        }

        .refresh-info {
            text-align: center;
            padding: 1rem;
            color: #A3A3A3;
            background: #1A1B23;
            border-top: 1px solid #2D2E3F;
            font-size: 0.8rem;
        }

        .rank-1 { color: #F59E0B; }
        .rank-2 { color: #E5E7EB; }
        .rank-3 { color: #CD7C2F; }

        .zero-score {
            color: #6B7280;
            opacity: 0.6;
        }

        /* SCROLL INDICATORS */
        .scroll-indicator {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 30px;
            height: 60px;
            background: rgba(139, 92, 246, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            z-index: 50;
            border-radius: 4px;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .scroll-indicator.left {
            left: 180px;
        }

        .scroll-indicator.right {
            right: 80px;
        }

        .scroll-indicator.show {
            opacity: 1;
        }

        /* MOBILE RESPONSIVE */
        @media (max-width: 768px) {
            .header-content {
                padding: 0 0.75rem;
            }
            
            .logo-text {
                font-size: 1rem;
            }
            
            .live-indicator {
                padding: 0.4rem 0.75rem;
                font-size: 0.7rem;
            }
            
            .container {
                padding: 0.75rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .filters-section {
                padding: 0.75rem;
                top: 70px;
            }
            
            .filters-container {
                gap: 0.5rem;
            }
            
            .filter-group {
                min-width: 100px;
                max-width: 150px;
            }
            
            .filter-group select {
                padding: 0.6rem;
                font-size: 0.8rem;
            }
            
            .leaderboard-header {
                padding: 1rem;
            }
            
            .leaderboard-title {
                font-size: 1.2rem;
            }
            
            .event-info {
                font-size: 0.8rem;
            }
            
            .detailed-table th,
            .detailed-table td {
                padding: 0.5rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .detailed-table th:nth-child(2),
            .detailed-table td:nth-child(2) {
                left: 50px;
                min-width: 100px;
            }
            
            .gymnast-cell {
                font-size: 0.8rem;
            }
            
            .club-cell {
                font-size: 0.7rem;
                min-width: 80px;
            }
            
            .rank-cell {
                font-size: 0.9rem;
            }
            
            .final-score-cell {
                font-size: 1rem;
            }
            
            .scroll-indicator.left {
                left: 150px;
            }
            
            .scroll-indicator.right {
                right: 60px;
            }
        }

        @media (max-width: 480px) {
            .header-content {
                flex-direction: column;
                gap: 0.5rem;
                padding: 0.5rem;
            }
            
            .logo {
                gap: 0.5rem;
            }
            
            .logo-icon {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
            
            .logo-text {
                font-size: 0.9rem;
            }
            
            .filters-section {
                top: 90px;
            }
            
            .filter-group {
                min-width: 90px;
                max-width: 120px;
            }
            
            .filter-group label {
                font-size: 0.7rem;
            }
            
            .filter-group select {
                padding: 0.5rem;
                font-size: 0.75rem;
            }
            
            .detailed-table th,
            .detailed-table td {
                padding: 0.4rem 0.25rem;
                font-size: 0.7rem;
            }
            
            .detailed-table th:nth-child(2),
            .detailed-table td:nth-child(2) {
                left: 40px;
                min-width: 90px;
            }
            
            .scroll-indicator {
                width: 25px;
                height: 50px;
                font-size: 1rem;
            }
            
            .scroll-indicator.left {
                left: 130px;
            }
            
            .scroll-indicator.right {
                right: 50px;
            }
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
            <div class="header-actions">
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    LIVE
                </div>
                <?php if ($is_logged_in): ?>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?></div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 600;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div style="font-size: 0.7rem; color: #A3A3A3;"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="dashboard.php" class="back-btn">← Dashboard</a>
        <?php else: ?>
            <a href="index.php" class="back-btn">← Home</a>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-title">Live Competition Leaderboard</h1>
        </div>

        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-container">
                    <div class="filter-group">
                        <label for="event_id">Event</label>
                        <select name="event_id" id="event_id" onchange="this.form.submit()">
                            <option value="">Select Event...</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['event_id']; ?>" 
                                        <?php echo ($selected_event && $selected_event['event_id'] == $event['event_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($event['event_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($selected_event): ?>
                    <div class="filter-group">
                        <label for="category">Category</label>
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
                        <label for="apparatus">Apparatus</label>
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
                    <?php echo $selected_event['event_date']; ?>
                    <?php if ($selected_category): ?>
                        • <?php echo htmlspecialchars($selected_category); ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($leaderboard_data)): ?>
            <div class="table-wrapper">
                <div class="scroll-indicator left" id="scrollLeft">←</div>
                <div class="scroll-indicator right" id="scrollRight">→</div>
                
                <div class="table-container" id="tableContainer">
                    <table class="detailed-table <?php echo $is_logged_in ? 'detailed-view' : 'public-view'; ?>">
                        <thead>
                            <?php if ($is_logged_in): ?>
                            <!-- Detailed view for logged in users -->
                            <tr>
                                <th rowspan="2">RANK</th>
                                <th rowspan="2">GYMNAST</th>
                                <th rowspan="2">CLUB</th>
                                <th rowspan="2">APPARATUS</th>
                                <th colspan="7" class="section-header difficulty-section">DIFFICULTY</th>
                                <th colspan="4" class="section-header artistry-section">ARTISTRY</th>
                                <th colspan="4" class="section-header artistry-section">EXECUTION</th>
                                <th rowspan="2" class="section-header">FINAL SCORE</th>
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
                                <!-- Artistry columns -->
                                <th class="artistry-section">A1</th>
                                <th class="artistry-section">A2</th>
                                <th class="artistry-section">A3</th>
                                <th class="artistry-section">Total A</th>
                                <th class="artistry-section">E1</th>
                                <th class="artistry-section">E2</th>
                                <th class="artistry-section">E3</th>
                                <th class="artistry-section">Total E</th>
                            </tr>
                            <?php else: ?>
                            <!-- Simplified view for public users -->
                            <tr>
                                <th>RANK</th>
                                <th>GYMNAST</th>
                                <th>CLUB</th>
                                <th>APPARATUS</th>
                                <th class="difficulty-section">Total D</th>
                                <th class="artistry-section">Total A</th>
                                <th class="artistry-section">Total E</th>
                                <th class="section-header">FINAL SCORE</th>
                            </tr>
                            <?php endif; ?>
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
                                
                                <?php if ($is_logged_in): ?>
                                <!-- Detailed view columns -->
                                <!-- Difficulty scores -->
                                <td class="score-cell <?php echo ($data['score_d1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d1'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_d2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d2'], 2); ?></td>
                                <td class="score-cell"><?php echo number_format($data['d1_d2_avg'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_d3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d3'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_d4'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_d4'], 2); ?></td>
                                <td class="score-cell"><?php echo number_format($data['d3_d4_avg'], 2); ?></td>
                                <td class="total-score-cell"><?php echo number_format($data['total_d'], 2); ?></td>
                                
                                <!-- Artistry scores -->
                                <td class="score-cell <?php echo ($data['score_a1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a1'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_a2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a2'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_a3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_a3'], 2); ?></td>
                                <td class="total-score-cell"><?php echo number_format($data['total_a'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_e1'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e1'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_e2'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e2'], 2); ?></td>
                                <td class="score-cell <?php echo ($data['score_e3'] == 0) ? 'zero-score' : ''; ?>"><?php echo number_format($data['score_e3'], 2); ?></td>
                                <td class="total-score-cell"><?php echo number_format($data['total_e'], 2); ?></td>
                                <?php else: ?>
                                <!-- Public view columns -->
                                <td class="total-score-cell"><?php echo number_format($data['total_d'], 2); ?></td>
                                <td class="total-score-cell"><?php echo number_format($data['total_a'], 2); ?></td>
                                <td class="total-score-cell"><?php echo number_format($data['total_e'], 2); ?></td>
                                <?php endif; ?>
                                
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
            </div>
            <?php else: ?>
            <div class="no-data">
                <h3>No scores available yet</h3>
                <p>Scores will appear here as judges enter them during the competition.</p>
            </div>
            <?php endif; ?>

            <div class="refresh-info">
                📱 Page refreshes every 15 seconds • Last updated: <?php echo date('H:i:s'); ?>
            </div>
        </div>
        <?php else: ?>
        <div class="leaderboard-container">
            <div class="no-data">
                <h3>Select an Event</h3>
                <p>Choose an active event above to view the live competition leaderboard.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 15 seconds for live updates
        setTimeout(function() {
            window.location.reload();
        }, 15000);

        // Scroll indicators for mobile
        const tableContainer = document.getElementById('tableContainer');
        const scrollLeft = document.getElementById('scrollLeft');
        const scrollRight = document.getElementById('scrollRight');

        function updateScrollIndicators() {
            if (!tableContainer || !scrollLeft || !scrollRight) return;
            
            const { scrollLeft: left, scrollWidth, clientWidth } = tableContainer;
            const maxScroll = scrollWidth - clientWidth;
            
            // Show/hide left indicator
            if (left > 20) {
                scrollLeft.classList.add('show');
            } else {
                scrollLeft.classList.remove('show');
            }
            
            // Show/hide right indicator
            if (left < maxScroll - 20) {
                scrollRight.classList.add('show');
            } else {
                scrollRight.classList.remove('show');
            }
        }

        // Event listeners for scroll indicators
        if (tableContainer) {
            tableContainer.addEventListener('scroll', updateScrollIndicators);
            window.addEventListener('resize', updateScrollIndicators);
            window.addEventListener('load', function() {
                updateScrollIndicators();
                // Add smooth scrolling
                tableContainer.style.scrollBehavior = 'smooth';
            });
        }

        // Highlight selected filters
        document.querySelectorAll('select').forEach(select => {
            if (select.value) {
                select.style.borderColor = '#8B5CF6';
                select.style.background = '#2D2E3F';
            }
        });

        // Touch gesture for horizontal scrolling on mobile
        let isDown = false;
        let startX;
        let scrollLeftStart;

        if (tableContainer) {
            tableContainer.addEventListener('mousedown', (e) => {
                isDown = true;
                startX = e.pageX - tableContainer.offsetLeft;
                scrollLeftStart = tableContainer.scrollLeft;
            });

            tableContainer.addEventListener('mouseleave', () => {
                isDown = false;
            });

            tableContainer.addEventListener('mouseup', () => {
                isDown = false;
            });

            tableContainer.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2;
                tableContainer.scrollLeft = scrollLeftStart - walk;
            });

            // Touch events for mobile
            tableContainer.addEventListener('touchstart', (e) => {
                startX = e.touches[0].pageX - tableContainer.offsetLeft;
                scrollLeftStart = tableContainer.scrollLeft;
            });

            tableContainer.addEventListener('touchmove', (e) => {
                const x = e.touches[0].pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 1.5;
                tableContainer.scrollLeft = scrollLeftStart - walk;
            });
        }
    </script>
</body>
</html>