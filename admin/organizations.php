<?php
require_once '../config/database.php';
require_once '../auth.php';

startSecureSession();
requireLogin();
requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle organization operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_organization'])) {
        $org_name = trim($_POST['org_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        
        if (!empty($org_name)) {
            $auth = new Auth();
            $result = $auth->createOrganization($org_name, $contact_email, $contact_phone, $_SESSION['user_id']);
            
            if ($result) {
                $message = "Organization created successfully!";
            } else {
                $error = "Error creating organization. Name may already exist.";
            }
        } else {
            $error = "Organization name is required.";
        }
    }
    
    if (isset($_POST['update_organization'])) {
        $org_id = $_POST['org_id'];
        $org_name = trim($_POST['org_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        
        try {
            $query = "UPDATE organizations SET org_name = :org_name, contact_email = :contact_email, 
                      contact_phone = :contact_phone WHERE org_id = :org_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':org_name', $org_name);
            $stmt->bindParam(':contact_email', $contact_email);
            $stmt->bindParam(':contact_phone', $contact_phone);
            $stmt->bindParam(':org_id', $org_id);
            $stmt->execute();
            
            $message = "Organization updated successfully!";
        } catch (PDOException $e) {
            $error = "Error updating organization: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_organization'])) {
        $org_id = $_POST['org_id'];
        
        try {
            // Check if organization has users
            $check_users = "SELECT COUNT(*) FROM users WHERE organization_id = :org_id";
            $check_stmt = $conn->prepare($check_users);
            $check_stmt->bindParam(':org_id', $org_id);
            $check_stmt->execute();
            $user_count = $check_stmt->fetchColumn();
            
            // Check if organization has teams
            $check_teams = "SELECT COUNT(*) FROM teams WHERE organization_id = :org_id";
            $check_teams_stmt = $conn->prepare($check_teams);
            $check_teams_stmt->bindParam(':org_id', $org_id);
            $check_teams_stmt->execute();
            $team_count = $check_teams_stmt->fetchColumn();
            
            if ($user_count > 0 || $team_count > 0) {
                $error = "Cannot delete organization with existing users or teams.";
            } else {
                $query = "DELETE FROM organizations WHERE org_id = :org_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':org_id', $org_id);
                $stmt->execute();
                
                $message = "Organization deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting organization: " . $e->getMessage();
        }
    }
}

// Get all organizations with statistics
$orgs_query = "SELECT o.*, 
                      u_creator.full_name as creator_name,
                      COUNT(DISTINCT u.user_id) as total_users,
                      COUNT(DISTINCT t.team_id) as total_teams,
                      COUNT(DISTINCT g.gymnast_id) as total_gymnasts,
                      o.created_at
               FROM organizations o
               LEFT JOIN users u_creator ON o.created_by = u_creator.user_id
               LEFT JOIN users u ON o.org_id = u.organization_id AND u.is_active = 1
               LEFT JOIN teams t ON o.org_id = t.organization_id
               LEFT JOIN gymnasts g ON t.team_id = g.team_id
               GROUP BY o.org_id
               ORDER BY o.org_name";

$orgs_stmt = $conn->prepare($orgs_query);
$orgs_stmt->execute();
$organizations = $orgs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get organization for editing
$edit_org = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_query = "SELECT * FROM organizations WHERE org_id = :org_id";
    $edit_stmt = $conn->prepare($edit_query);
    $edit_stmt->bindParam(':org_id', $edit_id);
    $edit_stmt->execute();
    $edit_org = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get detailed organization view
$view_org = null;
$org_details = [];
if (isset($_GET['view'])) {
    $view_id = $_GET['view'];
    
    // Get organization details
    $view_query = "SELECT o.*, u.full_name as creator_name 
                   FROM organizations o 
                   LEFT JOIN users u ON o.created_by = u.user_id 
                   WHERE o.org_id = :org_id";
    $view_stmt = $conn->prepare($view_query);
    $view_stmt->bindParam(':org_id', $view_id);
    $view_stmt->execute();
    $view_org = $view_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($view_org) {
        // Get users in this organization
        $users_query = "SELECT user_id, username, full_name, email, role, created_at, is_active 
                        FROM users WHERE organization_id = :org_id ORDER BY role, full_name";
        $users_stmt = $conn->prepare($users_query);
        $users_stmt->bindParam(':org_id', $view_id);
        $users_stmt->execute();
        $org_details['users'] = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get teams in this organization
        $teams_query = "SELECT t.*, COUNT(g.gymnast_id) as gymnast_count 
                        FROM teams t 
                        LEFT JOIN gymnasts g ON t.team_id = g.team_id 
                        WHERE t.organization_id = :org_id 
                        GROUP BY t.team_id 
                        ORDER BY t.team_name";
        $teams_stmt = $conn->prepare($teams_query);
        $teams_stmt->bindParam(':org_id', $view_id);
        $teams_stmt->execute();
        $org_details['teams'] = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get events created by this organization's admins
        $events_query = "SELECT e.*, u.full_name as creator_name 
                         FROM events e 
                         JOIN users u ON e.created_by = u.user_id 
                         WHERE u.organization_id = :org_id 
                         ORDER BY e.event_date DESC";
        $events_stmt = $conn->prepare($events_query);
        $events_stmt->bindParam(':org_id', $view_id);
        $events_stmt->execute();
        $org_details['events'] = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations Management - Gymnastics Scoring</title>
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
            background: #8e44ad;
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

        .container {
            max-width: 1400px;
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
        .btn-purple { background: #8e44ad; color: white; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #8e44ad 0%, #3498db 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .orgs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .org-card {
            border: 2px solid #e1e8ed;
            border-radius: 15px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            background: white;
        }

        .org-card:hover {
            border-color: #8e44ad;
            box-shadow: 0 5px 15px rgba(142, 68, 173, 0.1);
            transform: translateY(-2px);
        }

        .org-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .org-name {
            font-size: 1.3rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .org-contact {
            color: #7f8c8d;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }

        .org-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .org-stat {
            text-align: center;
        }

        .org-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #8e44ad;
        }

        .org-stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .org-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
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

        .form-group input {
            padding: 0.8rem;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: #8e44ad;
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

        .tabs {
            display: flex;
            background: white;
            border-radius: 15px 15px 0 0;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 0;
        }

        .tab {
            flex: 1;
            padding: 1rem 2rem;
            cursor: pointer;
            background: #f8f9fa;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #666;
        }

        .tab.active {
            background: #8e44ad;
            color: white;
        }

        .tab:hover {
            background: #9b59b6;
            color: white;
        }

        .tab-content {
            display: none;
            background: white;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .orgs-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .org-stats {
                grid-template-columns: 1fr;
            }
            
            .org-actions {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
        }

        .quick-add-form {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            border-left: 4px solid #8e44ad;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Organizations Management</h1>
            <div>
                <a href="events.php" class="btn btn-secondary">Events</a>
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

        <?php if ($view_org): ?>
        <!-- Organization Detail View -->
        <div class="card">
            <div class="card-header">
                Organization Details: <?php echo htmlspecialchars($view_org['org_name']); ?>
                <div style="float: right;">
                    <a href="organizations.php" class="btn btn-secondary btn-small">Back to List</a>
                </div>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div>
                        <strong>Organization Name:</strong><br>
                        <?php echo htmlspecialchars($view_org['org_name']); ?>
                    </div>
                    <div>
                        <strong>Contact Email:</strong><br>
                        <?php echo htmlspecialchars($view_org['contact_email'] ?? 'Not provided'); ?>
                    </div>
                    <div>
                        <strong>Contact Phone:</strong><br>
                        <?php echo htmlspecialchars($view_org['contact_phone'] ?? 'Not provided'); ?>
                    </div>
                    <div>
                        <strong>Created By:</strong><br>
                        <?php echo htmlspecialchars($view_org['creator_name'] ?? 'Unknown'); ?>
                    </div>
                    <div>
                        <strong>Created Date:</strong><br>
                        <?php echo date('M d, Y', strtotime($view_org['created_at'])); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Organization Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('users')">Users (<?php echo count($org_details['users']); ?>)</button>
            <button class="tab" onclick="switchTab('teams')">Teams (<?php echo count($org_details['teams']); ?>)</button>
            <button class="tab" onclick="switchTab('events')">Events (<?php echo count($org_details['events']); ?>)</button>
        </div>

        <!-- Users Tab -->
        <div id="users" class="tab-content active">
            <h3>Organization Users</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($org_details['users'] as $user): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Teams Tab -->
        <div id="teams" class="tab-content">
            <h3>Organization Teams</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Team Name</th>
                            <th>Athletes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($org_details['teams'] as $team): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($team['team_name']); ?></strong></td>
                            <td><?php echo $team['gymnast_count']; ?> athletes</td>
                            <td>
                                <a href="athletes.php?team_id=<?php echo $team['team_id']; ?>" class="btn btn-primary btn-small">
                                    View Athletes
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Events Tab -->
        <div id="events" class="tab-content">
            <h3>Events Created by Organization</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Creator</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($org_details['events'] as $event): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($event['event_name']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                            <td><?php echo ucfirst($event['status']); ?></td>
                            <td><?php echo htmlspecialchars($event['creator_name']); ?></td>
                            <td>
                                <a href="events.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-primary btn-small">
                                    Manage
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($organizations); ?></div>
                <div class="stat-label">Total Organizations</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo array_sum(array_column($organizations, 'total_users')); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo array_sum(array_column($organizations, 'total_teams')); ?></div>
                <div class="stat-label">Total Teams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo array_sum(array_column($organizations, 'total_gymnasts')); ?></div>
                <div class="stat-label">Total Athletes</div>
            </div>
        </div>

        <div class="page-header">
            <h2>Organizations Management</h2>
            <button onclick="openModal('createModal')" class="btn btn-purple">Create Organization</button>
        </div>

        <!-- Quick Add Form -->
        <?php if ($edit_org): ?>
        <div class="quick-add-form">
            <h3>Edit Organization</h3>
            <form method="POST">
                <input type="hidden" name="org_id" value="<?php echo $edit_org['org_id']; ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="org_name">Organization Name *</label>
                        <input type="text" id="org_name" name="org_name" required 
                               value="<?php echo htmlspecialchars($edit_org['org_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="contact_email">Contact Email</label>
                        <input type="email" id="contact_email" name="contact_email" 
                               value="<?php echo htmlspecialchars($edit_org['contact_email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="contact_phone">Contact Phone</label>
                        <input type="text" id="contact_phone" name="contact_phone" 
                               value="<?php echo htmlspecialchars($edit_org['contact_phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="display: flex; align-items: end; gap: 1rem;">
                        <button type="submit" name="update_organization" class="btn btn-warning">Update Organization</button>
                        <a href="organizations.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Organizations Grid -->
        <div class="orgs-grid">
            <?php foreach ($organizations as $org): ?>
            <div class="org-card">
                <div class="org-header">
                    <div>
                        <div class="org-name"><?php echo htmlspecialchars($org['org_name']); ?></div>
                        <div class="org-contact">
                            <?php if ($org['contact_email']): ?>
                                📧 <?php echo htmlspecialchars($org['contact_email']); ?><br>
                            <?php endif; ?>
                            <?php if ($org['contact_phone']): ?>
                                📞 <?php echo htmlspecialchars($org['contact_phone']); ?><br>
                            <?php endif; ?>
                            👤 Created by: <?php echo htmlspecialchars($org['creator_name'] ?? 'Unknown'); ?>
                        </div>
                    </div>
                </div>

                <div class="org-stats">
                    <div class="org-stat">
                        <div class="org-stat-number"><?php echo $org['total_users']; ?></div>
                        <div class="org-stat-label">Users</div>
                    </div>
                    <div class="org-stat">
                        <div class="org-stat-number"><?php echo $org['total_teams']; ?></div>
                        <div class="org-stat-label">Teams</div>
                    </div>
                    <div class="org-stat">
                        <div class="org-stat-number"><?php echo $org['total_gymnasts']; ?></div>
                        <div class="org-stat-label">Athletes</div>
                    </div>
                </div>

                <div class="org-actions">
                    <a href="?view=<?php echo $org['org_id']; ?>" class="btn btn-primary btn-small">View Details</a>
                    <a href="?edit=<?php echo $org['org_id']; ?>" class="btn btn-warning btn-small">Edit</a>
                    <?php if ($org['total_users'] == 0 && $org['total_teams'] == 0): ?>
                        <button onclick="deleteOrg(<?php echo $org['org_id']; ?>)" class="btn btn-danger btn-small">Delete</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>

    <!-- Create Organization Modal -->
    <div id="createModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 10% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 500px;">
            <h3 style="margin-bottom: 1rem;">Create New Organization</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="new_org_name">Organization Name *</label>
                    <input type="text" id="new_org_name" name="org_name" required>
                </div>
                <div class="form-group">
                    <label for="new_contact_email">Contact Email</label>
                    <input type="email" id="new_contact_email" name="contact_email">
                </div>
                <div class="form-group">
                    <label for="new_contact_phone">Contact Phone</label>
                    <input type="text" id="new_contact_phone" name="contact_phone">
                </div>
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="create_organization" class="btn btn-purple">Create Organization</button>
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: white; margin: 15% auto; padding: 2rem; border-radius: 15px; width: 90%; max-width: 400px;">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this organization? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="org_id" id="deleteOrgId">
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button type="submit" name="delete_organization" class="btn btn-danger">Delete Organization</button>
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function deleteOrg(orgId) {
            document.getElementById('deleteOrgId').value = orgId;
            openModal('deleteModal');
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
    </script>
</body>
</html>