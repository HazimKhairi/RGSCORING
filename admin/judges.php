<?php
require_once '../config/database.php';
require_once '../auth.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle judge operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_judge'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $organization_id = !empty($_POST['organization_id']) ? $_POST['organization_id'] : null;
        
        if (!empty($username) && !empty($email) && !empty($password) && !empty($full_name)) {
            $auth = new Auth();
            $result = $auth->register($username, $email, $password, $full_name, 'judge', $organization_id);
            
            if ($result) {
                $message = "Judge created successfully!";
            } else {
                $error = "Error creating judge. Username or email may already exist.";
            }
        } else {
            $error = "Please fill in all required fields.";
        }
    }
    
    if (isset($_POST['update_judge_status'])) {
        $judge_id = $_POST['judge_id'];
        $is_active = $_POST['is_active'];
        
        try {
            $query = "UPDATE users SET is_active = :is_active WHERE user_id = :judge_id AND role = 'judge'";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':is_active', $is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':judge_id', $judge_id);
            $stmt->execute();
            
            $message = "Judge status updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating judge status: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['remove_assignment'])) {
        $assignment_id = $_POST['assignment_id'];
        
        try {
            $query = "DELETE FROM judge_assignments WHERE assignment_id = :assignment_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':assignment_id', $assignment_id);
            $stmt->execute();
            
            $message = "Judge assignment removed successfully!";
        } catch (PDOException $e) {
            $error = "Error removing assignment: " . $e->getMessage();
        }
    }
}

// Get all judges with detailed information
$judges_query = "SELECT u.*, o.org_name,
                        COUNT(DISTINCT ja.assignment_id) as total_assignments,
                        COUNT(DISTINCT s.score_id) as total_scores,
                        COUNT(DISTINCT ja.event_id) as events_assigned,
                        MAX(s.created_at) as last_score_date
                 FROM users u
                 LEFT JOIN organizations o ON u.organization_id = o.org_id
                 LEFT JOIN judge_assignments ja ON u.user_id = ja.judge_id
                 LEFT JOIN scores s ON u.user_id = s.judge_id
                 WHERE u.role = 'judge'
                 GROUP BY u.user_id
                 ORDER BY u.full_name";

