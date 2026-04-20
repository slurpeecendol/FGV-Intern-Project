<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// Ensure add_asset_requests table exists
$db->query("CREATE TABLE IF NOT EXISTS add_asset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by INT NOT NULL,
    asset_number VARCHAR(50),
    asset_class VARCHAR(50),
    description VARCHAR(255),
    serial_number VARCHAR(100),
    brand VARCHAR(100),
    model VARCHAR(100),
    location VARCHAR(100),
    notes TEXT,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure ewaste_requests table exists
$db->query("CREATE TABLE IF NOT EXISTS ewaste_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('bypass','add') NOT NULL DEFAULT 'bypass',
    requested_by INT NOT NULL,
    inventory_id INT DEFAULT NULL,
    asset_number VARCHAR(50),
    asset_class VARCHAR(50),
    description VARCHAR(255),
    serial_number VARCHAR(100),
    notes TEXT,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure asset_classes table exists and is seeded
$db->query("CREATE TABLE IF NOT EXISTS asset_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$seed_check = $db->query("SELECT COUNT(*) c FROM asset_classes")->fetch_assoc()['c'];
if ($seed_check == 0) {
    $default_classes = ['MONITOR','PC','LAPTOP','PRINTER','SCANNER','UPS','KEYBOARD','MOUSE','OTHER'];
    foreach ($default_classes as $i => $cls) {
        $cls_esc = mysqli_real_escape_string($db, $cls);
        $db->query("INSERT IGNORE INTO asset_classes (name, sort_order) VALUES ('$cls_esc', $i)");
    }
}
// Also seed any classes already in inventory_items not in the table yet
$db->query("INSERT IGNORE INTO asset_classes (name) SELECT DISTINCT asset_class FROM inventory_items WHERE asset_class IS NOT NULL AND asset_class != ''");
$db->query("INSERT IGNORE INTO asset_classes (name) SELECT DISTINCT asset_class FROM ewaste_items WHERE asset_class IS NOT NULL AND asset_class != ''");

// Fetch classes for dropdowns
$_asset_classes_res = $db->query("SELECT ac.name, ag.name as group_name, ag.id as group_id FROM asset_classes ac LEFT JOIN asset_groups ag ON ac.group_id=ag.id ORDER BY ag.sort_order, ag.name, ac.sort_order, ac.name");
$_asset_classes = [];
$_asset_classes_grouped = [];
while ($r = $_asset_classes_res->fetch_assoc()) {
    $_asset_classes[] = $r['name'];
    $grp = $r['group_name'] ?? 'Uncategorised';
    $_asset_classes_grouped[$grp][] = $r['name'];
}
$db->query("CREATE TABLE IF NOT EXISTS delete_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT,
    requested_by INT NOT NULL,
    reason TEXT,
    asset_number VARCHAR(50),
    asset_class VARCHAR(50),
    asset_description VARCHAR(255),
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
)");
// Add snapshot columns if they don't exist yet (for existing installations)
foreach (['asset_number VARCHAR(50)', 'asset_class VARCHAR(50)', 'asset_description VARCHAR(255)'] as $col_def) {
    $col_name = explode(' ', $col_def)[0];
    $col_check = $db->query("SHOW COLUMNS FROM delete_requests LIKE '$col_name'");
    if ($col_check && $col_check->num_rows === 0) {
        $db->query("ALTER TABLE delete_requests ADD COLUMN $col_def NULL");
    }
}
// Make inventory_id nullable if it isn't already
$db->query("ALTER TABLE delete_requests MODIFY COLUMN inventory_id INT NULL");

// Fetch current user's staff ID (stored in department column)
$_current_user = $db->query("SELECT department FROM users WHERE id=".(int)$_SESSION['user_id'])->fetch_assoc();
$_user_staff_id = $_current_user['department'] ?? '';

// ── BULK E-WASTE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'ewaste') {
    $ids      = array_map('intval', $_POST['selected_ids'] ?? []);
    $wo_name  = trim($_POST['modal_wo_name'] ?? '');
    $wo_desig = trim($_POST['modal_wo_designation'] ?? '');
    $wo_date  = $_POST['modal_wo_date'] ?: date('Y-m-d');
    $wo_sig   = $_POST['modal_wo_signature'] ?? '';
    $is_writeoff = !empty($_POST['modal_bulk_writeoff']) && !empty($wo_name);
    $count = 0;
    foreach ($ids as $bid) {
        $item = $db->query("SELECT * FROM inventory_items WHERE id=$bid")->fetch_assoc();
        if ($item) {
            $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$bid")->fetch_assoc();
            if (!$exists) {
                if ($is_writeoff) {
                    $wo_notes = "Write-off authorised by: $wo_name" . ($wo_desig ? ", $wo_desig" : "") . ($wo_sig ? " [Signature captured]" : "");
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,notes,writeoff_name,writeoff_designation,writeoff_date,writeoff_signature,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?,?,?,?,?,?)");
                    $stmt->bind_param('ssssssssssi',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$bid,$wo_notes,$wo_name,$wo_desig,$wo_date,$wo_sig,$_SESSION['user_id']);
                } else {
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?)");
                    $stmt->bind_param('ssssii',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$bid,$_SESSION['user_id']);
                }
                $stmt->execute(); $stmt->close();
            }
            // Keep in inventory — set location to E-Waste, status stays Active
            $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$bid");
            logActivity($_SESSION['user_id'], $is_writeoff ? 'WRITE_OFF' : 'FLAGGED_EWASTE', 'inventory', $bid, ($is_writeoff ? 'Bulk write-off by '.$wo_name.': ' : 'Bulk moved to e-waste: ').$item['description']);
            $count++;
        }
    }
    header('Location: ewaste.php?msg='.($is_writeoff ? 'writeoff' : 'bulk_added').'&count='.$count); exit;
}

// ── BULK DELETE (admin only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    if (!isAdmin()) { header('Location: inventory.php?msg=no_permission'); exit; }
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    $count = 0;
    foreach ($ids as $bid) {
        $item = $db->query("SELECT description FROM inventory_items WHERE id=$bid")->fetch_assoc();
        if ($item) {
            $db->query("DELETE FROM ewaste_items WHERE original_inventory_id=$bid");
            $db->query("DELETE FROM inventory_items WHERE id=$bid");
            logActivity($_SESSION['user_id'], 'DELETE', 'inventory', $bid, 'Bulk deleted: '.$item['description']);
            $count++;
        }
    }
    header('Location: inventory.php?msg=bulk_deleted&count='.$count); exit;
}

// ── DELETE (admin only) ──
if ($action === 'delete' && $id) {
    if (!isAdmin()) { header('Location: inventory.php?msg=no_permission'); exit; }
    $item = $db->query("SELECT description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("DELETE FROM ewaste_items WHERE original_inventory_id=$id");
        $db->query("DELETE FROM inventory_items WHERE id=$id");
        logActivity($_SESSION['user_id'], 'DELETE', 'inventory', $id, 'Deleted asset: '.$item['description']);
        header('Location: inventory.php?msg=deleted'); exit;
    }
}

