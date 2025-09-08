<?php
require_once 'config/database.php';

startSecureSession();

// Redirect logged-in users to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Get live events and organization leaderboards
$database = new Database();
$conn = $database->getConnection();

$live_events = [];
try {
    // Get active events with organization participation
    $events_query = "SELECT DISTINCT e.event_id, e.event_name, e.event_date, e.location, e.status,
                            COUNT(DISTINCT o.org_id) as org_count,
                            COUNT(DISTINCT g.gymnast_id) as total_participants,
                            COUNT(DISTINCT s.score_id) as total_scores
                     FROM events e
                     LEFT JOIN scores s ON e.event_id = s.event_id
                     LEFT JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                     LEFT JOIN teams t ON g.team_id = t.team_id
                     LEFT JOIN organizations o ON t.organization_id = o.org_id
                     WHERE e.status IN ('active', 'upcoming')
                     GROUP BY e.event_id
                     ORDER BY 
                        CASE WHEN e.status = 'active' THEN 1 ELSE 2 END,
                        e.event_date DESC
                     LIMIT 6";
    
    $events_stmt = $conn->prepare($events_query);
    $events_stmt->execute();
    $events_data = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each event, get top organizations
    foreach ($events_data as $event) {
        $event_id = $event['event_id'];
        
        // Get organization leaderboard for this event
        $org_query = "SELECT o.org_name,
                             COUNT(DISTINCT g.gymnast_id) as participants,
                             COUNT(s.score_id) as total_scores,
                             AVG(CASE WHEN s.score_id IS NOT NULL THEN 
                                 (s.score_d1 + s.score_d2 + s.score_d3 + s.score_d4 + 
                                  (s.score_a1 + s.score_a2 + s.score_a3)/3 + 
                                  (s.score_e1 + s.score_e2 + s.score_e3)/3 - 
                                  s.technical_deduction) END) as avg_score
                      FROM organizations o
                      JOIN teams t ON o.org_id = t.organization_id
                      JOIN gymnasts g ON t.team_id = g.team_id
                      LEFT JOIN scores s ON g.gymnast_id = s.gymnast_id AND s.event_id = :event_id
                      WHERE EXISTS (SELECT 1 FROM scores s2 WHERE s2.gymnast_id = g.gymnast_id AND s2.event_id = :event_id)
                      GROUP BY o.org_id, o.org_name
                      HAVING total_scores > 0
                      ORDER BY avg_score DESC, total_scores DESC
                      LIMIT 3";
        
        $org_stmt = $conn->prepare($org_query);
        $org_stmt->bindParam(':event_id', $event_id);
        $org_stmt->execute();
        $event['organizations'] = $org_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $live_events[] = $event;
    }
} catch (PDOException $e) {
    // Database not set up yet
}

