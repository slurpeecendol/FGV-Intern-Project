<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$file = __DIR__ . '/assets/2026_FJB_WRITTEN_OFF_FORM.xlsx';

if (!file_exists($file)) {
    http_response_code(404);
    die('File not found.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="2026_FJB_WRITTEN_OFF_FORM.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');
readfile($file);
exit;
