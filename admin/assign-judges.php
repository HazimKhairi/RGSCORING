<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

$event_id = $_GET['event_id'] ?? null;
$selected_event = null;

if ($event_id) {
    // Get event details
    $event_query = "SELECT * FROM events WHERE event_id = :event_id";
    $event_stmt = $conn->prepare($event_query);
    $event_stmt->bindParam(':event_id', $event_id);
    $event_stmt->execute();
    $selected_event = $event_stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle judge assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['assign_judge'])) {
        $judge_id = $_POST['judge_id'];
        $apparatus_id = $_POST['apparatus_id'];
        $event_id = $_POST['event_id'];
        
        try {
            // Check if assignment already exists
            $check_query = "SELECT assignment_id FROM judge_assignments 
                           WHERE judge_id = :judge_id AND event_id = :event_id AND apparatus_id = :apparatus_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':judge_id', $judge_id);
            $check_stmt->bindParam(':event_id', $event_id);
            $check_stmt->bindParam(':apparatus_id', $apparatus_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                $assign_query = "INSERT INTO judge_assignments (judge_id, event_id, apparatus_id, assigned_by) 
                                VALUES (:judge_id, :event_id, :apparatus_id, :assigned_by)";
                $assign_stmt = $conn->prepare($assign_query);
                $assign_stmt->bindParam(':judge_id', $judge_id);
                $assign_stmt->bindParam(':event_id', $event_id);
                $assign_stmt->bindParam(':apparatus_id', $apparatus_id);
                $assign_stmt->bindParam(':assigned_by', $_SESSION['user_id']);
                $assign_stmt->execute();
                
                $message = "Judge assigned successfully!";
            } else {
                $error = "Judge is already assigned to this apparatus for this event.";
            }
        } catch (PDOException $e) {
            $error = "Error assigning judge: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['remove_assignment'])) {
        $assignment_id = $_POST['assignment_id'];
        
        try {
            $remove_query = "DELETE FROM judge_assignments WHERE assignment_id = :assignment_id";
            $remove_stmt = $conn->prepare($remove_query);
            $remove_stmt->bindParam(':assignment_id', $assignment_id);
            $remove_stmt->execute();
            
            $message = "Judge assignment removed successfully!";
        } catch (PDOException $e) {
            $error = "Error removing assignment: " . $e->getMessage();
        }
    }
}

