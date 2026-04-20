<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ── MIGRATION: ewaste_requests table ──
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

// Fetch asset classes dynamically
$_ac_res = $db->query("SELECT ac.name, ag.name as group_name FROM asset_classes ac LEFT JOIN asset_groups ag ON ac.group_id=ag.id ORDER BY ag.sort_order, ag.name, ac.sort_order, ac.name");
$_asset_classes = [];
$_asset_classes_grouped = [];
if ($_ac_res) while ($r = $_ac_res->fetch_assoc()) {
    $_asset_classes[] = $r['name'];
    $grp = $r['group_name'] ?? 'Uncategorised';
    $_asset_classes_grouped[$grp][] = $r['name'];
}
if (empty($_asset_classes)) {
    $_asset_classes = ['MONITOR','PC','LAPTOP','PRINTER','SCANNER','UPS','KEYBOARD','MOUSE','OTHER'];
    $_asset_classes_grouped = ['Uncategorised' => $_asset_classes];
}

// ── BULK MARK AS COLLECTED ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bulk_collect' && isAdmin()) {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    $count = 0;
    foreach ($ids as $bid) {
        $item = $db->query("SELECT * FROM ewaste_items WHERE id=$bid AND disposal_status='Approved'")->fetch_assoc();
        if ($item) {
            $db->query("UPDATE ewaste_items SET disposal_status='Collected', date_disposed=CURDATE(), updated_at=NOW() WHERE id=$bid");
            if ($item['original_inventory_id']) {
                $inv_id = (int)$item['original_inventory_id'];
                $db->query("UPDATE inventory_items SET item_status='Collected', updated_at=NOW() WHERE id=$inv_id");
            }
            logActivity($_SESSION['user_id'], 'COLLECTED', 'ewaste', $bid, 'Bulk collected: '.$item['description']);
            $count++;
        }
    }
    header('Location: ewaste.php?msg=bulk_collected&count='.$count); exit;
}

// ── BULK RESTORE TO IT ASSETS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bulk_restore' && isAdmin()) {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    $count = 0;
    foreach ($ids as $bid) {
        $ew = $db->query("SELECT * FROM ewaste_items WHERE id=$bid")->fetch_assoc();
        if ($ew) {
            if ($ew['original_inventory_id']) {
                $inv_id = (int)$ew['original_inventory_id'];
                $db->query("UPDATE inventory_items SET item_status='Active', location='', updated_at=NOW() WHERE id=$inv_id");
            } else {
                $stmt = $db->prepare("INSERT INTO inventory_items (asset_number,asset_class,description,serial_number,item_status,condition_status,created_by) VALUES (?,?,?,?,'Active','Good',?)");
                $stmt->bind_param('ssssi', $ew['asset_number'],$ew['asset_class'],$ew['description'],$ew['serial_number'],$_SESSION['user_id']);
                $stmt->execute(); $stmt->close();
            }
            $db->query("DELETE FROM ewaste_items WHERE id=$bid");
            logActivity($_SESSION['user_id'],'RESTORE','ewaste',$bid,'Bulk restored to IT Assets: '.$ew['description']);
            $count++;
        }
    }
    header('Location: ewaste.php?msg=bulk_restored&count='.$count); exit;
}

// ── BULK DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'bulk_delete' && isAdmin()) {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    $count = 0;
    foreach ($ids as $bid) {
        $item = $db->query("SELECT * FROM ewaste_items WHERE id=$bid")->fetch_assoc();
        if ($item) {
            if ($item['original_inventory_id']) {
                $inv_id = (int)$item['original_inventory_id'];
                $db->query("UPDATE inventory_items SET location='', item_status='Active', updated_at=NOW() WHERE id=$inv_id AND item_status IN ('Active','Collected')");
            }
            $db->query("DELETE FROM ewaste_items WHERE id=$bid");
            logActivity($_SESSION['user_id'],'DELETE','ewaste',$bid,'Bulk deleted e-waste: '.$item['description']);
            $count++;
        }
    }
    header('Location: ewaste.php?msg=bulk_deleted&count='.$count); exit;
}

// ── MARK AS COLLECTED ──
if ($action === 'collect' && isAdmin() && $id) {
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE ewaste_items SET disposal_status='Collected', date_disposed=CURDATE(), updated_at=NOW() WHERE id=$id");
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET item_status='Collected', updated_at=NOW() WHERE id=$inv_id");
        }
        logActivity($_SESSION['user_id'],'COLLECTED','ewaste',$id,'Marked as collected: '.$item['description']);
        header('Location: ewaste.php?msg=collected'); exit;
    }
}