// ── RETRACT DELETE REQUEST (staff cancels their own pending delete request) ──
if ($action === 'retract_delete' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM delete_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM delete_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','inventory',0,'Retracted delete request');
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── RETRACT ADD ASSET REQUEST (staff cancels their own pending add request) ──
if ($action === 'retract_add' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM add_asset_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM add_asset_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','inventory',0,'Retracted add asset request: '.$req['description']);
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── RETRACT E-WASTE REQUEST (staff cancels their own pending ewaste request) ──
if ($action === 'retract_ew' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM ewaste_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM ewaste_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','ewaste',0,'Retracted e-waste request: '.$req['description']);
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── REQUEST DELETE (user submits delete request) ──
if ($action === 'request_delete' && $id && !isAdmin()) {
    $reason = trim($_POST['reason'] ?? '');
    $item = $db->query("SELECT asset_number, asset_class, description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $existing = $db->query("SELECT id FROM delete_requests WHERE inventory_id=$id AND status='Pending'")->fetch_assoc();
        if (!$existing) {
            $stmt = $db->prepare("INSERT INTO delete_requests (inventory_id, requested_by, reason, asset_number, asset_class, asset_description) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('iissss', $id, $_SESSION['user_id'], $reason, $item['asset_number'], $item['asset_class'], $item['description']);
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'], 'DELETE_REQUEST', 'inventory', $id, 'Requested deletion: '.$item['description']);
        }
        header('Location: inventory.php?msg=delete_requested'); exit;
    }
}

// ── APPROVE DELETE REQUEST (admin) ──
if ($action === 'approve_delete' && $id && isAdmin()) {
    $req = $db->query("SELECT * FROM delete_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        $inv_id = (int)$req['inventory_id'];
        $item = $db->query("SELECT description FROM inventory_items WHERE id=$inv_id")->fetch_assoc();
        $db->query("UPDATE delete_requests SET status='Approved', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW(), inventory_id=NULL WHERE id=$id");
        $db->query("DELETE FROM ewaste_items WHERE original_inventory_id=$inv_id");
        $db->query("DELETE FROM inventory_items WHERE id=$inv_id");
        logActivity($_SESSION['user_id'], 'DELETE', 'inventory', $inv_id, 'Approved delete request: '.($item['description']??''));
        $ref = ($_GET['ref'] ?? '') === 'pending' ? 'inventory.php?view=pending_requests&msg=delete_approved' : 'inventory.php?msg=delete_approved';
        header('Location: '.$ref); exit;
    }
}

// ── REJECT DELETE REQUEST (admin) ──
if ($action === 'reject_delete' && $id && isAdmin()) {
    $req = $db->query("SELECT * FROM delete_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("UPDATE delete_requests SET status='Rejected', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'], 'UPDATE', 'inventory', $req['inventory_id'], 'Rejected delete request');
        $ref = ($_GET['ref'] ?? '') === 'pending' ? 'inventory.php?view=pending_requests&msg=delete_rejected' : 'inventory.php?msg=delete_rejected';
        header('Location: '.$ref); exit;
    }
}

// ── RETRACT ADD ASSET REQUEST (staff) ──
if ($action === 'retract_add' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM add_asset_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM add_asset_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'DELETE','inventory',0,'Retracted add request: '.$req['description']);
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── RETRACT DELETE REQUEST (staff) ──
if ($action === 'retract_delete' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM delete_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM delete_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','inventory',0,'Retracted delete request');
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── RETRACT EWASTE REQUEST (staff) ──
if ($action === 'retract_ew' && $id && !isAdmin()) {
    $req = $db->query("SELECT * FROM ewaste_requests WHERE id=$id AND requested_by={$_SESSION['user_id']} AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("DELETE FROM ewaste_requests WHERE id=$id");
        logActivity($_SESSION['user_id'],'DELETE','ewaste',0,'Retracted e-waste request: '.$req['description']);
        header('Location: inventory.php?view=my_requests&msg=retracted'); exit;
    }
}

// ── REQUEST ADD ASSET (staff) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_add_asset']) && !isAdmin()) {
    $fields = ['asset_number','asset_class','description','serial_number','brand','model','location','notes'];
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    if (empty($data['description']) || empty($data['asset_class'])) {
        $err = 'Description and Asset Class are required.';
    } else {
        // Check for duplicate pending request (same user + description + class)
        $desc_esc  = mysqli_real_escape_string($db, $data['description']);
        $class_esc = mysqli_real_escape_string($db, $data['asset_class']);
        $dup = $db->query("SELECT id FROM add_asset_requests WHERE requested_by={$_SESSION['user_id']} AND description='$desc_esc' AND asset_class='$class_esc' AND status='Pending'")->fetch_assoc();
        if ($dup) {
            $err = 'You already have a pending request to add this asset. Please wait for admin to review it.';
        } else {
            $stmt = $db->prepare("INSERT INTO add_asset_requests (requested_by,asset_number,asset_class,description,serial_number,brand,model,location,notes) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('issssssss',$_SESSION['user_id'],$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$data['brand'],$data['model'],$data['location'],$data['notes']);
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'],'CREATE','inventory',0,'Requested to add asset: '.$data['description']);
            header('Location: inventory.php?msg=add_requested'); exit;
        }
    }
}

// ── APPROVE ADD ASSET REQUEST (admin) ──
if ($action === 'approve_add' && $id && isAdmin()) {
    $req = $db->query("SELECT * FROM add_asset_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        // Insert as real inventory item
        $stmt = $db->prepare("INSERT INTO inventory_items (asset_number,asset_class,description,serial_number,brand,model,location,item_status,condition_status,created_by) VALUES (?,?,?,?,?,?,?,'Active','Good',?)");
        $stmt->bind_param('sssssssi',$req['asset_number'],$req['asset_class'],$req['description'],$req['serial_number'],$req['brand'],$req['model'],$req['location'],$req['requested_by']);
        $stmt->execute(); $new_id = $stmt->insert_id; $stmt->close();
        $db->query("UPDATE add_asset_requests SET status='Approved', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'],'CREATE','inventory',$new_id,'Approved add request: '.$req['description']);
        header('Location: inventory.php?view=pending_requests&msg=add_approved'); exit;
    }
}

// ── REJECT ADD ASSET REQUEST (admin) ──
if ($action === 'reject_add' && $id && isAdmin()) {
    $req = $db->query("SELECT * FROM add_asset_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("UPDATE add_asset_requests SET status='Rejected', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','inventory',0,'Rejected add request: '.$req['description']);
        header('Location: inventory.php?view=pending_requests&msg=add_rejected'); exit;
    }
}

// ── MOVE TO EWASTE via HOD write-off modal (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modal_ewaste_id'])) {
    $mid      = (int)$_POST['modal_ewaste_id'];
    $wo_name  = trim($_POST['modal_wo_name'] ?? '');
    $wo_desig = trim($_POST['modal_wo_designation'] ?? '');
    $wo_date  = $_POST['modal_wo_date'] ?: date('Y-m-d');
    $wo_time  = $_POST['modal_wo_time'] ?? date('H:i');
    $wo_sig   = trim($_POST['modal_wo_signature'] ?? '');
    if ($mid && !empty($wo_name)) {
        $item = $db->query("SELECT * FROM inventory_items WHERE id=$mid")->fetch_assoc();
        if ($item) {
            $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$mid")->fetch_assoc();
            if (!$exists) {
                $wo_notes = "Write-off authorised by: $wo_name" . ($wo_desig ? ", $wo_desig" : "") . " | Staff ID: $wo_sig | Date: $wo_date $wo_time";
                $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,notes,writeoff_name,writeoff_designation,writeoff_date,writeoff_signature,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?,?,?,?,?,?)");
                $stmt->bind_param('ssssssssssi',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$mid,$wo_notes,$wo_name,$wo_desig,$wo_date,$wo_sig,$_SESSION['user_id']);
                $stmt->execute(); $stmt->close();
            }
            // Keep in inventory — set location to E-Waste, status stays Active
            $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$mid");
            logActivity($_SESSION['user_id'],'WRITE_OFF','inventory',$mid,'Write-off to e-waste by '.$wo_name.': '.$item['description']);
            header('Location: ewaste.php?msg=writeoff'); exit;
        }
    }
}

// ── MOVE TO EWASTE (fallback direct action) ──
if ($action === 'ewaste' && $id) {
    $item = $db->query("SELECT * FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$id")->fetch_assoc();
        if (!$exists) {
            $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?)");
            $stmt->bind_param('ssssii', $item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$id,$_SESSION['user_id']);
            $stmt->execute(); $stmt->close();
        }
        // Keep in inventory — set location to E-Waste, status stays Active
        $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'], 'FLAGGED_EWASTE', 'inventory', $id, 'Flagged as e-waste: '.$item['description']);
        header('Location: ewaste.php?msg=added'); exit;
    }
}

// ── BYPASS WRITE-OFF: single item ──
if ($action === 'bypass_ewaste' && $id) {
    $item = $db->query("SELECT * FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        if (isAdmin()) {
            // Admin: direct insert as Approved
            $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$id")->fetch_assoc();
            if (!$exists) {
                $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,created_by) VALUES (?,?,?,?,?,CURDATE(),'Approved',?)");
                $stmt->bind_param('ssssii',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$id,$_SESSION['user_id']);
                $stmt->execute(); $stmt->close();
            }
            $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$id");
            logActivity($_SESSION['user_id'],'FLAGGED_EWASTE','inventory',$id,'Bypassed write-off, sent to E-Waste: '.$item['description']);
            header('Location: ewaste.php?msg=bypass_added'); exit;
        } else {
            // Staff: create a bypass request
            $exists_req = $db->query("SELECT id FROM ewaste_requests WHERE inventory_id=$id AND status='Pending'")->fetch_assoc();
            if (!$exists_req) {
                $stmt = $db->prepare("INSERT INTO ewaste_requests (type,requested_by,inventory_id,asset_number,asset_class,description,serial_number) VALUES ('bypass',?,?,?,?,?,?)");
                $stmt->bind_param('iissss',$_SESSION['user_id'],$id,$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number']);
                $stmt->execute(); $stmt->close();
                logActivity($_SESSION['user_id'],'FLAGGED_EWASTE','inventory',$id,'Requested bypass e-waste: '.$item['description']);
            }
            header('Location: inventory.php?msg=bypass_requested'); exit;
        }
    }
}

// ── BYPASS WRITE-OFF: bulk ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bypass_ewaste') {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    $count = 0;
    foreach ($ids as $bid) {
        $item = $db->query("SELECT * FROM inventory_items WHERE id=$bid")->fetch_assoc();
        if ($item) {
            if (isAdmin()) {
                $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$bid")->fetch_assoc();
                if (!$exists) {
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,created_by) VALUES (?,?,?,?,?,CURDATE(),'Approved',?)");
                    $stmt->bind_param('ssssii',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$bid,$_SESSION['user_id']);
                    $stmt->execute(); $stmt->close();
                }
                $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$bid");
                logActivity($_SESSION['user_id'],'FLAGGED_EWASTE','inventory',$bid,'Bulk bypassed write-off: '.$item['description']);
            } else {
                $exists_req = $db->query("SELECT id FROM ewaste_requests WHERE inventory_id=$bid AND status='Pending'")->fetch_assoc();
                if (!$exists_req) {
                    $stmt = $db->prepare("INSERT INTO ewaste_requests (type,requested_by,inventory_id,asset_number,asset_class,description,serial_number) VALUES ('bypass',?,?,?,?,?,?)");
                    $stmt->bind_param('iissss',$_SESSION['user_id'],$bid,$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number']);
                    $stmt->execute(); $stmt->close();
                    logActivity($_SESSION['user_id'],'FLAGGED_EWASTE','inventory',$bid,'Requested bulk bypass e-waste: '.$item['description']);
                }
            }
            $count++;
        }
    }
    if (isAdmin()) {
        header('Location: ewaste.php?msg=bypass_bulk&count='.$count);
    } else {
        header('Location: inventory.php?msg=bypass_bulk_requested&count='.$count);
    }
    exit;
}

// ── MARK AS DISPOSED (all users) — moves to Disposed, does NOT touch ewaste ──
if ($action === 'dispose' && $id) {
    $item = $db->query("SELECT description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE inventory_items SET item_status='Disposed', updated_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'], 'DISPOSED', 'inventory', $id, 'Marked as disposed: '.$item['description']);
        header('Location: disposed.php?msg=added'); exit;
    }
}

// ── SAVE (ADD: admin only | EDIT: all users) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    // All logged-in users can add and edit assets
    $fields = ['asset_number','asset_class','description','serial_number','brand','model','location','condition_status','notes'];
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    $purchase_date  = !empty($_POST['purchase_date'])  ? $_POST['purchase_date']  : null;
    $purchase_price = !empty($_POST['purchase_price']) ? $_POST['purchase_price'] : null;

    if (empty($data['description']) || empty($data['asset_class'])) {
        $err = 'Description and Asset Class are required.';
    } else {
        $edit_id = (int)($_POST['edit_id'] ?? 0);

        // ── DUPLICATE CHECK ──
        // Check if another asset with same asset_number OR serial_number already exists
        $dup_err = '';
        if (!empty($data['asset_number'])) {
            $an = mysqli_real_escape_string($db, $data['asset_number']);
            $dup = $db->query("SELECT id FROM inventory_items WHERE asset_number='$an' AND id != $edit_id LIMIT 1")->fetch_assoc();
            if ($dup) $dup_err = 'Asset Number <strong>' . h($data['asset_number']) . '</strong> already exists in the system.';
        }
        if (!$dup_err && !empty($data['serial_number'])) {
            $sn = mysqli_real_escape_string($db, $data['serial_number']);
            $dup = $db->query("SELECT id, description FROM inventory_items WHERE serial_number='$sn' AND id != $edit_id LIMIT 1")->fetch_assoc();
            if ($dup) $dup_err = 'Serial Number <strong>' . h($data['serial_number']) . '</strong> is already registered to another asset (' . h($dup['description']) . ').';
        }
        if ($dup_err) {
            $err = $dup_err;
        } elseif ($edit_id) {
            // Preserve existing item_status — controlled by E-Waste flow only
            $current_status = $db->query("SELECT item_status FROM inventory_items WHERE id=$edit_id")->fetch_assoc()['item_status'] ?? 'Active';
            $stmt = $db->prepare("UPDATE inventory_items SET asset_number=?,asset_class=?,description=?,serial_number=?,brand=?,model=?,location=?,condition_status=?,item_status=?,purchase_date=?,purchase_price=?,notes=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ssssssssssdsi',$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$data['brand'],$data['model'],$data['location'],$data['condition_status'],$current_status,$purchase_date,$purchase_price,$data['notes'],$edit_id);
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'],'UPDATE','inventory',$edit_id,'Updated asset: '.$data['description']);

            // ── WRITE-OFF: flag to e-waste if authorised ──
            if (!empty($_POST['writeoff_authorised']) && !empty($_POST['writeoff_name'])) {
                $wo_name  = trim($_POST['writeoff_name']);
                $wo_desig = trim($_POST['writeoff_designation'] ?? '');
                $wo_date  = $_POST['writeoff_date'] ?: date('Y-m-d');
                $wo_time  = $_POST['writeoff_time'] ?? date('H:i');
                $wo_sig   = trim($_POST['writeoff_signature'] ?? '');
                $item     = $db->query("SELECT * FROM inventory_items WHERE id=$edit_id")->fetch_assoc();
                if ($item) {
                    $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$edit_id")->fetch_assoc();
                    if (!$exists) {
                        $wo_notes = "Write-off authorised by: $wo_name" . ($wo_desig ? ", $wo_desig" : "") . " | Staff ID: $wo_sig | Date: $wo_date $wo_time";
                        $stmt2 = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,notes,writeoff_name,writeoff_designation,writeoff_date,writeoff_signature,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?,?,?,?,?,?)");
                        $stmt2->bind_param('ssssssssssi',$item['asset_number'],$item['asset_class'],$item['description'],$item['serial_number'],$edit_id,$wo_notes,$wo_name,$wo_desig,$wo_date,$wo_sig,$_SESSION['user_id']);
                        $stmt2->execute(); $stmt2->close();
                    }
                    // Keep in inventory — set location to E-Waste, status stays Active
                    $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$edit_id");
                    logActivity($_SESSION['user_id'],'WRITE_OFF','inventory',$edit_id,'Write-off to e-waste by '.$wo_name.': '.$item['description']);
                    header('Location: ewaste.php?msg=writeoff'); exit;
                }
            }

            header('Location: inventory.php?msg=updated'); exit;
        } else {
            $stmt = $db->prepare("INSERT INTO inventory_items (asset_number,asset_class,description,serial_number,brand,model,location,condition_status,item_status,purchase_date,purchase_price,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $data['item_status'] = 'Active';
            $stmt->bind_param('ssssssssssdsi',$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$data['brand'],$data['model'],$data['location'],$data['condition_status'],$data['item_status'],$purchase_date,$purchase_price,$data['notes'],$_SESSION['user_id']);
            $stmt->execute(); $new_id = $stmt->insert_id; $stmt->close();
            logActivity($_SESSION['user_id'],'CREATE','inventory',$new_id,'Added asset: '.$data['description']);
            header('Location: inventory.php?msg=added'); exit;
        }
    } // end duplicate check passed
}

