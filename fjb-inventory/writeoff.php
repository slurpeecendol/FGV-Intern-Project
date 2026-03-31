<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db  = getDB();
$msg = $err = '';

// ── ADMIN APPROVE WRITE-OFF ──
if (isset($_GET['action']) && $_GET['action'] === 'approve_wo' && isAdmin()) {
    $wid  = (int)($_GET['id'] ?? 0);
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$wid")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE ewaste_items SET disposal_status='Approved', updated_at=NOW() WHERE id=$wid");
        // Now set inventory location to E-Waste
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$inv_id");
        }
        logActivity($_SESSION['user_id'], 'APPROVE_WRITEOFF', 'ewaste', $wid, 'Approved write-off: '.$item['description']);
        header('Location: writeoff.php?msg=approved'); exit;
    }
}

// ── ADMIN REJECT WRITE-OFF ──
if (isset($_GET['action']) && $_GET['action'] === 'reject_wo' && isAdmin()) {
    $wid  = (int)($_GET['id'] ?? 0);
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$wid")->fetch_assoc();
    if ($item) {
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET location='', updated_at=NOW() WHERE id=$inv_id");
        }
        $db->query("DELETE FROM ewaste_items WHERE id=$wid");
        logActivity($_SESSION['user_id'], 'REJECT_WRITEOFF', 'ewaste', $wid, 'Rejected write-off: '.$item['description']);
        header('Location: writeoff.php?msg=rejected'); exit;
    }
}

// ── SUBMIT BULK WRITE-OFF ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_item_ids'])) {
    $bulk_ids = array_map('intval', explode(',', $_POST['bulk_item_ids']));
    $wo_name  = trim($_POST['writeoff_name'] ?? '');
    $wo_desig = trim($_POST['writeoff_designation'] ?? '');
    $wo_sid   = trim($_POST['writeoff_signature'] ?? '');
    $wo_date  = $_POST['writeoff_date'] ?: date('Y-m-d');
    $wo_time  = $_POST['writeoff_time'] ?: date('H:i');

    $proof_path = '';
    if (!empty($_FILES['writeoff_proof']['name'])) {
        $upload_dir = 'assets/writeoff_proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext     = strtolower(pathinfo($_FILES['writeoff_proof']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) {
            $err = 'Only PDF, JPG, or PNG files are allowed.';
        } else {
            $filename = 'proof_bulk_'.time().'.'.$ext;
            move_uploaded_file($_FILES['writeoff_proof']['tmp_name'], $upload_dir.$filename);
            $proof_path = $upload_dir.$filename;
        }
    }
    if (!$err && empty($wo_name)) $err = 'Authorised By name is required.';

    if (!$err) {
        $count = 0;
        foreach ($bulk_ids as $bid) {
            if (!$bid) continue;
            $item = $db->query("SELECT * FROM inventory_items WHERE id=$bid")->fetch_assoc();
            if ($item) {
                $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$bid")->fetch_assoc();
                if (!$exists) {
                    $wo_notes = "Write-off by: $wo_name | $wo_desig | Date: $wo_date $wo_time".($proof_path ? " | Proof: $proof_path" : '');
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,notes,writeoff_name,writeoff_designation,writeoff_date,writeoff_signature,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?,?,?,?,?,?)");
                    $an=$item['asset_number']; $ac=$item['asset_class'];
                    $de=$item['description'];  $sn=$item['serial_number'];
                    $stmt->bind_param('ssssisssssi',$an,$ac,$de,$bid,$wo_notes,$wo_name,$wo_desig,$wo_date,$wo_sid,$_SESSION['user_id']);
                    $stmt->execute(); $stmt->close();
                    $db->query("UPDATE inventory_items SET location='E-Waste', updated_at=NOW() WHERE id=$bid");
                    logActivity($_SESSION['user_id'],'WRITE_OFF','inventory',$bid,'Bulk write-off by '.$wo_name.': '.$item['description']);
                    $count++;
                }
            }
        }
        header('Location: writeoff.php?msg=submitted'); exit;
    }
}

