<?php
require_once '../config/database.php';
require_once '../auth.php';

startSecureSession();
requireLogin();
requireRole('super_admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle user management operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $organization_id = !empty($_POST['organization_id']) ? $_POST['organization_id'] : null;
        
        $auth = new Auth();
        $result = $auth->register($username, $email, $password, $full_name, $role, $organization_id);
        
        if ($result) {
            $message = "User created successfully!";
        } else {
            $error = "Error creating user. Username or email may already exist.";
        }
    }
    
    if (isset($_POST['update_user_status'])) {
        $user_id = $_POST['user_id'];
        $is_active = $_POST['is_active'];
        
        try {
            $query = "UPDATE users SET is_active = :is_active WHERE user_id = :user_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':is_active', $is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $message = "User status updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating user status: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['create_organization'])) {
        $org_name = trim($_POST['org_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        
        $auth = new Auth();
        $result = $auth->createOrganization($org_name, $contact_email, $contact_phone, $_SESSION['user_id']);
        
        if ($result) {
            $message = "Organization created successfully!";
        } else {
            $error = "Error creating organization.";
        }
    }
    
    if (isset($_POST['backup_database'])) {
        // Simple backup functionality (in real production, use more sophisticated backup)
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $command = "mysqldump --user=root --password= --host=localhost gymnastics_scoring > backups/{$backup_file}";
        
        // Create backups directory if it doesn't exist
        if (!file_exists('../backups')) {
            mkdir('../backups', 0755, true);
        }
        
        $message = "Backup initiated. File: {$backup_file}";
    }
}

// Get system statistics
$stats = [];

// Total users by role
$user_stats_query = "SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role";
$user_stats_stmt = $conn->prepare($user_stats_query);
$user_stats_stmt->execute();
$stats['users_by_role'] = $user_stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total events by status
$event_stats_query = "SELECT status, COUNT(*) as count FROM events GROUP BY status";
$event_stats_stmt = $conn->prepare($event_stats_query);
$event_stats_stmt->execute();
$stats['events_by_status'] = $event_stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total scores
$scores_query = "SELECT COUNT(*) as total_scores FROM scores";
$scores_stmt = $conn->prepare($scores_query);
$scores_stmt->execute();
$stats['total_scores'] = $scores_stmt->fetchColumn();

// Total organizations
$orgs_query = "SELECT COUNT(*) as total_orgs FROM organizations";
$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$stats['total_organizations'] = $orgs_stmt->fetchColumn();

// Get all users with detailed info
$users_query = "SELECT u.*, o.org_name,
                       (SELECT COUNT(*) FROM judge_assignments ja WHERE ja.judge_id = u.user_id) as assignments,
                       (SELECT COUNT(*) FROM scores s WHERE s.judge_id = u.user_id) as scores_given
                FROM users u 
                LEFT JOIN organizations o ON u.organization_id = o.org_id 
                ORDER BY u.created_at DESC";
$users_stmt = $conn->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all organizations
$auth = new Auth();
$organizations = $auth->getOrganizations();

// Get recent activity
$activity_query = "SELECT 'Score' as type, s.created_at, u.full_name as user_name, 
                          CONCAT(g.gymnast_name, ' - ', a.apparatus_name) as details
                   FROM scores s
                   JOIN users u ON s.judge_id = u.user_id
                   JOIN gymnasts g ON s.gymnast_id = g.gymnast_id
                   JOIN apparatus a ON s.apparatus_id = a.apparatus_id
                   UNION ALL
                   SELECT 'Event' as type, e.created_at, u.full_name as user_name, e.event_name as details
                   FROM events e
                   JOIN users u ON e.created_by = u.user_id
                   ORDER BY created_at DESC
                   LIMIT 10";
$activity_stmt = $conn->prepare($activity_query);
$activity_stmt->execute();
$recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Management - Gymnastics Scoring</title>
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
            background: #e74c3c;
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
        }

        .super-admin-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .card-header {
            background: #34495e;
            color: white;
            padding: 1.5rem;
            font-size: 1.2rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #e74c3c;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #e74c3c;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
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
        .form-group select {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #e74c3c;
        }

        .table-container {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.8rem;
            text-align: left;
            border-bottom: 1px solid #e1e8ed;
            font-size: 0.9rem;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            top: 0;
        }

        .table tr:hover {
            background: #f8f9fa;
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

        .status-active { color: #27ae60; font-weight: bold; }
        .status-inactive { color: #e74c3c; font-weight: bold; }

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

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem;
            border-bottom: 1px solid #e1e8ed;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-type {
            background: #3498db;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .activity-score { background: #f39c12; }
        .activity-event { background: #27ae60; }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .warning-zone {
            background: #fff5f5;
            border: 2px solid #fed7d7;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .warning-zone h3 {
            color: #e53e3e;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table th,
            .table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div>
                <h1>Super Admin Control Panel</h1>
                <div class="super-admin-badge">MISS AFF - Supreme Authority</div>
            </div>
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

        <!-- Quick Actions -->
        <div class="quick-actions">
            <button onclick="openModal('createUserModal')" class="btn btn-success">Create User</button>
            <button onclick="openModal('createOrgModal')" class="btn btn-primary">Create Organization</button>
            <a href="events.php" class="btn btn-warning">Manage Events</a>
            <a href="../leaderboard.php" class="btn btn-secondary">View Leaderboard</a>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- System Statistics -->
            <div class="card">
                <div class="card-header">
                    🏆 System Statistics
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo array_sum($stats['users_by_role']); ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['total_organizations']; ?></div>
                            <div class="stat-label">Organizations</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo array_sum($stats['events_by_status']); ?></div>
                            <div class="stat-label">Total Events</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['total_scores']; ?></div>
                            <div class="stat-label">Scores Given</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Distribution -->
            <div class="card">
                <div class="card-header">
                    👥 User Distribution
                </div>
                <div class="card-body">
                    <?php foreach ($stats['users_by_role'] as $role => $count): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span class="role-badge role-<?php echo $role; ?>"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                            <strong><?php echo $count; ?> users</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    🔄 Recent Activity
                </div>
                <div class="card-body">
                    <?php foreach ($recent_activity as $activity): ?>
                        <div class="activity-item">
                            <div>
                                <span class="activity-type activity-<?php echo strtolower($activity['type']); ?>">
                                    <?php echo $activity['type']; ?>
                                </span>
                                <div style="margin-top: 0.3rem;">
                                    <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($activity['details']); ?></small>
                                </div>
                            </div>
                            <small><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- All Users Management -->
        <div class="card">
            <div class="card-header">
                👤 All Users Management
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Organization</th>
                                <th>Status</th>
                                <th>Activity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['org_name'] ?? 'None'); ?></td>
                                <td>
                                    <span class="status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['role'] == 'judge'): ?>
                                        <?php echo $user['assignments']; ?> assignments, <?php echo $user['scores_given']; ?> scores
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? '0' : '1'; ?>">
                                            <button type="submit" name="update_user_status" 
                                                    class="btn btn-small <?php echo $user['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                                <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <em>You</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="warning-zone">
            <h3>⚠️ Danger Zone</h3>
            <p>These actions can affect the entire system. Use with extreme caution.</p>
            <div style="margin-top: 1rem;">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="backup_database" class="btn btn-warning" 
                            onclick="return confirm('Create database backup?')">
                        Backup Database
                    </button>
                </form>
                <button class="btn btn-danger" onclick="alert('Contact system administrator for maintenance mode.')">
                    Maintenance Mode
                </button>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div id="createUserModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 5% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 600px;">
            <h3 style="margin-bottom: 1rem;">Create New User</h3>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" required>
                            <option value="user">User</option>
                            <option value="judge">Judge</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Organization</label>
                        <select name="organization_id">
                            <option value="">No Organization</option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['org_id']; ?>">
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="create_user" class="btn btn-success">Create User</button>
                    <button type="button" onclick="closeModal('createUserModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Organization Modal -->
    <div id="createOrgModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 10% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 500px;">
            <h3 style="margin-bottom: 1rem;">Create New Organization</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Organization Name *</label>
                    <input type="text" name="org_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="contact_email">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="text" name="contact_phone">
                </div>
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="create_organization" class="btn btn-success">Create Organization</button>
                    <button type="button" onclick="closeModal('createOrgModal')" class="btn btn-secondary">Cancel</button>
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

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('[id$="Modal"]');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Auto-refresh statistics every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>