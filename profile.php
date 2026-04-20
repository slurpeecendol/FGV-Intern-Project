<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$msg = $err = '';

$user = $db->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();

// ── SAVE PROFILE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $staff_id   = trim($_POST['department'] ?? '');
    $avatar_data = $user['avatar'] ?? '';
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file     = $_FILES['avatar'];
        $allowed  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $max_size = 2 * 1024 * 1024;
        if (!isset($allowed[$file['type']])) {
            $err = 'Only JPG, PNG, GIF or WebP images are allowed.';
        } elseif ($file['size'] > $max_size) {
            $err = 'Image must be under 2MB.';
        } else {
            $upload_dir = __DIR__ . '/assets/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (!empty($user['avatar']) && strpos($user['avatar'],'assets/avatars/')!==false) {
                $old = __DIR__.'/'.$user['avatar'];
                if (file_exists($old)) unlink($old);
            }
            $ext      = $allowed[$file['type']];
            $filename = 'avatar_'.$uid.'_'.time().'.'.$ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir.$filename)) {
                $avatar_data = 'assets/avatars/'.$filename;
            } else { $err = 'Failed to save avatar. Please try again.'; }
        }
    }
    if (!$err) {
        $stmt = $db->prepare("UPDATE users SET full_name=?,email=?,department=?,avatar=? WHERE id=?");
        $stmt->bind_param('ssssi',$full_name,$email,$staff_id,$avatar_data,$uid);
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
        $stmt->bind_param('si',$hash,$uid); $stmt->execute(); $stmt->close();
        logActivity($uid,'UPDATE','user',$uid,'Changed password');
        $msg = 'Password changed successfully.';
    }
}

$show_avatar = !empty($user['avatar'])
    && strpos($user['avatar'],'data:')===false
    && file_exists(__DIR__.'/'.$user['avatar']);

$page_title = 'Profile Settings'; $active_nav = '';
require_once 'includes/layout.php';
?>

<style>
.profile-hero{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:24px}
.profile-hero-avatar{position:relative;flex-shrink:0}
.profile-hero-img{width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--accent)}
.profile-hero-initial{width:88px;height:88px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-family:'Plus Jakarta Sans',sans-serif;font-size:32px;font-weight:800;color:#fff;border:3px solid var(--accent)}
.profile-hero-info{flex:1}
.profile-hero-name{font-size:22px;font-weight:800;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;line-height:1}
.profile-hero-meta{display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap}
.profile-section-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
.profile-section-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px}
.profile-section-title{font-size:14px;font-weight:700;color:var(--text)}
.profile-section-body{padding:22px}
.account-row{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid var(--border)}
.account-row:last-child{border-bottom:none}
.account-row-label{font-size:13px;color:var(--muted);font-weight:500}
.account-row-value{font-size:13px;font-weight:600;color:var(--text)}
</style>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-danger-custom"><i class="bi bi-x-circle-fill"></i><?= h($err) ?></div><?php endif; ?>

