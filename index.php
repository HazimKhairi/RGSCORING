<?php
require_once 'config/database.php';

startSecureSession();

// Redirect logged-in users to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Get recent active events for display
$database = new Database();
$conn = $database->getConnection();

$recent_events = [];
try {
    $events_query = "SELECT event_name, event_date, location, status FROM events 
                     WHERE status IN ('active', 'upcoming') 
                     ORDER BY event_date ASC LIMIT 3";
    $events_stmt = $conn->prepare($events_query);
    $events_stmt->execute();
    $recent_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Database not set up yet
}

// Get total system stats if database is available
$stats = ['users' => 0, 'events' => 0, 'scores' => 0];
try {
    $stats_query = "SELECT 
                        (SELECT COUNT(*) FROM users WHERE is_active = 1) as users,
                        (SELECT COUNT(*) FROM events) as events,
                        (SELECT COUNT(*) FROM scores) as scores";
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
    <title>Gymnastics Scoring System</title>
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
            line-height: 1.6;
        }

        .header {
            background: #2c3e50;
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
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

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .nav-links {
            display: flex;
            gap: 1rem;
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

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .hero {
            background: #34495e;
            color: white;
            padding: 4rem 0;
            text-align: center;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .section {
            padding: 4rem 0;
        }

        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-header h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #2c3e50;
        }

        .section-header p {
            font-size: 1.1rem;
            color: #7f8c8d;
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .feature-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: white;
        }

        .feature-card h3 {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: #2c3e50;
        }

        .feature-card p {
            color: #7f8c8d;
            line-height: 1.6;
        }

        .stats-section {
            background: #3498db;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            text-align: center;
        }

        .stat-item {
            padding: 1rem;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .events-section {
            background: white;
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .event-card {
            border: 2px solid #e1e8ed;
            border-radius: 15px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .event-card:hover {
            border-color: #3498db;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.1);
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .event-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .event-status {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-active { background: #27ae60; color: white; }
        .status-upcoming { background: #f39c12; color: white; }

        .event-details {
            color: #7f8c8d;
            margin-bottom: 0.5rem;
        }

        .footer {
            background: #2c3e50;
            color: white;
            padding: 2rem 0;
            text-align: center;
        }

        .footer p {
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .nav-links {
                gap: 0.5rem;
            }
            
            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.8rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .events-grid {
                grid-template-columns: 1fr;
            }
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #e74c3c;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
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
                Gymnastics Scoring System
            </div>
            <div class="nav-links">
                <a href="leaderboard.php" class="btn btn-outline">Live Scores</a>
                <a href="login.php" class="btn btn-primary">Login</a>
            </div>
        </div>
    </header>

    <section class="hero">
        <div class="hero-content">
            <h1>Professional Gymnastics Scoring</h1>
            <p>Complete competition management system with real-time scoring, live leaderboards, and comprehensive event administration.</p>
            <div class="hero-buttons">
                <a href="leaderboard.php" class="btn btn-success">
                    <span class="live-indicator">
                        <span class="live-dot"></span>
                        View Live Scores
                    </span>
                </a>
                <a href="login.php" class="btn btn-primary">Access System</a>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-header">
                <h2>System Features</h2>
                <p>Everything you need to manage gymnastics competitions professionally</p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">🏆</div>
                    <h3>Live Scoring</h3>
                    <p>Real-time score entry with automatic calculations following official gymnastics scoring methods.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3>Dynamic Leaderboards</h3>
                    <p>Auto-refreshing leaderboards that update instantly as judges enter scores during competitions.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">👥</div>
                    <h3>Multi-Role Access</h3>
                    <p>Separate interfaces for super admins, event admins, judges, and spectators with appropriate permissions.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3>Mobile Responsive</h3>
                    <p>Optimized for all devices with mobile-first design prioritizing athlete names and scores.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3>Event Management</h3>
                    <p>Complete tournament administration including athlete registration, judge assignments, and scheduling.</p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <h3>Secure & Reliable</h3>
                    <p>Role-based security, data backup, and reliable hosting compatible with free hosting providers.</p>
                </div>
            </div>
        </div>
    </section>

    <?php if (!empty($recent_events)): ?>
    <section class="section events-section">
        <div class="container">
            <div class="section-header">
                <h2>Upcoming Events</h2>
                <p>Current and upcoming gymnastics competitions</p>
            </div>
            
            <div class="events-grid">
                <?php foreach ($recent_events as $event): ?>
                <div class="event-card">
                    <div class="event-header">
                        <div class="event-title"><?php echo htmlspecialchars($event['event_name']); ?></div>
                        <div class="event-status status-<?php echo $event['status']; ?>">
                            <?php echo ucfirst($event['status']); ?>
                        </div>
                    </div>
                    <div class="event-details">
                        <strong>Date:</strong> <?php echo date('M d, Y', strtotime($event['event_date'])); ?>
                    </div>
                    <div class="event-details">
                        <strong>Location:</strong> <?php echo htmlspecialchars($event['location'] ?? 'TBA'); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Gymnastics Scoring System. Professional competition management platform.</p>
        </div>
    </footer>

    <script>
        // Auto-refresh stats every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>