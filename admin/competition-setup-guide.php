<?php
require_once '../config/database.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Check system status for each step
$setup_status = [];

// Step 1: Organizations
$orgs_query = "SELECT COUNT(*) FROM organizations";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$setup_status['organizations'] = $orgs_stmt->fetchColumn();

// Step 2: Teams  
$teams_query = "SELECT COUNT(*) FROM teams";
$teams_stmt = $conn->prepare($teams_query);
$teams_stmt->execute();
$setup_status['teams'] = $teams_stmt->fetchColumn();

// Step 3: Athletes
$athletes_query = "SELECT COUNT(*) FROM gymnasts";
$athletes_stmt = $conn->prepare($athletes_query);
$athletes_stmt->execute();
$setup_status['athletes'] = $athletes_stmt->fetchColumn();

// Step 4: Judges
$judges_query = "SELECT COUNT(*) FROM users WHERE role = 'judge'";
$judges_stmt = $conn->prepare($judges_query);
$judges_stmt->execute();
$setup_status['judges'] = $judges_stmt->fetchColumn();

// Step 5: Events
$events_query = "SELECT COUNT(*) FROM events";
$events_stmt = $conn->prepare($events_query);
$events_stmt->execute();
$setup_status['events'] = $events_stmt->fetchColumn();

// Step 6: Judge Assignments
$assignments_query = "SELECT COUNT(*) FROM judge_assignments";
$assignments_stmt = $conn->prepare($assignments_query);
$assignments_stmt->execute();
$setup_status['assignments'] = $assignments_stmt->fetchColumn();