// Get all events if no specific event selected
$events = [];
if (!$event_id) {
    $events_query = "SELECT * FROM events WHERE status IN ('upcoming', 'active') ORDER BY event_date DESC";
    $events_stmt = $conn->prepare($events_query);
    $events_stmt->execute();
    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get available judges
$judges = [];
if ($event_id) {
    $judges_query = "SELECT u.user_id, u.full_name, u.email, o.org_name 
                     FROM users u 
                     LEFT JOIN organizations o ON u.organization_id = o.org_id 
                     WHERE u.role = 'judge' AND u.is_active = 1 
                     ORDER BY u.full_name";
    $judges_stmt = $conn->prepare($judges_query);
    $judges_stmt->execute();
    $judges = $judges_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get apparatus
$apparatus = [];
if ($event_id) {
    $apparatus_query = "SELECT * FROM apparatus ORDER BY apparatus_name";
    $apparatus_stmt = $conn->prepare($apparatus_query);
    $apparatus_stmt->execute();
    $apparatus = $apparatus_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get current assignments for the event
$assignments = [];
if ($event_id) {
    $assignments_query = "SELECT ja.assignment_id, ja.judge_id, ja.apparatus_id, 
                                 u.full_name as judge_name, u.email as judge_email,
                                 a.apparatus_name, o.org_name,
                                 assigner.full_name as assigned_by_name
                          FROM judge_assignments ja
                          JOIN users u ON ja.judge_id = u.user_id
                          JOIN apparatus a ON ja.apparatus_id = a.apparatus_id
                          LEFT JOIN organizations o ON u.organization_id = o.org_id
                          JOIN users assigner ON ja.assigned_by = assigner.user_id
                          WHERE ja.event_id = :event_id
                          ORDER BY a.apparatus_name, u.full_name";
    $assignments_stmt = $conn->prepare($assignments_query);
    $assignments_stmt->bindParam(':event_id', $event_id);
    $assignments_stmt->execute();
    $assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Judge Assignment - Gymnastics Scoring System</title>
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

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
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

        /* Event Selection Grid */
        .event-selection-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .card-body {
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

        /* Event Info Banner */
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

        /* Assignment Form */
        .assignment-form-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: end;
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
            padding: 0.875rem 1rem;
            border: 2px solid #E2E8F0;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.2s ease;
            background: #F9FAFB;
        }

        .form-input:focus {
            outline: none;
            border-color: #8B5CF6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .btn-danger {
            background: #EF4444;
            color: white;
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Assignments Display */
        .assignments-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }

        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
        }

        .assignment-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .assignment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: #8B5CF6;
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .apparatus-badge {
            background: linear-gradient(135deg, #3B82F6, #1D4ED8);
            color: white;
            padding: 0.375rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .judge-info {
            flex: 1;
        }

        .judge-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .judge-details {
            color: #64748B;
            font-size: 0.875rem;
            margin-bottom: 0.125rem;
        }

        .no-assignments {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748B;
        }

        .no-assignments h3 {
            color: #1E293B;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .no-assignments-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive Design */
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

            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .assignments-grid {
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

            .events-grid {
                grid-template-columns: 1fr;
            }

            .card-body {
                padding: 1.5rem;
            }

            .assignment-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
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

                <a href="events.php" class="nav-item active">
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
                    <h1>Judge Assignment</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Events</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Judge Assignment</span>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="events.php" class="header-btn secondary">← Back to Events</a>
                    <a href="../dashboard.php" class="header-btn">📊 Dashboard</a>
                </div>
            </header>

            <div class="content-area">
                <?php if ($message): ?>
                    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if (!$event_id): ?>
                <!-- Event Selection -->
                <div class="event-selection-card">
                    <div class="card-header">
                        <h2 class="card-title">👨‍⚖️ Select Event for Judge Assignment</h2>
                    </div>
                    <div class="card-body">
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

                <!-- Assignment Form -->
                <div class="assignment-form-card">
                    <div class="card-header">
                        <h3 class="card-title">➕ Assign Judge to Apparatus</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Select Judge *</label>
                                    <select name="judge_id" class="form-input" required>
                                        <option value="">Choose a judge...</option>
                                        <?php foreach ($judges as $judge): ?>
                                            <option value="<?php echo $judge['user_id']; ?>">
                                                <?php echo htmlspecialchars($judge['full_name']); ?>
                                                <?php if ($judge['org_name']): ?>
                                                    (<?php echo htmlspecialchars($judge['org_name']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Select Apparatus *</label>
                                    <select name="apparatus_id" class="form-input" required>
                                        <option value="">Choose apparatus...</option>
                                        <?php foreach ($apparatus as $app): ?>
                                            <option value="<?php echo $app['apparatus_id']; ?>">
                                                <?php echo htmlspecialchars($app['apparatus_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <button type="submit" name="assign_judge" class="btn btn-primary">
                                        Assign Judge
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Current Assignments -->
                <div class="assignments-card">
                    <div class="card-header">
                        <h3 class="card-title">📋 Current Judge Assignments</h3>
                        <span style="opacity: 0.8;"><?php echo count($assignments); ?> assignments</span>
                    </div>

                    <?php if (!empty($assignments)): ?>
                        <div class="assignments-grid">
                            <?php 
                            $grouped_assignments = [];
                            foreach ($assignments as $assignment) {
                                $grouped_assignments[$assignment['apparatus_name']][] = $assignment;
                            }
                            
                            foreach ($grouped_assignments as $apparatus_name => $apparatus_assignments): 
                            ?>
                                <div class="assignment-card">
                                    <div class="assignment-header">
                                        <div class="apparatus-badge"><?php echo htmlspecialchars($apparatus_name); ?></div>
                                    </div>
                                    
                                    <?php foreach ($apparatus_assignments as $assignment): ?>
                                        <div class="judge-info" style="border-bottom: 1px solid #E2E8F0; padding-bottom: 1rem; margin-bottom: 1rem;">
                                            <div class="judge-name"><?php echo htmlspecialchars($assignment['judge_name']); ?></div>
                                            <div class="judge-details">📧 <?php echo htmlspecialchars($assignment['judge_email']); ?></div>
                                            <?php if ($assignment['org_name']): ?>
                                                <div class="judge-details">🏢 <?php echo htmlspecialchars($assignment['org_name']); ?></div>
                                            <?php endif; ?>
                                            <div class="judge-details">👤 Assigned by: <?php echo htmlspecialchars($assignment['assigned_by_name']); ?></div>
                                            
                                            <form method="POST" style="margin-top: 0.75rem;">
                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                                <button type="submit" name="remove_assignment" class="btn btn-danger btn-small" 
                                                        onclick="return confirm('Remove this judge assignment?')">
                                                    🗑️ Remove
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-assignments">
                            <div class="no-assignments-icon">👨‍⚖️</div>
                            <h3>No judges assigned yet</h3>
                            <p>Use the form above to assign judges to apparatus for this event.</p>
                        </div>
                    <?php endif; ?>
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

        // Auto-submit form when both selects have values
        document.addEventListener('DOMContentLoaded', function() {
            const judgeSelect = document.querySelector('select[name="judge_id"]');
            const apparatusSelect = document.querySelector('select[name="apparatus_id"]');
            
            if (judgeSelect && apparatusSelect) {
                function checkForm() {
                    const submitBtn = document.querySelector('button[name="assign_judge"]');
                    if (judgeSelect.value && apparatusSelect.value) {
                        submitBtn.style.background = 'linear-gradient(135deg, #10B981, #059669)';
                        submitBtn.innerHTML = '✅ Ready to Assign';
                    } else {
                        submitBtn.style.background = 'linear-gradient(135deg, #8B5CF6, #A855F7)';
                        submitBtn.innerHTML = '✅ Assign Judge';
                    }
                }
                
                judgeSelect.addEventListener('change', checkForm);
                apparatusSelect.addEventListener('change', checkForm);
            }
        });

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.assignment-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>