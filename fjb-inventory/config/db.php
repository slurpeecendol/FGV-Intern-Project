<?php
// ============================================================
// Database Configuration - FJB Inventory System
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Change to your MySQL username
define('DB_PASS', '');           // Change to your MySQL password
define('DB_NAME', 'fjb_inventory');

define('SITE_NAME', 'FJB Inventory System');
define('SITE_SHORT', 'FJB PG');
define('SESSION_TIMEOUT', 3600); // 1 hour

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<div style="font-family:monospace;background:#1a1a2e;color:#e94560;padding:20px;margin:20px;border-radius:8px;">
                <strong>⚠ Database Connection Failed</strong><br><br>
                Error: ' . htmlspecialchars($conn->connect_error) . '<br><br>
                Please check your database settings in <code>config/db.php</code>
            </div>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function logActivity($userId, $action, $itemType, $itemId, $description) {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, item_type, item_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isisss', $userId, $action, $itemType, $itemId, $description, $ip);
    $stmt->execute();
    $stmt->close();
}
?>