// Load item for edit (all users can edit)
$edit_item = null;
if ($action === 'edit' && $id) {
    $edit_item = $db->query("SELECT * FROM inventory_items WHERE id=$id")->fetch_assoc();
}

// Fetch all for list — with optional filters
$f_search   = trim($_GET['search'] ?? '');
$f_class    = trim($_GET['class'] ?? '');
$f_status   = trim($_GET['status'] ?? '');
$f_location = trim($_GET['location'] ?? '');

$where = ['1=1'];
if ($f_search)   $where[] = "(inv.asset_number LIKE '%".mysqli_real_escape_string($db,$f_search)."%' OR inv.description LIKE '%".mysqli_real_escape_string($db,$f_search)."%' OR inv.serial_number LIKE '%".mysqli_real_escape_string($db,$f_search)."%')";
if ($f_class)    $where[] = "inv.asset_class='".mysqli_real_escape_string($db,$f_class)."'";
if ($f_status === 'Collected') {
    $where[] = "(inv.item_status='Collected' OR ew.disposal_status='Collected')";
} elseif ($f_status) {
    $where[] = "inv.item_status='".mysqli_real_escape_string($db,$f_status)."'";
}
if ($f_location) $where[] = "inv.location LIKE '%".mysqli_real_escape_string($db,$f_location)."%'";

$where_sql = implode(' AND ', $where);
$simple_where = str_replace('inv.', '', $where_sql);

// Count query — needs JOIN too when filtering by Collected (references ew.disposal_status)
if ($f_status === 'Collected') {
    $total_count = $db->query("SELECT COUNT(DISTINCT inv.id) c FROM inventory_items inv LEFT JOIN ewaste_items ew ON ew.original_inventory_id=inv.id WHERE $where_sql")->fetch_assoc()['c'];
} else {
    $total_count = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE $simple_where")->fetch_assoc()['c'];
}

// Check if delete_requests table exists, then build query accordingly
$dr_table_exists = $db->query("SHOW TABLES LIKE 'delete_requests'")->num_rows > 0;
if ($dr_table_exists) {
    $items = $db->query("SELECT DISTINCT inv.*, ew.disposal_status as ew_status, dr.id as del_req_id FROM inventory_items inv LEFT JOIN ewaste_items ew ON ew.original_inventory_id=inv.id LEFT JOIN delete_requests dr ON dr.inventory_id=inv.id AND dr.status='Pending' WHERE $where_sql ORDER BY inv.asset_class, inv.created_at DESC");
} else {
    $items = $db->query("SELECT DISTINCT inv.*, ew.disposal_status as ew_status, NULL as del_req_id FROM inventory_items inv LEFT JOIN ewaste_items ew ON ew.original_inventory_id=inv.id WHERE $where_sql ORDER BY inv.asset_class, inv.created_at DESC");
}
// Get distinct classes and locations for dropdowns
$all_classes   = $db->query("SELECT DISTINCT asset_class FROM inventory_items ORDER BY asset_class");
$all_locations = $db->query("SELECT DISTINCT location FROM inventory_items WHERE location IS NOT NULL AND location != '' ORDER BY location");

$view = $_GET['view'] ?? '';
$page_title = $view === 'pending_requests' ? 'Pending Requests' : ($view === 'my_requests' ? 'My Requests' : 'IT Assets');
$active_nav  = $view === 'pending_requests' ? 'inventory_pending' : ($view === 'my_requests' ? 'inventory_my_requests' : 'inventory');

// Message from redirect
$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')          $msg = 'Asset added successfully.';
if ($url_msg === 'updated')        $msg = 'Asset updated successfully.';
if ($url_msg === 'deleted')        $msg = 'Asset deleted.';
if ($url_msg === 'restored')       $msg = 'Item successfully restored to IT Assets.';
if ($url_msg === 'bulk_deleted')   $msg = ($_GET['count'] ?? 0).' asset(s) permanently deleted.';
if ($url_msg === 'delete_requested') $msg = 'Delete request submitted. Awaiting admin approval.';
if ($url_msg === 'delete_approved')  $msg = 'Delete request approved. Asset has been removed.';
if ($url_msg === 'delete_rejected')  $msg = 'Delete request rejected.';
if ($url_msg === 'add_requested')    $msg = 'Add request submitted. Awaiting admin approval.';
if ($url_msg === 'add_approved')     $msg = 'Add request approved. Asset has been added to IT Assets.';
if ($url_msg === 'add_rejected')     $msg = 'Add request rejected.';
if ($url_msg === 'bypass_requested') $msg = 'Bypass E-Waste request submitted. Awaiting admin approval.';
if ($url_msg === 'bypass_bulk_requested') $msg = ($_GET['count'] ?? 0).' bypass E-Waste request(s) submitted. Awaiting admin approval.';
if ($url_msg === 'retracted')            $msg = 'Request retracted successfully.';
if ($url_msg === 'ew_req_approved')  $msg = 'E-Waste request approved.';
if ($url_msg === 'ew_req_rejected')  $msg = 'E-Waste request rejected.';
if ($url_msg === 'retracted')        $msg = 'Request retracted successfully.';
if ($url_msg === 'no_permission')    $err = 'You do not have permission to perform that action.';


require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= $err ?></div><?php endif; ?>

<?php if ($view === 'pending_requests' && isAdmin()):
  $pending_del = $db->query("SELECT dr.*, inv.asset_number, inv.description, inv.asset_class, u.full_name as requester FROM delete_requests dr JOIN inventory_items inv ON inv.id=dr.inventory_id JOIN users u ON u.id=dr.requested_by WHERE dr.status='Pending' ORDER BY dr.created_at DESC");
  $pending_del_count = $pending_del->num_rows;
  $pending_add = $db->query("SELECT aar.*, u.full_name as requester FROM add_asset_requests aar JOIN users u ON aar.requested_by=u.id WHERE aar.status='Pending' ORDER BY aar.created_at DESC");
  $pending_add_count = $pending_add->num_rows;
  $pending_ew = $db->query("SELECT er.*, u.full_name as requester FROM ewaste_requests er JOIN users u ON er.requested_by=u.id WHERE er.status='Pending' ORDER BY er.created_at DESC");
  $pending_ew_count = $pending_ew ? $pending_ew->num_rows : 0;
  $total_pending = $pending_add_count + $pending_ew_count + $pending_del_count;
