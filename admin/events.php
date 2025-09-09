<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle event operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_event'])) {
        $event_name = trim($_POST['event_name']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $status = $_POST['status'];
        $description = trim($_POST['description']);
        
        if (!empty($event_name) && !empty($event_date)) {
            try {
                $query = "INSERT INTO events (event_name, event_date, location, status, created_by) 
                          VALUES (:event_name, :event_date, :location, :status, :created_by)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':event_name', $event_name);
                $stmt->bindParam(':event_date', $event_date);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                $stmt->execute();
                
                $message = "Event created successfully!";
            } catch (PDOException $e) {
                $error = "Error creating event: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields.";
        }
    }
    
    if (isset($_POST['update_event'])) {
        $event_id = $_POST['event_id'];
        $event_name = trim($_POST['event_name']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $status = $_POST['status'];
        
        try {
            $query = "UPDATE events SET event_name = :event_name, event_date = :event_date, 
                      location = :location, status = :status WHERE event_id = :event_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':event_name', $event_name);
            $stmt->bindParam(':event_date', $event_date);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':event_id', $event_id);
            $stmt->execute();
            
            $message = "Event updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating event: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_event'])) {
        $event_id = $_POST['event_id'];
        
        try {
            $check_query = "SELECT COUNT(*) FROM scores WHERE event_id = :event_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':event_id', $event_id);
            $check_stmt->execute();
            $score_count = $check_stmt->fetchColumn();
            
            if ($score_count > 0) {
                $error = "Cannot delete event with existing scores.";
            } else {
                $query = "DELETE FROM events WHERE event_id = :event_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':event_id', $event_id);
                $stmt->execute();
                
                $message = "Event deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting event: " . $e->getMessage();
        }
    }
}

// Get all events
$events_query = "SELECT e.*, u.full_name as creator_name,
                        (SELECT COUNT(*) FROM scores s WHERE s.event_id = e.event_id) as total_scores,
                        (SELECT COUNT(DISTINCT g.gymnast_id) FROM scores s 
                         JOIN gymnasts g ON s.gymnast_id = g.gymnast_id 
                         WHERE s.event_id = e.event_id) as total_athletes
                 FROM events e 
                 JOIN users u ON e.created_by = u.user_id 
                 ORDER BY e.event_date DESC";