// ── DELETE ──
if ($action === 'delete' && isAdmin() && $id) {
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        // Reset the linked inventory item so it no longer shows E-Waste location
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET location='', item_status='Active', updated_at=NOW() WHERE id=$inv_id AND item_status IN ('Active','Collected')");
        }
        $db->query("DELETE FROM ewaste_items WHERE id=$id");
        logActivity($_SESSION['user_id'],'DELETE','ewaste',$id,'Deleted e-waste: '.$item['description']);
        header('Location: ewaste.php?msg=deleted'); exit;
    }
}

// ── UNDO COLLECTED (revert Collected → Approved) ──
if ($action === 'undispose' && isAdmin() && $id) {
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE ewaste_items SET disposal_status='Approved', date_disposed=NULL, updated_at=NOW() WHERE id=$id");
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET item_status='Active', updated_at=NOW() WHERE id=$inv_id");
        }
        logActivity($_SESSION['user_id'],'UPDATE','ewaste',$id,'Reverted collection: '.$item['description']);
        header('Location: ewaste.php?msg=undisposed'); exit;
    }
}

// ── RESTORE TO IT ASSETS ──
if ($action === 'restore' && $id) {
    $ew = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($ew) {
        if ($ew['original_inventory_id']) {
            $inv_id = (int)$ew['original_inventory_id'];
            $db->query("UPDATE inventory_items SET item_status='Active', location='', updated_at=NOW() WHERE id=$inv_id");
        } else {
            $stmt = $db->prepare("INSERT INTO inventory_items (asset_number,asset_class,description,serial_number,item_status,condition_status,created_by) VALUES (?,?,?,?,'Active','Good',?)");
            $stmt->bind_param('ssssi', $ew['asset_number'],$ew['asset_class'],$ew['description'],$ew['serial_number'],$_SESSION['user_id']);
            $stmt->execute(); $stmt->close();
        }
        $db->query("DELETE FROM ewaste_items WHERE id=$id");
        logActivity($_SESSION['user_id'],'RESTORE','ewaste',$id,'Restored to IT Assets: '.$ew['description']);
        header('Location: ewaste.php?msg=restored'); exit;
    }
}

// ── STAFF: REQUEST ADD TO E-WASTE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_ewaste_add']) && !isAdmin()) {
    $data = [];
    foreach(['asset_number','asset_class','description','serial_number','notes'] as $f)
        $data[$f] = trim($_POST[$f] ?? '');
    if (empty($data['description']) || empty($data['asset_class'])) {
        $err = 'Description and Asset Class are required.';
    } else {
        // Check for duplicate pending request
        $desc_esc  = mysqli_real_escape_string($db, $data['description']);
        $class_esc = mysqli_real_escape_string($db, $data['asset_class']);
        $uid = (int)$_SESSION['user_id'];
        $dup = $db->query("SELECT id FROM ewaste_requests WHERE requested_by=$uid AND description='$desc_esc' AND asset_class='$class_esc' AND type='add' AND status='Pending'")->fetch_assoc();
        if ($dup) {
            $err = 'You already have a pending request to add this item to E-Waste. Please wait for admin to review it.';
        } else {
            $stmt = $db->prepare("INSERT INTO ewaste_requests (type,requested_by,asset_number,asset_class,description,serial_number,notes) VALUES ('add',?,?,?,?,?,?)");
            $stmt->bind_param('isssss',$_SESSION['user_id'],$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$data['notes']);
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'],'CREATE','ewaste',0,'Requested to add e-waste: '.$data['description']);
            header('Location: ewaste.php?msg=req_add_submitted'); exit;
        }
    }
}