?>
<style>
.req-page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.req-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:28px}
.req-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 18px;display:flex;align-items:center;gap:12px}
.req-stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.req-stat-val{font-size:24px;font-weight:800;color:var(--text);line-height:1}
.req-stat-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.req-section{margin-bottom:20px}
.req-section-head{background:#1a2332;border-radius:10px 10px 0 0;padding:12px 20px;display:flex;align-items:center;justify-content:space-between}
.req-section-title{display:flex;align-items:center;gap:10px;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:#fff}
.req-section-stripe{width:3px;height:18px;border-radius:2px;flex-shrink:0}
.req-section-count{font-size:11px;font-weight:700;border-radius:20px;padding:3px 10px}
.req-card-body{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 10px 10px}
.req-row{display:grid;align-items:center;padding:14px 20px;border-bottom:1px solid var(--border);gap:12px}
.req-row:last-child{border-bottom:none}
.req-row-add{grid-template-columns:2fr 1fr 1fr 1fr auto}
.req-row-del{grid-template-columns:2fr 1fr 1fr 1fr auto}
.req-row-ew{grid-template-columns:100px 2fr 1fr 1fr auto}
.req-col-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);padding:10px 20px;background:var(--body-bg);border-bottom:1px solid var(--border);display:grid;gap:12px}
.req-desc{font-size:13px;font-weight:700;color:var(--text);line-height:1.3}
.req-sub{font-size:11px;color:var(--muted);margin-top:2px}
.req-sub-accent{font-size:11px;color:var(--accent);font-weight:600;margin-top:2px}
.req-class{display:inline-block;background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700}
.req-by{font-size:13px;font-weight:600;color:var(--text)}
.req-date{font-size:11px;color:var(--muted)}
.req-empty{padding:36px;text-align:center;color:var(--muted);font-size:13px}
.req-approve{display:inline-flex;align-items:center;gap:5px;background:#16a34a;color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}
.req-approve-amber{background:#d97706}
.req-approve-red{background:#dc2626}
.req-reject{display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);color:var(--muted);border:1.5px solid var(--border);border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}
.type-badge{display:inline-flex;align-items:center;gap:5px;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700}
</style>

<!-- PAGE HEADER -->
<div class="req-page-header">
  <div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">IT Assets &rsaquo; <span style="color:var(--accent)">Pending Requests</span></div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Pending Requests</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Review and approve or reject staff requests</p>
  </div>
</div>

<!-- STAT STRIP -->
<div class="req-stats">
  <div class="req-stat" style="border-left:4px solid var(--accent)">
    <div class="req-stat-icon" style="background:rgba(242,140,40,.1)"><i class="bi bi-clock-history" style="color:var(--accent)"></i></div>
    <div><div class="req-stat-val"><?= $total_pending ?></div><div class="req-stat-lbl">Total Pending</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #16a34a">
    <div class="req-stat-icon" style="background:rgba(22,163,74,.1)"><i class="bi bi-plus-circle-fill" style="color:#16a34a"></i></div>
    <div><div class="req-stat-val"><?= $pending_add_count ?></div><div class="req-stat-lbl">Add Asset</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #d97706">
    <div class="req-stat-icon" style="background:rgba(217,119,6,.1)"><i class="bi bi-recycle" style="color:#d97706"></i></div>
    <div><div class="req-stat-val"><?= $pending_ew_count ?></div><div class="req-stat-lbl">E-Waste</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #dc2626">
    <div class="req-stat-icon" style="background:rgba(239,68,68,.1)"><i class="bi bi-trash-fill" style="color:#dc2626"></i></div>
    <div><div class="req-stat-val"><?= $pending_del_count ?></div><div class="req-stat-lbl">Delete</div></div>
  </div>
</div>

<!-- SECTION 1: ADD ASSET REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#16a34a"></div>
      <i class="bi bi-plus-circle-fill" style="color:#4ade80;font-size:15px"></i> Add Asset Requests
    </div>
    <span class="req-section-count" style="background:rgba(22,163,74,.2);color:#4ade80"><?= $pending_add_count ?> pending</span>
  </div>
  <div class="req-card-body">
    <?php if ($pending_add_count === 0): ?>
    <div class="req-empty"><i class="bi bi-check2-circle" style="font-size:28px;display:block;margin-bottom:8px;color:#16a34a;opacity:.5"></i>No pending add requests</div>
    <?php else: ?>
    <div class="req-col-label req-row-add">
      <span>Asset Details</span><span>Class</span><span>Requested By</span><span>Date Submitted</span><span>Action</span>
    </div>
    <?php while ($req = $pending_add->fetch_assoc()): ?>
    <div class="req-row req-row-add">
      <div>
        <div class="req-desc"><?= h($req['description']) ?></div>
        <?php if ($req['asset_number']): ?><div class="req-sub-accent"><?= h($req['asset_number']) ?></div><?php endif; ?>
        <?php if ($req['brand'] || $req['model']): ?><div class="req-sub"><?= h(trim($req['brand'].' '.$req['model'])) ?></div><?php endif; ?>
      </div>
      <div><span class="req-class"><?= h($req['asset_class']) ?></span></div>
      <div>
        <div class="req-by"><?= h($req['requester']) ?></div>
        <?php if ($req['serial_number']): ?><div class="req-sub">S/N: <?= h($req['serial_number']) ?></div><?php endif; ?>
      </div>
      <div><div class="req-date"><?= date('d M Y', strtotime($req['created_at'])) ?></div><div class="req-date"><?= date('H:i', strtotime($req['created_at'])) ?></div></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="inventory.php?action=approve_add&id=<?= $req['id'] ?>" onclick="return confirm('Approve and add this asset to IT Assets?')" class="req-approve"><i class="bi bi-check-lg"></i> Approve</a>
        <a href="inventory.php?action=reject_add&id=<?= $req['id'] ?>" class="req-reject"><i class="bi bi-x-lg"></i> Reject</a>
      </div>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>
  </div>
</div>

<!-- SECTION 2: E-WASTE REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#d97706"></div>
      <i class="bi bi-recycle" style="color:#fbbf24;font-size:15px"></i> E-Waste Requests
    </div>
    <span class="req-section-count" style="background:rgba(217,119,6,.2);color:#fbbf24"><?= $pending_ew_count ?> pending</span>
  </div>
  <div class="req-card-body">
    <?php if ($pending_ew_count === 0): ?>
    <div class="req-empty"><i class="bi bi-check2-circle" style="font-size:28px;display:block;margin-bottom:8px;color:#d97706;opacity:.5"></i>No pending e-waste requests</div>
    <?php else: ?>
    <div class="req-col-label req-row-ew">
      <span>Type</span><span>Asset Details</span><span>Requested By</span><span>Date Submitted</span><span>Action</span>
    </div>
    <?php while ($req = $pending_ew->fetch_assoc()): ?>
    <div class="req-row req-row-ew">
      <div>
        <?php if ($req['type'] === 'bypass'): ?>
        <span class="type-badge" style="background:rgba(217,119,6,.12);color:#d97706"><i class="bi bi-skip-forward-fill"></i> Bypass</span>
        <?php else: ?>
        <span class="type-badge" style="background:rgba(22,163,74,.12);color:#16a34a"><i class="bi bi-plus-circle-fill"></i> Add Item</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="req-desc"><?= h($req['description']) ?></div>
        <?php if ($req['asset_number']): ?><div class="req-sub-accent"><?= h($req['asset_number']) ?></div><?php endif; ?>
        <div><span class="req-class" style="margin-top:3px;display:inline-block"><?= h($req['asset_class']) ?></span></div>
      </div>
      <div>
        <div class="req-by"><?= h($req['requester']) ?></div>
        <?php if ($req['serial_number']): ?><div class="req-sub">S/N: <?= h($req['serial_number']) ?></div><?php endif; ?>
      </div>
      <div><div class="req-date"><?= date('d M Y', strtotime($req['created_at'])) ?></div><div class="req-date"><?= date('H:i', strtotime($req['created_at'])) ?></div></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="ewaste.php?action=approve_ew_req&id=<?= $req['id'] ?>" onclick="return confirm('Approve this e-waste request?')" class="req-approve req-approve-amber"><i class="bi bi-check-lg"></i> Approve</a>
        <a href="ewaste.php?action=reject_ew_req&id=<?= $req['id'] ?>" class="req-reject"><i class="bi bi-x-lg"></i> Reject</a>
      </div>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>
  </div>
</div>

<!-- SECTION 3: DELETE REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#dc2626"></div>
      <i class="bi bi-trash-fill" style="color:#f87171;font-size:15px"></i> Delete Requests
    </div>
    <span class="req-section-count" style="background:rgba(239,68,68,.2);color:#f87171"><?= $pending_del_count ?> pending</span>
  </div>
  <div class="req-card-body">
    <?php if ($pending_del_count === 0): ?>
    <div class="req-empty"><i class="bi bi-check2-circle" style="font-size:28px;display:block;margin-bottom:8px;color:#dc2626;opacity:.5"></i>No pending delete requests</div>
    <?php else: ?>
    <div class="req-col-label req-row-del">
      <span>Asset Details</span><span>Class</span><span>Requested By</span><span>Reason / Date</span><span>Action</span>
    </div>
    <?php while ($req = $pending_del->fetch_assoc()): ?>
    <div class="req-row req-row-del">
      <div>
        <div class="req-desc"><?= h($req['description']) ?></div>
        <div class="req-sub-accent"><?= h($req['asset_number'] ?: '—') ?></div>
      </div>
      <div><span class="req-class"><?= h($req['asset_class']) ?></span></div>
      <div><div class="req-by"><?= h($req['requester']) ?></div></div>
      <div>
        <?php if ($req['reason']): ?><div class="req-sub" style="color:var(--text)"><?= h($req['reason']) ?></div><?php endif; ?>
        <div class="req-date"><?= date('d M Y H:i', strtotime($req['created_at'])) ?></div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="inventory.php?action=approve_delete&id=<?= $req['id'] ?>&ref=pending" onclick="return confirm('Approve and permanently delete this asset?')" class="req-approve req-approve-red"><i class="bi bi-check-lg"></i> Approve</a>
        <a href="inventory.php?action=reject_delete&id=<?= $req['id'] ?>&ref=pending" class="req-reject"><i class="bi bi-x-lg"></i> Reject</a>
      </div>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>
  </div>
</div>
<?php require_once 'includes/layout_end.php'; ?>
<?php exit; endif; ?>

<?php if ($view === 'my_requests' && !isAdmin()):
  $my_del = $db->query("SELECT dr.*, COALESCE(inv.asset_number, dr.asset_number, '—') as asset_number, COALESCE(inv.description, dr.asset_description, '(Asset removed)') as description, COALESCE(inv.asset_class, dr.asset_class, '') as asset_class FROM delete_requests dr LEFT JOIN inventory_items inv ON inv.id=dr.inventory_id WHERE dr.requested_by={$_SESSION['user_id']} ORDER BY dr.created_at DESC");
  $my_del_count = $my_del->num_rows;
  $my_add = $db->query("SELECT * FROM add_asset_requests WHERE requested_by={$_SESSION['user_id']} ORDER BY created_at DESC");
  $my_add_count = $my_add->num_rows;
  $my_ew = $db->query("SELECT * FROM ewaste_requests WHERE requested_by={$_SESSION['user_id']} ORDER BY created_at DESC");
  $my_ew_count = $my_ew ? $my_ew->num_rows : 0;
  $total_my = $my_add_count + $my_ew_count + $my_del_count;
  $my_pending = 0;
  // Count pending ones for badge
  $tmp = $db->query("SELECT COUNT(*) c FROM add_asset_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'"); $my_pending += (int)$tmp->fetch_assoc()['c'];
  $tmp = $db->query("SELECT COUNT(*) c FROM delete_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'"); $my_pending += (int)$tmp->fetch_assoc()['c'];
  $tmp = $db->query("SELECT COUNT(*) c FROM ewaste_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'"); $my_pending += (int)$tmp->fetch_assoc()['c'];
?>
<style>
.req-page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.req-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:28px}
.req-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 18px;display:flex;align-items:center;gap:12px}
.req-stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.req-stat-val{font-size:24px;font-weight:800;color:var(--text);line-height:1}
.req-stat-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.req-section{margin-bottom:20px}
.req-section-head{background:#1a2332;border-radius:10px 10px 0 0;padding:12px 20px;display:flex;align-items:center;justify-content:space-between}
.req-section-title{display:flex;align-items:center;gap:10px;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:#fff}
.req-section-stripe{width:3px;height:18px;border-radius:2px;flex-shrink:0}
.req-section-count{font-size:11px;font-weight:700;border-radius:20px;padding:3px 10px}
.req-card-body{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 10px 10px}
.req-item{display:flex;align-items:center;padding:14px 20px;border-bottom:1px solid var(--border);gap:16px}
.req-item:last-child{border-bottom:none}
.req-item-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.req-item-body{flex:1;min-width:0}
.req-item-title{font-size:13px;font-weight:700;color:var(--text)}
.req-item-meta{display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap}
.req-class{display:inline-block;background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:1px 7px;font-size:10px;font-weight:700}
.req-meta-text{font-size:11px;color:var(--muted)}
.req-status{display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:5px 14px;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0}
.req-date-col{font-size:11px;color:var(--muted);text-align:right;flex-shrink:0;line-height:1.5;min-width:80px}
.req-empty{padding:36px;text-align:center;color:var(--muted);font-size:13px}
.type-badge{display:inline-flex;align-items:center;gap:5px;border-radius:6px;padding:3px 9px;font-size:10px;font-weight:700}
</style>

<!-- PAGE HEADER -->
<div class="req-page-header">
  <div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">IT Assets &rsaquo; <span style="color:var(--accent)">My Requests</span></div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">My Requests</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Track the status of your submitted requests</p>
  </div>
</div>

<!-- STAT STRIP -->
<div class="req-stats">
  <div class="req-stat" style="border-left:4px solid var(--accent)">
    <div class="req-stat-icon" style="background:rgba(242,140,40,.1)"><i class="bi bi-list-check" style="color:var(--accent)"></i></div>
    <div><div class="req-stat-val"><?= $total_my ?></div><div class="req-stat-lbl">Total Requests</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #16a34a">
    <div class="req-stat-icon" style="background:rgba(22,163,74,.1)"><i class="bi bi-plus-circle-fill" style="color:#16a34a"></i></div>
    <div><div class="req-stat-val"><?= $my_add_count ?></div><div class="req-stat-lbl">Add Asset</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #d97706">
    <div class="req-stat-icon" style="background:rgba(217,119,6,.1)"><i class="bi bi-recycle" style="color:#d97706"></i></div>
    <div><div class="req-stat-val"><?= $my_ew_count ?></div><div class="req-stat-lbl">E-Waste</div></div>
  </div>
  <div class="req-stat" style="border-left:4px solid #dc2626">
    <div class="req-stat-icon" style="background:rgba(239,68,68,.1)"><i class="bi bi-trash-fill" style="color:#dc2626"></i></div>
    <div><div class="req-stat-val"><?= $my_del_count ?></div><div class="req-stat-lbl">Delete</div></div>
  </div>
</div>

<?php
// Helper to render status badge
function reqStatusBadge($s) {
  $c = $s==='Pending' ? ['#d97706','rgba(217,119,6,.12)','hourglass-split'] : ($s==='Approved' ? ['#16a34a','rgba(22,163,74,.12)','check-circle-fill'] : ['#dc2626','rgba(239,68,68,.12)','x-circle-fill']);
  return "<span class=\"req-status\" style=\"background:{$c[1]};color:{$c[0]}\"><i class=\"bi bi-{$c[2]}\"></i> $s</span>";
}
?>

<!-- SECTION 1: ADD ASSET REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#16a34a"></div>
      <i class="bi bi-plus-circle-fill" style="color:#4ade80;font-size:15px"></i> Add Asset Requests
    </div>
    <span class="req-section-count" style="background:rgba(22,163,74,.2);color:#4ade80"><?= $my_add_count ?> total</span>
  </div>
  <div class="req-card-body">
    <?php if ($my_add_count === 0): ?>
    <div class="req-empty"><i class="bi bi-box-seam" style="font-size:28px;display:block;margin-bottom:8px;color:#16a34a;opacity:.5"></i>No add asset requests yet</div>
    <?php else: while ($req = $my_add->fetch_assoc()): ?>
    <div class="req-item">
      <div class="req-item-icon" style="background:rgba(22,163,74,.1)"><i class="bi bi-plus-circle" style="color:#16a34a"></i></div>
      <div class="req-item-body">
        <div class="req-item-title"><?= h($req['description']) ?></div>
        <div class="req-item-meta">
          <span class="req-class"><?= h($req['asset_class']) ?></span>
          <?php if ($req['asset_number']): ?><span class="req-meta-text" style="color:var(--accent);font-weight:600"><?= h($req['asset_number']) ?></span><?php endif; ?>
          <?php if ($req['serial_number']): ?><span class="req-meta-text">S/N: <?= h($req['serial_number']) ?></span><?php endif; ?>
          <?php if ($req['brand'] || $req['model']): ?><span class="req-meta-text"><?= h(trim($req['brand'].' '.$req['model'])) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="req-date-col"><?= date('d M Y', strtotime($req['created_at'])) ?><br><?= date('H:i', strtotime($req['created_at'])) ?></div>
      <?= reqStatusBadge($req['status']) ?>
      <?php if ($req['status'] === 'Pending'): ?>
      <a href="inventory.php?action=retract_add&id=<?= $req['id'] ?>" onclick="return confirm('Retract this add request?')"
        style="display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);color:var(--muted);border:1.5px solid var(--border);border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0">
        <i class="bi bi-arrow-counterclockwise"></i> Retract
      </a>
      <?php endif; ?>
    </div>
    <?php endwhile; endif; ?>
  </div>
</div>

<!-- SECTION 2: E-WASTE REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#d97706"></div>
      <i class="bi bi-recycle" style="color:#fbbf24;font-size:15px"></i> E-Waste Requests
    </div>
    <span class="req-section-count" style="background:rgba(217,119,6,.2);color:#fbbf24"><?= $my_ew_count ?> total</span>
  </div>
  <div class="req-card-body">
    <?php if ($my_ew_count === 0): ?>
    <div class="req-empty"><i class="bi bi-recycle" style="font-size:28px;display:block;margin-bottom:8px;color:#d97706;opacity:.5"></i>No e-waste requests yet</div>
    <?php else: while ($req = $my_ew->fetch_assoc()): ?>
    <div class="req-item">
      <div class="req-item-icon" style="background:rgba(217,119,6,.1)"><i class="bi bi-recycle" style="color:#d97706"></i></div>
      <div class="req-item-body">
        <div class="req-item-meta" style="margin-bottom:4px">
          <?php if ($req['type'] === 'bypass'): ?>
          <span class="type-badge" style="background:rgba(217,119,6,.12);color:#d97706"><i class="bi bi-skip-forward-fill"></i> Bypass Write-Off</span>
          <?php else: ?>
          <span class="type-badge" style="background:rgba(22,163,74,.12);color:#16a34a"><i class="bi bi-plus-circle-fill"></i> Add to E-Waste</span>
          <?php endif; ?>
        </div>
        <div class="req-item-title"><?= h($req['description']) ?></div>
        <div class="req-item-meta">
          <span class="req-class"><?= h($req['asset_class']) ?></span>
          <?php if ($req['asset_number']): ?><span class="req-meta-text" style="color:var(--accent);font-weight:600"><?= h($req['asset_number']) ?></span><?php endif; ?>
          <?php if ($req['serial_number']): ?><span class="req-meta-text">S/N: <?= h($req['serial_number']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="req-date-col"><?= date('d M Y', strtotime($req['created_at'])) ?><br><?= date('H:i', strtotime($req['created_at'])) ?></div>
      <?= reqStatusBadge($req['status']) ?>
      <?php if ($req['status'] === 'Pending'): ?>
      <a href="inventory.php?action=retract_ew&id=<?= $req['id'] ?>" onclick="return confirm('Retract this e-waste request?')"
        style="display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);color:var(--muted);border:1.5px solid var(--border);border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0">
        <i class="bi bi-arrow-counterclockwise"></i> Retract
      </a>
      <?php endif; ?>
    </div>
    <?php endwhile; endif; ?>
  </div>
</div>

<!-- SECTION 3: DELETE REQUESTS -->
<div class="req-section">
  <div class="req-section-head">
    <div class="req-section-title">
      <div class="req-section-stripe" style="background:#dc2626"></div>
      <i class="bi bi-trash-fill" style="color:#f87171;font-size:15px"></i> Delete Requests
    </div>
    <span class="req-section-count" style="background:rgba(239,68,68,.2);color:#f87171"><?= $my_del_count ?> total</span>
  </div>
  <div class="req-card-body">
    <?php if ($my_del_count === 0): ?>
    <div class="req-empty"><i class="bi bi-trash" style="font-size:28px;display:block;margin-bottom:8px;color:#dc2626;opacity:.5"></i>No delete requests yet</div>
    <?php else: while ($req = $my_del->fetch_assoc()): ?>
    <div class="req-item">
      <div class="req-item-icon" style="background:rgba(239,68,68,.1)"><i class="bi bi-trash" style="color:#dc2626"></i></div>
      <div class="req-item-body">
        <div class="req-item-title"><?= h($req['description']) ?></div>
        <div class="req-item-meta">
          <?php if ($req['asset_class']): ?><span class="req-class"><?= h($req['asset_class']) ?></span><?php endif; ?>
          <span class="req-meta-text" style="color:var(--accent);font-weight:600"><?= h($req['asset_number']) ?></span>
          <?php if ($req['reason']): ?><span class="req-meta-text" style="font-style:italic">"<?= h($req['reason']) ?>"</span><?php endif; ?>
        </div>
      </div>
      <div class="req-date-col"><?= date('d M Y', strtotime($req['created_at'])) ?><br><?= date('H:i', strtotime($req['created_at'])) ?></div>
      <?= reqStatusBadge($req['status']) ?>
      <?php if ($req['status'] === 'Pending'): ?>
      <a href="inventory.php?action=retract_delete&id=<?= $req['id'] ?>" onclick="return confirm('Retract this delete request?')"
        style="display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);color:var(--muted);border:1.5px solid var(--border);border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0">
        <i class="bi bi-arrow-counterclockwise"></i> Retract
      </a>
      <?php endif; ?>
    </div>
    <?php endwhile; endif; ?>
  </div>
</div>
<?php require_once 'includes/layout_end.php'; ?>
<?php exit; endif; ?>
<?php if ($action === 'add' || ($action === 'edit' && $edit_item)): ?>
<div class="form-card mb-4">
  <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:20px;color:var(--text)">
    <i class="bi bi-<?= $edit_item ? 'pencil' : (isAdmin() ? 'plus-circle' : 'send') ?> me-2" style="color:var(--green)"></i>
    <?= $edit_item ? 'Edit Asset' : (isAdmin() ? 'Add New Asset' : 'Request to Add Asset') ?>
  </h5>
  <?php if (!isAdmin() && !$edit_item): ?>
  <div style="background:rgba(37,99,235,.07);border:1px solid rgba(37,99,235,.2);border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
    <i class="bi bi-info-circle-fill" style="color:#2563eb;font-size:16px;flex-shrink:0"></i>
    <span style="font-size:13px;color:#1d4ed8;font-weight:500">Your request will be sent to the admin for approval before the asset is added to IT Assets.</span>
  </div>
  <?php endif; ?>
  <form method="POST">
    <?php if ($edit_item): ?>
    <input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>">
    <?php elseif (!isAdmin()): ?>
    <input type="hidden" name="request_add_asset" value="1">
    <?php endif; ?>
    <div class="row g-3">
      <!-- Row 1: Asset No | Class | Description -->
      <div class="col-md-3">
        <label class="form-label">Asset Number</label>
        <input type="text" name="asset_number" class="form-control" placeholder="e.g. OEPC1401"
          value="<?= h($edit_item['asset_number'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Asset Class <span style="color:var(--red)">*</span></label>
        <select name="asset_class" class="form-select" required>
          <?php if (!$edit_item): ?>
          <option value="" disabled selected>— Pick Asset Class —</option>
          <?php endif; ?>
          <?php foreach($_asset_classes_grouped as $grp_name => $grp_classes): ?>
          <optgroup label="<?= h($grp_name) ?>">
            <?php foreach($grp_classes as $cl): ?>
            <option value="<?= h($cl) ?>" <?= ($edit_item['asset_class'] ?? '') === $cl ? 'selected' : '' ?>><?= h($cl) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Description <span style="color:var(--red)">*</span></label>
        <input type="text" name="description" class="form-control" required placeholder="e.g. HP ELITEONE 800 G2 23"
          value="<?= h($edit_item['description'] ?? '') ?>">
      </div>
      <!-- Row 2: Serial | Brand | Model -->
      <div class="col-md-4">
        <label class="form-label">Serial Number</label>
        <input type="text" name="serial_number" class="form-control" placeholder="e.g. SGH629QBBY"
          value="<?= h($edit_item['serial_number'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Brand</label>
        <input type="text" name="brand" class="form-control" value="<?= h($edit_item['brand'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Model</label>
        <input type="text" name="model" class="form-control" value="<?= h($edit_item['model'] ?? '') ?>">
      </div>
      <!-- Row 3: Location full width -->
      <div class="col-12">
        <label class="form-label">Location</label>
        <input type="text" name="location" class="form-control" placeholder="e.g. Server Room 1"
          value="<?= h($edit_item['location'] ?? '') ?>">
      </div>
      <!-- Row 4: Notes full width -->
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($edit_item['notes'] ?? '') ?></textarea>
      </div>

      <?php if ($edit_item): ?>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn-primary-custom" id="submitBtn">
          <i class="bi bi-<?= $edit_item ? 'check-lg' : (isAdmin() ? 'check-lg' : 'send') ?>"></i>
          <?= $edit_item ? 'Update Asset' : (isAdmin() ? 'Add Asset' : 'Request to Add Asset') ?>
        </button>
        <a href="inventory.php" class="btn-secondary-custom"><i class="bi bi-x"></i>Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
// ── LIVE CLOCK for time fields ──
(function() {
  function tick() {
    const now  = new Date();
    const hh   = String(now.getHours()).padStart(2, '0');
    const mm   = String(now.getMinutes()).padStart(2, '0');
    const time = hh + ':' + mm;
    const t1 = document.getElementById('writeoffTime');
    const t2 = document.getElementById('modalWoTime');
    // Only auto-update if the user hasn't manually changed it (track with data-manual)
    if (t1 && !t1.dataset.manual) t1.value = time;
    if (t2 && !t2.dataset.manual) t2.value = time;
  }
  tick();
  setInterval(tick, 1000);
  // Stop auto-updating if user manually edits
  document.addEventListener('change', function(e) {
    if (e.target.id === 'writeoffTime' || e.target.id === 'modalWoTime') {
      e.target.dataset.manual = '1';
    }
  });
  // Reset manual flag when modal resets
  const origReset = window.resetEwasteModal;
  window.resetEwasteModal = function() {
    const t = document.getElementById('modalWoTime');
    if (t) delete t.dataset.manual;
    if (origReset) origReset();
  };
})();
function toggleWriteoff() {
  const body = document.getElementById('writeoffBody');
  const chev = document.getElementById('writeoffChevron');
  const open = body.style.display === 'none';
  body.style.display = open ? 'block' : 'none';
  chev.style.transform = open ? 'rotate(180deg)' : '';
}
function toggleWriteoffFields() {
  const checked = document.getElementById('writeoffCheck').checked;
  document.getElementById('writeoffFields').style.display = checked ? 'block' : 'none';
  const btn = document.getElementById('submitBtn');
  if (checked) {
    btn.innerHTML = '<i class="bi bi-pen me-1"></i> Update & Flag for Write-Off';
    btn.style.background = 'var(--red)';
  } else {
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Update Asset';
    btn.style.background = '';
  }
}
</script>

<!-- PAGE HEADER -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">All IT Assets</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Manage and track all registered IT equipment</p>
  </div>
  <a href="inventory.php?action=add" class="btn-primary-custom" style="padding:10px 20px;font-size:13px">
    <i class="bi bi-<?= isAdmin() ? 'plus-lg' : 'send' ?>"></i> <?= isAdmin() ? 'Add Asset' : 'Request to Add' ?>
  </a>
</div>

<!-- FILTER BAR -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  <form method="GET" action="inventory.php" id="filterForm" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%">
    <div style="position:relative;flex:1;min-width:220px">
      <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px"></i>
      <input type="text" name="search" value="<?= h($f_search) ?>" placeholder="Search asset no., description, serial..."
        style="width:100%;padding:9px 12px 9px 34px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none"
        onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'">
    </div>
    <select name="class" onchange="this.form.submit()"
      style="padding:9px 14px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;min-width:140px">
      <option value="">All Classes</option>
      <?php while ($r = $all_classes->fetch_assoc()): ?>
      <option value="<?= h($r['asset_class']) ?>" <?= $f_class === $r['asset_class'] ? 'selected' : '' ?>><?= h($r['asset_class']) ?></option>
      <?php endwhile; ?>
    </select>
    <select name="status" onchange="this.form.submit()"
      style="padding:9px 14px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;min-width:130px">
      <option value="">All Status</option>
      <option value="Active"     <?= $f_status === 'Active'     ? 'selected' : '' ?>>Active</option>
      <option value="Collected"  <?= $f_status === 'Collected'  ? 'selected' : '' ?>>Collected</option>
    </select>
    <select name="location" onchange="this.form.submit()"
      style="padding:9px 14px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;min-width:140px">
      <option value="">All Locations</option>
      <?php while ($r = $all_locations->fetch_assoc()): ?>
      <option value="<?= h($r['location']) ?>" <?= $f_location === $r['location'] ? 'selected' : '' ?>><?= h($r['location']) ?></option>
      <?php endwhile; ?>
    </select>
    <button type="submit"
      style="padding:9px 20px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap;display:flex;align-items:center;gap:6px">
      <i class="bi bi-funnel-fill"></i> Filter
    </button>
    <?php if ($f_search||$f_class||$f_status||$f_location): ?>
    <a href="inventory.php"
      style="padding:9px 16px;background:var(--surface);color:var(--muted);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">
      Clear
    </a>
    <?php endif; ?>
  </form>
</div>

<!-- BULK ACTION BAR (hidden until items selected) -->
<div id="bulkBar" style="display:none;position:sticky;top:12px;z-index:100;margin-bottom:12px">
  <div style="background:#1A2332;color:#fff;border-radius:10px;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 20px rgba(0,0,0,.3)">
    <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px">
      <i class="bi bi-check2-square me-2"></i><span id="bulkCount">0</span> item(s) selected
    </span>
    <div style="display:flex;gap:8px">
      <form method="POST" id="bulkEwasteForm" style="display:inline">
        <input type="hidden" name="bulk_action" value="ewaste">
        <div id="ewaste_ids"></div>
        <button type="button" id="flagEwasteBtn" onclick="goToWriteoff()"
          style="background:#E7F6ED;color:#15803d;border:1px solid #bbf7d0;border-radius:7px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
          <i class="bi bi-recycle"></i> Flag as E-Waste
        </button>
      </form>
      <form method="POST" id="bulkBypassForm" style="display:inline">
        <input type="hidden" name="bulk_action" value="bypass_ewaste">
        <div id="bypass_ids"></div>
        <button type="button" id="bypassEwasteBtn" onclick="submitBulk('bypass_ewaste')"
          style="background:rgba(217,119,6,.12);color:#d97706;border:1px solid rgba(217,119,6,.3);border-radius:7px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
          <i class="bi bi-skip-forward-fill"></i> Bypass to E-Waste
        </button>
      </form>
      <form method="POST" id="bulkDeleteForm" style="display:inline">
        <input type="hidden" name="bulk_action" value="delete">
        <div id="delete_ids"></div>
        <?php if (isAdmin()): ?>
        <button type="button" onclick="submitBulk('delete')"
          style="background:#FDECEC;color:#dc2626;border:1px solid #fecaca;border-radius:7px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px">
          <i class="bi bi-trash"></i> Delete
        </button>
        <?php endif; ?>
      </form>
      <button type="button" onclick="clearSelection()"
        style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:7px;padding:7px 14px;font-size:13px;cursor:pointer">
        <i class="bi bi-x"></i>
      </button>
    </div>
  </div>
</div>

<!-- TABLE -->
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;color:var(--muted);font-weight:500">
      <strong style="color:var(--text)"><?= number_format($total_count) ?></strong> record<?= $total_count !== 1 ? 's' : '' ?><?= ($f_search||$f_class||$f_status||$f_location) ? ' &nbsp;<span style="color:var(--accent)">(filtered)</span>' : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <th style="width:40px"><input type="checkbox" id="selectAll" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px"></th>
        <th>ASSET NO.</th><th>CLASS</th><th>DESCRIPTION</th>
        <th>SERIAL NO.</th><th>LOCATION</th><th>STATUS</th>
        <th>ACTIONS</th>
      </tr></thead>
      <tbody>
      <?php while ($row = $items->fetch_assoc()):
        // Auto-heal: if location='E-Waste' but no matching ewaste record, reset it
        if ($row['location'] === 'E-Waste' && empty($row['ew_status'])) {
            $db->query("UPDATE inventory_items SET location='', item_status='Active', updated_at=NOW() WHERE id=".(int)$row['id']);
            $row['location']     = '';
            $row['item_status']  = 'Active';
        }
        // Check if a pending bypass request exists for this item (staff only)
        $has_bypass_req = false;
        if (!isAdmin()) {
            $uid = (int)$_SESSION['user_id'];
            $iid = (int)$row['id'];
            $br = $db->query("SELECT id FROM ewaste_requests WHERE inventory_id=$iid AND requested_by=$uid AND status='Pending' AND type='bypass'")->fetch_assoc();
            $has_bypass_req = !empty($br);
        }
      ?>
      <tr>
        <td><input type="checkbox" class="row-check" value="<?= $row['id'] ?>"
          data-ewaste="<?= !empty($row['ew_status']) || in_array($row['item_status'], ['Collected','Disposed']) ? '1' : '0' ?>"
          data-bypass-pending="<?= $has_bypass_req ? '1' : '0' ?>"
          <?php if (!empty($row['ew_status']) || in_array($row['item_status'], ['Collected','Disposed'])): ?>
          disabled style="cursor:not-allowed;opacity:.3;width:15px;height:15px"
          <?php else: ?>
          style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px"
          <?php endif; ?>
          ></td>
        <td>
          <a href="inventory.php?action=edit&id=<?= $row['id'] ?>"
            style="color:var(--accent);font-size:13px;font-weight:600;text-decoration:none;font-family:'Plus Jakarta Sans',sans-serif">
            <?= h($row['asset_number'] ?: '—') ?>
          </a>
        </td>
        <td>
          <span style="display:inline-block;background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:700;letter-spacing:.04em;font-family:'Plus Jakarta Sans',sans-serif">
            <?= h($row['asset_class']) ?>
          </span>
        </td>
        <td style="font-weight:500;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif"><?= h($row['description']) ?></td>
        <td style="font-size:13px;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif"><?= h($row['serial_number'] ?: '—') ?></td>
        <td style="font-size:13px;font-family:'Plus Jakarta Sans',sans-serif"><?= h($row['location'] ?: '—') ?></td>
        <td>
          <?php
            $display_status = $row['item_status'];
            if ($row['ew_status'] === 'Collected') $display_status = 'Collected';
            elseif ($row['ew_status'] === 'Pending')  $display_status = 'Pending';
            elseif ($row['ew_status'] === 'Approved') $display_status = 'E-Waste';
          ?>
          <?php if ($display_status === 'Active'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(22,163,74,.1);color:#16a34a;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#16a34a;border-radius:50%;display:inline-block"></span> Active
            </span>
          <?php elseif ($display_status === 'Collected'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(234,179,8,.1);color:#ca8a04;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#ca8a04;border-radius:50%;display:inline-block"></span> Collected
            </span>
          <?php elseif ($display_status === 'Disposed'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,.1);color:#dc2626;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#dc2626;border-radius:50%;display:inline-block"></span> Disposed
            </span>
          <?php elseif ($display_status === 'Pending'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(245,158,11,.1);color:#d97706;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#d97706;border-radius:50%;display:inline-block"></span> Pending
            </span>
          <?php elseif ($display_status === 'E-Waste'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(245,158,11,.1);color:#d97706;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#d97706;border-radius:50%;display:inline-block"></span> E-Waste
            </span>
          <?php else: ?>
            <span style="background:rgba(100,116,139,.1);color:var(--muted);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <?= h($display_status) ?>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
            <button onclick="openQRModal(<?= $row['id'] ?>, '<?= addslashes(h($row['asset_number'] ?: 'N/A')) ?>', '<?= addslashes(h($row['description'])) ?>', '<?= addslashes(h($row['asset_class'])) ?>', '<?= addslashes(h($row['serial_number'] ?: '')) ?>', '<?= addslashes(h($row['brand'] ?: '')) ?>', '<?= addslashes(h($row['model'] ?: '')) ?>', '<?= addslashes(h($row['location'] ?: '')) ?>')"
              style="font-size:12px;font-weight:700;color:#7c3aed;background:rgba(124,58,237,.1);border:none;border-radius:6px;padding:5px 10px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:4px">
              <i class="bi bi-qr-code" style="font-size:11px"></i> QR
            </button>
            <?php if ($row['item_status'] !== 'Disposed' && $row['item_status'] !== 'Collected' && empty($row['ew_status'])): ?>
            <div style="position:relative;display:inline-block" class="ew-split-wrap">
              <div style="display:inline-flex;border-radius:6px;overflow:visible">
                <a href="writeoff.php?item_id=<?= $row['id'] ?>"
                  style="font-size:12px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.1);border:none;border-radius:6px 0 0 6px;cursor:pointer;padding:5px 10px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif;text-decoration:none;display:inline-block;border-right:1px solid rgba(22,163,74,.2)">E-Waste</a>
                <button type="button" onclick="toggleEwSplit(this)"
                  style="font-size:11px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.1);border:none;border-radius:0 6px 6px 0;cursor:pointer;padding:5px 7px;font-family:'Plus Jakarta Sans',sans-serif;display:inline-flex;align-items:center">
                  <i class="bi bi-chevron-down" style="font-size:9px"></i>
                </button>
              </div>
              <div class="ew-split-menu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:#fff;border:1px solid #e4e8ef;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:99999;min-width:190px;overflow:hidden">
                <a href="writeoff.php?item_id=<?= $row['id'] ?>"
                  style="display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:12px;font-weight:600;color:#15803d;text-decoration:none;border-bottom:1px solid #f0f2f5"
                  onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''">
                  <i class="bi bi-pen-fill" style="font-size:12px"></i>
                  <div>
                    <div style="font-weight:700">Via Write-Off</div>
                    <div style="font-size:10px;color:#9ca3af;font-weight:400">Requires authorisation</div>
                  </div>
                </a>
                <?php if ($has_bypass_req): ?>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:12px;font-weight:600;color:#9ca3af;cursor:not-allowed">
                  <i class="bi bi-hourglass-split" style="font-size:12px;color:#d97706"></i>
                  <div>
                    <div style="font-weight:700;color:#d97706">Bypass Pending</div>
                    <div style="font-size:10px;color:#9ca3af;font-weight:400">Awaiting admin approval</div>
                  </div>
                </div>
                <?php else: ?>
                <a href="inventory.php?action=bypass_ewaste&id=<?= $row['id'] ?>"
                  onclick="return confirm('Bypass write-off and send directly to E-Waste (Approved)?')"
                  style="display:flex;align-items:center;gap:8px;padding:10px 14px;font-size:12px;font-weight:600;color:#d97706;text-decoration:none"
                  onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
                  <i class="bi bi-skip-forward-fill" style="font-size:12px"></i>
                  <div>
                    <div style="font-weight:700">Bypass Write-Off</div>
                    <div style="font-size:10px;color:#9ca3af;font-weight:400">Direct to E-Waste (Approved)</div>
                  </div>
                </a>
                <?php endif; ?>
              </div>
            </div>
            <?php elseif (!empty($row['ew_status'])): ?>
            <span style="font-size:11px;font-weight:600;color:#d97706;background:rgba(245,158,11,.1);border-radius:6px;padding:5px 10px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">
              <?php
                if ($row['ew_status'] === 'Pending')   echo '⏳ Pending';
                elseif ($row['ew_status'] === 'Approved') echo '✓ E-Waste';
                elseif ($row['ew_status'] === 'Collected') echo '✓ Collected';
                else echo '✓ Disposed';
              ?>
            </span>
            <?php endif; ?>
            <?php $is_locked = !isAdmin() && (!empty($row['ew_status']) || $row['item_status'] === 'Collected'); ?>
            <?php if (!$is_locked): ?>
            <a href="inventory.php?action=edit&id=<?= $row['id'] ?>"
              style="font-size:12px;font-weight:700;color:var(--text);text-decoration:none;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif;padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:var(--surface)">Edit</a>
            <?php if (isAdmin()): ?>
            <a href="inventory.php?action=delete&id=<?= $row['id'] ?>"
              onclick="return confirm('Permanently delete this asset?')"
              style="font-size:12px;font-weight:700;color:#dc2626;text-decoration:none;background:rgba(239,68,68,.1);border-radius:6px;padding:5px 12px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">Delete</a>
            <?php else:
              $has_pending_req = !empty($row['del_req_id']);
              if ($has_pending_req): ?>
            <span style="font-size:11px;font-weight:600;color:#d97706;background:rgba(245,158,11,.1);border-radius:6px;padding:5px 10px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">⏳ Delete Pending</span>
            <?php else: ?>
            <button onclick="openDeleteRequest(<?= $row['id'] ?>, '<?= addslashes(h($row['description'])) ?>')"
              style="font-size:12px;font-weight:700;color:#dc2626;background:rgba(239,68,68,.1);border:none;border-radius:6px;padding:5px 12px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif;cursor:pointer">Request Delete</button>
            <?php endif; endif; ?>
            <?php else: ?>
            <span style="font-size:11px;color:var(--muted);font-style:italic;font-family:'Plus Jakarta Sans',sans-serif;padding:5px 4px">No actions</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// ── PERSISTENT CROSS-PAGE SELECTION ──
const selectedIds = new Set();

// Sync current-page checkboxes and header checkbox to match selectedIds
function syncCheckboxes() {
  const rows    = document.querySelectorAll('tbody .row-check:not(:disabled)');
  const selectAll = document.querySelector('thead #selectAll');
  if (!selectAll) return;

  let checkedCount = 0;
  rows.forEach(cb => {
    cb.checked = selectedIds.has(cb.value);
    if (cb.checked) checkedCount++;
  });

  const total = rows.length;
  selectAll.checked       = total > 0 && checkedCount === total;
  selectAll.indeterminate = checkedCount > 0 && checkedCount < total;
}

// Row checkbox — event delegation so it works after DataTables redraws
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('row-check') && !e.target.disabled) {
    if (e.target.checked) selectedIds.add(e.target.value);
    else selectedIds.delete(e.target.value);
    syncCheckboxes();
    updateBulkBar();
  }
});

// Header select-all — event delegation on thead so it survives any responsive cloning
document.addEventListener('change', function(e) {
  if (e.target.id === 'selectAll') {
    document.querySelectorAll('tbody .row-check:not(:disabled)').forEach(cb => {
      cb.checked = e.target.checked;
      if (e.target.checked) selectedIds.add(cb.value);
      else selectedIds.delete(cb.value);
    });
    updateBulkBar();
  }
});

// Hook into DataTables drawCallback (registered in layout_end.php)
// This runs AFTER DataTables has finished updating the DOM — no timing issues
window._onDtDraw = function() {
  syncCheckboxes();
  updateBulkBar();
};

function clearSelection() {
  selectedIds.clear();
  const selectAll = document.querySelector('thead #selectAll');
  if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
  document.querySelectorAll('tbody .row-check:not(:disabled)').forEach(cb => cb.checked = false);
  updateBulkBar();
}

function goToWriteoff() {
  if (!selectedIds.size) return;
  window.location.href = 'writeoff.php?bulk_ids=' + Array.from(selectedIds).join(',');
}

function submitBulk(action) {
  if (!selectedIds.size) return;
  const confirmMsgs = {
    delete:         'Permanently delete ' + selectedIds.size + ' selected asset(s)? This cannot be undone.',
    bypass_ewaste:  'Bypass write-off and send ' + selectedIds.size + ' item(s) directly to E-Waste (Approved)?',
  };
  const confirmMsg = confirmMsgs[action] || ('Flag ' + selectedIds.size + ' selected asset(s) as E-Waste?');
  if (!confirm(confirmMsg)) return;

  const formMap      = { delete: 'bulkDeleteForm', bypass_ewaste: 'bulkBypassForm' };
  const containerMap = { delete: 'delete_ids',     bypass_ewaste: 'bypass_ids' };
  const formId      = formMap[action]      || 'bulkEwasteForm';
  const containerId = containerMap[action] || 'ewaste_ids';
  const form        = document.getElementById(formId);
  const container   = document.getElementById(containerId);
  container.innerHTML = '';
  selectedIds.forEach(id => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
    container.appendChild(inp);
  });
  form.submit();
}

// Also hide Bypass button if any selected item is in E-Waste
function updateBulkBar() {
  const bar   = document.getElementById('bulkBar');
  const count = selectedIds.size;
  document.getElementById('bulkCount').textContent = count;
  bar.style.display = count > 0 ? 'block' : 'none';
  const anyInEwaste = [...document.querySelectorAll('.row-check')]
    .filter(cb => selectedIds.has(cb.value))
    .some(cb => cb.dataset.ewaste === '1');
  const anyBypassPending = [...document.querySelectorAll('.row-check')]
    .filter(cb => selectedIds.has(cb.value))
    .some(cb => cb.dataset.bypassPending === '1');
  const ewasteBtn  = document.getElementById('flagEwasteBtn');
  const bypassBtn  = document.getElementById('bypassEwasteBtn');
  if (ewasteBtn)  ewasteBtn.style.display  = anyInEwaste ? 'none' : 'flex';
  // Hide bypass if any selected item is in ewaste OR already has a pending bypass request
  if (bypassBtn)  bypassBtn.style.display  = (anyInEwaste || anyBypassPending) ? 'none' : 'flex';
}

// Split dropdown toggle for per-row E-Waste button
function toggleEwSplit(btn) {
  const menu = btn.closest('.ew-split-wrap').querySelector('.ew-split-menu');
  const isOpen = menu.style.display !== 'none';
  // Close all open menus first
  document.querySelectorAll('.ew-split-menu').forEach(m => m.style.display = 'none');
  menu.style.display = isOpen ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.ew-split-wrap')) {
    document.querySelectorAll('.ew-split-menu').forEach(m => m.style.display = 'none');
  }
});
</script>

<!-- ── HOD WRITE-OFF MODAL ── -->
<div id="ewasteModal" onclick="if(event.target===this)closeEwasteModal()"
  style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.72);z-index:999999;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border-radius:18px;max-width:620px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.5)">

    <!-- Header -->
    <div style="padding:20px 24px;display:flex;align-items:center;justify-content:space-between">
      <div>
        <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:16px;color:var(--text);display:flex;align-items:center;gap:9px">
          <i class="bi bi-pen" style="color:var(--red)"></i> Write-Off Authorisation
        </div>
        <div id="ewasteModalDesc" style="font-size:12px;color:var(--muted);margin-top:3px"></div>
      </div>
      <button type="button" onclick="closeEwasteModal()"
        style="background:none;border:none;color:var(--muted);font-size:22px;cursor:pointer;line-height:1">&times;</button>
    </div>

    <div style="height:1px;background:var(--border)"></div>

    <!-- Body -->
    <div style="padding:22px 24px">
      <!-- Consent -->
      <div style="background:rgba(248,81,73,.06);border:1px solid rgba(248,81,73,.2);border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;margin-bottom:20px">
        <input type="checkbox" id="modalWoCheck" value="1"
          style="width:17px;height:17px;accent-color:var(--red);cursor:pointer;flex-shrink:0;margin-top:2px"
          onchange="toggleModalFields()">
        <label for="modalWoCheck" style="cursor:pointer;font-size:13px;color:var(--text);margin:0;line-height:1.5">
          I authorise the write-off of this asset for e-waste disposal. This will permanently move it to the <strong>E-Waste</strong> section.
        </label>
      </div>

      <!-- Fields (revealed after checkbox) -->
      <div id="modalWoFields" style="display:none">
        <form method="POST" id="ewasteModalForm" onsubmit="return handleEwasteModalSubmit(event)">
          <input type="hidden" name="modal_ewaste_id" id="modalEwasteId">
          <input type="hidden" name="modal_bulk_mode" id="modalBulkMode" value="0">
          <div class="row g-3">
            <!-- Row 1: Full Name | Staff ID -->
            <div class="col-md-7">
              <label class="form-label">Authorised By (Full Name) <span style="color:var(--red)">*</span></label>
              <input type="text" name="modal_wo_name" id="modalWoName" class="form-control"
                placeholder="e.g. Ahmad bin Abdullah" required
                value="<?= h($_SESSION['full_name'] ?? '') ?>"
                <?= !isAdmin() ? 'readonly style="background:var(--surface2);cursor:not-allowed;opacity:.8"' : '' ?>>
              <?php if (!isAdmin()): ?>
              <small style="font-size:11px;color:var(--muted);margin-top:4px;display:block">
                <i class="bi bi-lock-fill"></i> Name locked to your account
              </small>
              <?php endif; ?>
            </div>
            <div class="col-md-5">
              <label class="form-label">Staff / Worker ID <span style="color:var(--red)">*</span></label>
              <input type="text" name="modal_wo_signature" id="modalSigData" class="form-control"
                placeholder="e.g. FJB-0012" required
                value="<?= h($_user_staff_id) ?>"
                <?= !isAdmin() ? 'readonly style="background:var(--surface2);cursor:not-allowed;opacity:.8"' : '' ?>>
              <?php if (!isAdmin()): ?>
              <small style="font-size:11px;color:var(--muted);margin-top:4px;display:block">
                <i class="bi bi-lock-fill"></i> Staff ID locked to your account
              </small>
              <?php endif; ?>
            </div>
            <!-- Row 2: Designation | Date | Time -->
            <div class="col-md-6">
              <label class="form-label">Designation</label>
              <input type="text" name="modal_wo_designation" class="form-control" placeholder="e.g. Head of IT Department">
            </div>
            <div class="col-md-3">
              <label class="form-label">Date</label>
              <input type="date" name="modal_wo_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Time</label>
              <input type="time" name="modal_wo_time" id="modalWoTime" class="form-control" value="<?= date('H:i') ?>">
            </div>
          </div>
          <div style="display:flex;gap:10px;margin-top:20px">
            <button type="submit"
              style="background:var(--red);color:#fff;border:none;border-radius:9px;padding:11px 24px;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px">
              <i class="bi bi-recycle"></i> Confirm Write-Off & Move to E-Waste
            </button>
            <button type="button" onclick="closeEwasteModal()"
              style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:9px;padding:11px 20px;font-size:13px;cursor:pointer">
              Cancel
            </button>
          </div>
        </form>
      </div>

      <!-- Prompt shown before checkbox -->
      <div id="modalWoPrompt" style="text-align:center;padding:10px 0 4px;color:var(--muted);font-size:13px">
        <i class="bi bi-arrow-up" style="display:block;font-size:20px;margin-bottom:4px"></i>
        Tick the checkbox above to proceed with authorisation
      </div>
    </div>

  </div>
