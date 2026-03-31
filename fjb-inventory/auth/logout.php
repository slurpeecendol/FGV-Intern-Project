<?php
// auth/logout.php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'LOGOUT', 'user', $_SESSION['user_id'], 'User logged out');
}

session_destroy();
header('Location: ../index.php');
exit;
?>