// ── ADMIN: APPROVE EWASTE REQUEST ──
if ($action === 'approve_ew_req' && isAdmin() && $id) {
    $req = $db->query("SELECT * FROM ewaste_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        if ($req['type'] === 'add') {
            $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,disposal_status,date_flagged,created_by) VALUES (?,?,?,?,'Approved',CURDATE(),?)");
            $stmt->bind_param('ssssi',$req['asset_number'],$req['asset_class'],$req['description'],$req['serial_number'],$req['requested_by']);
            $stmt->execute(); $stmt->close();
        } elseif ($req['type'] === 'bypass' && $req['inventory_id']) {
            $inv_id = (int)$req['inventory_id'];
            $inv = $db->query("SELECT * FROM inventory_items WHERE id=$inv_id")->fetch_assoc();
            if ($inv) {
                $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$inv_id")->fetch_assoc();
                if (!$exists) {
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,created_by) VALUES (?,?,?,?,?,CURDATE(),'Approved',?)");
                    $stmt->bind_param('ssssii',$inv['asset_number'],$inv['asset_class'],$inv['description'],$inv['serial_number'],$inv_id,$req['requested_by']);
                    $stmt->execute(); $stmt->close();
                }
                $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$inv_id");
            }
        }
        $db->query("UPDATE ewaste_requests SET status='Approved', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','ewaste',$id,'Approved e-waste request: '.$req['description']);
        header('Location: inventory.php?view=pending_requests&msg=ew_req_approved'); exit;
    }
}

// ── ADMIN: REJECT EWASTE REQUEST ──
if ($action === 'reject_ew_req' && isAdmin() && $id) {
    $req = $db->query("SELECT * FROM ewaste_requests WHERE id=$id AND status='Pending'")->fetch_assoc();
    if ($req) {
        $db->query("UPDATE ewaste_requests SET status='Rejected', reviewed_by={$_SESSION['user_id']}, reviewed_at=NOW() WHERE id=$id");
        logActivity($_SESSION['user_id'],'UPDATE','ewaste',$id,'Rejected e-waste request: '.$req['description']);
        header('Location: inventory.php?view=pending_requests&msg=ew_req_rejected'); exit;
    }
}

// ── SAVE (EDIT/ADD) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $data = [];
    foreach(['asset_number','asset_class','description','serial_number','condition_on_disposal','disposal_method','vendor_collector','certificate_number','notes'] as $f)
        $data[$f] = trim($_POST[$f] ?? '');
    $date_flagged  = $_POST['date_flagged'] ?: null;
    $date_disposed = $_POST['date_disposed'] ?: null;
    $weight_kg     = $_POST['weight_kg'] ?: null;
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if (empty($data['description']) || empty($data['asset_class'])) {
        $err = 'Description and Asset Class are required.';
    } else {
        if ($edit_id) {
            $current = $db->query("SELECT disposal_status FROM ewaste_items WHERE id=$edit_id")->fetch_assoc();
            $keep_status = $current['disposal_status'] ?? 'Pending';
            $stmt = $db->prepare("UPDATE ewaste_items SET asset_number=?,asset_class=?,description=?,serial_number=?,condition_on_disposal=?,disposal_status=?,date_flagged=?,date_disposed=?,disposal_method=?,weight_kg=?,vendor_collector=?,certificate_number=?,notes=?,updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sssssssssdsssi',$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$data['condition_on_disposal'],$keep_status,$date_flagged,$date_disposed,$data['disposal_method'],$weight_kg,$data['vendor_collector'],$data['certificate_number'],$data['notes'],$edit_id);
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'],'UPDATE','ewaste',$edit_id,'Updated e-waste: '.$data['description']);
            header('Location: ewaste.php?msg=updated'); exit;
        } else {
            $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,condition_on_disposal,disposal_status,date_flagged,date_disposed,disposal_method,weight_kg,vendor_collector,certificate_number,notes,created_by) VALUES (?,?,?,?,'','Approved',?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssdsssi',$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$date_flagged,$date_disposed,$data['disposal_method'],$weight_kg,$data['vendor_collector'],$data['certificate_number'],$data['notes'],$_SESSION['user_id']);
            $stmt->execute(); $new_id = $stmt->insert_id; $stmt->close();
            logActivity($_SESSION['user_id'],'CREATE','ewaste',$new_id,'Added e-waste: '.$data['description']);
            header('Location: ewaste.php?msg=added'); exit;
        }
    }
}

