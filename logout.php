<?php
require_once 'config/database.php';
require_once 'auth.php';

startSecureSession();

$auth = new Auth();
$auth->logout();

header("Location: login.php?message=logged_out");
exit();
?>