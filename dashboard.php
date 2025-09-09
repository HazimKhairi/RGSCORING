<?php
require_once 'config/database.php';

startSecureSession();
requireLogin();

$database = new Database();
$conn = $database->getConnection();

// Get dashboard statistics
$stats = [];
$setup_status = [];
try {
    // Total events by status
    $events_query = "SELECT status, COUNT(*) as count FROM events GROUP BY status";
    $events_stmt = $conn->prepare($events_query);
    $events_stmt->execute();
    $events_by_status = $events_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Total scores
    $scores_query = "SELECT COUNT(*) as total_scores FROM scores";
    $scores_stmt = $conn->prepare($scores_query);
    $scores_stmt->execute();
    $total_scores = $scores_stmt->fetchColumn();
    
    // Total athletes
    $athletes_query = "SELECT COUNT(*) as total_athletes FROM gymnasts";
    $athletes_stmt = $conn->prepare($athletes_query);
    $athletes_stmt->execute();
    $total_athletes = $athletes_stmt->fetchColumn();
    
    // Total organizations
    $orgs_query = "SELECT COUNT(*) as total_orgs FROM organizations";
    $orgs_stmt = $conn->prepare($orgs_query);
    $orgs_stmt->execute();
    $total_orgs = $orgs_stmt->fetchColumn();

    // Setup status for admin users
    if (hasRole('admin')) {
        $setup_status['organizations'] = $total_orgs;
        $setup_status['teams'] = $conn->query("SELECT COUNT(*) FROM teams")->fetchColumn();
        $setup_status['athletes'] = $total_athletes;
        $setup_status['judges'] = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'judge'")->fetchColumn();
        $setup_status['events'] = array_sum($events_by_status);
        $setup_status['assignments'] = $conn->query("SELECT COUNT(*) FROM judge_assignments")->fetchColumn();
    }
    
    // Recent activity
    $activity_query = "SELECT 'Score Entry' as type, s.created_at, u.full_name as user_name, 
                              CONCAT(g.gymnast_name, ' - ', a.apparatus_name) as details
                       FROM scores s
                       JOIN users u ON s.judge_id = u.user_id
                       JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                       JOIN apparatus a ON s.apparatus_id = a.apparatus_id
                       ORDER BY s.created_at DESC
                       LIMIT 5";
    $activity_stmt = $conn->prepare($activity_query);
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Database error handling
    $events_by_status = [];
    $total_scores = 0;
    $total_athletes = 0;
    $total_orgs = 0;
    $recent_activity = [];
}

