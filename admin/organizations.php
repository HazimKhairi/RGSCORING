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

// Calculate statistics
$total_organizations = count($organizations);
$total_users = array_sum(array_column($organizations, 'total_users'));
$total_teams = array_sum(array_column($organizations, 'total_teams'));
$total_athletes = array_sum(array_column($organizations, 'total_gymnasts'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations Management - Gymnastics Scoring System</title>
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
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #E2E8F0;
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            margin-bottom: 2rem;
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
            color: white;
        }

        .logo-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1E293B;
        }

        .nav-menu {
            padding: 0 1rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            margin-bottom: 0.25rem;
            border-radius: 10px;
            color: #64748B;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .nav-item:hover {
            background: #F1F5F9;
            color: #334155;
        }

        .nav-item.active {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
        }

        .nav-item.active .nav-icon {
            color: white;
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: #64748B;
        }

        .nav-text {
            font-size: 0.9rem;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 2rem;
            left: 1rem;
            right: 1rem;
        }

        .sign-out-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 10px;
            color: #EF4444;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #FEE2E2;
            background: #FEF2F2;
        }

        .sign-out-btn:hover {
            background: #FEE2E2;
            border-color: #EF4444;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            border-bottom: 1px solid #E2E8F0;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748B;
            font-size: 0.875rem;
        }

        .breadcrumb-separator {
            color: #CBD5E1;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-btn {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
        }

        .content-area {
            padding: 2rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid #E2E8F0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.organizations { background: linear-gradient(135deg, #8B5CF6, #7C3AED); }
        .stat-icon.users { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
        .stat-icon.teams { background: linear-gradient(135deg, #10B981, #059669); }
        .stat-icon.athletes { background: linear-gradient(135deg, #F59E0B, #D97706); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Organizations Section */
        .organizations-section {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
        }

        .section-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #E2E8F0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-weight: 600;
            color: #1E293B;
            font-size: 1.125rem;
        }

        .search-bar {
            position: relative;
        }

        .search-input {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 2px solid #E2E8F0;
            border-radius: 8px;
            font-size: 0.875rem;
            width: 300px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748B;
        }

        /* Edit Form */
        .edit-form-section {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .edit-form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .edit-form-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .form-input {
            padding: 0.75rem;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
        }

        /* Organizations Grid */
        .organizations-grid {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .org-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .org-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: #8B5CF6;
        }

        .org-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .org-info h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 0.25rem;
        }

        .org-meta {
            color: #64748B;
            font-size: 0.875rem;
            margin-bottom: 0.125rem;
        }

        .org-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
        }

        .org-stat {
            text-align: center;
        }

        .org-stat-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: #8B5CF6;
            margin-bottom: 0.25rem;
        }

        .org-stat-label {
            font-size: 0.75rem;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .org-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .btn-primary {
            background: #3B82F6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563EB;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: #F59E0B;
            color: white;
        }

        .btn-warning:hover {
            background: #D97706;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #EF4444;
            color: white;
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6B7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4B5563;
            transform: translateY(-1px);
        }

        /* Detail View */
        .detail-view {
            background: white;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .detail-header {
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .detail-title {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .detail-body {
            padding: 2rem;
        }

        .detail-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-info-item {
            padding: 1rem;
            background: #F8FAFC;
            border-radius: 8px;
        }

        .detail-info-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .detail-info-value {
            color: #1E293B;
            font-size: 0.9rem;
        }

        /* Tabs */
        .tabs-container {
            margin-top: 2rem;
        }

        .tabs-header {
            display: flex;
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
            border-radius: 12px 12px 0 0;
        }

        .tab-button {
            flex: 1;
            padding: 1rem 2rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            color: #64748B;
            transition: all 0.2s ease;
            position: relative;
        }

        .tab-button.active {
            color: #8B5CF6;
            background: white;
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(135deg, #8B5CF6, #A855F7);
        }

        .tab-content {
            display: none;
            padding: 2rem;
            background: white;
            border-radius: 0 0 12px 12px;
        }

        .tab-content.active {
            display: block;
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
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #E2E8F0;
            font-size: 0.875rem;
        }

        .table th {
            background: #F8FAFC;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
        }

        .table tr:hover {
            background: #F8FAFC;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin { background: #3B82F6; color: white; }
        .role-judge { background: #F59E0B; color: white; }
        .role-user { background: #10B981; color: white; }
        .role-super_admin { background: #EF4444; color: white; }

        .status-active { color: #10B981; font-weight: 600; }
        .status-inactive { color: #EF4444; font-weight: 600; }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .alert-error {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        /* Modal */
        .modal {
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1E293B;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .no-organizations {
            text-align: center;
            padding: 3rem 2rem;
            color: #64748B;
        }

        .no-organizations h3 {
            color: #1E293B;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .organizations-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .content-area {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .search-input {
                width: 100%;
            }

            .org-stats {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .tabs-header {
                flex-direction: column;
            }

            .detail-info-grid {
                grid-template-columns: 1fr;
            }
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748B;
            cursor: pointer;
        }

        @media (max-width: 1024px) {
            .mobile-menu-btn {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">🤸</div>
                    <div class="logo-text">GymnasticsScore</div>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="../dashboard.php" class="nav-item">
                    <div class="nav-icon">📊</div>
                    <div class="nav-text">Dashboard</div>
                </a>

                <a href="events.php" class="nav-item">
                    <div class="nav-icon">🏆</div>
                    <div class="nav-text">Events Module</div>
                </a>

                <a href="judges.php" class="nav-item">
                    <div class="nav-icon">👨‍⚖️</div>
                    <div class="nav-text">Judges Module</div>
                </a>

                <a href="athletes.php" class="nav-item">
                    <div class="nav-icon">🤸‍♂️</div>
                    <div class="nav-text">Athletes Module</div>
                </a>

                <a href="teams.php" class="nav-item">
                    <div class="nav-icon">👥</div>
                    <div class="nav-text">Teams Module</div>
                </a>

                <a href="organizations.php" class="nav-item active">
                    <div class="nav-icon">🏢</div>
                    <div class="nav-text">Organizations</div>
                </a>

                <a href="reports.php" class="nav-item">
                    <div class="nav-icon">📈</div>
                    <div class="nav-text">Reports Module</div>
                </a>

                <a href="../leaderboard.php" class="nav-item">
                    <div class="nav-icon">🏅</div>
                    <div class="nav-text">Live Scores</div>
                </a>

                <?php if ($_SESSION['role'] == 'super_admin'): ?>
                <a href="system-management.php" class="nav-item">
                    <div class="nav-icon">⚙️</div>
                    <div class="nav-text">Administration</div>
                </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="sign-out-btn">
                    <div class="nav-icon">🚪</div>
                    <div class="nav-text">Sign Out</div>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">☰</button>
                    <h1>Organizations Management</h1>
                    <div class="breadcrumb">
                        <span>🏠 Home</span>
                        <span class="breadcrumb-separator">›</span>
                        <span>Organizations</span>
                    </div>
                </div>
                <div class="header-right">
                    <button class="header-btn" onclick="openModal('createModal')">
                        🏢 New Organization
                    </button>
                </div>
            </header>

            <div class="content-area">
                <?php if ($message): ?>
                    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($view_org): ?>
                <!-- Organization Detail View -->
                <div class="detail-view">
                    <div class="detail-header">
                        <div class="detail-title"><?php echo htmlspecialchars($view_org['org_name']); ?></div>
                        <div>
                            <a href="organizations.php" class="btn btn-secondary">← Back to List</a>
                            <a href="?edit=<?php echo $view_org['org_id']; ?>" class="btn btn-warning">✏️ Edit</a>
                        </div>
                    </div>
                    <div class="detail-body">
                        <div class="detail-info-grid">
                            <div class="detail-info-item">
                                <div class="detail-info-label">Organization Name</div>
                                <div class="detail-info-value"><?php echo htmlspecialchars($view_org['org_name']); ?></div>
                            </div>
                            <div class="detail-info-item">
                                <div class="detail-info-label">Contact Email</div>
                                <div class="detail-info-value"><?php echo htmlspecialchars($view_org['contact_email'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="detail-info-item">
                                <div class="detail-info-label">Contact Phone</div>
                                <div class="detail-info-value"><?php echo htmlspecialchars($view_org['contact_phone'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="detail-info-item">
                                <div class="detail-info-label">Created By</div>
                                <div class="detail-info-value"><?php echo htmlspecialchars($view_org['creator_name'] ?? 'Unknown'); ?></div>
                            </div>
                            <div class="detail-info-item">
                                <div class="detail-info-label">Created Date</div>
                                <div class="detail-info-value"><?php echo date('M d, Y', strtotime($view_org['created_at'])); ?></div>
                            </div>
                        </div>

                        <!-- Organization Tabs -->
                        <div class="tabs-container">
                            <div class="tabs-header">
                                <button class="tab-button active" onclick="switchTab('users')">
                                    Users (<?php echo count($org_details['users']); ?>)
                                </button>
                                <button class="tab-button" onclick="switchTab('teams')">
                                    Teams (<?php echo count($org_details['teams']); ?>)
                                </button>
                                <button class="tab-button" onclick="switchTab('events')">
                                    Events (<?php echo count($org_details['events']); ?>)
                                </button>
                            </div>

                            <!-- Users Tab -->
                            <div id="users" class="tab-content active">
                                <h3 style="margin-bottom: 1rem;">Organization Users</h3>
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
                                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
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
                                <h3 style="margin-bottom: 1rem;">Organization Teams</h3>
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
                                                        👁️ View Athletes
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
                                <h3 style="margin-bottom: 1rem;">Events Created by Organization</h3>
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
                                                        🏆 Manage
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php else: ?>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon organizations">🏢</div>
                        </div>
                        <div class="stat-number"><?php echo $total_organizations; ?></div>
                        <div class="stat-label">Total Organizations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon users">👥</div>
                        </div>
                        <div class="stat-number"><?php echo $total_users; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon teams">🏃‍♂️</div>
                        </div>
                        <div class="stat-number"><?php echo $total_teams; ?></div>
                        <div class="stat-label">Total Teams</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon athletes">🤸‍♂️</div>
                        </div>
                        <div class="stat-number"><?php echo $total_athletes; ?></div>
                        <div class="stat-label">Total Athletes</div>
                    </div>
                </div>

                <!-- Edit Form -->
                <?php if ($edit_org): ?>
                <div class="edit-form-section">
                    <div class="edit-form-header">
                        <h3 class="edit-form-title">Edit Organization</h3>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="org_id" value="<?php echo $edit_org['org_id']; ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Organization Name *</label>
                                <input type="text" name="org_name" class="form-input" required 
                                       value="<?php echo htmlspecialchars($edit_org['org_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Email</label>
                                <input type="email" name="contact_email" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_org['contact_email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Phone</label>
                                <input type="text" name="contact_phone" class="form-input" 
                                       value="<?php echo htmlspecialchars($edit_org['contact_phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="update_organization" class="btn btn-warning">Update Organization</button>
                            <a href="organizations.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Organizations Section -->
                <div class="organizations-section">
                    <div class="section-header">
                        <h2 class="section-title">All Organizations (<?php echo count($organizations); ?>)</h2>
                        <div class="search-bar">
                            <div class="search-icon">🔍</div>
                            <input type="text" class="search-input" placeholder="Search organizations..." id="orgSearch" onkeyup="filterOrganizations(this.value)">
                        </div>
                    </div>

                    <?php if (!empty($organizations)): ?>
                    <div class="organizations-grid" id="organizationsGrid">
                        <?php foreach ($organizations as $org): ?>
                        <div class="org-card" data-search="<?php echo strtolower($org['org_name'] . ' ' . ($org['contact_email'] ?? '')); ?>">
                            <div class="org-header">
                                <div class="org-info">
                                    <h3><?php echo htmlspecialchars($org['org_name']); ?></h3>
                                    <?php if ($org['contact_email']): ?>
                                        <div class="org-meta">📧 <?php echo htmlspecialchars($org['contact_email']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($org['contact_phone']): ?>
                                        <div class="org-meta">📞 <?php echo htmlspecialchars($org['contact_phone']); ?></div>
                                    <?php endif; ?>
                                    <div class="org-meta">👤 Created by: <?php echo htmlspecialchars($org['creator_name'] ?? 'Unknown'); ?></div>
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
                                <a href="?view=<?php echo $org['org_id']; ?>" class="btn btn-primary">👁️ View Details</a>
                                <a href="?edit=<?php echo $org['org_id']; ?>" class="btn btn-warning">✏️ Edit</a>
                                <?php if ($org['total_users'] == 0 && $org['total_teams'] == 0): ?>
                                    <button onclick="deleteOrg(<?php echo $org['org_id']; ?>)" class="btn btn-danger">🗑️ Delete</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-organizations">
                        <h3>No organizations found</h3>
                        <p>Start by creating your first organization to manage users and teams.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Create Organization Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New Organization</h3>
            </div>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Organization Name *</label>
                        <input type="text" name="org_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="contact_email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-input">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('createModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_organization" class="btn btn-primary">Create Organization</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
            </div>
            <p>Are you sure you want to delete this organization? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="org_id" id="deleteOrgId">
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="delete_organization" class="btn btn-danger">Delete Organization</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function switchTab(tabName) {
            // Remove active class from all tab buttons and contents
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab button and corresponding content
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function filterOrganizations(searchValue) {
            const orgCards = document.querySelectorAll('.org-card');
            const searchTerm = searchValue.toLowerCase();
            
            orgCards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                if (searchData.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function deleteOrg(orgId) {
            document.getElementById('deleteOrgId').value = orgId;
            openModal('deleteModal');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !menuBtn.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>