// ── SUBMIT WRITE-OFF FORM ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $item_id  = (int)$_POST['item_id'];
    $wo_name  = trim($_POST['writeoff_name'] ?? '');
    $wo_desig = trim($_POST['writeoff_designation'] ?? '');
    $wo_sid   = trim($_POST['writeoff_signature'] ?? '');
    $wo_date  = $_POST['writeoff_date'] ?: date('Y-m-d');
    $wo_time  = $_POST['writeoff_time'] ?: date('H:i');

    $proof_path = '';
    if (!empty($_FILES['writeoff_proof']['name'])) {
        $upload_dir = 'assets/writeoff_proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext     = strtolower(pathinfo($_FILES['writeoff_proof']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) {
            $err = 'Only PDF, JPG, or PNG files are allowed for proof.';
        } else {
            $filename = 'proof_'.$item_id.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['writeoff_proof']['tmp_name'], $upload_dir.$filename);
            $proof_path = $upload_dir.$filename;
        }
    }

    if (!$err) {
        if (empty($_FILES['writeoff_proof']['name'])) {
            $err = 'A proof document is required. Please upload a PDF, JPG, or PNG file.';
        }
    }

    if (!$err) {
        if (empty($wo_name)) {
            $err = 'Authorised By name is required.';
        } else {
            $item = $db->query("SELECT * FROM inventory_items WHERE id=$item_id")->fetch_assoc();
            if ($item) {
                $exists = $db->query("SELECT id FROM ewaste_items WHERE original_inventory_id=$item_id")->fetch_assoc();
                if (!$exists) {
                    $wo_notes = "Write-off by: $wo_name | $wo_desig | Date: $wo_date $wo_time".($proof_path ? " | Proof: $proof_path" : '');
                    $stmt = $db->prepare("INSERT INTO ewaste_items (asset_number,asset_class,description,serial_number,original_inventory_id,date_flagged,disposal_status,notes,writeoff_name,writeoff_designation,writeoff_date,writeoff_signature,created_by) VALUES (?,?,?,?,?,CURDATE(),'Pending',?,?,?,?,?,?)");
                    $an=$item['asset_number']; $ac=$item['asset_class'];
                    $de=$item['description'];  $sn=$item['serial_number'];
                    $stmt->bind_param('ssssisssssi',$an,$ac,$de,$sn,$item_id,$wo_notes,$wo_name,$wo_desig,$wo_date,$wo_sid,$_SESSION['user_id']);
                    $stmt->execute(); $stmt->close();
                }
                $db->query("UPDATE inventory_items SET updated_at=NOW() WHERE id=$item_id");
                logActivity($_SESSION['user_id'],'WRITE_OFF','inventory',$item_id,'Write-off submitted by '.$wo_name.': '.$item['description']);
                header('Location: writeoff.php?msg=submitted'); exit;
            }
        }
    }
}

// ── LOAD ITEM IF PASSED ──
$item_id = (int)($_GET['item_id'] ?? 0);
$wo_item = $item_id ? $db->query("SELECT * FROM inventory_items WHERE id=$item_id")->fetch_assoc() : null;

// ── BULK ITEMS IF PASSED ──
$bulk_ids_raw = trim($_GET['bulk_ids'] ?? '');
$bulk_items = [];
if ($bulk_ids_raw) {
    $safe_ids = implode(',', array_map('intval', explode(',', $bulk_ids_raw)));
    if ($safe_ids) {
        $res = $db->query("SELECT * FROM inventory_items WHERE id IN ($safe_ids)");
        while ($r = $res->fetch_assoc()) $bulk_items[] = $r;
    }
}

// ── VIEW MODE ──
$view = $_GET['view'] ?? 'main'; // 'main' or 'collected'

