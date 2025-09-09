<?php
require_once '../config/database.php';
require_once '../auth.php';

startSecureSession();
requireLogin();
requireRole('super_admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle user management operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $organization_id = !empty($_POST['organization_id']) ? $_POST['organization_id'] : null;
        
        $auth = new Auth();
        $result = $auth->register($username, $email, $password, $full_name, $role, $organization_id);
        
        if ($result) {
            $message = "User created successfully!";
        } else {
            $error = "Error creating user. Username or email may already exist.";
        }
    }
    
    if (isset($_POST['update_user_status'])) {
        $user_id = $_POST['user_id'];
        $is_active = $_POST['is_active'];
        
        try {
            $query = "UPDATE users SET is_active = :is_active WHERE user_id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':is_active', $is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $message = "User status updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating user status: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_organization'])) {
        $org_name = trim($_POST['org_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        
        $auth = new Auth();
        $result = $auth->createOrganization($org_name, $contact_email, $contact_phone, $_SESSION['user_id']);
        
        if ($result) {
            $message = "Organization created successfully!";
        } else {
            $error = "Error creating organization.";
        }
    }
    
    if (isset($_POST['backup_database'])) {
        // Simple backup functionality (in real production, use more sophisticated backup)
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $command = "mysqldump --user=root --password= --host=localhost gymnastics_scoring > backups/{$backup_file}";
        
        // Create backups directory if it doesn't exist
        if (!file_exists('../backups')) {
            mkdir('../backups', 0755, true);
        }
        
        $message = "Backup initiated. File: {$backup_file}";
    }
}

// Get system statistics
$stats = [];

// Total users by role
$user_stats_query = "SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role";
$user_stats_stmt = $conn->prepare($user_stats_query);
$user_stats_stmt->execute();
$stats['users_by_role'] = $user_stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total events by status
$event_stats_query = "SELECT status, COUNT(*) as count FROM events GROUP BY status";
$event_stats_stmt = $conn->prepare($event_stats_query);
$event_stats_stmt->execute();
$stats['events_by_status'] = $event_stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total scores
$scores_query = "SELECT COUNT(*) as total_scores FROM scores";
$scores_stmt = $conn->prepare($scores_query);
$scores_stmt->execute();
$stats['total_scores'] = $scores_stmt->fetchColumn();

// Total organizations
$orgs_query = "SELECT COUNT(*) as total_orgs FROM organizations";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$stats['total_organizations'] = $orgs_stmt->fetchColumn();

// Get all users with detailed info
$users_query = "SELECT u.*, o.org_name,
                       (SELECT COUNT(*) FROM judge_assignments ja WHERE ja.judge_id = u.user_id) as assignments,
                       (SELECT COUNT(*) FROM scores s WHERE s.judge_id = u.user_id) as scores_given
                FROM users u 
                LEFT JOIN organizations o ON u.organization_id = o.org_id 
                ORDER BY u.created_at DESC";
$users_stmt = $conn->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all organizations
$auth = new Auth();
$organizations = $auth->getOrganizations();

// Get recent activity
$activity_query = "SELECT 'Score' as type, s.created_at, u.full_name as user_name, 
                          CONCAT(g.gymnast_name, ' - ', a.apparatus_name) as details
                   FROM scores s
                   JOIN users u ON s.judge_id = u.user_id
                   JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                   JOIN apparatus a ON s.apparatus_id = a.apparatus_id
                   UNION ALL
                   SELECT 'Event' as type, e.created_at, u.full_name as user_name, e.event_name as details
                   FROM events e
                   JOIN users u ON e.created_by = u.user_id
                   ORDER BY created_at DESC
                   LIMIT 10";
$activity_stmt = $conn->prepare($activity_query);
$activity_stmt->execute();
$recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Management - Gymnastics Scoring System</title>
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
            background: linear-gradient(135deg, #EF4444, #DC2626);
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
            background: linear-gradient(135deg, #EF4444, #DC2626);
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

        .super-admin-badge {
            background: linear-gradient(135deg, #EF4444, #DC2626);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .content-area {
            padding: 2rem;
        }

        /* Quick Actions */
        .quick-actions-section {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .quick-actions-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 1.5rem;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .quick-action-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .quick-action-btn.secondary {
            background: #64748B;
        }

        .quick-action-btn.secondary:hover {
            box-shadow: 0 8px 25px rgba(100, 116, 139, 0.4);
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

        .stat-icon.users { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .stat-icon.orgs { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }
        .stat-icon.events { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.scores { background: linear-gradient(135deg, #F59E0B, #D97706); }

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

        /* Dashboard Grid */
        .dashboard-sections {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            align-items: start; /* prevent cards from stretching to equal height */
        }

        .section-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            align-self: start; /* ensure each card sizes to its content */
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
            padding: 1.5rem 2rem;
        }

        /* User Roles Grid */
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .role-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: #F8FAFC;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
            line-height: 1;
        }

        .role-super_admin { background: #EF4444; color: white; }
        .role-admin { background: #3B82F6; color: white; }
        .role-judge { background: #F59E0B; color: white; }
        .role-user { background: #10B981; color: white; }

        .role-count {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1E293B;
        }

        /* Activity List */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #F1F5F9;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #F1F5F9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8B5CF6;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #1E293B;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .activity-details {
            color: #64748B;
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            color: #94A3B8;
            font-size: 0.75rem;
        }

        /* Users Management */
        .users-management-section {
            grid-column: span 2;
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            margin-top: 2rem;
        }

        .table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background: #F8FAFC;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #E2E8F0;
            font-size: 0.875rem;
            position: sticky;
            top: 0;
        }

        .users-table td {
            padding: 1rem;
            border-bottom: 1px solid #F1F5F9;
            font-size: 0.875rem;
        }

        .users-table tr:hover {
            background: #F8FAFC;
        }

        .status-active { color: #10B981; font-weight: 600; }
        .status-inactive { color: #EF4444; font-weight: 600; }

        /* Ensure activity numbers with labels stay on one line */
        .activity-item { white-space: nowrap; display: inline-flex; align-items: baseline; }

        .user-actions {
            display: flex;
            gap: 0.5rem;
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

        .btn-warning {
            background: #F59E0B;
            color: white;
        }

        .btn-success {
            background: #10B981;
            color: white;
        }

        .btn-secondary {
            background: #6B7280;
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        /* Danger Zone */
        .danger-zone {
            background: white;
            border: 2px solid #FEE2E2;
            border-radius: 16px;
            overflow: hidden;
            margin-top: 2rem;
        }

        .danger-header {
            background: #FEF2F2;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #FEE2E2;
        }

        .danger-title {
            color: #DC2626;
            font-weight: 600;
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
        }

        .danger-subtitle {
            color: #991B1B;
            font-size: 0.875rem;
        }

        .danger-content {
            padding: 2rem;
        }

        .danger-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-danger {
            background: #EF4444;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-1px);
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

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
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

            .dashboard-sections {
                grid-template-columns: 1fr;
            }

            .users-management-section {
                grid-column: span 1;
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

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }

            .roles-grid {
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
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">⚙️</div>
                    <div class="logo-text">System Admin</div>
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

                <a href="system-management.php" class="nav-item active">
                    <div class="nav-icon">⚙️</div>
                    <div class="nav-text">Administration</div>
                </a>
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
                    <h1>System Management</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Administration</span>
                    </div>
                </div>
                <div class="super-admin-badge">
                    🔥 Supreme Administrator
                </div>
            </header>

            <div class="content-area">
                <?php if ($message): ?>
                    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="quick-actions-section">
                    <h2 class="quick-actions-title">Quick Actions</h2>
                    <div class="quick-actions-grid">
                        <button class="quick-action-btn" onclick="openModal('createUserModal')">
                            👤 Create User
                        </button>
                        <button class="quick-action-btn" onclick="openModal('createOrgModal')">
                            🏢 Create Organization
                        </button>
                        <a href="events.php" class="quick-action-btn secondary">
                            🏆 Manage Events
                        </a>
                        <a href="../leaderboard.php" class="quick-action-btn secondary">
                            🏅 View Leaderboard
                        </a>
                    </div>
                </div>

                <!-- System Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon users">👥</div>
                        </div>
                        <div class="stat-number"><?php echo array_sum($stats['users_by_role']); ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon orgs">🏢</div>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_organizations']; ?></div>
                        <div class="stat-label">Organizations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon events">🏆</div>
                        </div>
                        <div class="stat-number"><?php echo array_sum($stats['events_by_status']); ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon scores">📝</div>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_scores']; ?></div>
                        <div class="stat-label">Scores Given</div>
                    </div>
                </div>

                <!-- Users Management -->
                <div class="users-management-section">
                    <div class="section-header">
                        <h2 class="section-title">All Users Management</h2>
                        <span style="color: #64748B; font-size: 0.875rem;"><?php echo count($users); ?> users total</span>
                    </div>
                    <div class="table-container">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Organization</th>
                                    <th>Status</th>
                                    <th>Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['org_name'] ?? 'None'); ?></td>
                                    <td>
                                        <span class="status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['role'] == 'judge'): ?>
                                            <small><span class="activity-item"><?php echo $user['assignments']; ?> assignments</span><br><span class="activity-item"><?php echo $user['scores_given']; ?> scores</span></small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="user-actions">
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? '0' : '1'; ?>">
                                                    <button type="submit" name="update_user_status" 
                                                            class="btn <?php echo $user['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                                        <?php echo $user['is_active'] ? '⏸️ Deactivate' : '▶️ Activate'; ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="btn btn-secondary">👑 You</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Danger Zone -->
                <div class="danger-zone">
                    <div class="danger-header">
                        <h3 class="danger-title">⚠️ Danger Zone</h3>
                        <p class="danger-subtitle">These actions can affect the entire system. Use with extreme caution.</p>
                    </div>
                    <div class="danger-content">
                        <div class="danger-actions">
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="backup_database" class="btn-danger" 
                                        onclick="return confirm('Create database backup?')">
                                    💾 Backup Database
                                </button>
                            </form>
                            <button class="btn-danger" onclick="alert('Contact system administrator for maintenance mode.')">
                                🔧 Maintenance Mode
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New User</h3>
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
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-input" required>
                            <option value="user">User</option>
                            <option value="judge">Judge</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Organization</label>
                        <select name="organization_id" class="form-input">
                            <option value="">No Organization</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>">
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('createUserModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_user" class="quick-action-btn">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Organization Modal -->
    <div id="createOrgModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Organization</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Organization Name *</label>
                        <input type="text" name="org_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="contact_email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-input">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('createOrgModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_organization" class="quick-action-btn">Create Organization</button>
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

        // Auto-refresh statistics every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>