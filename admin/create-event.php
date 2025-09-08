<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle event creation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_event'])) {
        $event_name = trim($_POST['event_name']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $status = $_POST['status'];
        $description = trim($_POST['description']);
        
        // Validate required fields
        if (!empty($event_name) && !empty($event_date)) {
            try {
                // Begin transaction
                $conn->beginTransaction();
                
                // Create the event
                $query = "INSERT INTO events (event_name, event_date, location, status, created_by) 
                          VALUES (:event_name, :event_date, :location, :status, :created_by)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':event_name', $event_name);
                $stmt->bindParam(':event_date', $event_date);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                $stmt->execute();
                
                $event_id = $conn->lastInsertId();
                
                // Handle team registration if selected
                if (!empty($_POST['selected_teams'])) {
                    foreach ($_POST['selected_teams'] as $team_id) {
                        // Get gymnasts from this team and register them for the event
                        $gymnasts_query = "SELECT gymnast_id FROM gymnasts WHERE team_id = :team_id";
                        $gymnasts_stmt = $conn->prepare($gymnasts_query);
                        $gymnasts_stmt->bindParam(':team_id', $team_id);
                        $gymnasts_stmt->execute();
                        $gymnasts = $gymnasts_stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Create composite entries for each gymnast and apparatus
                        if (!empty($_POST['selected_apparatus'])) {
                            foreach ($gymnasts as $gymnast_id) {
                                foreach ($_POST['selected_apparatus'] as $apparatus_id) {
                                    $composite_query = "INSERT INTO composite (gymnastID, teamID, apparatusID, eventID) 
                                                       VALUES (:gymnast_id, :team_id, :apparatus_id, :event_id)";
                                    $composite_stmt = $conn->prepare($composite_query);
                                    $composite_stmt->bindParam(':gymnast_id', $gymnast_id);
                                    $composite_stmt->bindParam(':team_id', $team_id);
                                    $composite_stmt->bindParam(':apparatus_id', $apparatus_id);
                                    $composite_stmt->bindParam(':event_id', $event_id);
                                    $composite_stmt->execute();
                                }
                            }
                        }
                    }
                }
                
                $conn->commit();
                $message = "Event created successfully! Event ID: " . $event_id;
                
                // Redirect to event management after 2 seconds
                header("refresh:2;url=events.php");
                
            } catch (PDOException $e) {
                $conn->rollback();
                $error = "Error creating event: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields (Event Name and Date).";
        }
    }
}

// Get all teams for registration options
$teams_query = "SELECT t.team_id, t.team_name, o.org_name,
                       COUNT(g.gymnast_id) as athlete_count
                FROM teams t
                LEFT JOIN organizations o ON t.organization_id = o.org_id
                LEFT JOIN gymnasts g ON t.team_id = g.team_id
                GROUP BY t.team_id
                HAVING athlete_count > 0
                ORDER BY t.team_name";
$teams_stmt = $conn->prepare($teams_query);
$teams_stmt->execute();
$teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all apparatus
$apparatus_query = "SELECT * FROM apparatus ORDER BY apparatus_name";
$apparatus_stmt = $conn->prepare($apparatus_query);
$apparatus_stmt->execute();
$apparatus_list = $apparatus_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for information
$categories_query = "SELECT DISTINCT gymnast_category FROM gymnasts ORDER BY gymnast_category";
$categories_stmt = $conn->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get judges for pre-assignment reference
$judges_query = "SELECT u.user_id, u.full_name, u.email, o.org_name 
                 FROM users u 
                 LEFT JOIN organizations o ON u.organization_id = o.org_id 
                 WHERE u.role = 'judge' AND u.is_active = 1 
                 ORDER BY u.full_name";