// ── FETCH CURRENT USER'S STAFF ID ──
$_cur_user = $db->query("SELECT department FROM users WHERE id=".(int)$_SESSION['user_id'])->fetch_assoc();
$_user_staff_id = $_cur_user['department'] ?? '';

// ── PENDING QUEUE ──
$wo_queue = $db->query("SELECT ew.*, u.full_name as submitted_by_name FROM ewaste_items ew LEFT JOIN users u ON ew.created_by=u.id WHERE ew.disposal_status='Pending' ORDER BY ew.created_at DESC");
$wo_count = $wo_queue ? $wo_queue->num_rows : 0;

// ── COLLECTED PROOFS ──
$collected_items = $db->query("SELECT ew.*, u.full_name as submitted_by_name FROM ewaste_items ew LEFT JOIN users u ON ew.created_by=u.id WHERE ew.disposal_status IN ('Approved','Disposed') AND ew.writeoff_name IS NOT NULL AND ew.writeoff_name != '' ORDER BY ew.updated_at DESC");
$collected_count = $collected_items ? $collected_items->num_rows : 0;

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'submitted') $msg = 'Write-off submitted successfully. Awaiting admin approval.';
if ($url_msg === 'approved')  $msg = 'Write-off approved. Item is now visible in the E-Waste section.';
if ($url_msg === 'rejected')  $msg = 'Write-off rejected. Item restored to IT Assets.';

$page_title = $view === 'collected' ? 'Collected Proofs' : 'Write Off Authorisation';
$active_nav = $view === 'collected' ? 'writeoff_collected' : 'writeoff';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<?php if ($view === 'collected'): ?>
<!-- ══ COLLECTED PROOFS VIEW ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Collected Proofs</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Write-off authorisation records with uploaded documentation</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="background:rgba(22,163,74,.1);color:#16a34a;border-radius:20px;padding:5px 14px;font-size:13px;font-weight:700">
      <?= $collected_count ?> collected
    </span>
    <a href="writeoff.php" class="btn-secondary-custom" style="font-size:13px;padding:8px 16px">
      <i class="bi bi-arrow-left"></i> Back
    </a>
  </div>
</div>

<div class="table-card">
  <?php if ($collected_count === 0): ?>
  <div style="padding:48px 20px;text-align:center">
    <i class="bi bi-folder2-open" style="font-size:36px;display:block;margin-bottom:12px;color:var(--muted)"></i>
    <div style="font-size:14px;font-weight:600;color:var(--text)">No collected proofs yet</div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">Approved write-offs with documentation will appear here</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <th>ASSET NO.</th><th>CLASS</th><th>DESCRIPTION</th><th>AUTHORISED BY</th>
        <th>DESIGNATION</th><th>STAFF ID</th><th>DATE</th><th>STATUS</th><th>PROOF</th>
      </tr></thead>
      <tbody>
      <?php while ($row = $collected_items->fetch_assoc()):
        preg_match('/Proof: ([^\s|]+)/', $row['notes'] ?? '', $pm);
        $proof_file = $pm[1] ?? '';
      ?>
      <tr>
        <td style="font-size:13px;font-weight:600;color:var(--accent)"><?= h($row['asset_number'] ?: '—') ?></td>
        <td><span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:700"><?= h($row['asset_class']) ?></span></td>
        <td style="font-size:13px;font-weight:500"><?= h($row['description']) ?></td>
        <td style="font-size:13px;font-weight:600"><?= h($row['writeoff_name'] ?: '—') ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['writeoff_designation'] ?: '—') ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['writeoff_signature'] ?: '—') ?></td>
        <td style="font-size:13px"><?= $row['writeoff_date'] ? date('d/m/Y', strtotime($row['writeoff_date'])) : '—' ?></td>
        <td>
          <?php if ($row['disposal_status'] === 'Approved'): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(59,130,246,.1);color:#2563eb;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:600">
              <span style="width:5px;height:5px;background:#2563eb;border-radius:50%"></span> Approved
            </span>
          <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,.1);color:#dc2626;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:600">
              <span style="width:5px;height:5px;background:#dc2626;border-radius:50%"></span> Disposed
            </span>
          <?php endif; ?>
        </td>
        <td>
          <?php
            $is_own = ($row['created_by'] == $_SESSION['user_id']);
            $can_view = isAdmin() || $is_own;
          ?>
          <?php if ($proof_file && file_exists($proof_file) && $can_view): ?>
            <a href="<?= h($proof_file) ?>" target="_blank"
              style="font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border:1px solid rgba(37,99,235,.2);background:rgba(37,99,235,.07);border-radius:6px;padding:4px 10px">
              <i class="bi bi-file-earmark-text"></i> View
            </a>
          <?php elseif ($proof_file && file_exists($proof_file) && !$can_view): ?>
            <span style="font-size:12px;color:var(--muted);font-style:italic">Restricted</span>
          <?php else: ?>
            <span style="font-size:12px;color:var(--muted)">No file</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ══ MAIN WRITE-OFF VIEW ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px">
  <div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Write Off Authorisation</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Authorise IT assets for E-Waste disposal</p>
  </div>