$edit_item = null;
if ($action === 'edit' && $id) {
    $edit_item = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
}

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')    $msg = 'E-waste item added successfully.';
if ($url_msg === 'updated')  $msg = 'E-waste record updated.';
if ($url_msg === 'deleted')  $msg = 'Item deleted.';
if ($url_msg === 'restored') $msg = 'Item restored back to IT Assets.';
if ($url_msg === 'writeoff') $msg = 'Write-off submitted. Awaiting admin approval in Write Off Authorisation.';
if ($url_msg === 'undisposed') $msg = 'Item reverted back to Approved.';
if ($url_msg === 'collected')      $msg = 'Item marked as collected.';
if ($url_msg === 'bulk_collected') $msg = ($_GET['count'] ?? 0).' item(s) marked as collected.';
if ($url_msg === 'bulk_restored')  $msg = ($_GET['count'] ?? 0).' item(s) restored to IT Assets.';
if ($url_msg === 'bulk_deleted')   $msg = ($_GET['count'] ?? 0).' item(s) deleted.';
if ($url_msg === 'bypass_added')   $msg = 'Item sent directly to E-Waste (Approved) — write-off bypassed.';
if ($url_msg === 'bypass_bulk')    $msg = ($_GET['count'] ?? 0).' item(s) sent directly to E-Waste (Approved) — write-off bypassed.';
if ($url_msg === 'req_add_submitted') $msg = 'Add to E-Waste request submitted. Awaiting admin approval.';
if ($url_msg === 'ew_req_approved')  $msg = 'E-Waste request approved.';
if ($url_msg === 'ew_req_rejected')  $msg = 'E-Waste request rejected.';

$page_title = 'E-Waste Management'; $active_nav = 'ewaste';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if (($action === 'add' || $action === 'edit') && ($edit_item ? isAdmin() : true)): ?>
<div class="form-card mb-4">
  <h5 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;margin-bottom:20px;color:var(--text)">
    <i class="bi bi-recycle me-2" style="color:#16a34a"></i>
    <?= $edit_item ? 'Edit E-Waste Record' : (isAdmin() ? 'Add E-Waste Item' : 'Request to Add E-Waste Item') ?>
  </h5>
  <?php if (!isAdmin() && !$edit_item): ?>
  <div style="background:rgba(37,99,235,.07);border:1px solid rgba(37,99,235,.2);border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
    <i class="bi bi-info-circle-fill" style="color:#2563eb;font-size:16px;flex-shrink:0"></i>
    <span style="font-size:13px;color:#1d4ed8;font-weight:500">Your request will be reviewed by admin before the item appears in E-Waste.</span>
  </div>
  <?php endif; ?>
  <form method="POST">
    <?php if ($edit_item): ?><input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>">
    <?php elseif (!isAdmin()): ?><input type="hidden" name="request_ewaste_add" value="1">
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Asset Number</label>
        <input type="text" name="asset_number" class="form-control" value="<?= h($edit_item['asset_number'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Asset Class <span style="color:var(--red)">*</span></label>
        <select name="asset_class" class="form-select" required>
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
        <input type="text" name="description" class="form-control" required value="<?= h($edit_item['description'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Serial Number</label>
        <input type="text" name="serial_number" class="form-control" value="<?= h($edit_item['serial_number'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date Flagged</label>
        <input type="date" name="date_flagged" class="form-control" value="<?= h($edit_item['date_flagged'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date Disposed</label>
        <input type="date" name="date_disposed" class="form-control" value="<?= h($edit_item['date_disposed'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Weight (kg)</label>
        <input type="number" name="weight_kg" step="0.01" class="form-control" value="<?= h($edit_item['weight_kg'] ?? '') ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($edit_item['notes'] ?? '') ?></textarea>
      </div>

      <?php if ($edit_item): ?>
      <!-- Write-Off Proof Panel -->
      <div class="col-12">
        <?php
        $has_name = !empty($edit_item['writeoff_name']);
        $has_sig  = !empty($edit_item['writeoff_signature']);
        ?>
        <div style="border:1px solid <?= $has_name ? 'rgba(22,163,74,.3)' : 'var(--border)' ?>;border-radius:12px;overflow:hidden">
          <div style="background:<?= $has_name ? 'rgba(22,163,74,.06)' : 'var(--surface2,#f8fafc)' ?>;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div style="display:flex;align-items:center;gap:10px">
              <i class="bi bi-<?= $has_name ? 'patch-check-fill' : 'pen' ?>" style="color:<?= $has_name ? '#16a34a' : 'var(--muted)' ?>;font-size:18px"></i>
              <div>
                <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Write-Off Authorisation</div>
                <?php if ($has_name): ?>
                <div style="font-size:12px;color:var(--muted)">Signed by <strong style="color:var(--text)"><?= h($edit_item['writeoff_name']) ?></strong> — <?= $edit_item['writeoff_designation'] ? h($edit_item['writeoff_designation']).' — ' : '' ?><?= $edit_item['writeoff_date'] ? date('d/m/Y', strtotime($edit_item['writeoff_date'])) : '' ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($has_name): ?>
            <button type="button" onclick="document.getElementById('sigModal').style.display='flex'"
              style="background:rgba(22,163,74,.12);color:#16a34a;border:1px solid rgba(22,163,74,.3);border-radius:8px;padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;font-family:'Plus Jakarta Sans',sans-serif">
              <i class="bi bi-id-card"></i> View Proof
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Sig Modal -->
      <?php if ($has_name): ?>
      <div id="sigModal" onclick="if(event.target===this)this.style.display='none'"
        style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:999999;align-items:center;justify-content:center;padding:20px">
        <div style="background:var(--surface);border-radius:16px;max-width:680px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
          <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
            <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;color:var(--text);font-size:15px">
              <i class="bi bi-patch-check-fill me-2" style="color:#16a34a"></i>Write-Off Authorisation
            </div>
            <button type="button" onclick="document.getElementById('sigModal').style.display='none'"
              style="background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer">&times;</button>
          </div>
          <div style="padding:20px 24px 24px">
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;font-size:13px">
              <?php foreach([
                ['Authorised By', $edit_item['writeoff_name']],
                ['Designation',   $edit_item['writeoff_designation']],
                ['Staff / Worker ID', $edit_item['writeoff_signature']],
                ['Date', $edit_item['writeoff_date'] ? date('d/m/Y', strtotime($edit_item['writeoff_date'])) : '—'],
                ['Time', (function() use ($edit_item) { preg_match('/\d{2}:\d{2}/', $edit_item['notes'] ?? '', $tm); return !empty($tm[0]) ? $tm[0] : '—'; })()],
              ] as [$label, $val]): ?>
              <div style="background:var(--body-bg);border-radius:8px;padding:12px;border:1px solid var(--border)">
                <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px"><?= $label ?></div>
                <div style="color:var(--text);font-weight:600"><?= h($val ?: '—') ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn-primary-custom">
          <i class="bi bi-<?= $edit_item ? 'check-lg' : (isAdmin() ? 'check-lg' : 'send') ?>"></i>
          <?= $edit_item ? 'Update Record' : (isAdmin() ? 'Add Record' : 'Submit Request') ?>
        </button>
        <a href="ewaste.php" class="btn-secondary-custom"><i class="bi bi-x"></i>Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- PAGE HEADER -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">E-Waste Management</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Track and manage devices flagged for disposal</p>
  </div>
  <a href="ewaste.php?action=add" class="btn-primary-custom" style="padding:10px 20px;font-size:13px">
    <i class="bi bi-<?= isAdmin() ? 'plus-lg' : 'send' ?>"></i> <?= isAdmin() ? 'Add Item' : 'Request to Add Item' ?>
  </a>
