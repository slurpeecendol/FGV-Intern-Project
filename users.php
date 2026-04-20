<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ── TOGGLE ACTIVE ──
if ($action === 'toggle' && $id && $id !== $_SESSION['user_id']) {
    $u = $db->query("SELECT is_active, username FROM users WHERE id=$id")->fetch_assoc();
    if ($u) {
        $new_state = $u['is_active'] ? 0 : 1;
        $db->query("UPDATE users SET is_active=$new_state WHERE id=$id");
        logActivity($_SESSION['user_id'],'USER_TOGGLE','user',$id,($new_state?'Activated':'Deactivated').' user: '.$u['username']);
        header('Location: users.php?msg='.($new_state?'activated':'deactivated')); exit;
    }
}

// ── DELETE ──
if ($action === 'delete' && $id && $id !== $_SESSION['user_id']) {
    $u = $db->query("SELECT username FROM users WHERE id=$id")->fetch_assoc();
    if ($u) {
        $db->query("DELETE FROM users WHERE id=$id");
        logActivity($_SESSION['user_id'],'DELETE','user',$id,'Deleted user: '.$u['username']);
        header('Location: users.php?msg=deleted'); exit;
    }
}

// ── SAVE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? 'user';
    $department = trim($_POST['department'] ?? '');
    $password   = $_POST['password'] ?? '';
    $edit_id    = (int)($_POST['edit_id'] ?? 0);

    if (empty($username) || empty($full_name)) {
        $err = 'Username and Full Name are required.';
    } elseif (!$edit_id && empty($password)) {
        $err = 'Password is required for new users.';
    } else {
        if ($edit_id) {
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET username=?,full_name=?,email=?,role=?,department=?,password=? WHERE id=?");
                $stmt->bind_param('ssssssi',$username,$full_name,$email,$role,$department,$hash,$edit_id);
            } else {
                $stmt = $db->prepare("UPDATE users SET username=?,full_name=?,email=?,role=?,department=? WHERE id=?");
                $stmt->bind_param('sssssi',$username,$full_name,$email,$role,$department,$edit_id);
            }
            try {
                $stmt->execute(); $stmt->close();
                logActivity($_SESSION['user_id'],'UPDATE','user',$edit_id,'Updated user: '.$username);
                header('Location: users.php?msg=updated'); exit;
            } catch (mysqli_sql_exception $e) {
                $err = $e->getCode() === 1062 ? 'Username already exists. Please choose another.' : 'Could not update user. Please try again.';
                $stmt->close();
            }
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username,password,full_name,email,role,department) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssssss',$username,$hash,$full_name,$email,$role,$department);
            try {
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    logActivity($_SESSION['user_id'],'CREATE','user',$new_id,'Created user: '.$username);
                    $stmt->close();
                    header('Location: users.php?msg=added'); exit;
                } else { $err = 'Username already exists.'; }
            } catch (mysqli_sql_exception $e) {
                $err = $e->getCode() === 1062 ? 'Username already exists. Please choose another.' : 'Could not create user. Please try again.';
            }
            $stmt->close();
        }
    }
}

$edit_item = null;
if ($action === 'edit' && $id) {
    $edit_item = $db->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
}

$users_result = $db->query("SELECT * FROM users ORDER BY role, full_name");
$all_users = [];
while ($u = $users_result->fetch_assoc()) $all_users[] = $u;

$total_users    = count($all_users);
$admin_count    = count(array_filter($all_users, fn($u) => $u['role'] === 'admin'));
$staff_count    = $total_users - $admin_count;
$active_count   = count(array_filter($all_users, fn($u) => $u['is_active']));
$inactive_count = $total_users - $active_count;

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')       $msg = 'User created successfully.';
if ($url_msg === 'updated')     $msg = 'User updated.';
if ($url_msg === 'deleted')     $msg = 'User deleted.';
if ($url_msg === 'activated')   $msg = 'User activated.';
if ($url_msg === 'deactivated') $msg = 'User deactivated.';

$page_title = 'Manage Users'; $active_nav = 'users';
require_once 'includes/layout.php';

// Avatar color map (cycling through accents per initial)
function avatarColor($name) {
    $colors = ['#F28C28','#2563eb','#16a34a','#7c3aed','#0891b2','#dc2626','#d97706'];
    return $colors[ord(strtoupper($name[0] ?? 'A')) % count($colors)];
}
?>

<style>
.usr-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 20px;display:flex;align-items:center;gap:14px}
.usr-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.usr-stat-val{font-size:24px;font-weight:800;color:var(--text);line-height:1}
.usr-stat-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.usr-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.usr-form-panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:22px;overflow:hidden}
.usr-form-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.usr-form-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
</style>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- PAGE HEADER -->
<div style="margin-bottom:24px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">
    Admin &rsaquo; <span style="color:var(--accent)">Manage Users</span>
  </div>
  <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Manage Users</h4>
  <p style="font-size:13px;color:var(--muted);margin:4px 0 0">Create, edit and control access for all system users</p>
</div>