</div>

<script>
// ── EWASTE MODAL ──
function openEwasteModal(id, desc) {
  document.getElementById('modalEwasteId').value = id;
  document.getElementById('modalBulkMode').value = '0';
  document.getElementById('ewasteModalDesc').textContent = 'Asset: ' + desc;
  resetEwasteModal();
}
function openBulkEwasteModal() {
  if (!selectedIds.size) return;
  document.getElementById('modalEwasteId').value = '';
  document.getElementById('modalBulkMode').value = '1';
  document.getElementById('ewasteModalDesc').textContent = selectedIds.size + ' asset(s) selected for write-off';
  resetEwasteModal();
}
function resetEwasteModal() {
  document.getElementById('modalWoCheck').checked = false;
  document.getElementById('modalWoFields').style.display = 'none';
  document.getElementById('modalWoPrompt').style.display = 'block';
  document.getElementById('ewasteModal').style.display = 'flex';
  // Restore Staff ID to the account's locked value (don't clear it)
  const sigField = document.getElementById('modalSigData');
  if (sigField && sigField.readOnly) sigField.value = sigField.defaultValue;
  // Reset time manual flag so live clock resumes
  const t = document.getElementById('modalWoTime');
  if (t) delete t.dataset.manual;
  document.querySelector('.sidebar') && (document.querySelector('.sidebar').style.zIndex = '0');
  document.querySelector('.topbar')  && (document.querySelector('.topbar').style.zIndex  = '0');
}
function closeEwasteModal() {
  document.getElementById('ewasteModal').style.display = 'none';
  document.querySelector('.sidebar') && (document.querySelector('.sidebar').style.zIndex = '');
  document.querySelector('.topbar')  && (document.querySelector('.topbar').style.zIndex  = '');
}
function toggleModalFields() {
  const checked = document.getElementById('modalWoCheck').checked;
  document.getElementById('modalWoFields').style.display  = checked ? 'block' : 'none';
  document.getElementById('modalWoPrompt').style.display  = checked ? 'none'  : 'block';
}
function handleEwasteModalSubmit(e) {
  const isBulk = document.getElementById('modalBulkMode').value === '1';
  if (!isBulk) return true;
  e.preventDefault();
  const wo_name = document.getElementById('modalWoName').value.trim();
  if (!wo_name) { alert('Please enter the authorised person\'s name.'); return false; }
  const sig      = document.getElementById('modalSigData').value.trim();
  const wo_desig = document.querySelector('[name="modal_wo_designation"]').value;
  const wo_date  = document.querySelector('[name="modal_wo_date"]').value;
  const wo_time  = document.querySelector('[name="modal_wo_time"]').value;
  const form = document.createElement('form');
  form.method = 'POST';
  const fields = {
    bulk_action: 'ewaste',
    modal_wo_name: wo_name,
    modal_wo_designation: wo_desig,
    modal_wo_date: wo_date,
    modal_wo_time: wo_time,
    modal_wo_signature: sig,
    modal_bulk_writeoff: '1'
  };
  for (const [k,v] of Object.entries(fields)) {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = k; inp.value = v;
    form.appendChild(inp);
  }
  selectedIds.forEach(id => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
  return false;
}

function openDeleteRequest(id, desc) {
  document.getElementById('delReqId').value = id;
  document.getElementById('delReqDesc').textContent = desc;
  document.getElementById('delReqModal').style.display = 'flex';
}
function closeDeleteRequest() {
  document.getElementById('delReqModal').style.display = 'none';
}
</script>

<!-- REQUEST DELETE MODAL (users only) -->
<?php if (!isAdmin()): ?>
<div id="delReqModal" onclick="if(event.target===this)closeDeleteRequest()"
  style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border-radius:16px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;color:#dc2626;font-size:15px">
        <i class="bi bi-trash me-2"></i>Request Delete
      </div>
      <button onclick="closeDeleteRequest()" style="background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="POST" action="inventory.php">
      <input type="hidden" name="action" value="">
      <div style="padding:20px 24px 24px">
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
          You are requesting deletion of: <strong style="color:var(--text)" id="delReqDesc"></strong>.<br>
          An admin must approve before the asset is removed.
        </p>
        <label style="font-size:13px;font-weight:600;color:var(--text);display:block;margin-bottom:6px">Reason (optional)</label>
        <textarea name="reason" rows="3" class="form-control" placeholder="Why should this asset be deleted?" style="font-size:13px"></textarea>
        <input type="hidden" name="del_req_id" id="delReqId">
        <div style="display:flex;gap:10px;margin-top:16px">
          <button type="button" onclick="submitDeleteRequest()"
            style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;display:flex;align-items:center;gap:6px">
            <i class="bi bi-send"></i> Submit Request
          </button>
          <button type="button" onclick="closeDeleteRequest()"
            style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 18px;font-size:13px;cursor:pointer">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function submitDeleteRequest() {
  const id = document.getElementById('delReqId').value;
  const reason = document.querySelector('#delReqModal textarea[name="reason"]').value;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'inventory.php?action=request_delete&id=' + id;
  const r = document.createElement('input');
  r.type = 'hidden'; r.name = 'reason'; r.value = reason;
  form.appendChild(r);
  document.body.appendChild(form);
  form.submit();
}
</script>
<?php endif; ?>