</div>

<!-- FILTER BAR -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  <form method="GET" action="ewaste.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%">
    <div style="position:relative;flex:1;min-width:220px">
      <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px"></i>
      <input type="text" name="ew_search" value="<?= h($_GET['ew_search'] ?? '') ?>" placeholder="Search asset no., description..."
        style="width:100%;padding:9px 12px 9px 34px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none"
        onfocus="this.style.borderColor='var(--accent)'" onblur="this.style.borderColor='var(--border)'">
    </div>
    <select name="ew_status" onchange="this.form.submit()"
      style="padding:9px 14px;background:var(--surface);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;min-width:140px">
      <option value="">All Status</option>
      <option value="Approved"   <?= ($_GET['ew_status'] ?? '') === 'Approved'   ? 'selected' : '' ?>>Approved</option>
      <option value="Collected"  <?= ($_GET['ew_status'] ?? '') === 'Collected'  ? 'selected' : '' ?>>Collected</option>
    </select>
    <button type="submit" style="padding:9px 20px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;display:flex;align-items:center;gap:6px">
      <i class="bi bi-funnel-fill"></i> Filter
    </button>
    <?php if (!empty($_GET['ew_search']) || !empty($_GET['ew_status'])): ?>
    <a href="ewaste.php" style="padding:9px 16px;background:var(--surface);color:var(--muted);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;font-family:'Plus Jakarta Sans',sans-serif">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- TABLE -->
