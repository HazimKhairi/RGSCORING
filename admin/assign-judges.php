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
    <title>Judge Assignment - Gymnastics Scoring</title>
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

        .event-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .event-card {
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .event-card:hover {
            border-color: #f39c12;
            background: #fef9e7;
        }

        .event-card.selected {
            border-color: #f39c12;
            background: #f39c12;
            color: white;
        }

        .assignment-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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

        .form-group select {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
        }

        .form-group select:focus {
            outline: none;
            border-color: #f39c12;
        }

        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1rem;
        }

        .assignment-card {
            border: 1px solid #e1e8ed;
            border-radius: 10px;
            padding: 1.5rem;
            background: #f8f9fa;
        }

        .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .apparatus-badge {
            background: #3498db;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .judge-info {
            flex: 1;
        }

        .judge-name {
            font-size: 1.1rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.3rem;
        }

        .judge-details {
            font-size: 0.9rem;
            color: #7f8c8d;
            margin-bottom: 0.2rem;
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

        .table {
            width: 100%;
            border-collapse: collapse;
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

        .no-assignments {
            text-align: center;
            padding: 3rem;
            color: #7f8c8d;
        }

        @media (max-width: 768px) {
            .assignment-form {
                grid-template-columns: 1fr;
            }
            
            .assignments-grid {
                grid-template-columns: 1fr;
            }
            
            .assignment-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Judge Assignment</h1>
            <div>
                <a href="events.php" class="btn btn-secondary">Back to Events</a>
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

        <?php if (!$event_id): ?>
        <!-- Event Selection -->
        <div class="card">
            <div class="card-header">Select Event for Judge Assignment</div>
            <div class="card-body">
                <div class="event-selector">
                    <?php foreach ($events as $event): ?>
                        <a href="?event_id=<?php echo $event['event_id']; ?>" class="event-card">
                            <h3><?php echo htmlspecialchars($event['event_name']); ?></h3>
                            <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($event['event_date'])); ?></p>
                            <p><strong>Status:</strong> <?php echo ucfirst($event['status']); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($event['location'] ?? 'TBA'); ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        
        <!-- Event Info -->
        <div class="card">
            <div class="card-header">
                <?php echo htmlspecialchars($selected_event['event_name']); ?> - Judge Assignments
            </div>
            <div class="card-body">
                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($selected_event['event_date'])); ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($selected_event['location'] ?? 'TBA'); ?></p>
                <p><strong>Status:</strong> <?php echo ucfirst($selected_event['status']); ?></p>
            </div>
        </div>

        <!-- Assignment Form -->
        <div class="card">
            <div class="card-header">Assign Judge to Apparatus</div>
            <div class="card-body">
                <form method="POST" class="assignment-form">
                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                    
                    <div class="form-group">
                        <label for="judge_id">Select Judge:</label>
                        <select name="judge_id" id="judge_id" required>
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
                        <label for="apparatus_id">Select Apparatus:</label>
                        <select name="apparatus_id" id="apparatus_id" required>
                            <option value="">Choose apparatus...</option>
                            <?php foreach ($apparatus as $app): ?>
                                <option value="<?php echo $app['apparatus_id']; ?>">
                                    <?php echo htmlspecialchars($app['apparatus_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="assign_judge" class="btn btn-success">Assign Judge</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Current Assignments -->
        <div class="card">
            <div class="card-header">Current Judge Assignments</div>
            <div class="card-body">
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
                                    <div class="judge-info" style="border-bottom: 1px solid #e1e8ed; padding-bottom: 1rem; margin-bottom: 1rem;">
                                        <div class="judge-name"><?php echo htmlspecialchars($assignment['judge_name']); ?></div>
                                        <div class="judge-details"><?php echo htmlspecialchars($assignment['judge_email']); ?></div>
                                        <?php if ($assignment['org_name']): ?>
                                            <div class="judge-details"><?php echo htmlspecialchars($assignment['org_name']); ?></div>
                                        <?php endif; ?>
                                        <div class="judge-details">Assigned by: <?php echo htmlspecialchars($assignment['assigned_by_name']); ?></div>
                                        
                                        <form method="POST" style="margin-top: 0.5rem;">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                            <button type="submit" name="remove_assignment" class="btn btn-danger" 
                                                    onclick="return confirm('Remove this judge assignment?')">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-assignments">
                        <h3>No judges assigned yet</h3>
                        <p>Use the form above to assign judges to apparatus for this event.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Auto-submit form when both selects have values
        document.addEventListener('DOMContentLoaded', function() {
            const judgeSelect = document.getElementById('judge_id');
            const apparatusSelect = document.getElementById('apparatus_id');
            
            function checkForm() {
                if (judgeSelect.value && apparatusSelect.value) {
                    // Enable submit button or auto-submit
                    document.querySelector('button[name="assign_judge"]').disabled = false;
                }
            }
            
            judgeSelect.addEventListener('change', checkForm);
            apparatusSelect.addEventListener('change', checkForm);
        });
    </script>
</body>
</html>