<!-- ── QR CODE MODAL ── -->
<div id="qrModal" onclick="if(event.target===this)closeQRModal()"
  style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:999999;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border-radius:16px;max-width:400px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.4)">
    <div style="background:#1a2332;border-radius:16px 16px 0 0;padding:16px 20px;display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:9px">
        <i class="bi bi-qr-code" style="color:#F28C28;font-size:16px"></i>
        <div style="font-size:14px;font-weight:700;color:#fff">Asset QR Code</div>
      </div>
      <button onclick="closeQRModal()" style="background:none;border:none;color:rgba(255,255,255,.6);font-size:20px;cursor:pointer;line-height:1">&times;</button>
    </div>
    <div style="padding:22px">
      <div id="qrModalAssetId" style="font-size:18px;font-weight:800;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;line-height:1"></div>
      <div id="qrModalDesc" style="font-size:12px;color:var(--muted);margin-top:3px;margin-bottom:16px"></div>
      <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:12px;padding:18px;display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:16px">
        <div id="qrModalCode" style="background:#fff;padding:10px;border-radius:8px;display:flex;align-items:center;justify-content:center;min-height:150px"></div>
        <div style="font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em">Scan to view asset details</div>
      </div>
      <div style="background:rgba(242,140,40,.06);border:1px solid rgba(242,140,40,.25);border-radius:8px;padding:10px 13px;display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <i class="bi bi-link-45deg" style="color:var(--accent);font-size:15px;flex-shrink:0"></i>
        <span id="qrModalLink" style="font-size:11px;color:var(--muted);font-family:monospace;flex:1;word-break:break-all;overflow:hidden"></span>
        <button onclick="copyQRLink()" id="qrCopyBtn"
          style="background:var(--accent);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;font-family:'Plus Jakarta Sans',sans-serif">
          Copy
        </button>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <button onclick="printQR()"
          style="display:flex;align-items:center;justify-content:center;gap:7px;background:var(--body-bg);color:var(--text);border:1.5px solid var(--border);border-radius:9px;padding:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
          <i class="bi bi-printer-fill"></i> Print QR
        </button>
        <button id="qrOpenBtn"
          style="display:flex;align-items:center;justify-content:center;gap:7px;background:var(--accent);color:#fff;border:none;border-radius:9px;padding:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
          <i class="bi bi-box-arrow-up-right"></i> Open Page
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var _qrAssetURL = '';