<!-- STAT STRIP -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px">
  <?php foreach([
    ['bi-people-fill',    'rgba(242,140,40,.12)', 'var(--accent)', $total_users,  'Total Users'],
    ['bi-shield-fill',    'rgba(37,99,235,.12)',  '#2563eb',       $admin_count,  'Admins'],
    ['bi-person-fill',    'rgba(22,163,74,.1)',   '#16a34a',       $staff_count,  'Staff'],
    ['bi-check-circle-fill','rgba(22,163,74,.1)', '#16a34a',       $active_count, 'Active'],
  ] as [$icon,$bg,$color,$val,$lbl]): ?>
  <div class="usr-stat">
    <div class="usr-stat-icon" style="background:<?= $bg ?>"><i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i></div>
    <div>
      <div class="usr-stat-val"><?= $val ?></div>
      <div class="usr-stat-lbl"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ADD / EDIT FORM PANEL -->
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="usr-form-panel">
  <div class="usr-form-header">
    <div class="usr-form-title">
      <i class="bi bi-person-<?= $edit_item ? 'gear' : 'plus-fill' ?>" style="color:var(--accent)"></i>
      <?= $edit_item ? 'Edit User — '.h($edit_item['full_name']) : 'Create New User' ?>
    </div>
    <a href="users.php" style="display:inline-flex;align-items:center;gap:5px;color:var(--muted);font-size:13px;text-decoration:none;background:var(--body-bg);border:1px solid var(--border);border-radius:7px;padding:5px 12px">
      <i class="bi bi-x"></i> Cancel
    </a>
  </div>
  <div style="padding:22px">
    <form method="POST">
      <?php if ($edit_item): ?><input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Username <span style="color:var(--red)">*</span></label>
          <input type="text" name="username" class="form-control" required value="<?= h($edit_item['username'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Full Name <span style="color:var(--red)">*</span></label>
          <input type="text" name="full_name" class="form-control" required value="<?= h($edit_item['full_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($edit_item['email'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Password <?= $edit_item ? '<span style="color:var(--muted);font-weight:400">(leave blank to keep)</span>' : '<span style="color:var(--red)">*</span>' ?></label>
          <input type="password" name="password" class="form-control" <?= $edit_item ? '' : 'required' ?> placeholder="<?= $edit_item ? 'Leave blank to keep current' : 'Set password' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Role</label>
          <select name="role" class="form-select">
            <option value="user" <?= ($edit_item['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User (Staff)</option>
            <option value="admin" <?= ($edit_item['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Staff ID</label>
          <input type="text" name="department" class="form-control" value="<?= h($edit_item['department'] ?? '') ?>" placeholder="e.g. FJB-0012">
        </div>
        <div class="col-12">
          <button type="submit" class="btn-primary-custom">
            <i class="bi bi-check-lg"></i><?= $edit_item ? 'Update User' : 'Create User' ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- TABLE CARD -->
<div class="table-card">
  <div class="table-card-header">
    <div>
      <div class="table-card-title">All Users</div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= $total_users ?> user<?= $total_users !== 1 ? 's' : '' ?> registered</div>
    </div>
    <a href="users.php?action=add" class="btn-primary-custom"><i class="bi bi-person-plus-fill"></i> New User</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table" style="font-family:'Plus Jakarta Sans',sans-serif">
      <thead><tr>
        <th style="width:36px">#</th>
        <th>User</th>
        <th>Username</th>
        <th>Email</th>
        <th>Role</th>
        <th>Staff ID</th>
        <th>Last Login</th>
        <th>Status</th>
        <th style="width:100px">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($all_users as $i => $row):
        $aColor = avatarColor($row['full_name']);
      ?>
      <tr>
        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:9px">
            <?php
              $showAv = !empty($row['avatar']) && strpos($row['avatar'],'data:')===false && file_exists(__DIR__.'/'.$row['avatar']);
            ?>
            <?php if ($showAv): ?>
            <img src="<?= h($row['avatar']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0">
            <?php else: ?>
            <div class="usr-avatar" style="background:<?= $aColor ?>">
              <?= strtoupper(substr($row['full_name'],0,1)) ?>
            </div>
            <?php endif; ?>
            <span style="font-size:13px;font-weight:600;color:var(--text)"><?= h($row['full_name']) ?></span>
          </div>
        </td>
        <td><code><?= h($row['username']) ?></code></td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['email'] ?: '—') ?></td>
        <td>
          <?php if ($row['role']==='admin'): ?>
          <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(242,140,40,.1);color:#92400e;border:1px solid rgba(242,140,40,.25);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700">
            <i class="bi bi-shield-fill" style="font-size:9px"></i> Admin
          </span>
          <?php else: ?>
          <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(37,99,235,.08);color:#1d4ed8;border:1px solid rgba(37,99,235,.2);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700">
            <i class="bi bi-person-fill" style="font-size:9px"></i> Staff
          </span>
          <?php endif; ?>
        </td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['department'] ?: '—') ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= $row['last_login'] ? date('d/m/Y H:i', strtotime($row['last_login'])) : 'Never' ?></td>
        <td>
          <?php if ($row['is_active']): ?>
          <span class="badge-status bs-active">Active</span>
          <?php else: ?>
          <span class="badge-status bs-disposed">Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:5px">
            <a href="users.php?action=edit&id=<?= $row['id'] ?>" class="btn-icon btn-edit" title="Edit"><i class="bi bi-pencil-fill"></i></a>
            <?php if ($row['id'] != $_SESSION['user_id']): ?>
            <a href="users.php?action=toggle&id=<?= $row['id'] ?>"
               class="btn-icon <?= $row['is_active'] ? 'btn-view' : 'btn-edit' ?>"
               title="<?= $row['is_active'] ? 'Deactivate' : 'Activate' ?>"
               onclick="return confirm('<?= $row['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
              <i class="bi bi-toggle-<?= $row['is_active'] ? 'on' : 'off' ?>"></i>
            </a>
            <a href="users.php?action=delete&id=<?= $row['id'] ?>" class="btn-icon btn-delete" title="Delete"
               onclick="return confirm('Delete <?= h($row['full_name']) ?> permanently?')">
              <i class="bi bi-trash-fill"></i>
            </a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
