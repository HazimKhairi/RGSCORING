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

// Calculate statistics
$total_athletes = count($gymnasts);
$total_teams = count($teams);
$total_scores = array_sum(array_column($gymnasts, 'total_scores'));
$total_categories = count(array_unique(array_column($gymnasts, 'gymnast_category')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Athletes Management - Gymnastics Scoring System</title>
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

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .header-btn {
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

        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .header-btn.secondary {
            background: #64748B;
        }

        .header-btn.secondary:hover {
            box-shadow: 0 8px 25px rgba(100, 116, 139, 0.4);
        }

        .content-area {
            padding: 2rem;
        }

        /* Statistics Cards */
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

        .stat-icon.athletes { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.teams { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .stat-icon.scores { background: linear-gradient(135deg, #F59E0B, #D97706); }
        .stat-icon.categories { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }

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

        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }

        .tabs-header {
            display: flex;
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
        }

        .tab-button {
            flex: 1;
            padding: 1rem 2rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            color: #64748B;
            transition: all 0.2s ease;
            position: relative;
        }

        .tab-button.active {
            color: #8B5CF6;
            background: white;
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
        }

        .tab-content {
            display: none;
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
        }

        /* Search Bar */
        .search-section {
            margin-bottom: 2rem;
        }

        .search-bar {
            position: relative;
            max-width: 400px;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid #E2E8F0;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748B;
        }

        /* Quick Add Form */
        .quick-add-section {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-add-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .quick-add-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .form-input {
            padding: 0.75rem;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        /* Athletes/Teams Grid */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .item-card {
            background: white;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: #8B5CF6;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .item-info h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .item-meta {
            color: #64748B;
            font-size: 0.875rem;
            margin-bottom: 0.125rem;
        }

        .category-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #8B5CF6;
            color: white;
        }

        .score-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #10B981;
            color: white;
        }

        .item-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: #3B82F6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563EB;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: #F59E0B;
            color: white;
        }

        .btn-warning:hover {
            background: #D97706;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #EF4444;
            color: white;
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-1px);
        }

        .btn-success {
            background: #10B981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .alert-error {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        /* Modal */
        .modal {
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .no-items {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748B;
        }

        .no-items h3 {
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

            .items-grid {
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .tabs-header {
                flex-direction: column;
            }

            .header-actions {
                flex-direction: column;
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
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
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

                <a href="athletes.php" class="nav-item active">
                    <div class="nav-icon">🤸‍♂️</div>
                    <div class="nav-text">Athletes Module</div>
                </a>

                <a href="organizations.php" class="nav-item">
                    <div class="nav-icon">🏢</div>
                    <div class="nav-text">Organizations</div>
                </a>

                <a href="reports.php" class="nav-item">
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
                    <h1>Athletes Management</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Athletes</span>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="header-btn" onclick="openModal('athleteModal')">
                        New Athlete
                    </button>
                    <button class="header-btn secondary" onclick="openModal('teamModal')">
                        New Team
                    </button>
                </div>
            </header>

            <div class="content-area">
                <?php if ($message): ?>
                    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon athletes">🤸‍♂️</div>
                        </div>
                        <div class="stat-number"><?php echo $total_athletes; ?></div>
                        <div class="stat-label">Total Athletes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon teams">👥</div>
                        </div>
                        <div class="stat-number"><?php echo $total_teams; ?></div>
                        <div class="stat-label">Total Teams</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon scores">📝</div>
                        </div>
                        <div class="stat-number"><?php echo $total_scores; ?></div>
                        <div class="stat-label">Total Scores</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon categories">🏷️</div>
                        </div>
                        <div class="stat-number"><?php echo $total_categories; ?></div>
                        <div class="stat-label">Categories</div>
                    </div>
                </div>

                <!-- Tabs Container -->
                <div class="tabs-container">
                    <div class="tabs-header">
                        <button class="tab-button active" onclick="switchTab('athletes')">
                            Athletes (<?php echo count($gymnasts); ?>)
                        </button>
                        <button class="tab-button" onclick="switchTab('teams')">
                            Teams (<?php echo count($teams); ?>)
                        </button>
                    </div>

                    <!-- Athletes Tab -->
                    <div id="athletes" class="tab-content active">
                        <?php if ($edit_athlete): ?>
                        <div class="quick-add-section">
                            <div class="quick-add-header">
                                <h3 class="quick-add-title">Edit Athlete</h3>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="gymnast_id" value="<?php echo $edit_athlete['gymnast_id']; ?>">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Athlete Name *</label>
                                        <input type="text" name="gymnast_name" class="form-input" required 
                                               value="<?php echo htmlspecialchars($edit_athlete['gymnast_name']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Category *</label>
                                        <select name="gymnast_category" class="form-input" required>
                                            <option value="">Select category...</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category; ?>" 
                                                        <?php echo ($edit_athlete['gymnast_category'] == $category) ? 'selected' : ''; ?>>
                                                    <?php echo $category; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Team *</label>
                                        <select name="team_id" class="form-input" required>
                                            <option value="">Select team...</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                        <?php echo ($edit_athlete['team_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                    <?php if ($team['org_name']): ?>
                                                        (<?php echo htmlspecialchars($team['org_name']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-actions">
                                    <button type="submit" name="update_athlete" class="btn btn-primary">Update Athlete</button>
                                    <a href="athletes.php" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="search-section">
                            <div class="search-bar">
                                <div class="search-icon">🔍</div>
                                <input type="text" class="search-input" placeholder="Search athletes..." id="athleteSearch" onkeyup="filterItems('athletesGrid', this.value)">
                            </div>
                        </div>

                        <?php if (!empty($gymnasts)): ?>
                        <div class="items-grid" id="athletesGrid">
                            <?php foreach ($gymnasts as $gymnast): ?>
                            <div class="item-card" data-search="<?php echo strtolower($gymnast['gymnast_name'] . ' ' . $gymnast['team_name'] . ' ' . $gymnast['gymnast_category']); ?>">
                                <div class="item-header">
                                    <div class="item-info">
                                        <h3><?php echo htmlspecialchars($gymnast['gymnast_name']); ?></h3>
                                        <div class="item-meta">👥 <?php echo htmlspecialchars($gymnast['team_name']); ?></div>
                                        <?php if ($gymnast['org_name']): ?>
                                            <div class="item-meta">🏢 <?php echo htmlspecialchars($gymnast['org_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="category-badge"><?php echo htmlspecialchars($gymnast['gymnast_category']); ?></span>
                                    </div>
                                </div>

                                <div style="margin: 1rem 0;">
                                    <span class="score-badge"><?php echo $gymnast['total_scores']; ?> scores</span>
                                </div>

                                <div class="item-actions">
                                    <a href="?edit=<?php echo $gymnast['gymnast_id']; ?>" class="btn btn-warning"> Edit</a>
                                    <?php if ($gymnast['total_scores'] == 0): ?>
                                        <button onclick="deleteAthlete(<?php echo $gymnast['gymnast_id']; ?>)" class="btn btn-danger">Delete</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-items">
                            <h3>No athletes found</h3>
                            <p>Start by adding your first athlete to the system.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Teams Tab -->
                    <div id="teams" class="tab-content">
                        <div class="search-section">
                            <div class="search-bar">
                                <div class="search-icon">🔍</div>
                                <input type="text" class="search-input" placeholder="Search teams..." id="teamSearch" onkeyup="filterItems('teamsGrid', this.value)">
                            </div>
                        </div>

                        <?php if (!empty($teams)): ?>
                        <div class="items-grid" id="teamsGrid">
                            <?php foreach ($teams as $team): ?>
                            <div class="item-card" data-search="<?php echo strtolower($team['team_name'] . ' ' . ($team['org_name'] ?? '')); ?>">
                                <div class="item-header">
                                    <div class="item-info">
                                        <h3><?php echo htmlspecialchars($team['team_name']); ?></h3>
                                        <div class="item-meta">🏢 <?php echo htmlspecialchars($team['org_name'] ?? 'Independent'); ?></div>
                                    </div>
                                </div>

                                <div style="margin: 1rem 0;">
                                    <span class="score-badge"><?php echo $team['athlete_count']; ?> athletes</span>
                                </div>

                                <div class="item-actions">
                                    <a href="#" class="btn btn-primary"> View Athletes</a>
                                    <a href="#" class="btn btn-warning"> Edit</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-items">
                            <h3>No teams found</h3>
                            <p>Create your first team to organize athletes.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create Athlete Modal -->
    <div id="athleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Athlete</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Athlete Name *</label>
                        <input type="text" name="gymnast_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="gymnast_category" class="form-input" required>
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Team *</label>
                        <select name="team_id" class="form-input" required>
                            <option value="">Select team...</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?php echo $team['team_id']; ?>">
                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                    <?php if ($team['org_name']): ?>
                                        (<?php echo htmlspecialchars($team['org_name']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('athleteModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_athlete" class="btn btn-success">Create Athlete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Team Modal -->
    <div id="teamModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Team</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Team Name *</label>
                        <input type="text" name="team_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Organization</label>
                        <select name="organization_id" class="form-input">
                            <option value="">Independent Team</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>">
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('teamModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_team" class="btn btn-success">Create Team</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
            </div>
            <p>Are you sure you want to delete this athlete? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="gymnast_id" id="deleteAthleteId">
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="delete_athlete" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function switchTab(tabName) {
            // Remove active class from all tab buttons and contents
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab button and corresponding content
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function filterItems(gridId, searchValue) {
            const grid = document.getElementById(gridId);
            const items = grid.querySelectorAll('.item-card');
            const searchTerm = searchValue.toLowerCase();
            
            items.forEach(item => {
                const searchData = item.getAttribute('data-search');
                if (searchData.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function deleteAthlete(athleteId) {
            document.getElementById('deleteAthleteId').value = athleteId;
            openModal('deleteModal');
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

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>