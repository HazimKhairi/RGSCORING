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
            background: #0F0F1A;
            color: white;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* MOBILE-FIRST HEADER */
        .header {
            background: #1A1B23;
            padding: 1rem 0;
            border-bottom: 1px solid #2D2E3F;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
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
        }

        .logo-text {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
        }

        .header-nav {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .nav-item {
            color: #A3A3A3;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .nav-item:hover,
        .nav-item.active {
            color: white;
            background: #2D2E3F;
        }

        .login-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        /* MOBILE MENU TOGGLE */
        .mobile-menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            gap: 4px;
        }

        .mobile-menu-toggle span {
            width: 20px;
            height: 2px;
            background: white;
            transition: 0.3s;
        }

        .mobile-nav {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1A1B23;
            border-top: 1px solid #2D2E3F;
            padding: 1rem;
            flex-direction: column;
            gap: 1rem;
        }

        .mobile-nav.active {
            display: flex;
        }

        /* CONTAINER */
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* HERO SECTION */
        .hero-section {
            text-align: center;
            padding: 2rem 0;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #10B981;
            margin-bottom: 1.5rem;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: #10B981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .hero-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .hero-subtitle {
            color: #A3A3A3;
            font-size: 1rem;
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.9rem 2rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cta-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
        }

        .secondary-btn {
            background: transparent;
            color: white;
            border: 2px solid #2D2E3F;
            padding: 0.85rem 2rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .secondary-btn:hover {
            border-color: #8B5CF6;
            background: rgba(139, 92, 246, 0.1);
        }

        /* STATS SECTION */
        .stats-section {
            background: #1A1B23;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            border: 1px solid #2D2E3F;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: #0F0F1A;
            border-radius: 12px;
            border: 1px solid #2D2E3F;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.5rem;
            display: block;
        }

        .stat-label {
            color: #A3A3A3;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* COMPETITIONS SECTION */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: white;
        }

        .view-all-btn {
            color: #8B5CF6;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .view-all-btn:hover {
            text-decoration: underline;
        }

        /* IMPROVED TOURNAMENT CARDS */
        .tournaments-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .tournament-card {
            background: #1A1B23;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #2D2E3F;
            position: relative;
        }

        .tournament-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            border-color: #8B5CF6;
        }

        .tournament-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            position: relative;
        }

        .tournament-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }

        .tournament-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: block;
        }

        .tournament-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: white;
            line-height: 1.3;
        }

        .tournament-meta {
            color: rgba(255,255,255,0.8);
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .tournament-content {
            padding: 1.5rem;
        }

        .tournament-prize {
            font-size: 1.5rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 1rem;
            text-align: center;
        }

        /* LEADERBOARD */
        .leaderboard {
            margin-bottom: 1.5rem;
        }

        .leaderboard-header {
            font-size: 0.9rem;
            font-weight: 600;
            color: #A3A3A3;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .leaderboard-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #2D2E3F;
        }

        .leaderboard-item:last-child {
            border-bottom: none;
        }

        .org-rank {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }

        .rank-number {
            width: 28px;
            height: 28px;
            background: #2D2E3F;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .rank-1 { background: #F59E0B; color: white; }
        .rank-2 { background: #6B7280; color: white; }
        .rank-3 { background: #CD7C2F; color: white; }

        .org-name {
            font-weight: 500;
            color: white;
            font-size: 0.9rem;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .org-score {
            color: #8B5CF6;
            font-weight: 600;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        /* TOURNAMENT FOOTER */
        .tournament-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #2D2E3F;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .tournament-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: #A3A3A3;
            flex-wrap: wrap;
        }

        .view-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        /* NO EVENTS STATE */
        .no-events {
            text-align: center;
            padding: 3rem 1rem;
            color: #A3A3A3;
            background: #1A1B23;
            border-radius: 16px;
            border: 1px solid #2D2E3F;
        }

        .no-events-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .no-events h3 {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: white;
        }

        .no-events p {
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* FOOTER */
        .footer {
            background: #1A1B23;
            border-top: 1px solid #2D2E3F;
            padding: 2rem 0;
            text-align: center;
            margin-top: 3rem;
        }

        .footer p {
            color: #A3A3A3;
            font-size: 0.85rem;
        }

        .footer a {
            color: #8B5CF6;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* ANIMATIONS */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .tournament-card {
            animation: slideUp 0.6s ease forwards;
        }

        .tournament-card:nth-child(2) { animation-delay: 0.1s; }
        .tournament-card:nth-child(3) { animation-delay: 0.2s; }
        .tournament-card:nth-child(4) { animation-delay: 0.3s; }

        /* TABLET RESPONSIVE */
        @media (min-width: 768px) {
            .container {
                padding: 2rem;
            }

            .hero-title {
                font-size: 3rem;
            }

            .hero-subtitle {
                font-size: 1.1rem;
            }

            .tournaments-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 2rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .stat-number {
                font-size: 2.5rem;
            }

            .tournament-meta {
                flex-direction: row;
                gap: 1rem;
            }
        }

        /* DESKTOP RESPONSIVE */
        @media (min-width: 1024px) {
            .header-content {
                padding: 0 2rem;
            }

            .logo-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .logo-text {
                font-size: 1.5rem;
            }

            .nav-item {
                font-size: 1rem;
                padding: 0.5rem 1rem;
            }

            .login-btn {
                font-size: 1rem;
                padding: 0.75rem 1.5rem;
            }

            .tournaments-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .hero-section {
                padding: 3rem 0;
            }
        }

        /* MOBILE RESPONSIVE */
        @media (max-width: 767px) {
            .header-nav {
                display: none;
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .hero-title {
                font-size: 1.8rem;
            }

            .hero-subtitle {
                font-size: 0.9rem;
            }

            .hero-actions {
                flex-direction: column;
                align-items: center;
            }

            .cta-btn,
            .secondary-btn {
                width: 100%;
                max-width: 280px;
                justify-content: center;
            }

            .tournament-stats {
                font-size: 0.75rem;
                gap: 0.75rem;
            }

            .tournament-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .view-btn {
                width: 100%;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        /* EXTRA SMALL MOBILE */
        @media (max-width: 480px) {
            .container {
                padding: 0.75rem;
            }

            .hero-section {
                padding: 1.5rem 0;
            }

            .hero-title {
                font-size: 1.6rem;
            }

            .tournament-header {
                padding: 1rem;
            }

            .tournament-content {
                padding: 1rem;
            }

            .tournament-title {
                font-size: 1.1rem;
            }

            .org-name {
                font-size: 0.85rem;
            }

            .stat-number {
                font-size: 1.8rem;
            }

            .stat-label {
                font-size: 0.8rem;
            }
        }

        /* PERFORMANCE OPTIMIZATIONS */
        .tournament-card {
            will-change: transform;
        }

        .view-btn {
            will-change: transform;
        }

        /* ACCESSIBILITY IMPROVEMENTS */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* FOCUS STYLES */
        .nav-item:focus,
        .login-btn:focus,
        .view-btn:focus,
        .cta-btn:focus,
        .secondary-btn:focus {
            outline: 2px solid #8B5CF6;
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-text">GymnasticsScore</div>
            </div>
            
            <div class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                <span></span>
                <span></span>
                <span></span>
            </div>
            
            <nav class="header-nav">
                <a href="#" class="nav-item active">Competitions</a>
                <a href="leaderboard.php" class="nav-item">Live Scores</a>
                <a href="login.php" class="login-btn">Login</a>
            </nav>
            
            <nav class="mobile-nav" id="mobileNav">
                <a href="#" class="nav-item active">Competitions</a>
                <a href="leaderboard.php" class="nav-item">Live Scores</a>
                <a href="login.php" class="login-btn">Login</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-badge">
                <div class="live-dot"></div>
                Live Scoring System
            </div>
            
            <h1 class="hero-title">Rhythmic Gymnastics Scoring Platform</h1>
            <p class="hero-subtitle">
                Experience professional gymnastics competitions with real-time scoring, 
                live leaderboards, and comprehensive tournament management
            </p>
            
        </div>


        <!-- Competitions Section -->
        <div class="section-header">
            <h2 class="section-title">Active Competitions</h2>
            <a href="leaderboard.php" class="view-all-btn">View All →</a>
        </div>

        <?php if (!empty($live_events)): ?>
        <div class="tournaments-grid">
            <?php foreach ($live_events as $event): ?>
            <div class="tournament-card">
                <div class="tournament-header">
                    <div class="tournament-status">
                        <?php if ($event['status'] === 'active'): ?>
                            LIVE
                        <?php else: ?>
                            UPCOMING
                        <?php endif; ?>
                    </div>
                    
                    <span class="tournament-icon">🏆</span>
                    <h3 class="tournament-title"><?php echo htmlspecialchars($event['event_name']); ?></h3>
                    
                    <div class="tournament-meta">
                        <span>📅 <?php echo date('M d, Y', strtotime($event['event_date'])); ?></span>
                        <span>📍 <?php echo htmlspecialchars($event['location'] ?? 'Location TBA'); ?></span>
                    </div>
                </div>
                
                <div class="tournament-content">
                    <div class="tournament-prize">
                        <?php echo $event['total_participants']; ?> Athletes Competing
                    </div>

                    <?php if (!empty($event['organizations'])): ?>
                    <div class="leaderboard">
                        <div class="leaderboard-header">Top Organizations</div>
                        <?php 
                        $rank = 1;
                        foreach ($event['organizations'] as $org): 
                        ?>
                        <div class="leaderboard-item">
                            <div class="org-rank">
                                <div class="rank-number rank-<?php echo $rank; ?>"><?php echo $rank; ?></div>
                                <div class="org-name"><?php echo htmlspecialchars($org['org_name']); ?></div>
                            </div>
                            <div class="org-score"><?php echo number_format($org['avg_score'] ?? 0, 2); ?></div>
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
                            View Results
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-events">
            <div class="no-events-icon">🏅</div>
            <h3>No Active Competitions</h3>
            <p>Check back soon for upcoming gymnastics tournaments and competitions.</p>
        </div>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <div class="container">
            <p>Made by <a href="#">hazimdev</a> • Professional Gymnastics Scoring Platform</p>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        function toggleMobileMenu() {
            const mobileNav = document.getElementById('mobileNav');
            mobileNav.classList.toggle('active');
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            const mobileNav = document.getElementById('mobileNav');
            const toggle = document.querySelector('.mobile-menu-toggle');
            
            if (!toggle.contains(e.target) && !mobileNav.contains(e.target)) {
                mobileNav.classList.remove('active');
            }
        });

        // Auto-refresh every 30 seconds for live updates
        if (window.location.pathname.includes('index.php') || window.location.pathname === '/') {
            setTimeout(() => {
                window.location.reload();
            }, 30000);
        }

        // Smooth scroll behavior
        document.documentElement.style.scrollBehavior = 'smooth';

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe tournament cards for animation
        document.querySelectorAll('.tournament-card').forEach(card => {
            observer.observe(card);
        });

        // Touch improvements for mobile
        document.querySelectorAll('.tournament-card').forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            card.addEventListener('touchend', function() {
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });

        // Preload critical resources
        const preloadLink = document.createElement('link');
        preloadLink.rel = 'preload';
        preloadLink.as = 'font';
        preloadLink.href = 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap';
        preloadLink.crossOrigin = 'anonymous';
        document.head.appendChild(preloadLink);
    </script>
</body>
</html>