function openQRModal(id, assetNo, desc, cls, serial, brand, model, location) {
  var base = window.location.origin + window.location.pathname.replace('inventory.php','');
  _qrAssetURL = base + 'asset.php?id=' + id;

  document.getElementById('qrModalAssetId').textContent = assetNo + (cls ? ' \u00b7 ' + cls : '');
  document.getElementById('qrModalDesc').textContent    = desc;
  document.getElementById('qrModalLink').textContent    = _qrAssetURL;
  document.getElementById('qrOpenBtn').onclick = function() { window.open(_qrAssetURL, '_blank'); };

  // Plain text without URL — prevents iOS from opening Safari on scan
  var lines = [
    'FJB JOHOR BULKERS SDN BHD',
    '------------------------------',
    'ASET NO: ' + assetNo,
    'DESC: '    + desc,
    'CLASS: '   + cls,
  ];
  if (serial)   lines.push('S/N: '   + serial);
  if (brand)    lines.push('BRAND: ' + brand);
  if (model)    lines.push('MODEL: ' + model);
  if (location) lines.push('LOC: '   + location);
  lines.push('------------------------------');
  lines.push('FGV JOHOR BULKERS SDN BHD');

  var container = document.getElementById('qrModalCode');
  container.innerHTML = '';
  new QRCode(container, {
    text: lines.join('\n'),
    width: 150, height: 150,
    colorDark: '#1a2332', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });

  document.getElementById('qrModal').style.display = 'flex';
  var sb = document.querySelector('.sidebar'), tb = document.querySelector('.topbar');
  if (sb) sb.style.zIndex = '0';
  if (tb) tb.style.zIndex = '0';
}

