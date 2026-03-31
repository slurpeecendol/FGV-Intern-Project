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
            $stmt->execute(); $stmt->close();
            logActivity($_SESSION['user_id'],'UPDATE','user',$edit_id,'Updated user: '.$username);
            header('Location: users.php?msg=updated'); exit;
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username,password,full_name,email,role,department) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssssss',$username,$hash,$full_name,$email,$role,$department);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                logActivity($_SESSION['user_id'],'CREATE','user',$new_id,'Created user: '.$username);
                header('Location: users.php?msg=added'); exit;
            } else {
                $err = 'Username already exists.';
            }
            $stmt->close();
        }
    }
}

$edit_item = null;
if ($action === 'edit' && $id) {
    $edit_item = $db->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
}

$users = $db->query("SELECT * FROM users ORDER BY role, full_name");
$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')       $msg = 'User created successfully.';
if ($url_msg === 'updated')     $msg = 'User updated.';
if ($url_msg === 'deleted')     $msg = 'User deleted.';
if ($url_msg === 'activated')   $msg = 'User activated.';
if ($url_msg === 'deactivated') $msg = 'User deactivated.';

$page_title = 'Manage Users'; $active_nav = 'users';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- FORM -->
<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="form-card mb-4">
  <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:20px;color:var(--text)">
    <i class="bi bi-person-<?= $edit_item ? 'gear' : 'plus' ?> me-2" style="color:var(--green)"></i>
    <?= $edit_item ? 'Edit User' : 'Create New User' ?>
  </h5>
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
        <label class="form-label">Password <?= $edit_item ? '(leave blank to keep)' : '<span style="color:var(--red)">*</span>' ?></label>
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
        <input type="text" name="department" class="form-control" value="<?= h($edit_item['department'] ?? '') ?>">
      </div>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg"></i><?= $edit_item ? 'Update User' : 'Create User' ?></button>
        <a href="users.php" class="btn-secondary-custom"><i class="bi bi-x"></i>Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="table-card">
  <div class="table-card-header">
    <div class="table-card-title"><i class="bi bi-people me-2" style="color:var(--green)"></i>All Users</div>
    <a href="users.php?action=add" class="btn-primary-custom"><i class="bi bi-person-plus"></i>New User</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table">
      <thead><tr><th>#</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Staff ID</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $i=1; while ($row = $users->fetch_assoc()): ?>
      <tr>
        <td ><?= $i++ ?></td>
        <td><code style="color:var(--green)"><?= h($row['username']) ?></code></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--green-dim);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:#fff;flex-shrink:0">
              <?= strtoupper(substr($row['full_name'],0,1)) ?>
            </div>
            <?= h($row['full_name']) ?>
          </div>
        </td>
        <td ><?= h($row['email'] ?: '—') ?></td>
        <td>
          <?php if ($row['role']==='admin'): ?>
          <span class="badge-status bs-active">Admin</span>
          <?php else: ?>
          <span class="badge-status bs-repair">User</span>
          <?php endif; ?>
        </td>
        <td><?= h($row['department'] ?: '—') ?></td>
        <td style="font-size:12px"><?= $row['last_login'] ? date('d/m/Y H:i', strtotime($row['last_login'])) : 'Never' ?></td>
        <td>
          <?php if ($row['is_active']): ?>
          <span class="badge-status bs-active">Active</span>
          <?php else: ?>
          <span class="badge-status bs-disposed">Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="d-flex gap-1">
            <a href="users.php?action=edit&id=<?= $row['id'] ?>" class="btn-icon btn-edit" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php if ($row['id'] != $_SESSION['user_id']): ?>
            <a href="users.php?action=toggle&id=<?= $row['id'] ?>" class="btn-icon btn-view" title="Toggle Active"
               onclick="return confirm('Toggle this user status?')">
              <i class="bi bi-toggle-<?= $row['is_active'] ? 'on' : 'off' ?>"></i></a>
            <a href="users.php?action=delete&id=<?= $row['id'] ?>" class="btn-icon btn-delete" title="Delete"
               onclick="return confirm('Delete this user permanently?')"><i class="bi bi-trash"></i></a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
