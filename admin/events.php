<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle event creation/update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_event'])) {
        $event_name = trim($_POST['event_name']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $status = $_POST['status'];
        
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
            // Check if event has scores
            $check_query = "SELECT COUNT(*) FROM scores WHERE event_id = :event_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':event_id', $event_id);
            $check_stmt->execute();
            $score_count = $check_stmt->fetchColumn();
            
            if ($score_count > 0) {
                $error = "Cannot delete event with existing scores. Please remove scores first.";
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
                        (SELECT COUNT(*) FROM scores s WHERE s.event_id = e.event_id) as total_scores
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
    <title>Event Management - Gymnastics Scoring</title>
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
            background: #3498db;
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
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
        .form-group select,
        .form-group textarea {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }

        .table-container {
            overflow-x: auto;
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
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-upcoming { background: #3498db; color: white; }
        .status-active { background: #27ae60; color: white; }
        .status-completed { background: #95a5a6; color: white; }

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

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
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
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .close {
            font-size: 2rem;
            cursor: pointer;
            color: #999;
        }

        .close:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Event Management</h1>
            <div>
                <a href="../dashboard.php" class="btn btn-secondary">Dashboard</a>
                <a href="../logout.php" class="btn btn-danger">Logout</a>
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

        <div class="page-header">
            <h2>Manage Events</h2>
            <button onclick="openModal('createModal')" class="btn btn-success">Create New Event</button>
        </div>

        <!-- Events Table -->
        <div class="card">
            <div class="card-header">All Events</div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Creator</th>
                                <th>Scores</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($event['event_name']); ?></strong></td>
                                <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                                <td><?php echo htmlspecialchars($event['location'] ?? 'TBA'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $event['status']; ?>">
                                        <?php echo ucfirst($event['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($event['creator_name']); ?></td>
                                <td><?php echo $event['total_scores']; ?> scores</td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $event['event_id']; ?>" class="btn btn-warning">Edit</a>
                                        <a href="assign-judges.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-primary">Judges</a>
                                        <a href="athletes.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-success">Athletes</a>
                                        <?php if ($event['total_scores'] == 0): ?>
                                        <button onclick="deleteEvent(<?php echo $event['event_id']; ?>)" class="btn btn-danger">Delete</button>
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
    </div>

    <!-- Create Event Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php echo $edit_event ? 'Edit Event' : 'Create New Event'; ?></h3>
                <span class="close" onclick="closeModal('createModal')">&times;</span>
            </div>
            <form method="POST">
                <?php if ($edit_event): ?>
                    <input type="hidden" name="event_id" value="<?php echo $edit_event['event_id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="event_name">Event Name *</label>
                    <input type="text" id="event_name" name="event_name" required 
                           value="<?php echo $edit_event ? htmlspecialchars($edit_event['event_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="event_date">Event Date *</label>
                    <input type="date" id="event_date" name="event_date" required 
                           value="<?php echo $edit_event ? $edit_event['event_date'] : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" 
                           value="<?php echo $edit_event ? htmlspecialchars($edit_event['location']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="upcoming" <?php echo ($edit_event && $edit_event['status'] == 'upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="active" <?php echo ($edit_event && $edit_event['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo ($edit_event && $edit_event['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="<?php echo $edit_event ? 'update_event' : 'create_event'; ?>" class="btn btn-success">
                        <?php echo $edit_event ? 'Update Event' : 'Create Event'; ?>
                    </button>
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <span class="close" onclick="closeModal('deleteModal')">&times;</span>
            </div>
            <p>Are you sure you want to delete this event? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="event_id" id="deleteEventId">
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="delete_event" class="btn btn-danger">Delete Event</button>
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function deleteEvent(eventId) {
            document.getElementById('deleteEventId').value = eventId;
            openModal('deleteModal');
        }

        // Auto-open edit modal if edit parameter is present
        <?php if ($edit_event): ?>
        openModal('createModal');
        <?php endif; ?>

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