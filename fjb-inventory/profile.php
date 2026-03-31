<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$msg = $err = '';

// ── LOAD CURRENT USER ──
$user = $db->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();

// ── SAVE PROFILE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $staff_id   = trim($_POST['department'] ?? '');

    // Handle avatar upload
    $avatar_data = $user['avatar'] ?? '';
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file     = $_FILES['avatar'];
        $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB
        if (!in_array($file['type'], $allowed)) {
            $err = 'Only JPG, PNG, GIF or WebP images are allowed.';
        } elseif ($file['size'] > $max_size) {
            $err = 'Image must be under 2MB.';
        } else {
            $avatar_data = 'data:'.$file['type'].';base64,'.base64_encode(file_get_contents($file['tmp_name']));
        }
    }

    if (!$err) {
        $stmt = $db->prepare("UPDATE users SET full_name=?,email=?,department=?,avatar=? WHERE id=?");
        $stmt->bind_param('ssssi', $full_name, $email, $staff_id, $avatar_data, $uid);
        $stmt->execute(); $stmt->close();
        $_SESSION['full_name'] = $full_name;
        $_SESSION['avatar']    = $avatar_data;
        logActivity($uid,'UPDATE','user',$uid,'Updated profile');
        $msg = 'Profile updated successfully.';
        $user = $db->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
    }
}

// ── CHANGE PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $err = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $err = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $err = 'New passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $uid); $stmt->execute(); $stmt->close();
        logActivity($uid,'UPDATE','user',$uid,'Changed password');
        $msg = 'Password changed successfully.';
    }
}

$page_title = 'Profile Settings'; $active_nav = '';
require_once 'includes/layout.php';
?>

<div class="row g-4" style="max-width:900px">

  <!-- ── PROFILE INFO ── -->
  <div class="col-md-7">
    <div class="form-card">
      <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:22px;color:var(--text);display:flex;align-items:center;gap:9px">
        <i class="bi bi-person-circle" style="color:var(--accent)"></i> Profile Information
      </h5>

      <?php if ($msg): ?>
      <div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_profile">

        <!-- Avatar -->
        <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;padding:18px;background:var(--surface2);border-radius:12px;border:1px solid var(--border)">
          <div id="avatarPreview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;flex-shrink:0;border:3px solid var(--accent);display:flex;align-items:center;justify-content:center;background:var(--accent)">
            <?php if (!empty($user['avatar'])): ?>
            <img src="<?= h($user['avatar']) ?>" id="avatarImg" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
            <span id="avatarInitial" style="font-family:'Syne',sans-serif;font-weight:800;font-size:28px;color:#fff"><?= strtoupper(substr($user['full_name'],0,1)) ?></span>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-weight:600;color:var(--text);margin-bottom:6px">Profile Picture</div>
            <label for="avatarInput" style="background:var(--accent);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
              <i class="bi bi-upload"></i> Upload Photo
            </label>
            <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none" onchange="previewAvatar(this)">
            <div style="font-size:11px;color:var(--muted);margin-top:5px">JPG, PNG, WebP · Max 2MB</div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>"
              <?= !isAdmin() ? 'readonly style="cursor:not-allowed;opacity:.7"' : '' ?> required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?= h($user['username']) ?>" disabled
              style="opacity:.6;cursor:not-allowed">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Staff ID</label>
            <input type="text" name="department" class="form-control" value="<?= h($user['department']) ?>"
              <?= !isAdmin() ? 'readonly style="cursor:not-allowed;opacity:.7"' : '' ?> placeholder="e.g. FJB-0012">
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <input type="text" class="form-control" value="<?= ucfirst(h($user['role'])) ?>" disabled
              style="opacity:.6;cursor:not-allowed">
          </div>
        </div>

        <div style="margin-top:20px">
          <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg"></i> Save Changes</button>
          <a href="dashboard.php" class="btn-secondary-custom" style="margin-left:8px"><i class="bi bi-x"></i> Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ── CHANGE PASSWORD ── -->
  <div class="col-md-5">
    <div class="form-card">
      <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:22px;color:var(--text);display:flex;align-items:center;gap:9px">
        <i class="bi bi-lock-fill" style="color:var(--accent)"></i> Change Password
      </h5>
      <form method="POST">
        <input type="hidden" name="change_password">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Current Password</label>
            <div style="position:relative">
              <input type="password" name="current_password" id="cp1" class="form-control"
                style="padding-right:42px" placeholder="Enter current password" required>
              <button type="button" onclick="togglePw('cp1','ei1')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px">
                <i class="bi bi-eye" id="ei1"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">New Password</label>
            <div style="position:relative">
              <input type="password" name="new_password" id="cp2" class="form-control"
                style="padding-right:42px" placeholder="Min. 6 characters" required>
              <button type="button" onclick="togglePw('cp2','ei2')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px">
                <i class="bi bi-eye" id="ei2"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Confirm New Password</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="cp3" class="form-control"
                style="padding-right:42px" placeholder="Repeat new password" required>
              <button type="button" onclick="togglePw('cp3','ei3')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px">
                <i class="bi bi-eye" id="ei3"></i>
              </button>
            </div>
          </div>
        </div>
        <div style="margin-top:20px">
          <button type="submit" class="btn-primary-custom"><i class="bi bi-shield-lock"></i> Update Password</button>
        </div>
      </form>
    </div>

    <!-- Account info card -->
    <div class="form-card" style="margin-top:16px">
      <h6 style="font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Account Details</h6>
      <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
        <div style="display:flex;justify-content:space-between">
          <span style="color:var(--muted)">Member since</span>
          <span style="color:var(--text);font-weight:500"><?= $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between">
          <span style="color:var(--muted)">Last login</span>
          <span style="color:var(--text);font-weight:500"><?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : '—' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between">
          <span style="color:var(--muted)">Account status</span>
          <span class="badge-status bs-active">Active</span>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
function previewAvatar(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('avatarPreview');
    preview.innerHTML = '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover">';
  };
  reader.readAsDataURL(input.files[0]);
}
function togglePw(inputId, iconId) {
  const inp  = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { inp.type = 'password'; icon.className = 'bi bi-eye'; }
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