<!-- PROFILE HERO -->
<div class="profile-hero">
  <div class="profile-hero-avatar">
    <?php if ($show_avatar): ?>
    <img src="<?= h($user['avatar']) ?>" class="profile-hero-img" id="avatarPreview">
    <?php else: ?>
    <div class="profile-hero-initial" id="avatarPreview"><?= strtoupper(substr($user['full_name'],0,1)) ?></div>
    <?php endif; ?>
  </div>
  <div class="profile-hero-info">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Your Account</div>
    <div class="profile-hero-name"><?= h($user['full_name']) ?></div>
    <div class="profile-hero-meta">
      <span style="background:rgba(242,140,40,.12);color:var(--accent);border-radius:5px;padding:2px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em"><?= ucfirst(h($user['role'])) ?></span>
      <span style="font-size:12px;color:var(--muted)"><i class="bi bi-person" style="font-size:11px"></i> <?= h($user['username']) ?></span>
      <?php if ($user['department']): ?>
      <span style="font-size:12px;color:var(--muted)"><i class="bi bi-tag" style="font-size:11px"></i> <?= h($user['department']) ?></span>
      <?php endif; ?>
      <?php if ($user['email']): ?>
      <span style="font-size:12px;color:var(--muted)"><i class="bi bi-envelope" style="font-size:11px"></i> <?= h($user['email']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;gap:10px;text-align:right;flex-shrink:0">
    <div>
      <div style="font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.06em">Member Since</div>
      <div style="font-size:14px;font-weight:700;color:var(--text);margin-top:2px"><?= $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—' ?></div>
    </div>
    <div>
      <div style="font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.06em">Last Login</div>
      <div style="font-size:13px;font-weight:600;color:var(--text);margin-top:2px"><?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : 'Never' ?></div>
    </div>
  </div>
</div>

<div class="row g-4" style="max-width:960px">

  <!-- PROFILE FORM -->
  <div class="col-md-7">
    <div class="profile-section-card">
      <div class="profile-section-header">
        <i class="bi bi-person-fill" style="color:var(--accent);font-size:15px"></i>
        <div class="profile-section-title">Profile Information</div>
      </div>
      <div class="profile-section-body">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="save_profile">

          <!-- Avatar upload row -->
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:22px;padding:16px;background:var(--body-bg);border-radius:10px;border:1px solid var(--border)">
            <div id="avatarThumb" style="width:52px;height:52px;border-radius:50%;overflow:hidden;flex-shrink:0;border:2px solid var(--accent);display:flex;align-items:center;justify-content:center;background:var(--accent)">
              <?php if ($show_avatar): ?>
              <img src="<?= h($user['avatar']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?>
              <span style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:18px;color:#fff"><?= strtoupper(substr($user['full_name'],0,1)) ?></span>
              <?php endif; ?>
            </div>
            <div>
              <label for="avatarInput" style="display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer">
                <i class="bi bi-upload"></i> Upload Photo
              </label>
              <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none" onchange="previewAvatar(this)">
              <div style="font-size:11px;color:var(--muted);margin-top:4px">JPG, PNG, WebP · Max 2MB</div>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>"
                <?= !isAdmin() ? 'readonly style="cursor:not-allowed;opacity:.7"' : '' ?> required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username <span style="font-size:11px;color:var(--muted);font-weight:400">(cannot change)</span></label>
              <input type="text" class="form-control" value="<?= h($user['username']) ?>" disabled style="opacity:.6;cursor:not-allowed">
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
              <label class="form-label">Role <span style="font-size:11px;color:var(--muted);font-weight:400">(set by admin)</span></label>
              <input type="text" class="form-control" value="<?= ucfirst(h($user['role'])) ?>" disabled style="opacity:.6;cursor:not-allowed">
            </div>
          </div>

          <div style="margin-top:22px;display:flex;gap:8px">
            <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg"></i> Save Changes</button>
            <a href="dashboard.php" class="btn-secondary-custom"><i class="bi bi-x"></i> Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <!-- CHANGE PASSWORD -->
    <div class="profile-section-card">
      <div class="profile-section-header">
        <i class="bi bi-lock-fill" style="color:var(--accent);font-size:15px"></i>
        <div class="profile-section-title">Change Password</div>
      </div>
      <div class="profile-section-body">
        <form method="POST">
          <input type="hidden" name="change_password">
          <div style="display:flex;flex-direction:column;gap:16px">
            <?php foreach([
              ['cp1','ei1','current_password','Enter current password','Current Password'],
              ['cp2','ei2','new_password','Min. 6 characters','New Password'],
              ['cp3','ei3','confirm_password','Repeat new password','Confirm Password'],
            ] as [$cpid,$eiid,$name,$ph,$lbl]): ?>
            <div>
              <label class="form-label"><?= $lbl ?></label>
              <div style="position:relative">
                <input type="password" name="<?= $name ?>" id="<?= $cpid ?>" class="form-control"
                  style="padding-right:42px" placeholder="<?= $ph ?>" required>
                <button type="button" onclick="togglePw('<?= $cpid ?>','<?= $eiid ?>')"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px">
                  <i class="bi bi-eye" id="<?= $eiid ?>"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:20px">
            <button type="submit" class="btn-primary-custom"><i class="bi bi-shield-lock"></i> Update Password</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ACCOUNT DETAILS -->
    <div class="profile-section-card">
      <div class="profile-section-header">
        <i class="bi bi-info-circle-fill" style="color:var(--accent);font-size:15px"></i>
        <div class="profile-section-title">Account Details</div>
      </div>
      <div style="padding:6px 22px">
        <div class="account-row">
          <span class="account-row-label">Member since</span>
          <span class="account-row-value"><?= $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : '—' ?></span>
        </div>
        <div class="account-row">
          <span class="account-row-label">Last login</span>
          <span class="account-row-value"><?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : 'Never' ?></span>
        </div>
        <div class="account-row">
          <span class="account-row-label">Account status</span>
          <span class="badge-status bs-active">Active</span>
        </div>
        <div class="account-row">
          <span class="account-row-label">Role</span>
          <span style="background:rgba(242,140,40,.12);color:var(--accent);border-radius:5px;padding:2px 10px;font-size:11px;font-weight:700;text-transform:uppercase"><?= ucfirst(h($user['role'])) ?></span>
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
    ['avatarPreview','avatarThumb'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';
    });
  };
  reader.readAsDataURL(input.files[0]);
}
function togglePw(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { inp.type = 'password'; icon.className = 'bi bi-eye'; }
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