</div>

<!-- FLOW STEPS -->
<div style="display:flex;align-items:stretch;margin-bottom:28px;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden">
  <?php foreach([
    ['bi-pen-fill',                    '1','Fill Write-Off Form'],
    ['bi-file-earmark-arrow-up-fill',  '2','Upload Proof Document'],
    ['bi-person-check-fill',           '3','Admin Approves'],
    ['bi-recycle',                     '4','Moved to E-Waste'],
  ] as $i => [$icon,$num,$label]):
    $active = $wo_item ? ($i===0) : false;
  ?>
  <div style="flex:1;display:flex;align-items:center;padding:14px 16px;border-right:<?= $i<3?'1px solid var(--border)':'none' ?>;<?= $active?'background:rgba(242,140,40,.06)':'' ?>">
    <div style="width:32px;height:32px;border-radius:50%;background:<?= $active?'var(--accent)':'var(--surface2)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi <?= $icon ?>" style="color:<?= $active?'#fff':'var(--muted)' ?>;font-size:13px"></i>
    </div>
    <div style="margin-left:10px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $active?'var(--accent)':'var(--muted)' ?>">Step <?= $num ?></div>
      <div style="font-size:12px;font-weight:600;color:var(--text)"><?= $label ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($bulk_items)): ?>
<!-- ── BULK WRITE-OFF FORM ── -->
<div class="form-card mb-4">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
    <div style="width:36px;height:36px;background:rgba(242,140,40,.12);border-radius:8px;display:flex;align-items:center;justify-content:center">
      <i class="bi bi-pen-fill" style="color:var(--accent);font-size:16px"></i>
    </div>
    <div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:15px;color:var(--text)">Bulk Write-Off Authorisation</div>
      <div style="font-size:12px;color:var(--muted)"><?= count($bulk_items) ?> asset(s) selected for e-waste disposal</div>
    </div>
  </div>

  <!-- Asset List -->
  <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:10px;margin-bottom:24px;overflow:hidden">
    <div style="padding:10px 16px;border-bottom:1px solid var(--border);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted)">Selected Assets</div>
    <?php foreach ($bulk_items as $bi): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border);last-child:border-none">
      <span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700"><?= h($bi['asset_class']) ?></span>
      <span style="font-size:13px;font-weight:600;color:var(--text);flex:1"><?= h($bi['description']) ?></span>
      <span style="font-size:12px;color:var(--muted)"><?= h($bi['asset_number'] ?: '—') ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="bulk_item_ids" value="<?= h($bulk_ids_raw) ?>">

    <!-- Step 1 -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">1</span>
      <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Authorisation Details</span>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label">Authorised By <span style="color:var(--red)">*</span></label>
        <input type="text" name="writeoff_name" class="form-control" required
          value="<?= h($_SESSION['full_name'] ?? '') ?>"
          readonly style="cursor:not-allowed;opacity:.75">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your account</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Designation</label>
        <input type="text" name="writeoff_designation" class="form-control" value="IT Staff" readonly style="cursor:not-allowed;opacity:.75">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your role</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Staff / Worker ID</label>
        <input type="text" name="writeoff_signature" class="form-control"
          value="<?= h($_user_staff_id) ?>" readonly style="cursor:not-allowed;opacity:.75"
          placeholder="<?= $_user_staff_id ? '' : 'Not set — update in Profile' ?>">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your account</small>
      </div>
      <div class="col-md-6">
        <label class="form-label">Date</label>
        <input type="date" name="writeoff_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Time</label>
        <input type="time" name="writeoff_time" class="form-control" value="<?= date('H:i') ?>">
      </div>
    </div>

    <!-- Step 2 -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">2</span>
      <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Upload Proof / IT Asset Documentation</span>
    </div>
    <div style="border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;background:var(--body-bg);cursor:pointer;transition:border-color .2s;margin-bottom:20px"
      onclick="document.getElementById('bulkProofFile').click()"
      ondragover="event.preventDefault();this.style.borderColor='var(--accent)'"
      ondragleave="this.style.borderColor='var(--border)'"
      ondrop="event.preventDefault();this.style.borderColor='var(--border)';document.getElementById('bulkProofFile').files=event.dataTransfer.files;document.getElementById('bulkProofLabel').textContent=event.dataTransfer.files[0].name">
      <i class="bi bi-file-earmark-arrow-up" style="font-size:32px;color:var(--muted);display:block;margin-bottom:8px"></i>
      <div style="font-size:13px;font-weight:600;color:var(--text)" id="bulkProofLabel">Click or drag to upload proof document</div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">PDF, JPG, PNG — applies to all selected assets</div>
    </div>
    <input type="file" id="bulkProofFile" name="writeoff_proof" accept=".pdf,.jpg,.jpeg,.png" style="display:none"
      onchange="document.getElementById('bulkProofLabel').textContent=this.files[0]?.name||'Click or drag to upload proof document'">

    <div style="display:flex;gap:10px;margin-top:8px">
      <button type="submit"
        style="background:var(--accent);color:#fff;border:none;border-radius:9px;padding:11px 24px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px">
        <i class="bi bi-send-fill"></i> Submit Write-Off for <?= count($bulk_items) ?> Asset(s)
      </button>
      <a href="inventory.php" style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:9px;padding:11px 20px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
        <i class="bi bi-arrow-left"></i> Cancel
      </a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($wo_item): ?>
