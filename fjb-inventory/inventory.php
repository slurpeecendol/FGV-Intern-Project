<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// Ensure delete_requests table exists
$db->query("CREATE TABLE IF NOT EXISTS delete_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    requested_by INT NOT NULL,
    reason TEXT,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
)");

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
        $db->query("DELETE FROM inventory_items WHERE id=$id");
        logActivity($_SESSION['user_id'], 'DELETE', 'inventory', $id, 'Deleted asset: '.$item['description']);
        header('Location: inventory.php?msg=deleted'); exit;
    }
}

// ── REQUEST DELETE (user submits delete request) ──
if ($action === 'request_delete' && $id && !isAdmin()) {
    $reason = trim($_POST['reason'] ?? '');
    $item = $db->query("SELECT description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $existing = $db->query("SELECT id FROM delete_requests WHERE inventory_id=$id AND status='Pending'")->fetch_assoc();
        if (!$existing) {
            $stmt = $db->prepare("INSERT INTO delete_requests (inventory_id, requested_by, reason) VALUES (?,?,?)");
            $stmt->bind_param('iis', $id, $_SESSION['user_id'], $reason);
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
        $db->query("DELETE FROM inventory_items WHERE id=$inv_id");
        $db->query("UPDATE delete_requests SET status='Approved', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
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
    $purchase_date  = $_POST['purchase_date'] ?: null;
    $purchase_price = $_POST['purchase_price'] ?: null;

    if (empty($data['description']) || empty($data['asset_class'])) {
        $err = 'Description and Asset Class are required.';
    } else {
        $edit_id = (int)($_POST['edit_id'] ?? 0);
        if ($edit_id) {
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
    }
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
if ($f_status)   $where[] = "inv.item_status='".mysqli_real_escape_string($db,$f_status)."'";
if ($f_location) $where[] = "inv.location LIKE '%".mysqli_real_escape_string($db,$f_location)."%'";

$where_sql = implode(' AND ', $where);
$simple_where = str_replace('inv.', '', $where_sql); // for COUNT query (no JOIN)

// Check if delete_requests table exists, then build query accordingly
$dr_table_exists = $db->query("SHOW TABLES LIKE 'delete_requests'")->num_rows > 0;
if ($dr_table_exists) {
    $items = $db->query("SELECT inv.*, ew.disposal_status as ew_status, dr.id as del_req_id FROM inventory_items inv LEFT JOIN ewaste_items ew ON ew.original_inventory_id=inv.id AND ew.disposal_status IN ('Pending','Approved') LEFT JOIN delete_requests dr ON dr.inventory_id=inv.id AND dr.status='Pending' WHERE $where_sql ORDER BY inv.asset_class, inv.created_at DESC");
} else {
    $items = $db->query("SELECT inv.*, ew.disposal_status as ew_status, NULL as del_req_id FROM inventory_items inv LEFT JOIN ewaste_items ew ON ew.original_inventory_id=inv.id AND ew.disposal_status IN ('Pending','Approved') WHERE $where_sql ORDER BY inv.asset_class, inv.created_at DESC");
}
$total_count = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE $simple_where")->fetch_assoc()['c'];

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
if ($url_msg === 'no_permission')    $err = 'You do not have permission to perform that action.';


require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<?php if ($view === 'pending_requests' && isAdmin()):
  $pending_reqs = $db->query("SELECT dr.*, inv.asset_number, inv.description, inv.asset_class, u.full_name as requester FROM delete_requests dr JOIN inventory_items inv ON inv.id=dr.inventory_id JOIN users u ON u.id=dr.requested_by WHERE dr.status='Pending' ORDER BY dr.created_at DESC");
  $pending_count = $pending_reqs->num_rows;
?>
<!-- PENDING REQUESTS PAGE -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Pending Delete Requests</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Review and approve or reject user deletion requests</p>
  </div>
</div>

<?php if ($pending_count === 0): ?>
<div class="table-card" style="padding:48px;text-align:center">
  <i class="bi bi-check-circle" style="font-size:40px;color:var(--muted);display:block;margin-bottom:12px"></i>
  <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:16px;color:var(--text);margin-bottom:6px">No pending requests</div>
  <div style="font-size:13px;color:var(--muted)">All delete requests have been reviewed.</div>
</div>
<?php else: ?>
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--muted);font-weight:500">
      <strong style="color:var(--text)"><?= $pending_count ?></strong> pending request<?= $pending_count !== 1 ? 's' : '' ?>
    </span>
  </div>
  <div style="display:flex;flex-direction:column;gap:0">
    <?php while ($req = $pending_reqs->fetch_assoc()): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border)">
      <div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:var(--text);margin-bottom:4px">
          <?= h($req['asset_number'] ?: '—') ?> &mdash; <?= h($req['description']) ?>
          <span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:6px"><?= h($req['asset_class']) ?></span>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          Requested by <strong style="color:var(--text)"><?= h($req['requester']) ?></strong>
          &bull; <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?>
          <?php if ($req['reason']): ?>
          &bull; <span style="color:var(--text)">Reason: <?= h($req['reason']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-shrink:0">
        <a href="inventory.php?action=approve_delete&id=<?= $req['id'] ?>&ref=pending" onclick="return confirm('Approve and permanently delete this asset?')"
          style="font-size:12px;font-weight:700;color:#fff;background:#dc2626;border-radius:7px;padding:7px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-family:'Plus Jakarta Sans',sans-serif">
          <i class="bi bi-check-lg"></i> Approve
        </a>
        <a href="inventory.php?action=reject_delete&id=<?= $req['id'] ?>&ref=pending"
          style="font-size:12px;font-weight:700;color:#dc2626;background:rgba(239,68,68,.1);border:1.5px solid rgba(239,68,68,.2);border-radius:7px;padding:7px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-family:'Plus Jakarta Sans',sans-serif">
          <i class="bi bi-x"></i> Reject
        </a>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
</div>
<?php endif; ?>
<?php require_once 'includes/layout_end.php'; ?>
<?php exit; endif; ?>

<?php if ($view === 'my_requests' && !isAdmin()):
  $my_reqs = $db->query("SELECT dr.*, inv.asset_number, inv.description, inv.asset_class FROM delete_requests dr JOIN inventory_items inv ON inv.id=dr.inventory_id WHERE dr.requested_by={$_SESSION['user_id']} ORDER BY dr.created_at DESC");
  if (!$my_reqs) $my_reqs = $db->query("SELECT dr.*, '' as asset_number, '' as description, '' as asset_class FROM delete_requests dr WHERE dr.requested_by={$_SESSION['user_id']} AND 1=0");
?>
<!-- MY REQUESTS PAGE -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">My Delete Requests</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Track the status of your submitted deletion requests</p>
  </div>
</div>

<?php
  // Re-query since we may need to handle deleted inventory items
  $my_reqs2 = $db->query("SELECT dr.*, COALESCE(inv.asset_number,'—') as asset_number, COALESCE(inv.description,'(Asset removed)') as description, COALESCE(inv.asset_class,'') as asset_class FROM delete_requests dr LEFT JOIN inventory_items inv ON inv.id=dr.inventory_id WHERE dr.requested_by={$_SESSION['user_id']} ORDER BY dr.created_at DESC");
  $req_count = $my_reqs2->num_rows;
?>

<?php if ($req_count === 0): ?>
<div class="table-card" style="padding:48px;text-align:center">
  <i class="bi bi-inbox" style="font-size:40px;color:var(--muted);display:block;margin-bottom:12px"></i>
  <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:16px;color:var(--text);margin-bottom:6px">No requests yet</div>
  <div style="font-size:13px;color:var(--muted)">You haven't submitted any delete requests.</div>
</div>
<?php else: ?>
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--muted);font-weight:500">
      <strong style="color:var(--text)"><?= $req_count ?></strong> request<?= $req_count !== 1 ? 's' : '' ?>
    </span>
  </div>
  <div>
    <?php while ($req = $my_reqs2->fetch_assoc()):
      $status = $req['status'];
      $statusColor = $status === 'Pending' ? '#d97706' : ($status === 'Approved' ? '#16a34a' : '#dc2626');
      $statusBg    = $status === 'Pending' ? 'rgba(217,119,6,.1)' : ($status === 'Approved' ? 'rgba(22,163,74,.1)' : 'rgba(239,68,68,.1)');
      $statusIcon  = $status === 'Pending' ? 'hourglass-split' : ($status === 'Approved' ? 'check-circle-fill' : 'x-circle-fill');
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border)">
      <div>
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:var(--text);margin-bottom:4px">
          <?= h($req['asset_number']) ?> &mdash; <?= h($req['description']) ?>
          <?php if ($req['asset_class']): ?>
          <span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:6px"><?= h($req['asset_class']) ?></span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          Submitted <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?>
          <?php if ($req['reason']): ?> &bull; Reason: <?= h($req['reason']) ?><?php endif; ?>
          <?php if ($req['reviewed_at'] && $status !== 'Pending'): ?>
          &bull; Reviewed <?= date('d/m/Y H:i', strtotime($req['reviewed_at'])) ?>
          <?php endif; ?>
        </div>
      </div>
      <span style="display:inline-flex;align-items:center;gap:6px;background:<?= $statusBg ?>;color:<?= $statusColor ?>;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap">
        <i class="bi bi-<?= $statusIcon ?>"></i> <?= $status ?>
      </span>
    </div>
    <?php endwhile; ?>
  </div>
</div>
<?php endif; ?>
<?php require_once 'includes/layout_end.php'; ?>
<?php exit; endif; ?>

<!-- ADD / EDIT FORM (add: admin only | edit: all users) -->
<?php if ($action === 'add' || ($action === 'edit' && $edit_item)): ?>
<div class="form-card mb-4">
  <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:20px;color:var(--text)">
    <i class="bi bi-<?= $edit_item ? 'pencil' : 'plus-circle' ?> me-2" style="color:var(--green)"></i>
    <?= $edit_item ? 'Edit Asset' : 'Add New Asset' ?>
  </h5>
  <form method="POST">
    <?php if ($edit_item): ?><input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>"><?php endif; ?>
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
          <?php foreach(['MONITOR','PC','LAPTOP','PRINTER','SCANNER','SERVER','NETWORKING','UPS','KEYBOARD','MOUSE','OTHER'] as $cl): ?>
          <option value="<?= $cl ?>" <?= ($edit_item['asset_class'] ?? '') === $cl ? 'selected' : '' ?>><?= $cl ?></option>
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
      <!-- ── WRITE-OFF AUTHORISATION ── -->
      <div class="col-12">
        <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-top:4px">
          <!-- Header toggle -->
          <div onclick="toggleWriteoff()" style="background:rgba(248,81,73,.06);border-bottom:1px solid var(--border);padding:14px 18px;cursor:pointer;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:10px">
              <i class="bi bi-pen" style="color:var(--red);font-size:16px"></i>
              <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Write-Off Authorisation</span>
              <span style="font-size:11px;color:var(--muted)">— Flag this asset for E-Waste disposal</span>
            </div>
            <i class="bi bi-chevron-down" id="writeoffChevron" style="color:var(--muted);transition:transform .2s"></i>
          </div>
          <!-- Body (hidden by default) -->
          <div id="writeoffBody" style="display:none;padding:20px;background:var(--surface)">
            <div class="row g-3">
              <!-- Checkbox -->
              <div class="col-12">
                <div style="background:rgba(248,81,73,.05);border:1px solid rgba(248,81,73,.2);border-radius:9px;padding:14px 16px;display:flex;align-items:center;gap:12px">
                  <input type="checkbox" name="writeoff_authorised" id="writeoffCheck" value="1"
                    style="width:18px;height:18px;accent-color:var(--red);cursor:pointer;flex-shrink:0"
                    onchange="toggleWriteoffFields()">
                  <label for="writeoffCheck" style="cursor:pointer;font-size:13px;color:var(--text);margin:0">
                    I authorise the write-off of this asset for e-waste disposal. This action will move the item to the <strong>E-Waste</strong> section.
                  </label>
                </div>
              </div>
              <!-- Name + Staff ID (shown only when checked) -->
              <div id="writeoffFields" style="display:none" class="col-12">
                <div class="row g-3">
                  <div class="col-md-5">
                    <label class="form-label">Authorised By (Full Name) <span style="color:var(--red)">*</span></label>
                    <input type="text" name="writeoff_name" id="writeoffName" class="form-control"
                      placeholder="e.g. Ahmad bin Abdullah"
                      value="<?= h($_SESSION['full_name'] ?? '') ?>"
                      <?= !isAdmin() ? 'readonly style="background:var(--surface2);cursor:not-allowed;opacity:.8"' : '' ?>>
                    <?php if (!isAdmin()): ?>
                    <small style="font-size:11px;color:var(--muted);margin-top:4px;display:block">
                      <i class="bi bi-lock-fill"></i> Name locked to your account
                    </small>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Staff / Worker ID <span style="color:var(--red)">*</span></label>
                    <input type="text" name="writeoff_signature" id="sigData" class="form-control"
                      placeholder="e.g. FJB-0012"
                      value="<?= h($_user_staff_id) ?>"
                      <?= !isAdmin() ? 'readonly style="background:var(--surface2);cursor:not-allowed;opacity:.8"' : '' ?>>
                    <?php if (!isAdmin()): ?>
                    <small style="font-size:11px;color:var(--muted);margin-top:4px;display:block">
                      <i class="bi bi-lock-fill"></i> Staff ID locked to your account
                    </small>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Designation</label>
                    <input type="text" name="writeoff_designation" class="form-control"
                      placeholder="e.g. Head of IT Department">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="writeoff_date" class="form-control"
                      value="<?= date('Y-m-d') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Time</label>
                    <input type="time" name="writeoff_time" id="writeoffTime" class="form-control"
                      value="<?= date('H:i') ?>">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn-primary-custom" id="submitBtn"><i class="bi bi-check-lg"></i><?= $edit_item ? 'Update Asset' : 'Add Asset' ?></button>
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
    <i class="bi bi-plus-lg"></i> Add Asset
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
      <option value="Disposed"   <?= $f_status === 'Disposed'   ? 'selected' : '' ?>>Disposed</option>
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
  <div style="background:var(--accent);color:#fff;border-radius:10px;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 20px rgba(242,140,40,.4)">
    <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px">
      <i class="bi bi-check2-square me-2"></i><span id="bulkCount">0</span> item(s) selected
    </span>
    <div style="display:flex;gap:8px">
      <form method="POST" id="bulkEwasteForm" style="display:inline">
        <input type="hidden" name="bulk_action" value="ewaste">
        <div id="ewaste_ids"></div>
        <button type="button" onclick="goToWriteoff()"
          style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:7px;padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px">
          <i class="bi bi-recycle"></i> Flag as E-Waste
        </button>
      </form>
      <form method="POST" id="bulkDeleteForm" style="display:inline">
        <input type="hidden" name="bulk_action" value="delete">
        <div id="delete_ids"></div>
        <?php if (isAdmin()): ?>
        <button type="button" onclick="submitBulk('delete')"
          style="background:rgba(248,81,73,.25);color:#fff;border:1px solid rgba(248,81,73,.5);border-radius:7px;padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px">
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
      <?php while ($row = $items->fetch_assoc()): ?>
      <tr>
        <td><input type="checkbox" class="row-check" value="<?= $row['id'] ?>"
          style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px"></td>
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
          <?php if ($row['item_status'] === 'Active'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(22,163,74,.1);color:#16a34a;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#16a34a;border-radius:50%;display:inline-block"></span> Active
            </span>
          <?php elseif ($row['item_status'] === 'Collected'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(234,179,8,.1);color:#ca8a04;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#ca8a04;border-radius:50%;display:inline-block"></span> Collected
            </span>
          <?php elseif ($row['item_status'] === 'Disposed'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,.1);color:#dc2626;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <span style="width:6px;height:6px;background:#dc2626;border-radius:50%;display:inline-block"></span> Disposed
            </span>
          <?php else: ?>
            <span style="background:rgba(100,116,139,.1);color:var(--muted);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif">
              <?= h($row['item_status']) ?>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
            <?php if ($row['item_status'] !== 'Disposed' && $row['item_status'] !== 'Collected' && empty($row['ew_status'])): ?>
            <a href="writeoff.php?item_id=<?= $row['id'] ?>"
              style="font-size:12px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.1);border:none;border-radius:6px;cursor:pointer;padding:5px 12px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif;text-decoration:none;display:inline-block">E-Waste</a>
            <?php elseif (!empty($row['ew_status'])): ?>
            <span style="font-size:11px;font-weight:600;color:#d97706;background:rgba(245,158,11,.1);border-radius:6px;padding:5px 10px;white-space:nowrap;font-family:'Plus Jakarta Sans',sans-serif">
              <?= $row['ew_status'] === 'Pending' ? '⏳ Pending' : '✓ In E-Waste' ?>
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

function updateBulkBar() {
  const bar   = document.getElementById('bulkBar');
  const count = selectedIds.size;
  document.getElementById('bulkCount').textContent = count;
  bar.style.display = count > 0 ? 'block' : 'none';
}

function syncCheckboxes() {
  // Sync visible checkboxes to match the selectedIds Set
  document.querySelectorAll('.row-check').forEach(cb => {
    cb.checked = selectedIds.has(cb.value);
  });
  const all     = document.querySelectorAll('.row-check');
  const checked = [...all].filter(cb => cb.checked);
  const selectAll = document.getElementById('selectAll');
  selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
  selectAll.checked = all.length > 0 && checked.length === all.length;
}

// Row checkbox change
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('row-check')) {
    if (e.target.checked) selectedIds.add(e.target.value);
    else selectedIds.delete(e.target.value);
    syncCheckboxes();
    updateBulkBar();
  }
});

// Select-all checkbox
document.getElementById('selectAll').addEventListener('change', function() {
  document.querySelectorAll('.row-check').forEach(cb => {
    if (this.checked) selectedIds.add(cb.value);
    else selectedIds.delete(cb.value);
    cb.checked = this.checked;
  });
  updateBulkBar();
});

// Re-sync on DataTables page change (draw event)
$(document).on('draw.dt', function() {
  syncCheckboxes();
});

function clearSelection() {
  selectedIds.clear();
  document.getElementById('selectAll').checked = false;
  document.getElementById('selectAll').indeterminate = false;
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
  updateBulkBar();
}

function goToWriteoff() {
  if (!selectedIds.size) return;
  window.location.href = 'writeoff.php?bulk_ids=' + Array.from(selectedIds).join(',');
}

function submitBulk(action) {
  if (!selectedIds.size) return;
  const confirmMsg = action === 'delete'
    ? 'Permanently delete ' + selectedIds.size + ' selected asset(s)? This cannot be undone.'
    : 'Flag ' + selectedIds.size + ' selected asset(s) as E-Waste?';
  if (!confirm(confirmMsg)) return;

  const formId      = action === 'delete' ? 'bulkDeleteForm' : 'bulkEwasteForm';
  const containerId = action === 'delete' ? 'delete_ids'     : 'ewaste_ids';
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

<?php require_once 'includes/layout_end.php'; ?>