<?php
$ew_search = trim($_GET['ew_search'] ?? '');
$ew_status = trim($_GET['ew_status'] ?? '');
$ew_where  = ['1=1'];
if ($ew_search) $ew_where[] = "(ew.asset_number LIKE '%".mysqli_real_escape_string($db,$ew_search)."%' OR ew.description LIKE '%".mysqli_real_escape_string($db,$ew_search)."%')";
if ($ew_status) $ew_where[] = "ew.disposal_status='".mysqli_real_escape_string($db,$ew_status)."'";
$ew_sql   = implode(' AND ', $ew_where);
// Exclude Pending items — those live in Write-Off Authorisation queue only
$ew_base  = "ew.disposal_status != 'Pending'";
$ew_full  = $ew_sql === '1=1' ? $ew_base : "($ew_sql) AND $ew_base";
$items    = $db->query("SELECT ew.*, u.full_name as added_by FROM ewaste_items ew LEFT JOIN users u ON ew.created_by=u.id WHERE $ew_full ORDER BY ew.created_at DESC");
$ew_total = $db->query("SELECT COUNT(*) c FROM ewaste_items ew WHERE $ew_full")->fetch_assoc()['c'];
?>
<?php if (isAdmin()): ?>
<div id="ewBulkBar" style="display:none;background:#1a2332;border-radius:10px;padding:12px 20px;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">
  <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:#fff;display:flex;align-items:center;gap:8px">
    <i class="bi bi-check2-square"></i>
    <span id="ewBulkCount">0</span> item(s) selected
  </span>
  <div style="display:flex;gap:8px;align-items:center">

    <!-- Custom dropdown -->
    <div style="position:relative" id="ewActionDropdownWrap">
      <button type="button" onclick="toggleEwDropdown()"
        style="display:flex;align-items:center;gap:10px;padding:7px 14px;background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:7px;font-size:13px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif;cursor:pointer;min-width:200px;justify-content:space-between">
        <span id="ewActionLabel">— Choose Action —</span>
        <i class="bi bi-chevron-down" style="font-size:11px"></i>
      </button>
      <div id="ewActionMenu"
        style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e4e8ef;border-radius:9px;overflow:hidden;min-width:220px;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:99999">
        <div id="ewOptCollectDiv" onclick="selectEwAction('bulk_collect','✓ Mark as Collected')"
          style="padding:11px 16px;font-size:13px;font-weight:600;color:#15803d;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .1s"
          onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''">
          <i class="bi bi-truck"></i> Mark as Collected
        </div>
        <div id="ewOptCollect" style="height:1px;background:#f0f2f5"></div>
        <div onclick="selectEwAction('bulk_restore','↩ Restore to IT Assets')"
          style="padding:11px 16px;font-size:13px;font-weight:600;color:#92400e;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .1s"
          onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background=''">
          <i class="bi bi-arrow-counterclockwise"></i> Restore to IT Assets
        </div>
        <div style="height:1px;background:#f0f2f5"></div>
        <div onclick="selectEwAction('bulk_delete','✕ Delete')"
          style="padding:11px 16px;font-size:13px;font-weight:600;color:#dc2626;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .1s"
          onmouseover="this.style.background='#fff1f2'" onmouseout="this.style.background=''">
          <i class="bi bi-trash-fill"></i> Delete
        </div>
      </div>
    </div>

    <input type="hidden" id="ewBulkActionValue" value="">
    <button type="button" onclick="applyEwBulk()"
      style="background:var(--accent);color:#fff;border:none;border-radius:7px;padding:7px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif">
      Apply
    </button>
    <button type="button" onclick="clearEwSelection()"
      style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:7px;padding:7px 12px;font-size:13px;cursor:pointer">
      <i class="bi bi-x"></i>
    </button>
  </div>
</div>
<form method="POST" id="ewBulkForm" style="display:none">
  <input type="hidden" name="bulk_action" id="ewBulkActionInput" value="">
  <div id="ew_bulk_ids"></div>
</form>
<?php endif; ?>