// Calculate setup completion for admins
$setup_completion = 0;
if (hasRole('admin') && !empty($setup_status)) {
    $completed_steps = 0;
    if ($setup_status['organizations'] > 0) $completed_steps++;
    if ($setup_status['teams'] > 0) $completed_steps++;
    if ($setup_status['athletes'] > 0) $completed_steps++;
    if ($setup_status['judges'] > 0) $completed_steps++;
    if ($setup_status['events'] > 0) $completed_steps++;
    if ($setup_status['assignments'] > 0) $completed_steps++;
    $setup_completion = ($completed_steps / 6) * 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gymnastics Scoring System</title>
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

        .header-time {
            color: #64748B;
            font-size: 0.875rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: #F8FAFC;
            border-radius: 10px;
            border: 1px solid #E2E8F0;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-details {
            text-align: left;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: #1E293B;
        }

        .user-role {
            font-size: 0.75rem;
            color: #64748B;
            text-transform: capitalize;
        }

        .content-area {
            padding: 2rem;
        }

        /* Setup Guide Banner for Admins */
        .setup-guide-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .setup-guide-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="1" fill="white" opacity="0.1"/><circle cx="10" cy="50" r="1" fill="white" opacity="0.1"/><circle cx="90" cy="30" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
        }

        .setup-guide-content {
            position: relative;
            z-index: 1;
        }

        .setup-guide-banner h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .setup-guide-banner p {
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }

        .setup-progress-bar {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            height: 8px;
            margin: 1rem 0;
            overflow: hidden;
        }

        .setup-progress-fill {
            background: white;
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s ease;
        }

        .setup-guide-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .setup-guide-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .module-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid #E2E8F0;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            position: relative;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            border-color: #8B5CF6;
        }

        .module-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .module-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
        }

        .module-meta {
            text-align: right;
        }

        .module-status {
            font-size: 0.75rem;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .module-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: #8B5CF6;
        }

        .module-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.5rem;
        }

        .module-description {
            color: #64748B;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .module-hint {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: #F59E0B;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .module-hint.ready {
            background: #10B981;
        }

        .module-hint.completed {
            background: #6B7280;
        }

        /* Module specific colors */
        .events-module .module-icon {
            background: linear-gradient(135deg, #3B82F6, #1D4ED8);
        }

        .judges-module .module-icon {
            background: linear-gradient(135deg, #F59E0B, #D97706);
        }

        .athletes-module .module-icon {
            background: linear-gradient(135deg, #10B981, #059669);
        }

        .reports-module .module-icon {
            background: linear-gradient(135deg, #8B5CF6, #7C3AED);
        }

        .scoring-module .module-icon {
            background: linear-gradient(135deg, #EF4444, #DC2626);
        }

        .orgs-module .module-icon {
            background: linear-gradient(135deg, #6366F1, #4F46E5);
        }

        /* Dashboard sections */
        .dashboard-sections {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .section-card {
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

        .section-content {
            padding: 1.5rem 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: #F8FAFC;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .activity-list {
            space-y: 1rem;
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
        }

        .activity-time {
            color: #94A3B8;
            font-size: 0.75rem;
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
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 1rem;
            }

            .content-area {
                padding: 1rem;
            }

            .modules-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .user-info {
                display: none;
            }

            .header-time {
                display: none;
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

        .setup-incomplete-notice {
            background: #FEF3C7;
            border: 1px solid #F59E0B;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .setup-incomplete-notice .icon {
            font-size: 1.2rem;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            background: #8B5CF6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .quick-action-btn:hover {
            background: #7C3AED;
            transform: translateY(-1px);
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
                <a href="dashboard.php" class="nav-item active">
                    <div class="nav-icon">📊</div>
                    <div class="nav-text">Dashboard</div>
                </a>

                <?php if (hasRole('admin')): ?>
                <a href="admin/competition-setup-guide.php" class="nav-item">
                    <div class="nav-icon">🚀</div>
                    <div class="nav-text">Setup Guide</div>
                </a>

                <a href="admin/events.php" class="nav-item">
                    <div class="nav-icon">🏆</div>
                    <div class="nav-text">Events Module</div>
                </a>

                <a href="admin/judges.php" class="nav-item">
                    <div class="nav-icon">👨‍⚖️</div>
                    <div class="nav-text">Judges Module</div>
                </a>

                <a href="admin/athletes.php" class="nav-item">
                    <div class="nav-icon">🤸‍♂️</div>
                    <div class="nav-text">Athletes Module</div>
                </a>

                <a href="admin/organizations.php" class="nav-item">
                    <div class="nav-icon">🏢</div>
                    <div class="nav-text">Organizations</div>
                </a>

                <a href="admin/reports.php" class="nav-item">
                    <div class="nav-icon">📈</div>
                    <div class="nav-text">Reports Module</div>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['role'] == 'judge'): ?>
                <a href="judge/scoring.php" class="nav-item">
                    <div class="nav-icon">📝</div>
                    <div class="nav-text">Scoring Module</div>
                </a>
                <?php endif; ?>

                <a href="leaderboard.php" class="nav-item">
                    <div class="nav-icon">🏅</div>
                    <div class="nav-text">Live Scores</div>
                </a>

                <?php if ($_SESSION['role'] == 'super_admin'): ?>
                <a href="admin/system-management.php" class="nav-item">
                    <div class="nav-icon">⚙️</div>
                    <div class="nav-text">Administration</div>
                </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="sign-out-btn">
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
                    <h1>Overview</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Dashboard</span>
                    </div>
                </div>
                <div class="header-right">
                    <div class="header-time">
                        📅 <?php echo date('l, d F Y, H:i'); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?></div>
                        <div class="user-details">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo str_replace('_', ' ', $_SESSION['role']); ?></div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content-area">
                <?php if (hasRole('admin')): ?>
                    <?php if ($setup_completion < 100): ?>
                    <!-- Setup Guide Banner -->
                    <div class="setup-guide-banner">
                        <div class="setup-guide-content">
                            <h2>🚀 Competition Setup Assistant</h2>
                            <p>Get your gymnastics competition up and running with our step-by-step setup guide</p>
                            <div class="setup-progress-bar">
                                <div class="setup-progress-fill" style="width: <?php echo $setup_completion; ?>%"></div>
                            </div>
                            <p style="font-size: 0.9rem; margin-bottom: 1rem;">Setup Progress: <?php echo round($setup_completion); ?>% Complete</p>
                            <a href="admin/competition-setup-guide.php" class="setup-guide-btn">
                                📋 Open Setup Guide
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($setup_completion < 50): ?>
                    <!-- Quick Start Notice -->
                    <div class="setup-incomplete-notice">
                        <div class="icon">⚠️</div>
                        <div>
                            <strong>Getting Started:</strong> Complete the basic setup to start running competitions. 
                            <div class="quick-actions">
                                <?php if ($setup_status['organizations'] == 0): ?>
                                    <a href="admin/organizations.php" class="quick-action-btn">🏢 Create Organizations</a>
                                <?php endif; ?>
                                <?php if ($setup_status['teams'] == 0): ?>
                                    <a href="admin/teams.php" class="quick-action-btn">👥 Create Teams</a>
                                <?php endif; ?>
                                <?php if ($setup_status['athletes'] == 0): ?>
                                    <a href="admin/athletes.php" class="quick-action-btn">🤸‍♂️ Add Athletes</a>
                                <?php endif; ?>
                                <?php if ($setup_status['judges'] == 0): ?>
                                    <a href="admin/judges.php" class="quick-action-btn">👨‍⚖️ Register Judges</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Module Cards -->
                <div class="modules-grid">
                    <?php if (hasRole('admin')): ?>
                    <a href="admin/events.php" class="module-card events-module">
                        <div class="module-header">
                            <div class="module-icon">🏆</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                                <div class="module-count"><?php echo $setup_status['events'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="module-title">Events</div>
                        <div class="module-description">Manage gymnastics competitions, tournaments and scoring events</div>
                        <?php if (isset($setup_status['events'])): ?>
                            <?php if ($setup_status['events'] == 0 && $setup_status['athletes'] > 0 && $setup_status['judges'] > 0): ?>
                                <div class="module-hint ready">Ready</div>
                            <?php elseif ($setup_status['events'] > 0): ?>
                                <div class="module-hint completed">Active</div>
                            <?php elseif ($setup_status['athletes'] == 0 || $setup_status['judges'] == 0): ?>
                                <div class="module-hint">Need Setup</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a>

                    <a href="admin/judges.php" class="module-card judges-module">
                        <div class="module-header">
                            <div class="module-icon">👨‍⚖️</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                                <div class="module-count"><?php echo $setup_status['judges'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="module-title">Judges</div>
                        <div class="module-description">Register judges and assign them to events and apparatus</div>
                        <?php if (isset($setup_status['judges'])): ?>
                            <?php if ($setup_status['judges'] == 0): ?>
                                <div class="module-hint">Step 4</div>
                            <?php else: ?>
                                <div class="module-hint completed">Ready</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a>

                    <a href="admin/athletes.php" class="module-card athletes-module">
                        <div class="module-header">
                            <div class="module-icon">🤸‍♂️</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                                <div class="module-count"><?php echo $setup_status['athletes'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="module-title">Athletes</div>
                        <div class="module-description">Manage gymnasts, teams and competition registrations</div>
                        <?php if (isset($setup_status['athletes'])): ?>
                            <?php if ($setup_status['athletes'] == 0 && $setup_status['teams'] > 0): ?>
                                <div class="module-hint ready">Step 3</div>
                            <?php elseif ($setup_status['athletes'] > 0): ?>
                                <div class="module-hint completed">Ready</div>
                            <?php elseif ($setup_status['teams'] == 0): ?>
                                <div class="module-hint">Need Teams</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a>
<!-- 
                    <a href="admin/teams.php" class="module-card orgs-module">
                        <div class="module-header">
                            <div class="module-icon">👥</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                                <div class="module-count"><?php echo $setup_status['teams'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="module-title">Teams</div>
                        <div class="module-description">Organize athletes into teams for competitions</div>
                        <?php if (isset($setup_status['teams'])): ?>
                            <?php if ($setup_status['teams'] == 0): ?>
                                <div class="module-hint ready">Step 2</div>
                            <?php else: ?>
                                <div class="module-hint completed">Ready</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a> -->

                    <a href="admin/organizations.php" class="module-card reports-module">
                        <div class="module-header">
                            <div class="module-icon">🏢</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                                <div class="module-count"><?php echo $setup_status['organizations'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="module-title">Organizations</div>
                        <div class="module-description">Group teams under clubs, schools, or academies</div>
                        <?php if (isset($setup_status['organizations'])): ?>
                            <?php if ($setup_status['organizations'] == 0): ?>
                                <div class="module-hint ready">Step 1</div>
                            <?php else: ?>
                                <div class="module-hint completed">Ready</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </a>

                    <a href="admin/reports.php" class="module-card reports-module">
                        <div class="module-header">
                            <div class="module-icon">📈</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                            </div>
                        </div>
                        <div class="module-title">Reports</div>
                        <div class="module-description">Generate analytics and detailed competition reports</div>
                    </a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] == 'judge'): ?>
                    <a href="judge/scoring.php" class="module-card scoring-module">
                        <div class="module-header">
                            <div class="module-icon">📝</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                            </div>
                        </div>
                        <div class="module-title">Scoring</div>
                        <div class="module-description">Enter scores for assigned gymnasts and apparatus</div>
                    </a>
                    <?php endif; ?>

                    <a href="leaderboard.php" class="module-card orgs-module">
                        <div class="module-header">
                            <div class="module-icon">🏅</div>
                            <div class="module-meta">
                                <div class="module-status"></div>
                            </div>
                        </div>
                        <div class="module-title">Live Scores</div>
                        <div class="module-description">View real-time competition results and rankings</div>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
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

        // Animate progress bar
        document.addEventListener('DOMContentLoaded', function() {
            const progressBar = document.querySelector('.setup-progress-fill');
            if (progressBar) {
                const targetWidth = progressBar.style.width;
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = targetWidth;
                }, 500);
            }
        });

        // Auto-refresh statistics every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>