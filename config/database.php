<?php
class Database {
    private $host = "localhost";
    private $db_name = "gymnastics_scoring";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}

// Session management
function startSecureSession() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

function hasRole($required_role) {
    if (!isLoggedIn()) return false;
    
    $roles = ['user' => 1, 'judge' => 2, 'admin' => 3, 'super_admin' => 4];
    $user_level = $roles[$_SESSION['role']] ?? 0;
    $required_level = $roles[$required_role] ?? 0;
    
    return $user_level >= $required_level;
}

function requireRole($required_role) {
    if (!hasRole($required_role)) {
        header("HTTP/1.1 403 Forbidden");
        die("Access denied. Required role: " . $required_role);
    }
}
?>