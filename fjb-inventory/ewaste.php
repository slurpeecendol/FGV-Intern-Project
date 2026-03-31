<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

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
    $item = $db->query("SELECT description FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($item) {
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
        header('Location: inventory.php?msg=restored'); exit;
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
            $stmt->bind_param('ssssssssdsssi',$data['asset_number'],$data['asset_class'],$data['description'],$data['serial_number'],$date_flagged,$date_disposed,$data['disposal_method'],$weight_kg,$data['vendor_collector'],$data['certificate_number'],$data['notes'],$_SESSION['user_id']);
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
if ($url_msg === 'writeoff') $msg = 'Write-off submitted. Awaiting admin approval in Write Off Authorisation.';
if ($url_msg === 'undisposed') $msg = 'Item reverted back to Approved.';
if ($url_msg === 'collected') $msg = 'Item marked as collected.';

$page_title = 'E-Waste Management'; $active_nav = 'ewaste';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if (isAdmin() && ($action === 'add' || $action === 'edit')): ?>
<div class="form-card mb-4">
  <h5 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;margin-bottom:20px;color:var(--text)">
    <i class="bi bi-recycle me-2" style="color:#16a34a"></i>
    <?= $edit_item ? 'Edit E-Waste Record' : 'Add E-Waste Item' ?>
  </h5>
  <form method="POST">
    <?php if ($edit_item): ?><input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>"><?php endif; ?>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Asset Number</label>
        <input type="text" name="asset_number" class="form-control" value="<?= h($edit_item['asset_number'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Asset Class <span style="color:var(--red)">*</span></label>
        <select name="asset_class" class="form-select" required>
          <?php foreach(['MONITOR','PC','LAPTOP','PRINTER','SCANNER','SERVER','NETWORKING','UPS','KEYBOARD','MOUSE','OTHER'] as $cl): ?>
          <option <?= ($edit_item['asset_class'] ?? '') === $cl ? 'selected' : '' ?>><?= $cl ?></option>
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
        <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg"></i><?= $edit_item ? 'Update Record' : 'Add Record' ?></button>
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
  <?php if (isAdmin()): ?>
  <a href="ewaste.php?action=add" class="btn-primary-custom" style="padding:10px 20px;font-size:13px">
    <i class="bi bi-plus-lg"></i> Add Item
  </a>
  <?php endif; ?>
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
      <option value="Disposed"   <?= ($_GET['ew_status'] ?? '') === 'Disposed'   ? 'selected' : '' ?>>Disposed</option>
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
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--muted);font-weight:500">
      <strong style="color:var(--text)"><?= number_format($ew_total) ?></strong> record<?= $ew_total !== 1 ? 's' : '' ?><?= ($ew_search||$ew_status) ? ' <span style="color:var(--accent)">(filtered)</span>' : '' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <th>ASSET NO.</th><th>CLASS</th><th>DESCRIPTION</th>
        <th>SERIAL NO.</th><th>STATUS</th><th>DATE FLAGGED</th>
        <?php if (isAdmin()): ?><th>ACTIONS</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php while ($row = $items->fetch_assoc()): ?>
      <tr>
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

<?php require_once 'includes/layout_end.php'; ?>