$events_stmt = $conn->prepare($events_query);
$events_stmt->execute();
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get event for editing
$edit_event = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_query = "SELECT * FROM events WHERE event_id = :event_id";
    $edit_stmt = $conn->prepare($edit_query);
    $edit_stmt->bindParam(':event_id', $edit_id);
    $edit_stmt->execute();
    $edit_event = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events Management - Gymnastics Scoring System</title>
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

        .new-event-btn {
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

        .new-event-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .content-area {
            padding: 2rem;
        }

        /* Event Form */
        .event-form-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-header {
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            color: #64748B;
            font-size: 0.9rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
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
            border: 2px solid #E5E7EB;
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

        .form-select {
            padding: 0.875rem 1rem;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            background: #F9FAFB;
            transition: all 0.2s ease;
        }

        .form-select:focus {
            outline: none;
            border-color: #8B5CF6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-textarea {
            padding: 0.875rem 1rem;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            resize: vertical;
            min-height: 100px;
            background: #F9FAFB;
            transition: all 0.2s ease;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #8B5CF6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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

        .btn-secondary {
            background: #F1F5F9;
            color: #64748B;
            border: 1px solid #E2E8F0;
        }

        .btn-secondary:hover {
            background: #E2E8F0;
            color: #334155;
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

        /* Events Table */
        .events-table-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }

        .table-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 1.125rem;
        }

        .table-container {
            overflow-x: auto;
        }

        .events-table {
            width: 100%;
            border-collapse: collapse;
        }

        .events-table th {
            background: #F8FAFC;
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #E2E8F0;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .events-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #F1F5F9;
            font-size: 0.9rem;
        }

        .events-table tr:hover {
            background: #F8FAFC;
        }

        .status-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-upcoming {
            background: #FEF3C7;
            color: #D97706;
        }

        .status-active {
            background: #D1FAE5;
            color: #059669;
        }

        .status-completed {
            background: #E5E7EB;
            color: #6B7280;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
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

            .form-grid {
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

            .event-form-card {
                padding: 1.5rem;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .events-table th,
            .events-table td {
                padding: 0.75rem 1rem;
                font-size: 0.8rem;
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
                    <h1>Events Management</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Events</span>
                    </div>
                </div>
                <div class="header-right">
                    <button class="new-event-btn" onclick="showEventForm()">
                        New Event
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

                <!-- Event Form -->
                <div class="event-form-card" id="eventForm" style="<?php echo !$edit_event ? 'display: none;' : ''; ?>">
                    <div class="form-header">
                        <h2 class="form-title"><?php echo $edit_event ? 'Edit Event' : 'Create New Event'; ?></h2>
                        <p class="form-subtitle">Fill in the event details to create or update a gymnastics competition</p>
                    </div>

                    <form method="POST" action="">
                        <?php if ($edit_event): ?>
                            <input type="hidden" name="event_id" value="<?php echo $edit_event['event_id']; ?>">
                        <?php endif; ?>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Event Name *</label>
                                <input type="text" name="event_name" class="form-input" 
                                       placeholder="e.g., Spring Championship 2025"
                                       value="<?php echo $edit_event ? htmlspecialchars($edit_event['event_name']) : ''; ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Event Date *</label>
                                <input type="date" name="event_date" class="form-input" 
                                       value="<?php echo $edit_event ? $edit_event['event_date'] : ''; ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-input" 
                                       placeholder="e.g., Main Gymnasium, Sports Complex"
                                       value="<?php echo $edit_event ? htmlspecialchars($edit_event['location']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="upcoming" <?php echo ($edit_event && $edit_event['status'] == 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                                    <option value="active" <?php echo ($edit_event && $edit_event['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="completed" <?php echo ($edit_event && $edit_event['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Event Description</label>
                            <textarea name="description" class="form-textarea" 
                                      placeholder="Optional description about the event, rules, or special notes..."><?php echo $edit_event ? htmlspecialchars($edit_event['description'] ?? '') : ''; ?></textarea>
                        </div>

                        <div class="form-actions" style="margin-top: 15px;">
                            <button type="submit" name="<?php echo $edit_event ? 'update_event' : 'create_event'; ?>" class="btn btn-primary">
                                <?php echo $edit_event ? 'Update Event' : 'Create Event'; ?>
                            </button>
                            <button type="button" onclick="hideEventForm()" class="btn btn-secondary">
                                 Cancel
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Events Table -->
                <div class="events-table-card">
                    <div class="table-header">
                        <h2 class="table-title">All Events</h2>
                        <span style="color: #64748B; font-size: 0.875rem;"><?php echo count($events); ?> events total</span>
                    </div>

                    <div class="table-container">
                        <table class="events-table">
                            <thead>
                                <tr>
                                    <th>Event Name</th>
                                    <th>Date</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Athletes</th>
                                    <th>Scores</th>
                                    <th>Creator</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                <tr>
                                    <td>
                                        <strong style="color: #1E293B;"><?php echo htmlspecialchars($event['event_name']); ?></strong>
                                    </td>
                                    <td style="color: #64748B;">
                                        <?php echo date('M d, Y', strtotime($event['event_date'])); ?>
                                    </td>
                                    <td style="color: #64748B;">
                                        <?php echo htmlspecialchars($event['location'] ?? 'TBA'); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $event['status']; ?>">
                                            <?php echo ucfirst($event['status']); ?>
                                        </span>
                                    </td>
                                    <td style="color: #8B5CF6; font-weight: 600;">
                                        <?php echo $event['total_athletes']; ?>
                                    </td>
                                    <td style="color: #10B981; font-weight: 600;">
                                        <?php echo $event['total_scores']; ?>
                                    </td>
                                    <td style="color: #64748B;">
                                        <?php echo htmlspecialchars($event['creator_name']); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $event['event_id']; ?>" class="btn btn-secondary btn-small">
                                                Edit
                                            </a>
                                            <a href="assign-judges.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-primary btn-small">
                                                Judges
                                            </a>
                                            <?php if ($event['total_scores'] == 0): ?>
                                                <button onclick="deleteEvent(<?php echo $event['event_id']; ?>)" class="btn btn-danger btn-small">
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 10% auto; padding: 2rem; border-radius: 16px; width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 1rem; color: #1E293B;">Confirm Delete</h3>
            <p style="margin-bottom: 2rem; color: #64748B;">Are you sure you want to delete this event? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="event_id" id="deleteEventId">
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" name="delete_event" class="btn btn-danger">🗑️ Delete Event</button>
                    <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary">❌ Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function showEventForm() {
            document.getElementById('eventForm').style.display = 'block';
            document.querySelector('input[name="event_name"]').focus();
        }

        function hideEventForm() {
            document.getElementById('eventForm').style.display = 'none';
            // Clear form if not editing
            <?php if (!$edit_event): ?>
            document.querySelector('form').reset();
            <?php endif; ?>
        }

        function deleteEvent(eventId) {
            document.getElementById('deleteEventId').value = eventId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
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
            const modal = document.getElementById('deleteModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Auto-show form if editing
        <?php if ($edit_event): ?>
        document.getElementById('eventForm').style.display = 'block';
        <?php endif; ?>
    </script>
</body>
</html>