$judges_stmt = $conn->prepare($judges_query);
$judges_stmt->execute();
$judges = $judges_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - Gymnastics Scoring</title>
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
            background: #9b59b6;
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
            margin: 0.25rem;
        }

        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-purple { background: #9b59b6; color: white; }

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
            font-size: 1.3rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .form-wizard {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .wizard-step {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            margin: 0 0.5rem;
            border-radius: 25px;
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .wizard-step.active {
            background: #9b59b6;
            color: white;
        }

        .wizard-step.completed {
            background: #27ae60;
            color: white;
        }

        .step-number {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            font-size: 0.8rem;
        }

        .form-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .form-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
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
            border-color: #9b59b6;
            box-shadow: 0 0 0 3px rgba(155, 89, 182, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .required {
            color: #e74c3c;
        }

        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .selection-item {
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .selection-item:hover {
            border-color: #9b59b6;
            background: #f8f9fa;
        }

        .selection-item.selected {
            border-color: #9b59b6;
            background: #9b59b6;
            color: white;
        }

        .selection-item input[type="checkbox"] {
            position: absolute;
            opacity: 0;
        }

        .team-info {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }

        .selection-item.selected .team-info {
            color: rgba(255,255,255,0.8);
        }

        .apparatus-icon {
            width: 40px;
            height: 40px;
            background: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1.2rem;
            color: white;
        }

        .selection-item.selected .apparatus-icon {
            background: rgba(255,255,255,0.2);
        }

        .summary-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e1e8ed;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 600;
            color: #555;
        }

        .summary-value {
            color: #2c3e50;
        }

        .form-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #e1e8ed;
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

        .info-box {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-box h4 {
            color: #0c5460;
            margin-bottom: 0.5rem;
        }

        .info-box p {
            color: #0c5460;
            margin: 0;
        }

        .category-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .category-badge {
            background: #9b59b6;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .judge-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            padding: 0.5rem;
        }

        .judge-item {
            padding: 0.5rem;
            border-bottom: 1px solid #f1f1f1;
            font-size: 0.9rem;
        }

        .judge-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 768px) {
            .form-wizard {
                flex-wrap: wrap;
            }
            
            .wizard-step {
                margin-bottom: 0.5rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .selection-grid {
                grid-template-columns: 1fr;
            }
            
            .form-navigation {
                flex-direction: column;
                gap: 1rem;
            }
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e1e8ed;
            border-radius: 3px;
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #9b59b6;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .event-preview {
            background: white;
            border: 2px solid #9b59b6;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .event-preview h3 {
            color: #9b59b6;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>🏆 Create New Event</h1>
            <div>
                <a href="events.php" class="btn btn-secondary">Back to Events</a>
                <a href="../dashboard.php" class="btn btn-primary">Dashboard</a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success">
                ✅ <?php echo htmlspecialchars($message); ?>
                <br><small>Redirecting to events page...</small>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" id="eventForm">
            <!-- Progress Bar -->
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill" style="width: 25%"></div>
            </div>

            <!-- Form Wizard -->
            <div class="form-wizard">
                <div class="wizard-step active" data-step="1">
                    <div class="step-number">1</div>
                    Event Details
                </div>
                <div class="wizard-step" data-step="2">
                    <div class="step-number">2</div>
                    Select Teams
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-number">3</div>
                    Choose Apparatus
                </div>
                <div class="wizard-step" data-step="4">
                    <div class="step-number">4</div>
                    Review & Create
                </div>
            </div>

            <!-- Step 1: Event Details -->
            <div class="card form-section active" id="step1">
                <div class="card-header">
                    📝 Step 1: Event Information
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h4>Event Creation</h4>
                        <p>Start by providing the basic information about your gymnastics event. This will set up the foundation for team registration and judge assignments.</p>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="event_name">Event Name <span class="required">*</span></label>
                            <input type="text" id="event_name" name="event_name" required 
                                   placeholder="e.g., Spring Championship 2025">
                        </div>

                        <div class="form-group">
                            <label for="event_date">Event Date <span class="required">*</span></label>
                            <input type="date" id="event_date" name="event_date" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="location">Location</label>
                            <input type="text" id="location" name="location" 
                                   placeholder="e.g., Main Gymnasium, Sports Complex">
                        </div>

                        <div class="form-group">
                            <label for="status">Event Status</label>
                            <select id="status" name="status">
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Event Description</label>
                        <textarea id="description" name="description" 
                                  placeholder="Optional description about the event, rules, or special notes..."></textarea>
                    </div>

                    <div class="info-box">
                        <h4>Available Categories</h4>
                        <p>Current athlete categories in the system:</p>
                        <div class="category-list">
                            <?php foreach ($categories as $category): ?>
                                <span class="category-badge"><?php echo htmlspecialchars($category); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Team Selection -->
            <div class="card form-section" id="step2">
                <div class="card-header">
                    👥 Step 2: Register Teams
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h4>Team Registration</h4>
                        <p>Select which teams will participate in this event. Only teams with registered athletes are shown. You can also register individual athletes later.</p>
                    </div>

                    <div class="selection-grid">
                        <?php foreach ($teams as $team): ?>
                        <div class="selection-item" onclick="toggleTeamSelection(<?php echo $team['team_id']; ?>)">
                            <input type="checkbox" name="selected_teams[]" value="<?php echo $team['team_id']; ?>" 
                                   id="team_<?php echo $team['team_id']; ?>">
                            <h4><?php echo htmlspecialchars($team['team_name']); ?></h4>
                            <div class="team-info">
                                <?php if ($team['org_name']): ?>
                                    🏢 <?php echo htmlspecialchars($team['org_name']); ?><br>
                                <?php endif; ?>
                                👥 <?php echo $team['athlete_count']; ?> athletes
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($teams)): ?>
                    <div class="info-box">
                        <h4>No Teams Available</h4>
                        <p>No teams with athletes found. You can <a href="teams.php">create teams</a> and <a href="athletes.php">add athletes</a> before creating events.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 3: Apparatus Selection -->
            <div class="card form-section" id="step3">
                <div class="card-header">
                    🤸 Step 3: Select Apparatus
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h4>Competition Apparatus</h4>
                        <p>Choose which apparatus will be used in this competition. Athletes from selected teams will be registered for these apparatus.</p>
                    </div>

                    <div class="selection-grid">
                        <?php foreach ($apparatus_list as $apparatus): ?>
                        <div class="selection-item" onclick="toggleApparatusSelection(<?php echo $apparatus['apparatus_id']; ?>)">
                            <input type="checkbox" name="selected_apparatus[]" value="<?php echo $apparatus['apparatus_id']; ?>" 
                                   id="apparatus_<?php echo $apparatus['apparatus_id']; ?>">
                            <div class="apparatus-icon">🤸</div>
                            <h4><?php echo htmlspecialchars($apparatus['apparatus_name']); ?></h4>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="info-box">
                        <h4>Available Judges</h4>
                        <p>You can assign judges to apparatus after event creation. Current active judges:</p>
                        <div class="judge-list">
                            <?php foreach ($judges as $judge): ?>
                            <div class="judge-item">
                                <strong><?php echo htmlspecialchars($judge['full_name']); ?></strong>
                                <?php if ($judge['org_name']): ?>
                                    - <?php echo htmlspecialchars($judge['org_name']); ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 4: Review and Create -->
            <div class="card form-section" id="step4">
                <div class="card-header">
                    ✅ Step 4: Review & Create Event
                </div>
                <div class="card-body">
                    <div class="event-preview">
                        <h3>Event Preview</h3>
                        <div id="eventSummary" class="summary-section">
                            <!-- Summary will be populated by JavaScript -->
                        </div>
                    </div>

                    <div class="info-box">
                        <h4>What happens next?</h4>
                        <p>After creating the event:</p>
                        <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                            <li>Athletes from selected teams will be registered for selected apparatus</li>
                            <li>You can assign judges to apparatus in the event management page</li>
                            <li>Judges can start entering scores once assignments are made</li>
                            <li>Live leaderboards will be available for spectators</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="form-navigation">
                <button type="button" id="prevBtn" onclick="previousStep()" class="btn btn-secondary" style="display: none;">
                    ← Previous
                </button>
                <div>
                    <button type="button" id="nextBtn" onclick="nextStep()" class="btn btn-purple">
                        Next →
                    </button>
                    <button type="submit" id="createBtn" name="create_event" class="btn btn-success" style="display: none;">
                        🏆 Create Event
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 4;

        function updateProgress() {
            const progress = (currentStep / totalSteps) * 100;
            document.getElementById('progressFill').style.width = progress + '%';
        }

        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Show current step
            document.getElementById('step' + step).classList.add('active');
            
            // Update wizard indicators
            document.querySelectorAll('.wizard-step').forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index + 1 === step) {
                    stepEl.classList.add('active');
                } else if (index + 1 < step) {
                    stepEl.classList.add('completed');
                }
            });
            
            // Update navigation buttons
            document.getElementById('prevBtn').style.display = step > 1 ? 'block' : 'none';
            document.getElementById('nextBtn').style.display = step < totalSteps ? 'block' : 'none';
            document.getElementById('createBtn').style.display = step === totalSteps ? 'block' : 'none';
            
            updateProgress();
        }

        function nextStep() {
            if (validateCurrentStep()) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                    
                    if (currentStep === 4) {
                        generateSummary();
                    }
                }
            }
        }

        function previousStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }

        function validateCurrentStep() {
            if (currentStep === 1) {
                const eventName = document.getElementById('event_name').value.trim();
                const eventDate = document.getElementById('event_date').value;
                
                if (!eventName || !eventDate) {
                    alert('Please fill in the event name and date.');
                    return false;
                }
            }
            
            if (currentStep === 2) {
                const selectedTeams = document.querySelectorAll('input[name="selected_teams[]"]:checked');
                if (selectedTeams.length === 0) {
                    if (!confirm('No teams selected. Continue without pre-registering teams? You can add athletes individually later.')) {
                        return false;
                    }
                }
            }
            
            if (currentStep === 3) {
                const selectedApparatus = document.querySelectorAll('input[name="selected_apparatus[]"]:checked');
                if (selectedApparatus.length === 0) {
                    if (!confirm('No apparatus selected. Continue anyway? You can set up apparatus later.')) {
                        return false;
                    }
                }
            }
            
            return true;
        }

        function toggleTeamSelection(teamId) {
            const checkbox = document.getElementById('team_' + teamId);
            const item = checkbox.closest('.selection-item');
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        }

        function toggleApparatusSelection(apparatusId) {
            const checkbox = document.getElementById('apparatus_' + apparatusId);
            const item = checkbox.closest('.selection-item');
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        }

        function generateSummary() {
            const eventName = document.getElementById('event_name').value || 'Not specified';
            const eventDate = document.getElementById('event_date').value || 'Not specified';
            const location = document.getElementById('location').value || 'Not specified';
            const status = document.getElementById('status').value || 'upcoming';
            
            const selectedTeams = Array.from(document.querySelectorAll('input[name="selected_teams[]"]:checked')).map(cb => {
                return cb.closest('.selection-item').querySelector('h4').textContent;
            });
            
            const selectedApparatus = Array.from(document.querySelectorAll('input[name="selected_apparatus[]"]:checked')).map(cb => {
                return cb.closest('.selection-item').querySelector('h4').textContent;
            });
            
            const summary = `
                <div class="summary-item">
                    <div class="summary-label">Event Name:</div>
                    <div class="summary-value">${eventName}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Date:</div>
                    <div class="summary-value">${eventDate}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Location:</div>
                    <div class="summary-value">${location}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Status:</div>
                    <div class="summary-value">${status.charAt(0).toUpperCase() + status.slice(1)}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Teams:</div>
                    <div class="summary-value">${selectedTeams.length > 0 ? selectedTeams.join(', ') : 'None selected'}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Apparatus:</div>
                    <div class="summary-value">${selectedApparatus.length > 0 ? selectedApparatus.join(', ') : 'None selected'}</div>
                </div>
            `;
            
            document.getElementById('eventSummary').innerHTML = summary;
        }

        // Initialize
        showStep(1);

        // Form submission validation
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            if (!confirm('Create this event? Teams and apparatus will be registered automatically.')) {
                e.preventDefault();
            }
        });

        // Auto-fill current date as minimum
        document.getElementById('event_date').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>