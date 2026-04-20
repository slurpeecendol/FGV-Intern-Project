<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ── MIGRATION: ensure asset_groups table + group_id column exist ──
$db->query("CREATE TABLE IF NOT EXISTS asset_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$col_check = $db->query("SHOW COLUMNS FROM asset_classes LIKE 'group_id'");
if ($col_check->num_rows === 0) {
    $db->query("ALTER TABLE asset_classes ADD COLUMN group_id INT DEFAULT NULL");
}

// ── GROUP: ADD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_group'])) {
    $gname = trim($_POST['new_group']);
    if ($gname) {
        $gn = mysqli_real_escape_string($db, $gname);
        $gmax = $db->query("SELECT MAX(sort_order) m FROM asset_groups")->fetch_assoc()['m'] ?? 0;
        if ($db->query("INSERT IGNORE INTO asset_groups (name, sort_order) VALUES ('$gn', ".((int)$gmax+1).")")) {
            logActivity($_SESSION['user_id'], 'CREATE', 'inventory', 0, 'Added asset group: '.$gname);
            header('Location: asset_classes.php?msg=group_added'); exit;
        } else { $err = 'Group already exists.'; }
    } else { $err = 'Group name cannot be empty.'; }
}

// ── GROUP: RENAME ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_group_id'], $_POST['edit_group_name'])) {
    $gid = (int)$_POST['edit_group_id'];
    $gname = trim($_POST['edit_group_name']);
    if ($gname && $gid) {
        $gn = mysqli_real_escape_string($db, $gname);
        $db->query("UPDATE asset_groups SET name='$gn' WHERE id=$gid");
        logActivity($_SESSION['user_id'], 'UPDATE', 'inventory', 0, 'Renamed asset group to: '.$gname);
        header('Location: asset_classes.php?msg=group_updated'); exit;
    }
}

// ── GROUP: DELETE ──
if ($action === 'delete_group' && $id) {
    $db->query("UPDATE asset_classes SET group_id=NULL WHERE group_id=$id");
    $db->query("DELETE FROM asset_groups WHERE id=$id");
    logActivity($_SESSION['user_id'], 'DELETE', 'inventory', 0, 'Removed asset group id:'.$id);
    header('Location: asset_classes.php?msg=group_deleted'); exit;
}

// ── CLASS: ASSIGN GROUP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_group_class_id'])) {
    $cid = (int)$_POST['assign_group_class_id'];
    $gid = $_POST['assign_group_id'] === '' ? 'NULL' : (int)$_POST['assign_group_id'];
    $db->query("UPDATE asset_classes SET group_id=$gid WHERE id=$cid");
    header('Location: asset_classes.php?msg=class_assigned'); exit;
}

// ── CLASS: ADD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_class'])) {
    $name = strtoupper(trim($_POST['new_class']));
    $gid  = !empty($_POST['new_class_group']) ? (int)$_POST['new_class_group'] : 'NULL';
    if ($name) {
        $n = mysqli_real_escape_string($db, $name);
        $max = $db->query("SELECT MAX(sort_order) m FROM asset_classes")->fetch_assoc()['m'] ?? 0;
        if ($db->query("INSERT IGNORE INTO asset_classes (name, sort_order, group_id) VALUES ('$n', ".((int)$max+1).", $gid)")) {
            logActivity($_SESSION['user_id'], 'CREATE', 'inventory', 0, 'Added asset class: '.$name);
            header('Location: asset_classes.php?msg=added'); exit;
        } else { $err = 'Class already exists or could not be added.'; }
    } else { $err = 'Class name cannot be empty.'; }
}

