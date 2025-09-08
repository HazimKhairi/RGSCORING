<?php
require_once 'config/database.php';

startSecureSession();
requireLogin();

$database = new Database();
$conn = $database->getConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gymnastics Scoring System</title>
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
            background: #2c3e50;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .role-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .role-super_admin { background: #e74c3c; color: white; }
        .role-admin { background: #3498db; color: white; }
        .role-judge { background: #f39c12; color: white; }
        .role-user { background: #27ae60; color: white; }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .welcome-section {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            text-align: center;
        }

        .welcome-section h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .welcome-section p {
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid #3498db;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .card-content {
            color: #7f8c8d;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-align: center;
            margin: 0.5rem 0.5rem 0.5rem 0;
        }

        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-primary { background: #3498db; }
        .btn-success { background: #27ae60; }
        .btn-warning { background: #f39c12; }
        .btn-danger { background: #e74c3c; }

        .btn-primary:hover { background: #2980b9; }
        .btn-success:hover { background: #219a52; }
        .btn-warning:hover { background: #d68910; }
        .btn-danger:hover { background: #c0392b; }

        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background: #c0392b;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section h1 {
                font-size: 1.5rem;
            }
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
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                <span class="role-badge role-<?php echo $_SESSION['role']; ?>"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></span>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="welcome-section">
            <h1>Welcome to your Dashboard</h1>
            <p>Access your gymnastics scoring tools based on your role permissions</p>
        </div>

        <div class="dashboard-grid">
            <?php if ($_SESSION['role'] == 'super_admin'): ?>
                <!-- Super Admin Cards -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #e74c3c;">🔧</div>
                        <div class="card-title">System Management</div>
                    </div>
                    <div class="card-content">
                        <p>Full control over the entire system. Manage all users, organizations, and events.</p>
                        <a href="admin/system-management.php" class="btn btn-danger">Manage System</a>
                        <a href="admin/users.php" class="btn btn-primary">Manage Users</a>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #3498db;">🏢</div>
                        <div class="card-title">Organizations</div>
                    </div>
                    <div class="card-content">
                        <p>Create and manage organizations. Assign admins to organizations.</p>
                        <a href="admin/organizations.php" class="btn btn-primary">Manage Organizations</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
                <!-- Admin Cards -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #3498db;">🏆</div>
                        <div class="card-title">Event Management</div>
                    </div>
                    <div class="card-content">
                        <p>Create and manage gymnastics tournaments and competitions.</p>
                        <a href="admin/events.php" class="btn btn-primary">Manage Events</a>
                        <a href="admin/create-event.php" class="btn btn-success">Create Event</a>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #f39c12;">👨‍⚖️</div>
                        <div class="card-title">Judge Management</div>
                    </div>
                    <div class="card-content">
                        <p>Register judges and assign them to specific events and apparatus.</p>
                        <a href="admin/judges.php" class="btn btn-warning">Manage Judges</a>
                        <a href="admin/assign-judges.php" class="btn btn-primary">Assign Judges</a>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #27ae60;">🤸‍♂️</div>
                        <div class="card-title">Athletes & Teams</div>
                    </div>
                    <div class="card-content">
                        <p>Register gymnasts and organize them into teams for competitions.</p>
                        <a href="admin/athletes.php" class="btn btn-success">Manage Athletes</a>
                        <a href="admin/teams.php" class="btn btn-primary">Manage Teams</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($_SESSION['role'] == 'judge'): ?>
                <!-- Judge Cards -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #f39c12;">📝</div>
                        <div class="card-title">Score Entry</div>
                    </div>
                    <div class="card-content">
                        <p>Enter scores for gymnasts in your assigned events and apparatus.</p>
                        <a href="judge/scoring.php" class="btn btn-warning">Enter Scores</a>
                        <a href="judge/my-assignments.php" class="btn btn-primary">My Assignments</a>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon" style="background: #3498db;">📊</div>
                        <div class="card-title">Live Scores</div>
                    </div>
                    <div class="card-content">
                        <p>View live scores and leaderboards for ongoing competitions.</p>
                        <a href="live-scores.php" class="btn btn-primary">View Live Scores</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Common Cards for All Users -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #9b59b6;">🏅</div>
                    <div class="card-title">Live Leaderboard</div>
                </div>
                <div class="card-content">
                    <p>Watch real-time gymnastics competition scores and rankings.</p>
                    <a href="leaderboard.php" class="btn btn-primary">View Leaderboard</a>
                </div>
            </div>

            <?php if (hasRole('admin')): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #34495e;">📈</div>
                    <div class="card-title">Reports & Analytics</div>
                </div>
                <div class="card-content">
                    <p>Generate detailed reports and analytics for competitions.</p>
                    <a href="admin/reports.php" class="btn btn-primary">View Reports</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh for real-time updates
        if (window.location.pathname.includes('leaderboard') || window.location.pathname.includes('live-scores')) {
            setTimeout(() => {
                window.location.reload();
            }, 30000); // Refresh every 30 seconds
        }
    </script>
</body>
</html>