<!-- ── WRITE-OFF FORM ── -->
<div class="form-card mb-4">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
    <div style="width:36px;height:36px;background:rgba(242,140,40,.12);border-radius:8px;display:flex;align-items:center;justify-content:center">
      <i class="bi bi-pen-fill" style="color:var(--accent);font-size:16px"></i>
    </div>
    <div>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:15px;color:var(--text)">Write-Off Authorisation Form</div>
      <div style="font-size:12px;color:var(--muted)">Fill in details and upload proof, then submit for admin approval</div>
    </div>
  </div>

  <!-- Asset Info -->
  <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
    <?php foreach([
      ['Asset No.',   $wo_item['asset_number'] ?: '—'],
      ['Class',       $wo_item['asset_class']],
      ['Description', $wo_item['description']],
      ['Serial No.',  $wo_item['serial_number'] ?: '—'],
    ] as [$label,$val]): ?>
    <div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:3px"><?= $label ?></div>
      <div style="font-size:13px;font-weight:600;color:var(--text)"><?= h($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="item_id" value="<?= $wo_item['id'] ?>">

    <!-- Step 1 -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">1</span>
      <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Authorisation Details</span>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label">Authorised By <span style="color:var(--red)">*</span></label>
        <input type="text" name="writeoff_name" class="form-control" required
          value="<?= h($_SESSION['full_name'] ?? '') ?>"
          readonly style="cursor:not-allowed;opacity:.75">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your account</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Designation</label>
        <input type="text" name="writeoff_designation" class="form-control"
          value="IT Staff"
          readonly style="cursor:not-allowed;opacity:.75">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your role</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Staff / Worker ID</label>
        <input type="text" name="writeoff_signature" class="form-control"
          value="<?= h($_user_staff_id) ?>"
          readonly style="cursor:not-allowed;opacity:.75"
          placeholder="<?= $_user_staff_id ? '' : 'Not set — update in Profile' ?>">
        <small style="font-size:11px;color:var(--muted);margin-top:3px;display:block"><i class="bi bi-lock-fill"></i> Locked to your account</small>
      </div>
      <div class="col-md-6">
        <label class="form-label">Date</label>
        <input type="date" name="writeoff_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Time</label>
        <input type="time" name="writeoff_time" class="form-control" id="writeoffTime" value="<?= date('H:i') ?>">
      </div>
    </div>

    <!-- Step 2 -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">2</span>
      <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:13px;color:var(--text)">Upload Proof / IT Asset Documentation</span>
    </div>
    <div style="border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;background:var(--body-bg);cursor:pointer;transition:border-color .2s;margin-bottom:20px"
      id="dropzone"
      onclick="document.getElementById('proofFile').click()"
      ondragover="event.preventDefault();this.style.borderColor='var(--accent)'"
      ondragleave="this.style.borderColor='var(--border)'"
      ondrop="event.preventDefault();this.style.borderColor='var(--border)';handleDrop(event)">
      <i class="bi bi-file-earmark-arrow-up" style="font-size:32px;color:var(--muted);display:block;margin-bottom:8px"></i>
      <div style="font-size:13px;font-weight:600;color:var(--text)" id="proofLabel">Click or drag to upload proof document</div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px">Accepted: PDF, JPG, PNG &nbsp;·&nbsp; <span style="color:#dc2626;font-weight:700">Required</span></div>
      <input type="file" id="proofFile" name="writeoff_proof" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="updateLabel(this)">
    </div>

    <!-- Confirmation -->
    <div style="background:rgba(242,140,40,.05);border:1px solid rgba(242,140,40,.25);border-radius:9px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;margin-bottom:20px">
      <input type="checkbox" id="confirmCheck" required style="width:17px;height:17px;accent-color:var(--accent);cursor:pointer;flex-shrink:0;margin-top:2px">
      <label for="confirmCheck" style="cursor:pointer;font-size:13px;color:var(--text);margin:0;line-height:1.5">
        I confirm that I authorise the write-off of <strong><?= h($wo_item['description']) ?></strong>
        (<?= h($wo_item['asset_number'] ?: 'No asset no.') ?>) for E-Waste disposal.
        This will be submitted to the admin for approval.
      </label>
    </div>

    <div id="noFileMsg" style="display:none;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#dc2626;font-weight:600">
      <i class="bi bi-exclamation-circle-fill me-2"></i>Please upload a proof document before submitting.
    </div>

    <div class="d-flex gap-2">
      <button type="submit" id="submitBtn" onclick="return validateProof()" class="btn-primary-custom"><i class="bi bi-send-fill"></i> Submit for Approval</button>
      <a href="inventory.php" class="btn-secondary-custom"><i class="bi bi-x"></i> Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── PENDING ADMIN APPROVAL QUEUE ── -->
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div>
      <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:var(--text)">Pending Admin Approval</span>
      <div style="font-size:12px;color:var(--muted);margin-top:2px">Items appear in E-Waste Management only after admin approval</div>
    </div>
    <?php if ($wo_count > 0): ?>
    <span style="background:rgba(245,158,11,.12);color:#d97706;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700"><?= $wo_count ?> pending</span>
    <?php endif; ?>
  </div>

  <?php if ($wo_count === 0): ?>
  <div style="padding:48px 20px;text-align:center">
    <i class="bi bi-check-circle" style="font-size:32px;display:block;margin-bottom:10px;color:#16a34a"></i>
    <div style="font-size:14px;font-weight:600;color:var(--text)">No pending approvals</div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">All write-off requests have been processed</div>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <th>ASSET NO.</th><th>CLASS</th><th>DESCRIPTION</th><th>SERIAL NO.</th>
        <th>DATE SUBMITTED</th><th>AUTHORISED BY</th><th>PROOF</th>
        <?php if (isAdmin()): ?><th>ACTIONS</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php while ($row = $wo_queue->fetch_assoc()): ?>
      <?php preg_match('/Proof: ([^\s|]+)/', $row['notes'] ?? '', $pm); $proof_file = $pm[1] ?? ''; ?>
      <tr>
        <td style="font-size:13px;font-weight:600;color:var(--accent)"><?= h($row['asset_number'] ?: '—') ?></td>
        <td><span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:700"><?= h($row['asset_class']) ?></span></td>
        <td style="font-size:13px;font-weight:500"><?= h($row['description']) ?></td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['serial_number'] ?: '—') ?></td>
        <td style="font-size:13px"><?= $row['date_flagged'] ? date('d/m/Y', strtotime($row['date_flagged'])) : '—' ?></td>
        <td style="font-size:13px">
          <?php if ($row['writeoff_name']): ?>
            <div style="font-weight:600;color:var(--text)"><?= h($row['writeoff_name']) ?></div>
            <?php if ($row['writeoff_designation']): ?>
            <div style="font-size:11px;color:var(--muted)"><?= h($row['writeoff_designation']) ?></div>
            <?php endif; ?>
          <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
        </td>
        <td>
          <?php if ($proof_file && file_exists($proof_file)): ?>
            <a href="<?= h($proof_file) ?>" target="_blank"
              style="font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border:1px solid rgba(37,99,235,.2);background:rgba(37,99,235,.07);border-radius:6px;padding:3px 10px">
              <i class="bi bi-file-earmark-text"></i> View
            </a>
          <?php else: ?><span style="font-size:12px;color:var(--muted)">—</span><?php endif; ?>
        </td>
        <?php if (isAdmin()): ?>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <a href="writeoff.php?action=approve_wo&id=<?= $row['id'] ?>" onclick="return confirm('Approve this write-off? Item will move to E-Waste.')"
              style="font-size:12px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.08);border:1.5px solid rgba(22,163,74,.2);border-radius:6px;padding:4px 12px;text-decoration:none;white-space:nowrap">✓ Approve</a>
            <a href="writeoff.php?action=reject_wo&id=<?= $row['id'] ?>" onclick="return confirm('Reject this write-off? Item will be restored to IT Assets.')"
              style="font-size:12px;font-weight:700;color:#dc2626;background:rgba(239,68,68,.08);border:1.5px solid rgba(239,68,68,.2);border-radius:6px;padding:4px 12px;text-decoration:none;white-space:nowrap">✕ Reject</a>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
(function() {
  const t = document.getElementById('writeoffTime');
  if (!t) return;
  setInterval(() => {
    const now = new Date();
    t.value = String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
  }, 1000);
})();
function validateProof() {
  const file = document.getElementById('proofFile');
  const msg  = document.getElementById('noFileMsg');
  if (!file || !file.files || file.files.length === 0) {
    msg.style.display = 'block';
    msg.scrollIntoView({behavior:'smooth', block:'center'});
    return false;
  }
  msg.style.display = 'none';
  return true;
}

  const label = document.getElementById('proofLabel');
  if (input.files.length > 0) {
    label.textContent = '📎 ' + input.files[0].name;
    label.style.color = 'var(--accent)';
  }
}
function handleDrop(e) {
  const input = document.getElementById('proofFile');
  input.files = e.dataTransfer.files;
  updateLabel(input);
}
</script>

<?php endif; // end collected vs main view ?>

<?php require_once 'includes/layout_end.php'; ?>
