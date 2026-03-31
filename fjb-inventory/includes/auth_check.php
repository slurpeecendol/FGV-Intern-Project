<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . (defined('ROOT') ? ROOT : '') . 'index.php');
    exit;
}


if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: ' . (defined('ROOT') ? ROOT : '') . 'index.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();


function isAdmin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: dashboard.php');
        exit;
    }
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
