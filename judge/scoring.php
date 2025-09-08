<?php
require_once '../config/database.php';
require_once '../classes/Score.php';

startSecureSession();
requireLogin();
requireRole('judge');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Get judge's assigned events and apparatus
$assignments_query = "SELECT DISTINCT e.event_id, e.event_name, e.event_date, a.apparatus_id, a.apparatus_name 
                      FROM judge_assignments ja
                      JOIN events e ON ja.event_id = e.event_id
                      JOIN apparatus a ON ja.apparatus_id = a.apparatus_id
                      WHERE ja.judge_id = :judge_id AND e.status = 'active'
                      ORDER BY e.event_date DESC, a.apparatus_name";

$assignments_stmt = $conn->prepare($assignments_query);
$assignments_stmt->bindParam(':judge_id', $_SESSION['user_id']);
$assignments_stmt->execute();
$assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_event = null;
$selected_apparatus = null;
$gymnasts = [];

if (isset($_GET['event_id']) && isset($_GET['apparatus_id'])) {
    $event_id = $_GET['event_id'];
    $apparatus_id = $_GET['apparatus_id'];
    
    // Verify judge is assigned to this event/apparatus
    $verify_query = "SELECT * FROM judge_assignments 
                     WHERE judge_id = :judge_id AND event_id = :event_id AND apparatus_id = :apparatus_id";
    $verify_stmt = $conn->prepare($verify_query);
    $verify_stmt->bindParam(':judge_id', $_SESSION['user_id']);
    $verify_stmt->bindParam(':event_id', $event_id);
    $verify_stmt->bindParam(':apparatus_id', $apparatus_id);
    $verify_stmt->execute();
    
    if ($verify_stmt->rowCount() > 0) {
        // Get event and apparatus details
        $event_query = "SELECT * FROM events WHERE event_id = :event_id";
        $event_stmt = $conn->prepare($event_query);
        $event_stmt->bindParam(':event_id', $event_id);
        $event_stmt->execute();
        $selected_event = $event_stmt->fetch(PDO::FETCH_ASSOC);
        
        $apparatus_query = "SELECT * FROM apparatus WHERE apparatus_id = :apparatus_id";
        $apparatus_stmt = $conn->prepare($apparatus_query);
        $apparatus_stmt->bindParam(':apparatus_id', $apparatus_id);
        $apparatus_stmt->execute();
        $selected_apparatus = $apparatus_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get gymnasts for this event (without scores for this apparatus)
        $gymnasts_query = "SELECT g.gymnast_id, g.gymnast_name, g.gymnast_category, t.team_name,
                                  s.score_id, s.score_d1, s.score_d2, s.score_d3, s.score_d4,
                                  s.score_a1, s.score_a2, s.score_a3, s.score_e1, s.score_e2, s.score_e3,
                                  s.technical_deduction
                           FROM gymnasts g
                           JOIN teams t ON g.team_id = t.team_id
                           LEFT JOIN scores s ON (g.gymnast_id = s.gymnast_id AND s.event_id = :event_id AND s.apparatus_id = :apparatus_id)
                           ORDER BY g.gymnast_name";
        
        $gymnasts_stmt = $conn->prepare($gymnasts_query);
        $gymnasts_stmt->bindParam(':event_id', $event_id);
        $gymnasts_stmt->bindParam(':apparatus_id', $apparatus_id);
        $gymnasts_stmt->execute();
        $gymnasts = $gymnasts_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle score submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_score'])) {
    $gymnast_id = $_POST['gymnast_id'];
    $event_id = $_POST['event_id'];
    $apparatus_id = $_POST['apparatus_id'];
    
    $scoreD1 = (float)$_POST['scoreD1'];
    $scoreD2 = (float)$_POST['scoreD2'];
    $scoreD3 = (float)$_POST['scoreD3'];
    $scoreD4 = (float)$_POST['scoreD4'];
    $scoreA1 = (float)$_POST['scoreA1'];
    $scoreA2 = (float)$_POST['scoreA2'];
    $scoreA3 = (float)$_POST['scoreA3'];
    $scoreE1 = (float)$_POST['scoreE1'];
    $scoreE2 = (float)$_POST['scoreE2'];
    $scoreE3 = (float)$_POST['scoreE3'];
    $technicalDeduction = (float)$_POST['technicalDeduction'];
    
    try {
        // Check if score already exists
        $check_query = "SELECT score_id FROM scores WHERE gymnast_id = :gymnast_id AND event_id = :event_id AND apparatus_id = :apparatus_id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':gymnast_id', $gymnast_id);
        $check_stmt->bindParam(':event_id', $event_id);
        $check_stmt->bindParam(':apparatus_id', $apparatus_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing score
            $update_query = "UPDATE scores SET 
                            score_d1 = :scoreD1, score_d2 = :scoreD2, score_d3 = :scoreD3, score_d4 = :scoreD4,
                            score_a1 = :scoreA1, score_a2 = :scoreA2, score_a3 = :scoreA3,
                            score_e1 = :scoreE1, score_e2 = :scoreE2, score_e3 = :scoreE3,
                            technical_deduction = :technicalDeduction, judge_id = :judge_id,
                            updated_at = CURRENT_TIMESTAMP
                            WHERE gymnast_id = :gymnast_id AND event_id = :event_id AND apparatus_id = :apparatus_id";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':scoreD1', $scoreD1);
            $update_stmt->bindParam(':scoreD2', $scoreD2);
            $update_stmt->bindParam(':scoreD3', $scoreD3);
            $update_stmt->bindParam(':scoreD4', $scoreD4);
            $update_stmt->bindParam(':scoreA1', $scoreA1);
            $update_stmt->bindParam(':scoreA2', $scoreA2);
            $update_stmt->bindParam(':scoreA3', $scoreA3);
            $update_stmt->bindParam(':scoreE1', $scoreE1);
            $update_stmt->bindParam(':scoreE2', $scoreE2);
            $update_stmt->bindParam(':scoreE3', $scoreE3);
            $update_stmt->bindParam(':technicalDeduction', $technicalDeduction);
            $update_stmt->bindParam(':judge_id', $_SESSION['user_id']);
            $update_stmt->bindParam(':gymnast_id', $gymnast_id);
            $update_stmt->bindParam(':event_id', $event_id);
            $update_stmt->bindParam(':apparatus_id', $apparatus_id);
            $update_stmt->execute();
            
            $message = "Score updated successfully!";
        } else {
            // Insert new score
            $insert_query = "INSERT INTO scores (gymnast_id, event_id, apparatus_id, judge_id,
                            score_d1, score_d2, score_d3, score_d4, score_a1, score_a2, score_a3,
                            score_e1, score_e2, score_e3, technical_deduction)
                            VALUES (:gymnast_id, :event_id, :apparatus_id, :judge_id,
                            :scoreD1, :scoreD2, :scoreD3, :scoreD4, :scoreA1, :scoreA2, :scoreA3,
                            :scoreE1, :scoreE2, :scoreE3, :technicalDeduction)";
            
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':gymnast_id', $gymnast_id);
            $insert_stmt->bindParam(':event_id', $event_id);
            $insert_stmt->bindParam(':apparatus_id', $apparatus_id);
            $insert_stmt->bindParam(':judge_id', $_SESSION['user_id']);
            $insert_stmt->bindParam(':scoreD1', $scoreD1);
            $insert_stmt->bindParam(':scoreD2', $scoreD2);
            $insert_stmt->bindParam(':scoreD3', $scoreD3);
            $insert_stmt->bindParam(':scoreD4', $scoreD4);
            $insert_stmt->bindParam(':scoreA1', $scoreA1);
            $insert_stmt->bindParam(':scoreA2', $scoreA2);
            $insert_stmt->bindParam(':scoreA3', $scoreA3);
            $insert_stmt->bindParam(':scoreE1', $scoreE1);
            $insert_stmt->bindParam(':scoreE2', $scoreE2);
            $insert_stmt->bindParam(':scoreE3', $scoreE3);
            $insert_stmt->bindParam(':technicalDeduction', $technicalDeduction);
            $insert_stmt->execute();
            
            $message = "Score submitted successfully!";
        }
        
        // Refresh gymnasts data
        $gymnasts_stmt->execute();
        $gymnasts = $gymnasts_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error saving score: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Judge Scoring - Gymnastics Scoring System</title>
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

        .judge-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        .assignment-selector {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .assignment-card {
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .assignment-card:hover {
            border-color: #f39c12;
            background: #fef9e7;
        }

        .assignment-card.active {
            border-color: #f39c12;
            background: #f39c12;
            color: white;
        }

        .scoring-section {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .gymnast-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .gymnast-card {
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            overflow: hidden;
        }

        .gymnast-card.scored {
            border-color: #27ae60;
        }

        .gymnast-header {
            background: #34495e;
            color: white;
            padding: 1rem;
        }

        .gymnast-header.scored {
            background: #27ae60;
        }

        .gymnast-name {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 0.3rem;
        }

        .gymnast-details {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .score-form {
            padding: 1.5rem;
        }

        .score-sections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .score-group {
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            padding: 1rem;
        }

        .score-group h4 {
            margin-bottom: 0.8rem;
            color: #2c3e50;
            text-align: center;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .d-scores { border-left: 4px solid #3498db; }
        .a-scores { border-left: 4px solid #e74c3c; }
        .e-scores { border-left: 4px solid #f39c12; }

        .score-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .score-input {
            text-align: center;
        }

        .score-input label {
            display: block;
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
            color: #666;
        }

        .score-input input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
            font-size: 1rem;
            font-weight: bold;
        }

        .score-input input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .deduction-section {
            margin: 1rem 0;
            padding: 1rem;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 8px;
        }

        .deduction-section label {
            display: block;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #e53e3e;
        }

        .deduction-section input {
            width: 100px;
            padding: 0.5rem;
            border: 1px solid #e53e3e;
            border-radius: 5px;
            text-align: center;
        }

        .final-score-display {
            background: #f8f9fa;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            text-align: center;
        }

        .final-score-value {
            font-size: 2rem;
            font-weight: bold;
            color: #28a745;
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
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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

        .back-btn {
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .score-sections {
                grid-template-columns: 1fr;
            }
            
            .gymnast-list {
                grid-template-columns: 1fr;
            }
            
            .assignments-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Judge Scoring Panel</h1>
            <div class="judge-info">
                <span>Judge: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
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

        <div class="assignment-selector">
            <h2>Your Judging Assignments</h2>
            <div class="assignments-grid">
                <?php foreach ($assignments as $assignment): ?>
                    <a href="?event_id=<?php echo $assignment['event_id']; ?>&apparatus_id=<?php echo $assignment['apparatus_id']; ?>" 
                       class="assignment-card <?php echo ($selected_event && $selected_event['event_id'] == $assignment['event_id'] && $selected_apparatus && $selected_apparatus['apparatus_id'] == $assignment['apparatus_id']) ? 'active' : ''; ?>">
                        <h3><?php echo htmlspecialchars($assignment['event_name']); ?></h3>
                        <p><strong>Apparatus:</strong> <?php echo htmlspecialchars($assignment['apparatus_name']); ?></p>
                        <p><strong>Date:</strong> <?php echo $assignment['event_date']; ?></p>
                    </a>
                <?php endforeach; ?>
                
                <?php if (empty($assignments)): ?>
                    <p>No active judging assignments found. Contact your event administrator.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selected_event && $selected_apparatus): ?>
        <div class="scoring-section">
            <h2><?php echo htmlspecialchars($selected_event['event_name']); ?> - <?php echo htmlspecialchars($selected_apparatus['apparatus_name']); ?></h2>
            
            <div class="gymnast-list">
                <?php foreach ($gymnasts as $gymnast): ?>
                    <div class="gymnast-card <?php echo $gymnast['score_id'] ? 'scored' : ''; ?>">
                        <div class="gymnast-header <?php echo $gymnast['score_id'] ? 'scored' : ''; ?>">
                            <div class="gymnast-name"><?php echo htmlspecialchars($gymnast['gymnast_name']); ?></div>
                            <div class="gymnast-details">
                                <?php echo htmlspecialchars($gymnast['team_name']); ?> • 
                                <?php echo htmlspecialchars($gymnast['gymnast_category']); ?>
                                <?php if ($gymnast['score_id']): ?>
                                    • <strong>SCORED</strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST" class="score-form" onsubmit="return validateScore(this)">
                            <input type="hidden" name="gymnast_id" value="<?php echo $gymnast['gymnast_id']; ?>">
                            <input type="hidden" name="event_id" value="<?php echo $selected_event['event_id']; ?>">
                            <input type="hidden" name="apparatus_id" value="<?php echo $selected_apparatus['apparatus_id']; ?>">

                            <div class="score-sections">
                                <div class="score-group d-scores">
                                    <h4>D Scores (Difficulty)</h4>
                                    <div class="score-inputs">
                                        <div class="score-input">
                                            <label>D1</label>
                                            <input type="number" name="scoreD1" step="0.01" min="0" max="20" 
                                                   value="<?php echo $gymnast['score_d1'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>D2</label>
                                            <input type="number" name="scoreD2" step="0.01" min="0" max="20" 
                                                   value="<?php echo $gymnast['score_d2'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>D3</label>
                                            <input type="number" name="scoreD3" step="0.01" min="0" max="20" 
                                                   value="<?php echo $gymnast['score_d3'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>D4</label>
                                            <input type="number" name="scoreD4" step="0.01" min="0" max="20" 
                                                   value="<?php echo $gymnast['score_d4'] ?? '0.00'; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="score-group a-scores">
                                    <h4>A Scores (Artistry)</h4>
                                    <div class="score-inputs">
                                        <div class="score-input">
                                            <label>A1</label>
                                            <input type="number" name="scoreA1" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_a1'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>A2</label>
                                            <input type="number" name="scoreA2" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_a2'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>A3</label>
                                            <input type="number" name="scoreA3" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_a3'] ?? '0.00'; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="score-group e-scores">
                                    <h4>E Scores (Execution)</h4>
                                    <div class="score-inputs">
                                        <div class="score-input">
                                            <label>E1</label>
                                            <input type="number" name="scoreE1" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_e1'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>E2</label>
                                            <input type="number" name="scoreE2" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_e2'] ?? '0.00'; ?>" required>
                                        </div>
                                        <div class="score-input">
                                            <label>E3</label>
                                            <input type="number" name="scoreE3" step="0.01" min="0" max="10" 
                                                   value="<?php echo $gymnast['score_e3'] ?? '0.00'; ?>" required>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="deduction-section">
                                <label>Technical Deduction:</label>
                                <input type="number" name="technicalDeduction" step="0.01" min="0" max="10" 
                                       value="<?php echo $gymnast['technical_deduction'] ?? '0.00'; ?>" required>
                            </div>

                            <div class="final-score-display">
                                <div>Final Score</div>
                                <div class="final-score-value" id="finalScore_<?php echo $gymnast['gymnast_id']; ?>">0.00</div>
                            </div>

                            <button type="submit" name="submit_score" class="btn btn-success" style="width: 100%;">
                                <?php echo $gymnast['score_id'] ? 'Update Score' : 'Submit Score'; ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function calculateFinalScore(form) {
            const scoreD1 = parseFloat(form.scoreD1.value) || 0;
            const scoreD2 = parseFloat(form.scoreD2.value) || 0;
            const scoreD3 = parseFloat(form.scoreD3.value) || 0;
            const scoreD4 = parseFloat(form.scoreD4.value) || 0;
            const scoreA1 = parseFloat(form.scoreA1.value) || 0;
            const scoreA2 = parseFloat(form.scoreA2.value) || 0;
            const scoreA3 = parseFloat(form.scoreA3.value) || 0;
            const scoreE1 = parseFloat(form.scoreE1.value) || 0;
            const scoreE2 = parseFloat(form.scoreE2.value) || 0;
            const scoreE3 = parseFloat(form.scoreE3.value) || 0;
            const technicalDeduction = parseFloat(form.technicalDeduction.value) || 0;

            // Calculate averages as per the Java formula
            const avgD1D2 = (scoreD1 === 0) ? scoreD2 : (scoreD2 === 0) ? scoreD1 : (scoreD1 + scoreD2) / 2;
            const avgD3D4 = (scoreD3 === 0) ? scoreD4 : (scoreD4 === 0) ? scoreD3 : (scoreD3 + scoreD4) / 2;
            const totalD = avgD1D2 + avgD3D4;

            // Get middle A score
            const aScores = [scoreA1, scoreA2, scoreA3].filter(s => s > 0);
            const middleA = aScores.length === 0 ? 0 : aScores.sort((a, b) => a - b)[Math.floor(aScores.length / 2)];

            // Get middle E score
            const eScores = [scoreE1, scoreE2, scoreE3].filter(s => s > 0);
            const middleE = eScores.length === 0 ? 0 : eScores.sort((a, b) => a - b)[Math.floor(eScores.length / 2)];

            // Final calculation
            const totalScore = totalD + 10 - middleE + 10 - middleA;
            const finalScore = totalScore - technicalDeduction;

            return finalScore;
        }

        function updateFinalScore(form) {
            const gymnastId = form.gymnast_id.value;
            const finalScore = calculateFinalScore(form);
            document.getElementById('finalScore_' + gymnastId).textContent = finalScore.toFixed(2);
        }

        function validateScore(form) {
            const finalScore = calculateFinalScore(form);
            if (finalScore < 0) {
                alert('Final score cannot be negative. Please check your inputs.');
                return false;
            }
            return confirm('Submit this score? Final Score: ' + finalScore.toFixed(2));
        }

        // Add event listeners to all score inputs
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('.score-form');
            forms.forEach(form => {
                const inputs = form.querySelectorAll('input[type="number"]');
                inputs.forEach(input => {
                    input.addEventListener('input', () => updateFinalScore(form));
                });
                updateFinalScore(form); // Initial calculation
            });
        });
    </script>
</body>
</html>