<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle team operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_team'])) {
        $team_name = trim($_POST['team_name']);
        $organization_id = !empty($_POST['organization_id']) ? $_POST['organization_id'] : null;
        
        if (!empty($team_name)) {
            try {
                $query = "INSERT INTO teams (team_name, organization_id) VALUES (:team_name, :organization_id)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':team_name', $team_name);
                $stmt->bindParam(':organization_id', $organization_id);
                $stmt->execute();
                
                $message = "Team created successfully!";
            } catch (PDOException $e) {
                $error = "Error creating team: " . $e->getMessage();
            }
        } else {
            $error = "Team name is required.";
        }
    }
    
    if (isset($_POST['update_team'])) {
        $team_id = $_POST['team_id'];
        $team_name = trim($_POST['team_name']);
        $organization_id = !empty($_POST['organization_id']) ? $_POST['organization_id'] : null;
        
        try {
            $query = "UPDATE teams SET team_name = :team_name, organization_id = :organization_id WHERE team_id = :team_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':team_name', $team_name);
            $stmt->bindParam(':organization_id', $organization_id);
            $stmt->bindParam(':team_id', $team_id);
            $stmt->execute();
            
            $message = "Team updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating team: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_team'])) {
        $team_id = $_POST['team_id'];
        
        try {
            // Check if team has gymnasts
            $check_query = "SELECT COUNT(*) FROM gymnasts WHERE team_id = :team_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':team_id', $team_id);
            $check_stmt->execute();
            $gymnast_count = $check_stmt->fetchColumn();
            
            if ($gymnast_count > 0) {
                $error = "Cannot delete team with existing athletes. Please move or remove athletes first.";
            } else {
                $query = "DELETE FROM teams WHERE team_id = :team_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':team_id', $team_id);
                $stmt->execute();
                
                $message = "Team deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting team: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['transfer_athlete'])) {
        $gymnast_id = $_POST['gymnast_id'];
        $new_team_id = $_POST['new_team_id'];
        
        try {
            $query = "UPDATE gymnasts SET team_id = :new_team_id WHERE gymnast_id = :gymnast_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':new_team_id', $new_team_id);
            $stmt->bindParam(':gymnast_id', $gymnast_id);
            $stmt->execute();
            
            $message = "Athlete transferred successfully!";
        } catch (PDOException $e) {
            $error = "Error transferring athlete: " . $e->getMessage();
        }
    }
}

// Get all teams with statistics
$teams_query = "SELECT t.*, o.org_name,
                       COUNT(DISTINCT g.gymnast_id) as total_athletes,
                       COUNT(DISTINCT g.gymnast_category) as categories_represented,
                       COUNT(DISTINCT s.event_id) as events_participated,
                       COUNT(s.score_id) as total_scores,
                       AVG(CASE WHEN s.score_id IS NOT NULL THEN 
                           (s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                            (s.score_a1 + s.score_a2 + s.score_a3)/3 + 
                            (s.score_e1 + s.score_e2 + s.score_e3)/3 - 
                            s.technical_deduction) END) as avg_team_score
                FROM teams t
                LEFT JOIN organizations o ON t.organization_id = o.org_id
                LEFT JOIN gymnasts g ON t.team_id = g.team_id
                LEFT JOIN scores s ON g.gymnast_id = s.gymnast_id
                GROUP BY t.team_id
                ORDER BY t.team_name";

$teams_stmt = $conn->prepare($teams_query);
$teams_stmt->execute();
$teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get organizations for team creation/editing
$orgs_query = "SELECT * FROM organizations ORDER BY org_name";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$organizations = $orgs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get team for editing
$edit_team = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_query = "SELECT * FROM teams WHERE team_id = :team_id";
    $edit_stmt = $conn->prepare($edit_query);
    $edit_stmt->bindParam(':team_id', $edit_id);
    $edit_stmt->execute();
    $edit_team = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get detailed team view
$view_team = null;
$team_details = [];
if (isset($_GET['view'])) {
    $view_id = $_GET['view'];
    
    // Get team details
    $view_query = "SELECT t.*, o.org_name FROM teams t 
                   LEFT JOIN organizations o ON t.organization_id = o.org_id 
                   WHERE t.team_id = :team_id";
    $view_stmt = $conn->prepare($view_query);
    $view_stmt->bindParam(':team_id', $view_id);
    $view_stmt->execute();
    $view_team = $view_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($view_team) {
        // Get team athletes with their scores
        $athletes_query = "SELECT g.*, 
                                  COUNT(s.score_id) as total_scores,
                                  AVG(CASE WHEN s.score_id IS NOT NULL THEN 
                                      (s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                                       (s.score_a1 + s.score_a2 + s.score_a3)/3 + 
                                       (s.score_e1 + s.score_e2 + s.score_e3)/3 - 
                                       s.technical_deduction) END) as avg_score,
                                  MAX(s.created_at) as last_competition
                           FROM gymnasts g
                           LEFT JOIN scores s ON g.gymnast_id = s.gymnast_id
                           WHERE g.team_id = :team_id
                           GROUP BY g.gymnast_id
                           ORDER BY g.gymnast_name";
        $athletes_stmt = $conn->prepare($athletes_query);
        $athletes_stmt->bindParam(':team_id', $view_id);
        $athletes_stmt->execute();
        $team_details['athletes'] = $athletes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get team performance by event
        $events_query = "SELECT e.event_name, e.event_date, e.status,
                                COUNT(DISTINCT g.gymnast_id) as athletes_participated,
                                COUNT(s.score_id) as total_scores,
                                AVG(CASE WHEN s.score_id IS NOT NULL THEN 
                                    (s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                                     (s.score_a1 + s.score_a2 + s.score_a3)/3 + 
                                     (s.score_e1 + s.score_e2 + s.score_e3)/3 - 
                                     s.technical_deduction) END) as avg_event_score
                         FROM events e
                         JOIN scores s ON e.event_id = s.event_id
                         JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                         WHERE g.team_id = :team_id
                         GROUP BY e.event_id
                         ORDER BY e.event_date DESC";
        $events_stmt = $conn->prepare($events_query);
        $events_stmt->bindParam(':team_id', $view_id);
        $events_stmt->execute();
        $team_details['events'] = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get category breakdown
        $categories_query = "SELECT g.gymnast_category, 
                                    COUNT(g.gymnast_id) as athlete_count,
                                    COUNT(s.score_id) as category_scores,
                                    AVG(CASE WHEN s.score_id IS NOT NULL THEN 
                                        (s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                                         (s.score_a1 + s.score_a2 + s.score_a3)/3 + 
                                         (s.score_e1 + s.score_e2 + s.score_e3)/3 - 
                                         s.technical_deduction) END) as avg_category_score
                             FROM gymnasts g
                             LEFT JOIN scores s ON g.gymnast_id = s.gymnast_id
                             WHERE g.team_id = :team_id
                             GROUP BY g.gymnast_category
                             ORDER BY g.gymnast_category";
        $categories_stmt = $conn->prepare($categories_query);
        $categories_stmt->bindParam(':team_id', $view_id);
        $categories_stmt->execute();
        $team_details['categories'] = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get other teams for transfer dropdown
        $other_teams_query = "SELECT team_id, team_name FROM teams WHERE team_id != :team_id ORDER BY team_name";
        $other_teams_stmt = $conn->prepare($other_teams_query);
        $other_teams_stmt->bindParam(':team_id', $view_id);
        $other_teams_stmt->execute();
        $team_details['other_teams'] = $other_teams_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Calculate statistics
$total_teams = count($teams);
$total_athletes = array_sum(array_column($teams, 'total_athletes'));
$teams_with_org = count(array_filter($teams, function($t) { return !empty($t['org_name']); }));
$active_teams = count(array_filter($teams, function($t) { return $t['total_scores'] > 0; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Management - Gymnastics Scoring</title>
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
            background: #16a085;
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
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-teal { background: #16a085; color: white; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #16a085 0%, #1abc9c 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .teams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .team-card {
            border: 2px solid #e1e8ed;
            border-radius: 15px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            background: white;
        }

        .team-card:hover {
            border-color: #16a085;
            box-shadow: 0 5px 15px rgba(22, 160, 133, 0.1);
            transform: translateY(-2px);
        }

        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .team-name {
            font-size: 1.3rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.3rem;
        }

        .team-org {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .team-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .team-stat {
            text-align: center;
        }

        .team-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #16a085;
        }

        .team-stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .team-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #555;
        }

        .form-group input,
        .form-group select {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #16a085;
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

        .category-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            background: #3498db;
            color: white;
        }

        .score-badge {
            background: #16a085;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: bold;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .tabs {
            display: flex;
            background: white;
            border-radius: 15px 15px 0 0;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 0;
        }

        .tab {
            flex: 1;
            padding: 1rem 2rem;
            cursor: pointer;
            background: #f8f9fa;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #666;
        }

        .tab.active {
            background: #16a085;
            color: white;
        }

        .tab:hover {
            background: #1abc9c;
            color: white;
        }

        .tab-content {
            display: none;
            background: white;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
        }

        .search-bar {
            margin-bottom: 1.5rem;
        }

        .search-bar input {
            width: 100%;
            max-width: 400px;
            padding: 1rem;
            border: 2px solid #e1e8ed;
            border-radius: 25px;
            font-size: 1rem;
            background: white;
        }

        .search-bar input:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22, 160, 133, 0.1);
        }

        .transfer-form {
            background: #fff8dc;
            border: 1px solid #ffeaa7;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .teams-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .team-stats {
                grid-template-columns: 1fr;
            }
            
            .team-actions {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
        }

        .quick-add-form {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            border-left: 4px solid #16a085;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Teams Management</h1>
            <div>
                <a href="athletes.php" class="btn btn-secondary">Athletes</a>
                <a href="../dashboard.php" class="btn btn-primary">Dashboard</a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($view_team): ?>
        <!-- Team Detail View -->
        <div class="card">
            <div class="card-header">
                Team Profile: <?php echo htmlspecialchars($view_team['team_name']); ?>
                <div style="float: right;">
                    <a href="teams.php" class="btn btn-secondary btn-small">Back to List</a>
                    <a href="?edit=<?php echo $view_team['team_id']; ?>" class="btn btn-warning btn-small">Edit Team</a>
                </div>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div>
                        <strong>Team Name:</strong><br>
                        <?php echo htmlspecialchars($view_team['team_name']); ?>
                    </div>
                    <div>
                        <strong>Organization:</strong><br>
                        <?php echo htmlspecialchars($view_team['org_name'] ?? 'Independent'); ?>
                    </div>
                    <div>
                        <strong>Total Athletes:</strong><br>
                        <?php echo count($team_details['athletes']); ?>
                    </div>
                    <div>
                        <strong>Categories:</strong><br>
                        <?php echo count($team_details['categories']); ?> different categories
                    </div>
                </div>
            </div>
        </div>

        <!-- Team Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('athletes')">Athletes (<?php echo count($team_details['athletes']); ?>)</button>
            <button class="tab" onclick="switchTab('events')">Events (<?php echo count($team_details['events']); ?>)</button>
            <button class="tab" onclick="switchTab('categories')">Categories (<?php echo count($team_details['categories']); ?>)</button>
        </div>

        <!-- Athletes Tab -->
        <div id="athletes" class="tab-content active">
            <h3>Team Athletes</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Scores</th>
                            <th>Average</th>
                            <th>Last Competition</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_details['athletes'] as $athlete): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($athlete['gymnast_name']); ?></strong></td>
                            <td>
                                <span class="category-badge">
                                    <?php echo htmlspecialchars($athlete['gymnast_category']); ?>
                                </span>
                            </td>
                            <td><?php echo $athlete['total_scores']; ?></td>
                            <td>
                                <?php if ($athlete['avg_score']): ?>
                                    <span class="score-badge"><?php echo number_format($athlete['avg_score'], 2); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">No scores</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $athlete['last_competition'] ? date('M d, Y', strtotime($athlete['last_competition'])) : 'Never'; ?>
                            </td>
                            <td>
                                <button onclick="showTransferForm(<?php echo $athlete['gymnast_id']; ?>, '<?php echo addslashes($athlete['gymnast_name']); ?>')" 
                                        class="btn btn-warning btn-small">Transfer</button>
                                        
                                <div id="transfer_<?php echo $athlete['gymnast_id']; ?>" class="transfer-form" style="display: none;">
                                    <form method="POST">
                                        <input type="hidden" name="gymnast_id" value="<?php echo $athlete['gymnast_id']; ?>">
                                        <label>Transfer to:</label>
                                        <select name="new_team_id" required>
                                            <option value="">Select team...</option>
                                            <?php foreach ($team_details['other_teams'] as $other_team): ?>
                                                <option value="<?php echo $other_team['team_id']; ?>">
                                                    <?php echo htmlspecialchars($other_team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="transfer_athlete" class="btn btn-success btn-small">Confirm Transfer</button>
                                        <button type="button" onclick="hideTransferForm(<?php echo $athlete['gymnast_id']; ?>)" class="btn btn-secondary btn-small">Cancel</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Events Tab -->
        <div id="events" class="tab-content">
            <h3>Team Event Participation</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Athletes</th>
                            <th>Scores</th>
                            <th>Team Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_details['events'] as $event): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($event['event_name']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                            <td><?php echo ucfirst($event['status']); ?></td>
                            <td><?php echo $event['athletes_participated']; ?></td>
                            <td><?php echo $event['total_scores']; ?></td>
                            <td>
                                <?php if ($event['avg_event_score']): ?>
                                    <span class="score-badge"><?php echo number_format($event['avg_event_score'], 2); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Categories Tab -->
        <div id="categories" class="tab-content">
            <h3>Team by Categories</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Athletes</th>
                            <th>Total Scores</th>
                            <th>Category Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_details['categories'] as $category): ?>
                        <tr>
                            <td>
                                <span class="category-badge">
                                    <?php echo htmlspecialchars($category['gymnast_category']); ?>
                                </span>
                            </td>
                            <td><?php echo $category['athlete_count']; ?></td>
                            <td><?php echo $category['category_scores']; ?></td>
                            <td>
                                <?php if ($category['avg_category_score']): ?>
                                    <span class="score-badge"><?php echo number_format($category['avg_category_score'], 2); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_teams; ?></div>
                <div class="stat-label">Total Teams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_athletes; ?></div>
                <div class="stat-label">Total Athletes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $teams_with_org; ?></div>
                <div class="stat-label">With Organizations</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_teams; ?></div>
                <div class="stat-label">Active Teams</div>
            </div>
        </div>

        <div class="page-header">
            <h2>Teams Management</h2>
            <button onclick="openModal('createModal')" class="btn btn-teal">Create Team</button>
        </div>

        <!-- Quick Edit Form -->
        <?php if ($edit_team): ?>
        <div class="quick-add-form">
            <h3>Edit Team</h3>
            <form method="POST">
                <input type="hidden" name="team_id" value="<?php echo $edit_team['team_id']; ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="team_name">Team Name *</label>
                        <input type="text" id="team_name" name="team_name" required 
                               value="<?php echo htmlspecialchars($edit_team['team_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="organization_id">Organization</label>
                        <select id="organization_id" name="organization_id">
                            <option value="">Independent Team</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>" 
                                        <?php echo ($edit_team['organization_id'] == $org['org_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: end; gap: 1rem;">
                        <button type="submit" name="update_team" class="btn btn-warning">Update Team</button>
                        <a href="teams.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <div class="search-bar">
            <input type="text" id="teamSearch" placeholder="Search teams by name or organization..." 
                   onkeyup="filterTeams(this.value)">
        </div>

        <!-- Teams Grid -->
        <div class="teams-grid" id="teamsGrid">
            <?php foreach ($teams as $team): ?>
            <div class="team-card" data-team="<?php echo strtolower($team['team_name'] . ' ' . ($team['org_name'] ?? '')); ?>">
                <div class="team-header">
                    <div>
                        <div class="team-name"><?php echo htmlspecialchars($team['team_name']); ?></div>
                        <div class="team-org">
                            <?php if ($team['org_name']): ?>
                                🏢 <?php echo htmlspecialchars($team['org_name']); ?>
                            <?php else: ?>
                                🏷️ Independent Team
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="team-stats">
                    <div class="team-stat">
                        <div class="team-stat-number"><?php echo $team['total_athletes']; ?></div>
                        <div class="team-stat-label">Athletes</div>
                    </div>
                    <div class="team-stat">
                        <div class="team-stat-number"><?php echo $team['total_scores']; ?></div>
                        <div class="team-stat-label">Scores</div>
                    </div>
                    <div class="team-stat">
                        <div class="team-stat-number"><?php echo $team['categories_represented']; ?></div>
                        <div class="team-stat-label">Categories</div>
                    </div>
                </div>

                <?php if ($team['avg_team_score']): ?>
                <div style="text-align: center; margin: 1rem 0;">
                    <strong>Team Average: </strong>
                    <span class="score-badge"><?php echo number_format($team['avg_team_score'], 2); ?></span>
                </div>
                <?php endif; ?>

                <div class="team-actions">
                    <a href="?view=<?php echo $team['team_id']; ?>" class="btn btn-primary btn-small">View Details</a>
                    <a href="?edit=<?php echo $team['team_id']; ?>" class="btn btn-warning btn-small">Edit</a>
                    <a href="athletes.php?team_id=<?php echo $team['team_id']; ?>" class="btn btn-success btn-small">Manage Athletes</a>
                    <?php if ($team['total_athletes'] == 0): ?>
                        <button onclick="deleteTeam(<?php echo $team['team_id']; ?>)" class="btn btn-danger btn-small">Delete</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>

    <!-- Create Team Modal -->
    <div id="createModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 10% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 500px;">
            <h3 style="margin-bottom: 1rem;">Create New Team</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="new_team_name">Team Name *</label>
                    <input type="text" id="new_team_name" name="team_name" required>
                </div>
                <div class="form-group">
                    <label for="new_organization_id">Organization</label>
                    <select id="new_organization_id" name="organization_id">
                        <option value="">Independent Team</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['org_id']; ?>">
                                <?php echo htmlspecialchars($org['org_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="create_team" class="btn btn-teal">Create Team</button>
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 15% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 400px;">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this team? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="team_id" id="deleteTeamId">
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="delete_team" class="btn btn-danger">Delete Team</button>
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function deleteTeam(teamId) {
            document.getElementById('deleteTeamId').value = teamId;
            openModal('deleteModal');
        }

        function filterTeams(searchValue) {
            const teamCards = document.querySelectorAll('.team-card');
            const searchTerm = searchValue.toLowerCase();
            
            teamCards.forEach(card => {
                const teamData = card.getAttribute('data-team');
                if (teamData.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function showTransferForm(athleteId, athleteName) {
            if (confirm(`Transfer ${athleteName} to another team?`)) {
                document.getElementById(`transfer_${athleteId}`).style.display = 'block';
            }
        }

        function hideTransferForm(athleteId) {
            document.getElementById(`transfer_${athleteId}`).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('[id$="Modal"]');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>