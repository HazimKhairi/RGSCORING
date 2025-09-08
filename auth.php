<?php
require_once 'config/database.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function login($username, $password) {
        startSecureSession();
        
        $query = "SELECT user_id, username, email, password_hash, full_name, role, organization_id, is_active 
                  FROM users WHERE (username = :username OR email = :username) AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['organization_id'] = $user['organization_id'];
                $_SESSION['login_time'] = time();
                
                return true;
            }
        }
        return false;
    }
    
    public function logout() {
        startSecureSession();
        session_destroy();
        return true;
    }
    
    public function register($username, $email, $password, $full_name, $role = 'user', $organization_id = null) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, email, password_hash, full_name, role, organization_id) 
                  VALUES (:username, :email, :password_hash, :full_name, :role, :organization_id)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':organization_id', $organization_id);
        
        try {
            $stmt->execute();
            return $this->conn->lastInsertId();
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getUsersByRole($role) {
        $query = "SELECT u.*, o.org_name FROM users u 
                  LEFT JOIN organizations o ON u.organization_id = o.org_id 
                  WHERE u.role = :role AND u.is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getOrganizations() {
        $query = "SELECT * FROM organizations ORDER BY org_name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function createOrganization($org_name, $contact_email, $contact_phone, $created_by) {
        $query = "INSERT INTO organizations (org_name, contact_email, contact_phone, created_by) 
                  VALUES (:org_name, :contact_email, :contact_phone, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':org_name', $org_name);
        $stmt->bindParam(':contact_email', $contact_email);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':created_by', $created_by);
        
        try {
            $stmt->execute();
            return $this->conn->lastInsertId();
        } catch (PDOException $e) {
            return false;
        }
    }
}
?>