<div class="table-card">

  <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--muted);font-weight:500">
      <strong style="color:var(--text)"><?= number_format($ew_total) ?></strong> record<?= $ew_total !== 1 ? 's' : '' ?><?= ($ew_search||$ew_status) ? ' <span style="color:var(--accent)">(filtered)</span>' : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <?php if (isAdmin()): ?>
        <th style="width:40px">
          <input type="checkbox" id="ewSelectAll" style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px">
        </th>
        <?php endif; ?>
        <th>ASSET NO.</th><th>CLASS</th><th>DESCRIPTION</th>
        <th>SERIAL NO.</th><th>STATUS</th><th>DATE FLAGGED</th>
        <?php if (isAdmin()): ?><th>ACTIONS</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php while ($row = $items->fetch_assoc()): ?>
      <tr>
        <?php if (isAdmin()): ?>
        <td>
          <input type="checkbox" class="ew-row-check" value="<?= $row['id'] ?>"
            data-status="<?= h($row['disposal_status']) ?>"
            style="cursor:pointer;accent-color:var(--accent);width:15px;height:15px">
        </td>
        <?php endif; ?>
        <td>
          <a href="ewaste.php?action=edit&id=<?= $row['id'] ?>"
            style="color:var(--accent);font-size:13px;font-weight:600;text-decoration:none">
            <?= h($row['asset_number'] ?: '—') ?>
          </a>
        </td>
        <td>
          <span style="display:inline-block;background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:700">
            <?= h($row['asset_class']) ?>
          </span>
        </td>
        <td style="font-weight:500;font-size:13px"><?= h($row['description']) ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['serial_number'] ?: '—') ?></td>
        <td>
          <?php if ($row['disposal_status'] === 'Approved'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(59,130,246,.1);color:#2563eb;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600">
              <span style="width:6px;height:6px;background:#2563eb;border-radius:50%"></span> Approved
            </span>
          <?php elseif ($row['disposal_status'] === 'Collected'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(22,163,74,.1);color:#16a34a;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600">
              <span style="width:6px;height:6px;background:#16a34a;border-radius:50%"></span> Collected
            </span>
          <?php elseif ($row['disposal_status'] === 'Disposed'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,.1);color:#dc2626;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600">
              <span style="width:6px;height:6px;background:#dc2626;border-radius:50%"></span> Disposed
            </span>
          <?php endif; ?>
        </td>
        <td style="font-size:13px"><?= $row['date_flagged'] ? date('d/m/Y', strtotime($row['date_flagged'])) : '—' ?></td>
        <?php if (isAdmin()): ?>
        <td>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:nowrap;font-family:'Plus Jakarta Sans',sans-serif">

            <?php if ($row['disposal_status'] === 'Approved'): ?>
              <a href="ewaste.php?action=collect&id=<?= $row['id'] ?>" onclick="return confirm('Mark this item as collected?')"
                style="font-size:12px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.08);border:1.5px solid rgba(22,163,74,.2);border-radius:8px;padding:5px 13px;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:5px">
                <i class="bi bi-truck"></i> Collected
              </a>
            <?php elseif (in_array($row['disposal_status'], ['Collected','Disposed'])): ?>
              <a href="ewaste.php?action=undispose&id=<?= $row['id'] ?>" onclick="return confirm('Revert this item back to Approved?')"
                style="font-size:12px;font-weight:700;color:#c2590a;text-decoration:none;border:1.5px solid #f5c49a;border-radius:6px;padding:4px 12px;background:#fde8d0;white-space:nowrap">↩ Undo</a>
            <?php endif; ?>

            <a href="ewaste.php?action=edit&id=<?= $row['id'] ?>"
              style="font-size:12px;font-weight:700;color:var(--text);text-decoration:none;border:1.5px solid var(--border);border-radius:6px;padding:4px 12px;background:transparent;white-space:nowrap">Edit</a>

            <?php if (!in_array($row['disposal_status'], ['Collected','Disposed'])): ?>
            <a href="ewaste.php?action=restore&id=<?= $row['id'] ?>" onclick="return confirm('Restore this item back to IT Assets?')"
              style="font-size:12px;font-weight:700;color:#c2590a;text-decoration:none;border:1.5px solid #f5c49a;border-radius:6px;padding:4px 12px;background:#fde8d0;white-space:nowrap">Restore</a>
            <?php endif; ?>

            <a href="ewaste.php?action=delete&id=<?= $row['id'] ?>" onclick="return confirm('Delete this record?')"
              style="font-size:12px;font-weight:700;color:#dc2626;text-decoration:none;border:1.5px solid rgba(239,68,68,.2);border-radius:6px;padding:4px 12px;background:rgba(239,68,68,.08);white-space:nowrap">Delete</a>

          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (isAdmin()): ?>
<script>
const ewSelected = new Set();

function updateEwBulkBar() {
  document.querySelectorAll('tbody .ew-row-check').forEach(cb => {
    if (cb.checked) ewSelected.add(cb.value);
    else ewSelected.delete(cb.value);
  });
  const bar = document.getElementById('ewBulkBar');
  const count = ewSelected.size;
  document.getElementById('ewBulkCount').textContent = count;
  bar.style.display = count > 0 ? 'flex' : 'none';

  // Hide "Mark as Collected" if every selected item is already Collected
  const anyNotCollected = [...document.querySelectorAll('tbody .ew-row-check:checked')]
    .some(cb => cb.getAttribute('data-status') !== 'Collected');
  const collectOpt = document.getElementById('ewOptCollect');
  const collectDiv = document.getElementById('ewOptCollectDiv');
  if (collectOpt) collectOpt.style.display = anyNotCollected ? '' : 'none';
  if (collectDiv) collectDiv.style.display = anyNotCollected ? '' : 'none';
}

function syncEwCheckboxes() {
  const rows = document.querySelectorAll('tbody .ew-row-check');
  const selectAll = document.querySelector('thead #ewSelectAll');
  if (!selectAll) return;
  let checkedCount = 0;
  rows.forEach(cb => {
    cb.checked = ewSelected.has(cb.value);
    if (cb.checked) checkedCount++;
  });
  selectAll.checked       = rows.length > 0 && checkedCount === rows.length;
  selectAll.indeterminate = checkedCount > 0 && checkedCount < rows.length;
  updateEwBulkBar();
}

// Row checkbox — event delegation
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('ew-row-check')) {
    if (e.target.checked) ewSelected.add(e.target.value);
    else ewSelected.delete(e.target.value);
    syncEwCheckboxes();
  }
});