// ── CLASS: EDIT (rename) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'], $_POST['edit_name'])) {
    $edit_id  = (int)$_POST['edit_id'];
    $new_name = strtoupper(trim($_POST['edit_name']));
    if ($new_name && $edit_id) {
        $old = $db->query("SELECT name FROM asset_classes WHERE id=$edit_id")->fetch_assoc();
        if ($old) {
            $n = mysqli_real_escape_string($db, $new_name);
            $o = mysqli_real_escape_string($db, $old['name']);
            if ($db->query("UPDATE asset_classes SET name='$n' WHERE id=$edit_id")) {
                $db->query("UPDATE inventory_items SET asset_class='$n' WHERE asset_class='$o'");
                $db->query("UPDATE ewaste_items SET asset_class='$n' WHERE asset_class='$o'");
                logActivity($_SESSION['user_id'], 'UPDATE', 'inventory', 0, "Renamed asset class: $o → $new_name");
                header('Location: asset_classes.php?msg=updated'); exit;
            } else { $err = 'Name already exists or could not be updated.'; }
        }
    } else { $err = 'Class name cannot be empty.'; }
}

// ── CLASS: DELETE ──
if ($action === 'delete' && $id) {
    $cls = $db->query("SELECT name FROM asset_classes WHERE id=$id")->fetch_assoc();
    if ($cls) {
        $db->query("DELETE FROM asset_classes WHERE id=$id");
        logActivity($_SESSION['user_id'], 'DELETE', 'inventory', 0, 'Removed asset class: '.$cls['name']);
        header('Location: asset_classes.php?msg=deleted'); exit;
    }
}

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')          $msg = 'Asset class added.';
if ($url_msg === 'updated')        $msg = 'Asset class renamed.';
if ($url_msg === 'deleted')        $msg = 'Asset class removed.';
if ($url_msg === 'group_added')    $msg = 'Enterprise group added.';
if ($url_msg === 'group_updated')  $msg = 'Enterprise group renamed.';
if ($url_msg === 'group_deleted')  $msg = 'Enterprise group removed.';
if ($url_msg === 'class_assigned') $msg = 'Asset class group updated.';

// ── FETCH GROUPS ──
$groups_raw = $db->query("SELECT * FROM asset_groups ORDER BY sort_order, name");
$groups = [];
while ($g = $groups_raw->fetch_assoc()) $groups[] = $g;

// ── FETCH CLASSES ──
$classes_raw = $db->query("SELECT ac.*, ag.name as group_name FROM asset_classes ac LEFT JOIN asset_groups ag ON ac.group_id=ag.id ORDER BY ag.sort_order, ag.name, ac.sort_order, ac.name");
$classes = [];
while ($row = $classes_raw->fetch_assoc()) {
    $in_use  = (int)$db->query("SELECT COUNT(*) c FROM inventory_items WHERE asset_class='".mysqli_real_escape_string($db,$row['name'])."'")->fetch_assoc()['c'];
    $in_use += (int)$db->query("SELECT COUNT(*) c FROM ewaste_items  WHERE asset_class='".mysqli_real_escape_string($db,$row['name'])."'")->fetch_assoc()['c'];
    $row['in_use'] = $in_use;
    $classes[] = $row;
}
$total        = count($classes);
$in_use_count = count(array_filter($classes, fn($c) => $c['in_use'] > 0));
$total_items  = array_sum(array_column($classes, 'in_use'));

$page_title = 'Asset Classes'; $active_nav = 'asset_classes';
require_once 'includes/layout.php';
?>

