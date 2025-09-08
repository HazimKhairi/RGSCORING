<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle athlete operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_athlete'])) {
        $gymnast_name = trim($_POST['gymnast_name']);
        $gymnast_category = trim($_POST['gymnast_category']);
        $team_id = $_POST['team_id'];
        
        if (!empty($gymnast_name) && !empty($gymnast_category) && !empty($team_id)) {
            try {
                $query = "INSERT INTO gymnasts (gymnast_name, gymnast_category, team_id) 
                          VALUES (:gymnast_name, :gymnast_category, :team_id)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':gymnast_name', $gymnast_name);
                $stmt->bindParam(':gymnast_category', $gymnast_category);
                $stmt->bindParam(':team_id', $team_id);
                $stmt->execute();
                
                $message = "Athlete added successfully!";
            } catch (PDOException $e) {
                $error = "Error adding athlete: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields.";
        }
    }
    
    if (isset($_POST['update_athlete'])) {
        $gymnast_id = $_POST['gymnast_id'];
        $gymnast_name = trim($_POST['gymnast_name']);
        $gymnast_category = trim($_POST['gymnast_category']);
        $team_id = $_POST['team_id'];
        
        try {
            $query = "UPDATE gymnasts SET gymnast_name = :gymnast_name, 
                      gymnast_category = :gymnast_category, team_id = :team_id 
                      WHERE gymnast_id = :gymnast_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':gymnast_name', $gymnast_name);
            $stmt->bindParam(':gymnast_category', $gymnast_category);
            $stmt->bindParam(':team_id', $team_id);
            $stmt->bindParam(':gymnast_id', $gymnast_id);
            $stmt->execute();
            
            $message = "Athlete updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating athlete: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_athlete'])) {
        $gymnast_id = $_POST['gymnast_id'];
        
        try {
            // Check if athlete has scores
            $check_query = "SELECT COUNT(*) FROM scores WHERE gymnast_id = :gymnast_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':gymnast_id', $gymnast_id);
            $check_stmt->execute();
            $score_count = $check_stmt->fetchColumn();
            
            if ($score_count > 0) {
                $error = "Cannot delete athlete with existing scores.";
            } else {
                $query = "DELETE FROM gymnasts WHERE gymnast_id = :gymnast_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':gymnast_id', $gymnast_id);
                $stmt->execute();
                
                $message = "Athlete deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting athlete: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_team'])) {
        $team_name = trim($_POST['team_name']);
        $organization_id = $_POST['organization_id'];
        
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
}

// Get all gymnasts with team info
$gymnasts_query = "SELECT g.*, t.team_name, o.org_name,
                          (SELECT COUNT(*) FROM scores s WHERE s.gymnast_id = g.gymnast_id) as total_scores
                   FROM gymnasts g 
                   JOIN teams t ON g.team_id = t.team_id 
                   LEFT JOIN organizations o ON t.organization_id = o.org_id
                   ORDER BY g.gymnast_name";
$gymnasts_stmt = $conn->prepare($gymnasts_query);
$gymnasts_stmt->execute();
$gymnasts = $gymnasts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teams with organization info
$teams_query = "SELECT t.*, o.org_name,
                       (SELECT COUNT(*) FROM gymnasts g WHERE g.team_id = t.team_id) as athlete_count
                FROM teams t 
                LEFT JOIN organizations o ON t.organization_id = o.org_id
                ORDER BY t.team_name";
$teams_stmt = $conn->prepare($teams_query);
$teams_stmt->execute();
$teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get organizations for team creation
$orgs_query = "SELECT * FROM organizations ORDER BY org_name";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$organizations = $orgs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get athlete for editing
$edit_athlete = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_query = "SELECT * FROM gymnasts WHERE gymnast_id = :gymnast_id";
    $edit_stmt = $conn->prepare($edit_query);
    $edit_stmt->bindParam(':gymnast_id', $edit_id);
    $edit_stmt->execute();
    $edit_athlete = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}

// Common categories for dropdown
$categories = ['Junior', 'Senior', 'Elite', 'Youth', 'Masters', 'Recreational'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Athlete Management - Gymnastics Scoring</title>
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
            background: #27ae60;
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
            background: #27ae60;
            color: white;
        }

        .tab:hover {
            background: #219a52;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
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
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #27ae60;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 2rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e8ed;
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

        .score-count {
            background: #f39c12;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
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
            border-color: #27ae60;
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
        }

        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .table th,
            .table td {
                padding: 0.5rem;
                font-size: 0.9rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .quick-add-form {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            border-left: 4px solid #27ae60;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Athlete Management</h1>
            <div>
                <a href="events.php" class="btn btn-secondary">Events</a>
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

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($gymnasts); ?></div>
                <div class="stat-label">Total Athletes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($teams); ?></div>
                <div class="stat-label">Total Teams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo array_sum(array_column($gymnasts, 'total_scores')); ?></div>
                <div class="stat-label">Total Scores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_unique(array_column($gymnasts, 'gymnast_category'))); ?></div>
                <div class="stat-label">Categories</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('athletes')">Athletes</button>
            <button class="tab" onclick="switchTab('teams')">Teams</button>
        </div>

        <!-- Athletes Tab -->
        <div id="athletes" class="tab-content active">
            <!-- Quick Add Athlete Form -->
            <div class="quick-add-form">
                <h3 style="margin-bottom: 1rem;">Quick Add Athlete</h3>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="gymnast_name">Athlete Name *</label>
                            <input type="text" id="gymnast_name" name="gymnast_name" required 
                                   value="<?php echo $edit_athlete ? htmlspecialchars($edit_athlete['gymnast_name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="gymnast_category">Category *</label>
                            <select id="gymnast_category" name="gymnast_category" required>
                                <option value="">Select category...</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category; ?>" 
                                            <?php echo ($edit_athlete && $edit_athlete['gymnast_category'] == $category) ? 'selected' : ''; ?>>
                                        <?php echo $category; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="team_id">Team *</label>
                            <select id="team_id" name="team_id" required>
                                <option value="">Select team...</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['team_id']; ?>" 
                                            <?php echo ($edit_athlete && $edit_athlete['team_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($team['team_name']); ?>
                                        <?php if ($team['org_name']): ?>
                                            (<?php echo htmlspecialchars($team['org_name']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="display: flex; align-items: end;">
                            <?php if ($edit_athlete): ?>
                                <input type="hidden" name="gymnast_id" value="<?php echo $edit_athlete['gymnast_id']; ?>">
                                <button type="submit" name="update_athlete" class="btn btn-warning">Update Athlete</button>
                                <a href="athletes.php" class="btn btn-secondary">Cancel</a>
                            <?php else: ?>
                                <button type="submit" name="create_athlete" class="btn btn-success">Add Athlete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Search -->
            <div class="search-bar">
                <input type="text" id="athleteSearch" placeholder="Search athletes by name, team, or category..." 
                       onkeyup="filterTable('athletesTable', this.value)">
            </div>

            <!-- Athletes Table -->
            <div class="table-container">
                <table class="table" id="athletesTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Team</th>
                            <th>Organization</th>
                            <th>Scores</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gymnasts as $gymnast): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($gymnast['gymnast_name']); ?></strong></td>
                            <td>
                                <span class="category-badge">
                                    <?php echo htmlspecialchars($gymnast['gymnast_category']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($gymnast['team_name']); ?></td>
                            <td><?php echo htmlspecialchars($gymnast['org_name'] ?? 'None'); ?></td>
                            <td>
                                <span class="score-count"><?php echo $gymnast['total_scores']; ?></span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="?edit=<?php echo $gymnast['gymnast_id']; ?>" class="btn btn-warning btn-small">Edit</a>
                                    <?php if ($gymnast['total_scores'] == 0): ?>
                                        <button onclick="deleteAthlete(<?php echo $gymnast['gymnast_id']; ?>)" 
                                                class="btn btn-danger btn-small">Delete</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Teams Tab -->
        <div id="teams" class="tab-content">
            <!-- Quick Add Team Form -->
            <div class="quick-add-form">
                <h3 style="margin-bottom: 1rem;">Create New Team</h3>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="team_name">Team Name *</label>
                            <input type="text" id="team_name" name="team_name" required>
                        </div>

                        <div class="form-group">
                            <label for="organization_id">Organization</label>
                            <select id="organization_id" name="organization_id">
                                <option value="">No organization</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['org_id']; ?>">
                                        <?php echo htmlspecialchars($org['org_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="submit" name="create_team" class="btn btn-success">Create Team</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Search -->
            <div class="search-bar">
                <input type="text" id="teamSearch" placeholder="Search teams by name or organization..." 
                       onkeyup="filterTable('teamsTable', this.value)">
            </div>

            <!-- Teams Table -->
            <div class="table-container">
                <table class="table" id="teamsTable">
                    <thead>
                        <tr>
                            <th>Team Name</th>
                            <th>Organization</th>
                            <th>Athletes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($team['team_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($team['org_name'] ?? 'Independent'); ?></td>
                            <td>
                                <span class="score-count"><?php echo $team['athlete_count']; ?> athletes</span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="#" class="btn btn-primary btn-small">View Athletes</a>
                                    <a href="#" class="btn btn-warning btn-small">Edit</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 15% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 400px;">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this athlete?</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="gymnast_id" id="deleteAthleteId">
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="delete_athlete" class="btn btn-danger">Delete</button>
                    <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary">Cancel</button>
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

        function filterTable(tableId, searchValue) {
            const table = document.getElementById(tableId);
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const text = row.textContent.toLowerCase();
                
                if (text.includes(searchValue.toLowerCase())) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }

        function deleteAthlete(athleteId) {
            document.getElementById('deleteAthleteId').value = athleteId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Auto-open athletes tab if editing
        <?php if ($edit_athlete): ?>
        switchTab('athletes');
        <?php endif; ?>
    </script>
</body>
</html>