// Header select-all — event delegation
document.addEventListener('change', function(e) {
  if (e.target.id === 'ewSelectAll') {
    document.querySelectorAll('tbody .ew-row-check').forEach(cb => {
      cb.checked = e.target.checked;
      if (e.target.checked) ewSelected.add(cb.value);
      else ewSelected.delete(cb.value);
    });
    updateEwBulkBar();
  }
});

// Hook into drawCallback — resets header checkbox after page change
window._onDtDraw = function() {
  const selectAll = document.querySelector('thead #ewSelectAll');
  if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
  syncEwCheckboxes();
};

function toggleEwAll(checked) {
  document.querySelectorAll('tbody .ew-row-check').forEach(cb => { cb.checked = checked; });
  updateEwBulkBar();
}

function clearEwSelection() {
  ewSelected.clear();
  document.querySelectorAll('tbody .ew-row-check').forEach(cb => cb.checked = false);
  const selectAll = document.querySelector('thead #ewSelectAll');
  if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
  document.getElementById('ewBulkBar').style.display = 'none';
  document.getElementById('ewBulkCount').textContent = '0';
  document.getElementById('ewBulkActionValue').value = '';
  document.getElementById('ewActionLabel').textContent = '— Choose Action —';
}

function toggleEwDropdown() {
  var menu = document.getElementById('ewActionMenu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

function selectEwAction(value, label) {
  document.getElementById('ewBulkActionValue').value = value;
  document.getElementById('ewActionLabel').textContent = label;
  document.getElementById('ewActionMenu').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('ewActionDropdownWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('ewActionMenu').style.display = 'none';
  }
});

function applyEwBulk() {
  var action = document.getElementById('ewBulkActionValue').value;
  if (!action) { alert('Please choose an action from the dropdown.'); return; }
  if (!ewSelected.size) return;

  var labels = {
    bulk_collect: 'Mark ' + ewSelected.size + ' item(s) as Collected?',
    bulk_restore: 'Restore ' + ewSelected.size + ' item(s) back to IT Assets?',
    bulk_delete:  'Permanently delete ' + ewSelected.size + ' item(s)? This cannot be undone.',
  };
  if (!confirm(labels[action])) return;

  document.getElementById('ewBulkActionInput').value = action;
  var container = document.getElementById('ew_bulk_ids');
  container.innerHTML = '';
  ewSelected.forEach(function(id) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
    container.appendChild(inp);
  });
  document.getElementById('ewBulkForm').submit();
}
</script>
<?php endif; ?>

<?php require_once 'includes/layout_end.php'; ?>