<style>
.ac-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.ac-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px}
.ac-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.ac-stat-val{font-size:26px;font-weight:800;color:var(--text);line-height:1}
.ac-stat-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.ac-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
.ac-dark-head{background:#1a2332;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ac-dark-head-left{display:flex;align-items:center;gap:10px}
.ac-stripe{width:3px;height:22px;border-radius:2px;flex-shrink:0}
.ac-head-title{font-size:14px;font-weight:700;color:#fff}
.ac-head-sub{font-size:11px;color:rgba(255,255,255,.4);margin-top:1px}
.ac-table{width:100%;border-collapse:collapse}
.ac-table thead th{background:var(--body-bg);padding:10px 20px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.ac-table tbody td{padding:13px 20px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;color:var(--text)}
.ac-table tbody tr:last-child td{border-bottom:none}
.ac-table tbody tr:hover td{background:var(--body-bg)}
.class-pill{display:inline-flex;align-items:center;gap:7px;background:rgba(242,140,40,.08);border:1px solid rgba(242,140,40,.25);border-radius:7px;padding:5px 12px}
.class-dot{width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0}
.class-pill-name{font-size:12px;font-weight:700;color:var(--accent);letter-spacing:.05em}
.group-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:5px;padding:3px 10px;font-size:11px;font-weight:600;color:#2563eb}
.unassigned-pill{display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);border:1px solid var(--border);border-radius:5px;padding:3px 10px;font-size:11px;font-weight:600;color:var(--muted)}
.ac-select{border:1.5px solid var(--border);border-radius:7px;padding:6px 10px;font-size:12px;font-weight:600;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;background:var(--surface);cursor:pointer;outline:none}
.ac-select:focus{border-color:var(--accent)}
.ac-input{border:1.5px solid var(--border);border-radius:8px;padding:8px 14px;font-size:13px;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;background:var(--body-bg);outline:none;transition:border-color .2s}
.ac-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(242,140,40,.1)}
.ac-btn{display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
.ac-btn-sm{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;font-size:13px;cursor:pointer;text-decoration:none;border:1px solid transparent}
.btn-edit{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.2);color:#2563eb}
.btn-del{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.2);color:#dc2626}
.btn-del-dis{background:var(--body-bg);border-color:var(--border);color:var(--border);cursor:not-allowed}
.ac-edit-form{display:none;align-items:center;gap:6px}
.ac-edit-input{font-size:12px;font-weight:700;color:var(--text);background:var(--body-bg);border:1.5px solid var(--accent);border-radius:7px;padding:5px 10px;width:200px;font-family:'Plus Jakarta Sans',sans-serif;text-transform:uppercase;letter-spacing:.04em;outline:none}
.ac-btn-save{font-size:11px;font-weight:700;color:#fff;background:var(--accent);border:none;border-radius:6px;padding:5px 12px;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
.ac-btn-cancel{font-size:11px;font-weight:600;color:var(--muted);background:transparent;border:1.5px solid var(--border);border-radius:6px;padding:5px 10px;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
.cnt-badge{display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:3px 11px;font-size:11px;font-weight:700}
.cnt-blue{background:rgba(37,99,235,.08);color:#1d4ed8;border:1px solid rgba(37,99,235,.2)}
.cnt-gray{background:var(--body-bg);color:var(--muted);border:1px solid var(--border)}
.st-green{background:rgba(22,163,74,.08);color:#15803d;border:1px solid rgba(22,163,74,.2);display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:3px 11px;font-size:11px;font-weight:700}
.st-gray{background:var(--body-bg);color:var(--muted);border:1px solid var(--border);display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:3px 11px;font-size:11px;font-weight:700}
</style>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- PAGE HEADER -->
<div style="margin-bottom:24px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">
    Admin &rsaquo; <span style="color:var(--accent)">Asset Classes</span>
  </div>
  <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Asset Classes</h4>
  <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Manage enterprise groups and the asset classes assigned to each group</p>
</div>

<!-- STAT STRIP -->
<div class="ac-stats">
  <?php foreach([
    ['bi-collection-fill','rgba(242,140,40,.12)', 'var(--accent)', count($groups),    'Enterprise Groups'],
    ['bi-tags-fill',      'rgba(37,99,235,.12)',  '#2563eb',       $total,            'Total Classes'],
    ['bi-stack',          'rgba(22,163,74,.1)',   '#16a34a',       $total_items,      'Total Items'],
    ['bi-slash-circle',   'rgba(100,116,139,.1)', 'var(--muted)',  $total-$in_use_count,'Unused Classes'],
  ] as [$icon,$bg,$color,$val,$lbl]): ?>
  <div class="ac-stat">
    <div class="ac-stat-icon" style="background:<?= $bg ?>">
      <i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i>
    </div>
    <div>
      <div class="ac-stat-val"><?= $val ?></div>
      <div class="ac-stat-lbl"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ SECTION 1: ENTERPRISE GROUPS ══ -->
<div class="ac-card">
  <div class="ac-dark-head">
    <div class="ac-dark-head-left">
      <div class="ac-stripe" style="background:#F28C28"></div>
      <div>
        <div class="ac-head-title">Enterprise Asset Groups</div>
        <div class="ac-head-sub"><?= count($groups) ?> group<?= count($groups) !== 1 ? 's' : '' ?> defined</div>
      </div>
    </div>
    <form method="POST" style="display:flex;align-items:center;gap:8px">
      <input type="text" name="new_group" class="ac-input" placeholder="e.g. Network & Surveillance" required style="width:260px">
      <button type="submit" class="ac-btn"><i class="bi bi-plus-lg"></i> Add Group</button>
    </form>
  </div>

  <?php if (empty($groups)): ?>
  <div style="padding:40px;text-align:center;color:var(--muted);font-size:13px">
    <i class="bi bi-collection" style="font-size:28px;display:block;margin-bottom:10px"></i>
    No enterprise groups yet. Add one above.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="ac-table">
      <thead><tr>
        <th style="width:36px">#</th>
        <th>Group Name</th>
        <th>Classes Assigned</th>
        <th style="width:100px">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($groups as $gi => $grp):
        $grp_count = count(array_filter($classes, fn($c) => $c['group_id'] == $grp['id']));
      ?>
      <tr>
        <td style="color:var(--muted);font-size:12px"><?= $gi + 1 ?></td>
        <td>
          <div id="glabel-<?= $grp['id'] ?>" style="display:flex;align-items:center;gap:8px">
            <i class="bi bi-collection-fill" style="color:#F28C28;font-size:14px"></i>
            <span style="font-size:13px;font-weight:700;color:var(--text)"><?= h($grp['name']) ?></span>
          </div>
          <form id="geditform-<?= $grp['id'] ?>" method="POST" style="display:none;align-items:center;gap:6px">
            <input type="hidden" name="edit_group_id" value="<?= $grp['id'] ?>">
            <input type="text" name="edit_group_name" value="<?= h($grp['name']) ?>" class="ac-edit-input" required style="width:260px">
            <button type="submit" class="ac-btn-save">Save</button>
            <button type="button" class="ac-btn-cancel" onclick="cancelGEdit(<?= $grp['id'] ?>)">Cancel</button>
          </form>
        </td>
        <td>
          <span class="cnt-badge <?= $grp_count > 0 ? 'cnt-blue' : 'cnt-gray' ?>">
            <?= $grp_count ?> class<?= $grp_count !== 1 ? 'es' : '' ?>
          </span>
        </td>
        <td>
          <div id="gactions-<?= $grp['id'] ?>" style="display:flex;gap:5px">
            <button class="ac-btn-sm btn-edit" onclick="startGEdit(<?= $grp['id'] ?>)" title="Rename">
              <i class="bi bi-pencil-fill"></i>
            </button>
            <a href="asset_classes.php?action=delete_group&id=<?= $grp['id'] ?>"
              onclick="return confirm('Remove group \'<?= h($grp['name']) ?>\'? Classes in this group will become unassigned.')"
              class="ac-btn-sm btn-del" title="Delete Group">
              <i class="bi bi-trash-fill"></i>
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ SECTION 2: ASSET CLASSES ══ -->
<div class="ac-card">
  <div class="ac-dark-head">
    <div class="ac-dark-head-left">
      <div class="ac-stripe" style="background:#2563eb"></div>
      <div>
        <div class="ac-head-title">Asset Classes</div>
        <div class="ac-head-sub"><?= $total ?> class<?= $total !== 1 ? 'es' : '' ?> &mdash; assign each to an enterprise group</div>
      </div>
    </div>
    <form method="POST" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <input type="text" name="new_class" class="ac-input" placeholder="E.G. VIDEO RECORDER" required style="width:200px" oninput="this.value=this.value.toUpperCase()">
      <select name="new_class_group" class="ac-select">
        <option value="">— No Group —</option>
        <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>"><?= h($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="ac-btn"><i class="bi bi-plus-lg"></i> Add Class</button>
    </form>
  </div>

  <?php if (empty($classes)): ?>
  <div style="padding:40px;text-align:center;color:var(--muted);font-size:13px">No asset classes yet.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="ac-table">
      <thead><tr>
        <th style="width:36px">#</th>
        <th>Class Name</th>
        <th>Enterprise Group</th>
        <th>Items</th>
        <th>Status</th>
        <th style="width:90px">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($classes as $i => $row): $in_use = $row['in_use']; ?>
      <tr>
        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
        <td>
          <div id="label-<?= $row['id'] ?>" class="class-pill">
            <div class="class-dot"></div>
            <span class="class-pill-name"><?= h($row['name']) ?></span>
          </div>
          <form id="editform-<?= $row['id'] ?>" method="POST" class="ac-edit-form">
            <input type="hidden" name="edit_id" value="<?= $row['id'] ?>">
            <input type="text" name="edit_name" value="<?= h($row['name']) ?>" required class="ac-edit-input" oninput="this.value=this.value.toUpperCase()">
            <button type="submit" class="ac-btn-save">Save</button>
            <button type="button" class="ac-btn-cancel" onclick="cancelEdit(<?= $row['id'] ?>)">Cancel</button>
          </form>
        </td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="assign_group_class_id" value="<?= $row['id'] ?>">
            <select name="assign_group_id" class="ac-select" onchange="this.form.submit()">
              <option value="">— Unassigned —</option>
              <?php foreach ($groups as $g): ?>
              <option value="<?= $g['id'] ?>" <?= $row['group_id'] == $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td>
          <span class="cnt-badge <?= $in_use > 0 ? 'cnt-blue' : 'cnt-gray' ?>">
            <?= $in_use ?> item<?= $in_use !== 1 ? 's' : '' ?>
          </span>
        </td>
        <td>
          <?php if ($in_use > 0): ?>
          <span class="st-green"><i class="bi bi-check-circle-fill" style="font-size:10px"></i> In Use</span>
          <?php else: ?>
          <span class="st-gray"><i class="bi bi-dash-circle" style="font-size:10px"></i> Unused</span>
          <?php endif; ?>
        </td>
        <td>
          <div id="actions-<?= $row['id'] ?>" style="display:flex;gap:5px">
            <button class="ac-btn-sm btn-edit" onclick="startEdit(<?= $row['id'] ?>)" title="Rename">
              <i class="bi bi-pencil-fill"></i>
            </button>
            <?php if ($in_use === 0): ?>
            <a href="asset_classes.php?action=delete&id=<?= $row['id'] ?>"
              onclick="return confirm('Remove class \'<?= h($row['name']) ?>\'?')"
              class="ac-btn-sm btn-del" title="Delete">
              <i class="bi bi-trash-fill"></i>
            </a>
            <?php else: ?>
            <span class="ac-btn-sm btn-del-dis" title="In use — cannot delete"><i class="bi bi-trash-fill"></i></span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
function startEdit(id) {
  document.getElementById('label-'+id).style.display='none';
  document.getElementById('editform-'+id).style.display='flex';
  document.getElementById('actions-'+id).style.display='none';
  document.querySelector('#editform-'+id+' input[name="edit_name"]').focus();
}
function cancelEdit(id) {
  document.getElementById('label-'+id).style.display='inline-flex';
  document.getElementById('editform-'+id).style.display='none';
  document.getElementById('actions-'+id).style.display='flex';
}
function startGEdit(id) {
  document.getElementById('glabel-'+id).style.display='none';
  document.getElementById('geditform-'+id).style.display='flex';
  document.getElementById('gactions-'+id).style.display='none';
  document.querySelector('#geditform-'+id+' input[name="edit_group_name"]').focus();
}
function cancelGEdit(id) {
  document.getElementById('glabel-'+id).style.display='flex';
  document.getElementById('geditform-'+id).style.display='none';
  document.getElementById('gactions-'+id).style.display='flex';
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