// Get recent activity for each category
$recent_activity = [];
try {
    $activity_query = "
        SELECT 'organization' as type, org_name as name, created_at FROM organizations 
        UNION ALL
        SELECT 'team' as type, team_name as name, created_at FROM teams
        UNION ALL 
        SELECT 'athlete' as type, gymnast_name as name, created_at FROM gymnasts
        UNION ALL
        SELECT 'judge' as type, full_name as name, created_at FROM users WHERE role = 'judge'
        UNION ALL
        SELECT 'event' as type, event_name as name, created_at FROM events
        ORDER BY created_at DESC LIMIT 10
    ";
    $activity_stmt = $conn->prepare($activity_query);
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle gracefully
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Competition Setup Guide - Gymnastics Scoring</title>
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
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            text-align: center;
            margin-bottom: 3rem;
            border-radius: 16px;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #6366F1;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #4F46E5;
            transform: translateY(-2px);
        }

        .setup-progress {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 3rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .progress-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .progress-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.5rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #E5E7EB;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10B981, #059669);
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .step-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #E5E7EB;
            transition: all 0.3s ease;
            position: relative;
        }

        .step-card.completed {
            border-left-color: #10B981;
            background: linear-gradient(135deg, #F0FDF4, #ECFDF5);
        }

        .step-card.in-progress {
            border-left-color: #F59E0B;
            background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
        }

        .step-card.not-started {
            border-left-color: #EF4444;
        }

        .step-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .step-number.completed {
            background: #10B981;
            color: white;
        }

        .step-number.in-progress {
            background: #F59E0B;
            color: white;
        }

        .step-number.not-started {
            background: #EF4444;
            color: white;
        }

        .step-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .step-status.completed {
            background: #10B981;
            color: white;
        }

        .step-status.in-progress {
            background: #F59E0B;
            color: white;
        }

        .step-status.not-started {
            background: #EF4444;
            color: white;
        }

        .step-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.5rem;
        }

        .step-description {
            color: #64748B;
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .step-count {
            font-size: 2rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.5rem;
        }

        .step-count-label {
            color: #64748B;
            font-size: 0.9rem;
        }

        .step-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #8B5CF6;
            color: white;
        }

        .btn-primary:hover {
            background: #7C3AED;
            transform: translateY(-2px);
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

        .recommendations-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .recommendations-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 1rem;
        }

        .recommendation-list {
            list-style: none;
            space-y: 0.75rem;
        }

        .recommendation-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            background: #F8FAFC;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        .recommendation-icon {
            width: 24px;
            height: 24px;
            background: #3B82F6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            flex-shrink: 0;
            margin-top: 0.1rem;
        }

        .activity-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .activity-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 1rem;
        }

        .activity-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #F1F5F9;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
        }

        .activity-icon.organization { background: #8B5CF6; }
        .activity-icon.team { background: #3B82F6; }
        .activity-icon.athlete { background: #10B981; }
        .activity-icon.judge { background: #F59E0B; }
        .activity-icon.event { background: #EF4444; }

        .activity-content {
            flex: 1;
        }

        .activity-name {
            font-weight: 500;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            color: #64748B;
            font-size: 0.8rem;
        }

        .quick-start-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .quick-start-section h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .quick-start-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .quick-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .quick-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1.5rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .steps-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-start-actions {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../dashboard.php" class="back-btn">
            ← Back to Dashboard
        </a>

        <div class="header">
            <h1>🏆 Competition Setup Guide</h1>
            <p>Follow these steps to set up your gymnastics competition from start to finish</p>
        </div>

        <?php
        // Calculate overall progress
        $total_steps = 6;
        $completed_steps = 0;
        if ($setup_status['organizations'] > 0) $completed_steps++;
        if ($setup_status['teams'] > 0) $completed_steps++;
        if ($setup_status['athletes'] > 0) $completed_steps++;
        if ($setup_status['judges'] > 0) $completed_steps++;
        if ($setup_status['events'] > 0) $completed_steps++;
        if ($setup_status['assignments'] > 0) $completed_steps++;
        
        $progress_percentage = ($completed_steps / $total_steps) * 100;
        ?>

        <div class="setup-progress">
            <div class="progress-header">
                <h2>Setup Progress</h2>
                <p><?php echo $completed_steps; ?> of <?php echo $total_steps; ?> steps completed</p>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
            </div>
        </div>

        <?php if ($completed_steps == 0): ?>
        <div class="quick-start-section">
            <h3>🚀 Ready to Get Started?</h3>
            <p>Let's set up your first competition! We recommend starting with creating an organization and then building from there.</p>
            <div class="quick-start-actions">
                <a href="organizations.php" class="quick-btn">🏢 Start with Organizations</a>
                <a href="teams.php" class="quick-btn">👥 Create Independent Team</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="steps-grid">
            <!-- Step 1: Organizations -->
            <div class="step-card <?php echo $setup_status['organizations'] > 0 ? 'completed' : 'not-started'; ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['organizations'] > 0 ? 'completed' : 'not-started'; ?>">1</div>
                    <div class="step-status <?php echo $setup_status['organizations'] > 0 ? 'completed' : 'not-started'; ?>">
                        <?php echo $setup_status['organizations'] > 0 ? 'Completed' : 'Not Started'; ?>
                    </div>
                </div>
                <h3 class="step-title">🏢 Organizations</h3>
                <p class="step-description">
                    Create organizations to group teams under clubs, schools, or gymnastics academies. This is optional but recommended for better organization.
                </p>
                <div class="step-count"><?php echo $setup_status['organizations']; ?></div>
                <div class="step-count-label">Organizations created</div>
                <div class="step-actions">
                    <a href="organizations.php" class="btn btn-primary">
                        <?php echo $setup_status['organizations'] > 0 ? '👁️ Manage' : '➕ Create'; ?> Organizations
                    </a>
                </div>
            </div>

            <!-- Step 2: Teams -->
            <div class="step-card <?php echo $setup_status['teams'] > 0 ? 'completed' : ($setup_status['organizations'] > 0 ? 'in-progress' : 'not-started'); ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['teams'] > 0 ? 'completed' : ($setup_status['organizations'] > 0 ? 'in-progress' : 'not-started'); ?>">2</div>
                    <div class="step-status <?php echo $setup_status['teams'] > 0 ? 'completed' : ($setup_status['organizations'] > 0 ? 'in-progress' : 'not-started'); ?>">
                        <?php 
                        if ($setup_status['teams'] > 0) echo 'Completed';
                        elseif ($setup_status['organizations'] > 0) echo 'Ready';
                        else echo 'Waiting';
                        ?>
                    </div>
                </div>
                <h3 class="step-title">👥 Teams</h3>
                <p class="step-description">
                    Create teams to group athletes. Teams can be linked to organizations or remain independent. You need at least one team before adding athletes.
                </p>
                <div class="step-count"><?php echo $setup_status['teams']; ?></div>
                <div class="step-count-label">Teams created</div>
                <div class="step-actions">
                    <a href="teams.php" class="btn btn-primary">
                        <?php echo $setup_status['teams'] > 0 ? '👁️ Manage' : '➕ Create'; ?> Teams
                    </a>
                </div>
            </div>

            <!-- Step 3: Athletes -->
            <div class="step-card <?php echo $setup_status['athletes'] > 0 ? 'completed' : ($setup_status['teams'] > 0 ? 'in-progress' : 'not-started'); ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['athletes'] > 0 ? 'completed' : ($setup_status['teams'] > 0 ? 'in-progress' : 'not-started'); ?>">3</div>
                    <div class="step-status <?php echo $setup_status['athletes'] > 0 ? 'completed' : ($setup_status['teams'] > 0 ? 'in-progress' : 'not-started'); ?>">
                        <?php 
                        if ($setup_status['athletes'] > 0) echo 'Completed';
                        elseif ($setup_status['teams'] > 0) echo 'Ready';
                        else echo 'Waiting';
                        ?>
                    </div>
                </div>
                <h3 class="step-title">🤸‍♂️ Athletes</h3>
                <p class="step-description">
                    Add gymnasts to teams. Each athlete must belong to a team and have a category (Junior, Senior, etc.). These are the competitors in your events.
                </p>
                <div class="step-count"><?php echo $setup_status['athletes']; ?></div>
                <div class="step-count-label">Athletes registered</div>
                <div class="step-actions">
                    <a href="athletes.php" class="btn btn-primary">
                        <?php echo $setup_status['athletes'] > 0 ? '👁️ Manage' : '➕ Add'; ?> Athletes
                    </a>
                    <?php if ($setup_status['teams'] == 0): ?>
                        <span style="color: #EF4444; font-size: 0.8rem;">⚠️ Create teams first</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 4: Judges -->
            <div class="step-card <?php echo $setup_status['judges'] > 0 ? 'completed' : 'in-progress'; ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['judges'] > 0 ? 'completed' : 'in-progress'; ?>">4</div>
                    <div class="step-status <?php echo $setup_status['judges'] > 0 ? 'completed' : 'in-progress'; ?>">
                        <?php echo $setup_status['judges'] > 0 ? 'Completed' : 'Ready'; ?>
                    </div>
                </div>
                <h3 class="step-title">👨‍⚖️ Judges</h3>
                <p class="step-description">
                    Register judges who will score the competitions. Judges can be independent or linked to organizations. You need judges before creating events.
                </p>
                <div class="step-count"><?php echo $setup_status['judges']; ?></div>
                <div class="step-count-label">Judges registered</div>
                <div class="step-actions">
                    <a href="judges.php" class="btn btn-primary">
                        <?php echo $setup_status['judges'] > 0 ? '👁️ Manage' : '➕ Register'; ?> Judges
                    </a>
                </div>
            </div>

            <!-- Step 5: Events -->
            <div class="step-card <?php echo $setup_status['events'] > 0 ? 'completed' : ($setup_status['athletes'] > 0 && $setup_status['judges'] > 0 ? 'in-progress' : 'not-started'); ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['events'] > 0 ? 'completed' : ($setup_status['athletes'] > 0 && $setup_status['judges'] > 0 ? 'in-progress' : 'not-started'); ?>">5</div>
                    <div class="step-status <?php echo $setup_status['events'] > 0 ? 'completed' : ($setup_status['athletes'] > 0 && $setup_status['judges'] > 0 ? 'in-progress' : 'not-started'); ?>">
                        <?php 
                        if ($setup_status['events'] > 0) echo 'Completed';
                        elseif ($setup_status['athletes'] > 0 && $setup_status['judges'] > 0) echo 'Ready';
                        else echo 'Waiting';
                        ?>
                    </div>
                </div>
                <h3 class="step-title">🏆 Events</h3>
                <p class="step-description">
                    Create competitions! Events bring together athletes, teams, and judges. You can register teams and set up apparatus for scoring.
                </p>
                <div class="step-count"><?php echo $setup_status['events']; ?></div>
                <div class="step-count-label">Events created</div>
                <div class="step-actions">
                    <a href="events.php" class="btn btn-primary">
                        <?php echo $setup_status['events'] > 0 ? '👁️ Manage' : '➕ Create'; ?> Events
                    </a>
                    <?php if ($setup_status['athletes'] == 0 || $setup_status['judges'] == 0): ?>
                        <span style="color: #EF4444; font-size: 0.8rem;">⚠️ Need athletes & judges</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 6: Judge Assignments -->
            <div class="step-card <?php echo $setup_status['assignments'] > 0 ? 'completed' : ($setup_status['events'] > 0 ? 'in-progress' : 'not-started'); ?>">
                <div class="step-header">
                    <div class="step-number <?php echo $setup_status['assignments'] > 0 ? 'completed' : ($setup_status['events'] > 0 ? 'in-progress' : 'not-started'); ?>">6</div>
                    <div class="step-status <?php echo $setup_status['assignments'] > 0 ? 'completed' : ($setup_status['events'] > 0 ? 'in-progress' : 'not-started'); ?>">
                        <?php 
                        if ($setup_status['assignments'] > 0) echo 'Completed';
                        elseif ($setup_status['events'] > 0) echo 'Ready';
                        else echo 'Waiting';
                        ?>
                    </div>
                </div>
                <h3 class="step-title">📝 Judge Assignments</h3>
                <p class="step-description">
                    Assign judges to specific apparatus for each event. This allows judges to start scoring and enables live leaderboards.
                </p>
                <div class="step-count"><?php echo $setup_status['assignments']; ?></div>
                <div class="step-count-label">Judge assignments</div>
                <div class="step-actions">
                    <?php if ($setup_status['events'] > 0): ?>
                        <a href="events.php" class="btn btn-primary">👨‍⚖️ Assign Judges</a>
                    <?php else: ?>
                        <span style="color: #EF4444; font-size: 0.8rem;">⚠️ Create events first</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add smooth animations when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.step-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Animate progress bar
            const progressBar = document.querySelector('.progress-fill');
            if (progressBar) {
                const targetWidth = progressBar.style.width;
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = targetWidth;
                }, 500);
            }
        });

        // Add click animations
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });
    </script>
</body>
</html>