function closeQRModal() {
  document.getElementById('qrModal').style.display = 'none';
  var btn = document.getElementById('qrCopyBtn');
  btn.textContent = 'Copy'; btn.style.background = 'var(--accent)';
  var sb = document.querySelector('.sidebar'), tb = document.querySelector('.topbar');
  if (sb) sb.style.zIndex = '';
  if (tb) tb.style.zIndex = '';
}

function copyQRLink() {
  navigator.clipboard.writeText(_qrAssetURL).then(function() {
    var btn = document.getElementById('qrCopyBtn');
    btn.textContent = 'Copied!'; btn.style.background = '#16a34a';
    setTimeout(function() { btn.textContent = 'Copy'; btn.style.background = 'var(--accent)'; }, 2000);
  });
}

function printQR() {
  var img = document.querySelector('#qrModalCode img');
  if (!img) return;
  var win = window.open('','_blank','width=420,height=520');
  win.document.write('<html><body style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;gap:14px;padding:20px">');
  win.document.write('<div style="font-size:13px;color:#7c8fa6;text-transform:uppercase;letter-spacing:.06em">FJB IT Inventory</div>');
  win.document.write('<div style="font-size:20px;font-weight:800;color:#1a2332">' + document.getElementById('qrModalAssetId').textContent + '</div>');
  win.document.write('<div style="font-size:13px;color:#4b5563;text-align:center">' + document.getElementById('qrModalDesc').textContent + '</div>');
  win.document.write('<img src="' + img.src + '" style="width:200px;height:200px;margin:8px 0">');
  win.document.write('<div style="font-size:10px;color:#9ca3af;word-break:break-all;text-align:center;max-width:300px">' + _qrAssetURL + '</div>');
  win.document.write('</body></html>');
  win.document.close();
  win.focus();
  setTimeout(function() { win.print(); }, 400);
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