$judges_stmt = $conn->prepare($judges_query);
$judges_stmt->execute();
$judges = $judges_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get organizations for judge creation
$orgs_query = "SELECT * FROM organizations ORDER BY org_name";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$organizations = $orgs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get judge details if viewing specific judge
$view_judge = null;
$judge_details = [];
if (isset($_GET['view'])) {
    $view_id = $_GET['view'];
    
    // Get judge details
    $view_query = "SELECT u.*, o.org_name 
                   FROM users u 
                   LEFT JOIN organizations o ON u.organization_id = o.org_id 
                   WHERE u.user_id = :judge_id AND u.role = 'judge'";
    $view_stmt = $conn->prepare($view_query);
    $view_stmt->bindParam(':judge_id', $view_id);
    $view_stmt->execute();
    $view_judge = $view_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($view_judge) {
        // Get judge assignments
        $assignments_query = "SELECT ja.*, e.event_name, e.event_date, e.status as event_status,
                                     a.apparatus_name, assigner.full_name as assigned_by_name
                              FROM judge_assignments ja
                              JOIN events e ON ja.event_id = e.event_id
                              JOIN apparatus a ON ja.apparatus_id = a.apparatus_id
                              JOIN users assigner ON ja.assigned_by = assigner.user_id
                              WHERE ja.judge_id = :judge_id
                              ORDER BY e.event_date DESC, a.apparatus_name";
        $assignments_stmt = $conn->prepare($assignments_query);
        $assignments_stmt->bindParam(':judge_id', $view_id);
        $assignments_stmt->execute();
        $judge_details['assignments'] = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get judge's scoring history
        $scores_query = "SELECT s.*, g.gymnast_name, t.team_name, a.apparatus_name, 
                                e.event_name, e.event_date
                         FROM scores s
                         JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                         JOIN teams t ON g.team_id = t.team_id
                         JOIN apparatus a ON s.apparatus_id = a.apparatus_id
                         JOIN events e ON s.event_id = e.event_id
                         WHERE s.judge_id = :judge_id
                         ORDER BY s.created_at DESC
                         LIMIT 50";
        $scores_stmt = $conn->prepare($scores_query);
        $scores_stmt->bindParam(':judge_id', $view_id);
        $scores_stmt->execute();
        $judge_details['scores'] = $scores_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get performance statistics
        $stats_query = "SELECT 
                            COUNT(DISTINCT s.event_id) as events_judged,
                            COUNT(DISTINCT s.apparatus_id) as apparatus_judged,
                            COUNT(s.score_id) as total_scores,
                            AVG(s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4) as avg_d_total,
                            AVG((s.score_a1 + s.score_a2 + s.score_a3) / 3) as avg_a_score,
                            AVG((s.score_e1 + s.score_e2 + s.score_e3) / 3) as avg_e_score,
                            AVG(s.technical_deduction) as avg_deduction
                        FROM scores s
                        WHERE s.judge_id = :judge_id";
        $stats_stmt = $conn->prepare($stats_query);
        $stats_stmt->bindParam(':judge_id', $view_id);
        $stats_stmt->execute();
        $judge_details['stats'] = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Calculate system-wide judge statistics
$total_judges = count($judges);
$active_judges = count(array_filter($judges, function($j) { return $j['is_active']; }));
$judges_with_assignments = count(array_filter($judges, function($j) { return $j['total_assignments'] > 0; }));
$total_scores_given = array_sum(array_column($judges, 'total_scores'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Judges Management - Gymnastics Scoring</title>
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
            background: #f39c12;
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
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
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

        .judges-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .judge-card {
            border: 2px solid #e1e8ed;
            border-radius: 15px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            background: white;
        }

        .judge-card:hover {
            border-color: #f39c12;
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.1);
            transform: translateY(-2px);
        }

        .judge-card.inactive {
            opacity: 0.7;
            background: #f8f9fa;
        }

        .judge-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .judge-name {
            font-size: 1.3rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.3rem;
        }

        .judge-info {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-active { background: #27ae60; color: white; }
        .status-inactive { background: #e74c3c; color: white; }

        .judge-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .judge-stat {
            text-align: center;
        }

        .judge-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #f39c12;
        }

        .judge-stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .judge-actions {
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
            border-color: #f39c12;
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

        .apparatus-badge {
            background: #3498db;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .event-badge {
            background: #9b59b6;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
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
            background: #f39c12;
            color: white;
        }

        .tab:hover {
            background: #e67e22;
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
            border-color: #f39c12;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1);
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .judges-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .judge-stats {
                grid-template-columns: 1fr;
            }
            
            .judge-actions {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
        }

        .performance-chart {
            width: 100%;
            height: 200px;
            background: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Judges Management</h1>
            <div>
                <a href="assign-judges.php" class="btn btn-secondary">Assign Judges</a>
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

        <?php if ($view_judge): ?>
        <!-- Judge Detail View -->
        <div class="card">
            <div class="card-header">
                Judge Profile: <?php echo htmlspecialchars($view_judge['full_name']); ?>
                <div style="float: right;">
                    <a href="judges.php" class="btn btn-secondary btn-small">Back to List</a>
                </div>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div>
                        <strong>Full Name:</strong><br>
                        <?php echo htmlspecialchars($view_judge['full_name']); ?>
                    </div>
                    <div>
                        <strong>Username:</strong><br>
                        <?php echo htmlspecialchars($view_judge['username']); ?>
                    </div>
                    <div>
                        <strong>Email:</strong><br>
                        <?php echo htmlspecialchars($view_judge['email']); ?>
                    </div>
                    <div>
                        <strong>Organization:</strong><br>
                        <?php echo htmlspecialchars($view_judge['org_name'] ?? 'Independent'); ?>
                    </div>
                    <div>
                        <strong>Status:</strong><br>
                        <span class="status-badge status-<?php echo $view_judge['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $view_judge['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <strong>Joined:</strong><br>
                        <?php echo date('M d, Y', strtotime($view_judge['created_at'])); ?>
                    </div>
                </div>

                <!-- Performance Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $judge_details['stats']['events_judged'] ?? 0; ?></div>
                        <div class="stat-label">Events Judged</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $judge_details['stats']['apparatus_judged'] ?? 0; ?></div>
                        <div class="stat-label">Apparatus Types</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $judge_details['stats']['total_scores'] ?? 0; ?></div>
                        <div class="stat-label">Scores Given</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $judge_details['stats']['avg_deduction'] ? number_format($judge_details['stats']['avg_deduction'], 2) : '0.00'; ?></div>
                        <div class="stat-label">Avg Deduction</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Judge Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('assignments')">Assignments (<?php echo count($judge_details['assignments']); ?>)</button>
            <button class="tab" onclick="switchTab('scores')">Scoring History (<?php echo count($judge_details['scores']); ?>)</button>
            <button class="tab" onclick="switchTab('performance')">Performance</button>
        </div>

        <!-- Assignments Tab -->
        <div id="assignments" class="tab-content active">
            <h3>Judge Assignments</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Apparatus</th>
                            <th>Status</th>
                            <th>Assigned By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($judge_details['assignments'] as $assignment): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($assignment['event_name']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($assignment['event_date'])); ?></td>
                            <td>
                                <span class="apparatus-badge">
                                    <?php echo htmlspecialchars($assignment['apparatus_name']); ?>
                                </span>
                            </td>
                            <td><?php echo ucfirst($assignment['event_status']); ?></td>
                            <td><?php echo htmlspecialchars($assignment['assigned_by_name']); ?></td>
                            <td>
                                <?php if ($assignment['event_status'] != 'completed'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                    <button type="submit" name="remove_assignment" class="btn btn-danger btn-small"
                                            onclick="return confirm('Remove this assignment?')">
                                        Remove
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Scores Tab -->
        <div id="scores" class="tab-content">
            <h3>Recent Scoring History</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th>Gymnast</th>
                            <th>Team</th>
                            <th>Apparatus</th>
                            <th>D Score</th>
                            <th>A Score</th>
                            <th>E Score</th>
                            <th>Deduction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($judge_details['scores'] as $score): ?>
                        <tr>
                            <td><?php echo date('M d, H:i', strtotime($score['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($score['event_name']); ?></td>
                            <td><strong><?php echo htmlspecialchars($score['gymnast_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($score['team_name']); ?></td>
                            <td>
                                <span class="apparatus-badge">
                                    <?php echo htmlspecialchars($score['apparatus_name']); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($score['score_d1'] + $score['score_d2'] + $score['score_d3'] + $score['score_d4'], 2); ?></td>
                            <td><?php echo number_format(($score['score_a1'] + $score['score_a2'] + $score['score_a3']) / 3, 2); ?></td>
                            <td><?php echo number_format(($score['score_e1'] + $score['score_e2'] + $score['score_e3']) / 3, 2); ?></td>
                            <td><?php echo number_format($score['technical_deduction'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Performance Tab -->
        <div id="performance" class="tab-content">
            <h3>Judge Performance Analysis</h3>
            <div class="performance-chart">
                Performance charts would be displayed here<br>
                (D-Score averages, consistency metrics, comparison with other judges)
            </div>
            
            <div class="form-grid">
                <div>
                    <strong>Average D Score Total:</strong><br>
                    <?php echo $judge_details['stats']['avg_d_total'] ? number_format($judge_details['stats']['avg_d_total'], 2) : 'N/A'; ?>
                </div>
                <div>
                    <strong>Average A Score:</strong><br>
                    <?php echo $judge_details['stats']['avg_a_score'] ? number_format($judge_details['stats']['avg_a_score'], 2) : 'N/A'; ?>
                </div>
                <div>
                    <strong>Average E Score:</strong><br>
                    <?php echo $judge_details['stats']['avg_e_score'] ? number_format($judge_details['stats']['avg_e_score'], 2) : 'N/A'; ?>
                </div>
                <div>
                    <strong>Average Technical Deduction:</strong><br>
                    <?php echo $judge_details['stats']['avg_deduction'] ? number_format($judge_details['stats']['avg_deduction'], 2) : 'N/A'; ?>
                </div>
            </div>
        </div>

        <?php else: ?>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_judges; ?></div>
                <div class="stat-label">Total Judges</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_judges; ?></div>
                <div class="stat-label">Active Judges</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $judges_with_assignments; ?></div>
                <div class="stat-label">Assigned Judges</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_scores_given; ?></div>
                <div class="stat-label">Scores Given</div>
            </div>
        </div>

        <div class="page-header">
            <h2>Judges Management</h2>
            <button onclick="openModal('createModal')" class="btn btn-warning">Create Judge</button>
        </div>

        <!-- Search -->
        <div class="search-bar">
            <input type="text" id="judgeSearch" placeholder="Search judges by name, organization, or email..." 
                   onkeyup="filterJudges(this.value)">
        </div>

        <!-- Judges Grid -->
        <div class="judges-grid" id="judgesGrid">
            <?php foreach ($judges as $judge): ?>
            <div class="judge-card <?php echo $judge['is_active'] ? '' : 'inactive'; ?>" data-judge="<?php echo strtolower($judge['full_name'] . ' ' . $judge['email'] . ' ' . ($judge['org_name'] ?? '')); ?>">
                <div class="judge-header">
                    <div>
                        <div class="judge-name"><?php echo htmlspecialchars($judge['full_name']); ?></div>
                        <div class="judge-info">📧 <?php echo htmlspecialchars($judge['email']); ?></div>
                        <div class="judge-info">👤 <?php echo htmlspecialchars($judge['username']); ?></div>
                        <?php if ($judge['org_name']): ?>
                            <div class="judge-info">🏢 <?php echo htmlspecialchars($judge['org_name']); ?></div>
                        <?php endif; ?>
                        <?php if ($judge['last_score_date']): ?>
                            <div class="judge-info">⏰ Last active: <?php echo date('M d, Y', strtotime($judge['last_score_date'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="status-badge status-<?php echo $judge['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $judge['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>

                <div class="judge-stats">
                    <div class="judge-stat">
                        <div class="judge-stat-number"><?php echo $judge['total_assignments']; ?></div>
                        <div class="judge-stat-label">Assignments</div>
                    </div>
                    <div class="judge-stat">
                        <div class="judge-stat-number"><?php echo $judge['events_assigned']; ?></div>
                        <div class="judge-stat-label">Events</div>
                    </div>
                    <div class="judge-stat">
                        <div class="judge-stat-number"><?php echo $judge['total_scores']; ?></div>
                        <div class="judge-stat-label">Scores</div>
                    </div>
                </div>

                <div class="judge-actions">
                    <a href="?view=<?php echo $judge['user_id']; ?>" class="btn btn-primary btn-small">View Profile</a>
                    <a href="assign-judges.php?judge_id=<?php echo $judge['user_id']; ?>" class="btn btn-success btn-small">Assign</a>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="judge_id" value="<?php echo $judge['user_id']; ?>">
                        <input type="hidden" name="is_active" value="<?php echo $judge['is_active'] ? '0' : '1'; ?>">
                        <button type="submit" name="update_judge_status" 
                                class="btn btn-small <?php echo $judge['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                            <?php echo $judge['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>

    <!-- Create Judge Modal -->
    <div id="createModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 5% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 600px;">
            <h3 style="margin-bottom: 1rem;">Create New Judge</h3>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="organization_id">Organization</label>
                        <select id="organization_id" name="organization_id">
                            <option value="">Independent Judge</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>">
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="create_judge" class="btn btn-warning">Create Judge</button>
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
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

        function filterJudges(searchValue) {
            const judgeCards = document.querySelectorAll('.judge-card');
            const searchTerm = searchValue.toLowerCase();
            
            judgeCards.forEach(card => {
                const judgeData = card.getAttribute('data-judge');
                if (judgeData.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
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