// Get total system stats
$stats = ['total_events' => 0, 'total_orgs' => 0, 'total_athletes' => 0, 'total_scores' => 0];
try {
    $stats_query = "SELECT 
                        (SELECT COUNT(*) FROM events WHERE status = 'active') as total_events,
                        (SELECT COUNT(*) FROM organizations) as total_orgs,
                        (SELECT COUNT(*) FROM gymnasts) as total_athletes,
                        (SELECT COUNT(*) FROM scores) as total_scores";
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Database not set up yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rhythmic Gymnastics Scoring System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #1A1B23;
            color: white;
            min-height: 100vh;
        }

        .header {
            background: #1A1B23;
            padding: 1rem 0;
            border-bottom: 1px solid #2D2E3F;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
        }

        .header-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-item {
            color: #A3A3A3;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-item:hover,
        .nav-item.active {
            color: white;
            background: #2D2E3F;
        }

        .login-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: #A3A3A3;
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        .welcome-banner {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 3rem;
        }

        .welcome-banner h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-banner p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: white;
        }

        .tournaments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .tournament-card {
            background: #2D2E3F;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #3D3E4F;
        }

        .tournament-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            border-color: #8B5CF6;
        }

        .tournament-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
        }

        .tournament-content {
            padding: 1.5rem;
        }

        .tournament-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .status-active {
            background: #10B981;
            color: white;
        }

        .status-upcoming {
            background: #F59E0B;
            color: white;
        }

        .tournament-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: white;
        }

        .tournament-prize {
            font-size: 1.8rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.5rem;
        }

        .tournament-meta {
            color: #A3A3A3;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .leaderboard {
            margin-bottom: 1.5rem;
        }

        .leaderboard-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #3D3E4F;
        }

        .leaderboard-item:last-child {
            border-bottom: none;
        }

        .org-rank {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .rank-number {
            width: 24px;
            height: 24px;
            background: #3D3E4F;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .rank-1 { background: #F59E0B; }
        .rank-2 { background: #6B7280; }
        .rank-3 { background: #CD7C2F; }

        .org-name {
            font-weight: 500;
            color: white;
        }

        .org-score {
            color: #8B5CF6;
            font-weight: 600;
        }

        .tournament-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #3D3E4F;
        }

        .tournament-stats {
            display: flex;
            gap: 2rem;
            font-size: 0.85rem;
            color: #A3A3A3;
        }

        .view-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .stats-section {
            background: #2D2E3F;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #A3A3A3;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .no-events {
            text-align: center;
            padding: 4rem 2rem;
            color: #A3A3A3;
        }

        .no-events h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: white;
        }

        .footer {
            background: #1A1B23;
            border-top: 1px solid #2D2E3F;
            padding: 2rem 0;
            text-align: center;
            margin-top: 3rem;
        }

        .footer p {
            color: #A3A3A3;
            font-size: 0.9rem;
        }

        .footer a {
            color: #8B5CF6;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
            }
            
            .header-nav {
                gap: 1rem;
            }
            
            .container {
                padding: 1rem;
            }
            
            .tournaments-grid {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .welcome-banner h2 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Live indicator animation */
        .live-dot {
            width: 8px;
            height: 8px;
            background: #10B981;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">🤸</div>
                <div class="logo-text">GymnasticsScore</div>
            </div>
            <nav class="header-nav">
                <a href="#" class="nav-item active">Competitions</a>
                <a href="leaderboard.php" class="nav-item">Live Scores</a>
                <a href="login.php" class="login-btn">Login</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="welcome-banner">
            <h2>Welcome to Rhythmic Gymnastics Scoring System</h2>
            <p>Experience professional gymnastics competitions with real-time scoring and live leaderboards</p>
        </div>

        <div class="page-header">
            <h1 class="page-title">Live Competitions</h1>
            <p class="page-subtitle">Follow active tournaments and organization rankings in real-time</p>
        </div>

        <!-- Live Events -->
        <?php if (!empty($live_events)): ?>
        <div class="section-title">Active Competitions</div>
        
        <div class="tournaments-grid">
            <?php foreach ($live_events as $event): ?>
            <div class="tournament-card">
                <div class="tournament-image">
                    🏆
                </div>
                
                <div class="tournament-content">
                    <div class="tournament-status status-<?php echo $event['status']; ?>">
                        <?php if ($event['status'] === 'active'): ?>
                            <span class="live-dot"></span>LIVE
                        <?php else: ?>
                            UPCOMING
                        <?php endif; ?>
                    </div>
                    
                    <h3 class="tournament-title"><?php echo htmlspecialchars($event['event_name']); ?></h3>
                    
                    <div class="tournament-prize">
                        <?php echo $event['total_participants']; ?> Athletes
                    </div>
                    
                    <div class="tournament-meta">
                        📅 <?php echo date('M d, Y', strtotime($event['event_date'])); ?> • 
                        📍 <?php echo htmlspecialchars($event['location'] ?? 'Location TBA'); ?>
                    </div>

                    <?php if (!empty($event['organizations'])): ?>
                    <div class="leaderboard">
                        <?php 
                        $rank = 1;
                        foreach ($event['organizations'] as $org): 
                        ?>
                        <div class="leaderboard-item">
                            <div class="org-rank">
                                <div class="rank-number rank-<?php echo $rank; ?>"><?php echo $rank; ?></div>
                                <div class="org-name"><?php echo htmlspecialchars($org['org_name']); ?></div>
                            </div>
                            <div class="org-score"><?php echo number_format($org['avg_score'] ?? 0, 2); ?> pts</div>
                        </div>
                        <?php 
                        $rank++;
                        endforeach; 
                        ?>
                    </div>
                    <?php endif; ?>

                    <div class="tournament-footer">
                        <div class="tournament-stats">
                            <span><?php echo $event['org_count']; ?> orgs</span>
                            <span><?php echo $event['total_participants']; ?> athletes</span>
                            <span><?php echo $event['total_scores']; ?> scores</span>
                        </div>
                        
                        <a href="leaderboard.php?event_id=<?php echo $event['event_id']; ?>" class="view-btn">
                            View Live Score
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-events">
            <h3>No Active Competitions</h3>
            <p>Check back soon for upcoming gymnastics tournaments and competitions.</p>
        </div>
        <?php endif; ?>

        <!-- System Statistics -->
        <div class="stats-section">
            <h2 class="section-title">Platform Statistics</h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['total_events']; ?></div>
                    <div class="stat-label">Active Events</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['total_orgs']; ?></div>
                    <div class="stat-label">Organizations</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['total_athletes']; ?></div>
                    <div class="stat-label">Athletes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $stats['total_scores']; ?></div>
                    <div class="stat-label">Total Scores</div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <p>Made by <a href="#">hazimdev</a> • Professional Gymnastics Scoring Platform</p>
        </div>
    </footer>

    <script>
        // Auto-refresh every 30 seconds for live updates
        if (window.location.pathname.includes('index.php') || window.location.pathname === '/') {
            setTimeout(() => {
                window.location.reload();
            }, 30000);
        }

        // Add smooth scroll for navigation
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function(e) {
                // Handle navigation
            });
        });

        // Live status animations
        document.addEventListener('DOMContentLoaded', function() {
            const liveCards = document.querySelectorAll('.status-active');
            liveCards.forEach(card => {
                card.style.animation = 'pulse 2s infinite';
            });
        });
    </script>
</body>
</html>