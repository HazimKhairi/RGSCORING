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
    <title>Judges Management - Gymnastics Scoring System</title>
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

        .new-judge-btn {
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
        }

        .new-judge-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
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

        .stat-icon.judges { background: linear-gradient(135deg, #F59E0B, #D97706); }
        .stat-icon.active { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.assigned { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .stat-icon.scores { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }

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

        /* Judge Cards */
        .judges-section {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
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

        .search-bar {
            position: relative;
        }

        .search-input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            font-size: 0.875rem;
            width: 300px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748B;
        }

        .judges-grid {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .judge-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .judge-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: #8B5CF6;
        }

        .judge-card.inactive {
            opacity: 0.6;
            background: #F1F5F9;
        }

        .judge-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .judge-info h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .judge-meta {
            color: #64748B;
            font-size: 0.875rem;
            margin-bottom: 0.125rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-inactive {
            background: #FEE2E2;
            color: #991B1B;
        }

        .judge-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
        }

        .judge-stat {
            text-align: center;
        }

        .judge-stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.25rem;
        }

        .judge-stat-label {
            font-size: 0.75rem;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .judge-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .btn-success {
            background: #10B981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
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

        .btn-secondary {
            background: #6B7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4B5563;
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

        .form-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
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

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
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

            .judges-grid {
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

            .search-input {
                width: 100%;
            }

            .judge-stats {
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

        .no-judges {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748B;
        }

        .no-judges h3 {
            color: #1E293B;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
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

                <a href="judges.php" class="nav-item active">
                    <div class="nav-icon">👨‍⚖️</div>
                    <div class="nav-text">Judges Module</div>
                </a>

                <a href="athletes.php" class="nav-item">
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
                    <h1>Judges Management</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Judges</span>
                    </div>
                </div>
                <div class="header-right">
                    <button class="new-judge-btn" onclick="openModal('createModal')">
                        New Judge
                    </button>
                </div>
            </header>

            <div class="content-area">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon judges">👨‍⚖️</div>
                        </div>
                        <div class="stat-number"><?php echo $total_judges; ?></div>
                        <div class="stat-label">Total Judges</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon active">✅</div>
                        </div>
                        <div class="stat-number"><?php echo $active_judges; ?></div>
                        <div class="stat-label">Active Judges</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon assigned">📋</div>
                        </div>
                        <div class="stat-number"><?php echo $judges_with_assignments; ?></div>
                        <div class="stat-label">Assigned Judges</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon scores">📝</div>
                        </div>
                        <div class="stat-number"><?php echo $total_scores_given; ?></div>
                        <div class="stat-label">Scores Given</div>
                    </div>
                </div>

                <!-- Judges Section -->
                <div class="judges-section">
                    <div class="section-header">
                        <h2 class="section-title">All Judges (<?php echo count($judges); ?>)</h2>
                        <div class="search-bar">
                            <div class="search-icon">🔍</div>
                            <input type="text" class="search-input" placeholder="Search judges..." id="judgeSearch" onkeyup="filterJudges(this.value)">
                        </div>
                    </div>

                    <?php if (!empty($judges)): ?>
                    <div class="judges-grid" id="judgesGrid">
                        <?php foreach ($judges as $judge): ?>
                        <div class="judge-card <?php echo $judge['is_active'] ? '' : 'inactive'; ?>" 
                             data-judge="<?php echo strtolower($judge['full_name'] . ' ' . $judge['email'] . ' ' . ($judge['org_name'] ?? '')); ?>">
                            <div class="judge-header">
                                <div class="judge-info">
                                    <h3><?php echo htmlspecialchars($judge['full_name']); ?></h3>
                                    <div class="judge-meta">📧 <?php echo htmlspecialchars($judge['email']); ?></div>
                                    <div class="judge-meta">👤 <?php echo htmlspecialchars($judge['username']); ?></div>
                                    <?php if ($judge['org_name']): ?>
                                        <div class="judge-meta">🏢 <?php echo htmlspecialchars($judge['org_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($judge['last_score_date']): ?>
                                        <div class="judge-meta">⏰ Last active: <?php echo date('M d, Y', strtotime($judge['last_score_date'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="status-badge status-<?php echo $judge['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $judge['is_active'] ? 'Active' : 'Inactive'; ?>
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
                                <a href="?view=<?php echo $judge['user_id']; ?>" class="btn btn-primary"> View</a>
                                <a href="assign-judges.php?judge_id=<?php echo $judge['user_id']; ?>" class="btn btn-success">Assign</a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="judge_id" value="<?php echo $judge['user_id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $judge['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" name="update_judge_status" 
                                            class="btn <?php echo $judge['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $judge['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-judges">
                        <h3>No judges found</h3>
                        <p>Start by creating your first judge account.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Create Judge Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Judge</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Organization</label>
                        <select name="organization_id" class="form-input">
                            <option value="">Independent Judge</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>">
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_judge" class="btn btn-primary">Create Judge</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
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