<?php
session_start();
require_once 'config/db.php';

if (isset($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = $success = '';
$tab = $_POST['tab'] ?? $_GET['tab'] ?? 'login';

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'login')) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id,username,password,full_name,role,is_active,department,avatar FROM users WHERE username=?");
        $stmt->bind_param('s', $username); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['avatar']    = $user['avatar'] ?? '';
            $db->query("UPDATE users SET last_login=NOW() WHERE id=".(int)$user['id']);
            logActivity($user['id'],'LOGIN','user',$user['id'],'User logged in');
            header('Location: dashboard.php'); exit;
        } else { $error = 'Invalid username or password.'; }
    }
}

// ── SIGN UP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'signup') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $username   = trim($_POST['new_username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $password   = $_POST['new_password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    if (empty($full_name) || empty($username) || empty($password)) {
        $error = 'Full name, username and password are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();
        $chk = $db->prepare("SELECT id FROM users WHERE username=?");
        $chk->bind_param('s',$username); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) {
            $error = 'Username already taken. Please choose another.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';
            $stmt = $db->prepare("INSERT INTO users (username,password,full_name,email,role,department) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssssss',$username,$hash,$full_name,$email,$role,$department);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                logActivity($new_id,'SIGNUP','user',$new_id,'New user registered: '.$username);
                $success = 'Account created! You can now sign in.';
                $tab = 'login';
            } else { $error = 'Registration failed. Please try again.'; }
            $stmt->close();
        }
        $chk->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FJB IT Inventory — Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
  --bg:#f0f2f5; --surface:#fff; --surface2:#f7f8fa;
  --border:#e2e5ea; --text:#1a1f2e; --muted:#6b7280;
  --accent:#F28C28; --accent-h:#e07d1a; --red:#dc2626; --green:#16a34a;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--bg);font-family:'DM Sans',sans-serif;
  display:flex;align-items:center;justify-content:center;padding:20px;
  position:relative;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#d1d5db 1px,transparent 1px);
  background-size:28px 28px;z-index:0;opacity:.5}
body::after{content:'';position:fixed;width:600px;height:600px;
  background:radial-gradient(circle,rgba(242,140,40,.13) 0%,transparent 65%);
  top:-200px;right:-200px;z-index:0;animation:orb 10s ease-in-out infinite alternate}
@keyframes orb{to{transform:translate(-60px,100px)}}

.wrap{position:relative;z-index:1;width:100%;max-width:480px}

.brand{display:flex;align-items:center;gap:12px;justify-content:center;margin-bottom:28px}
.brand img{width:48px;height:48px;object-fit:contain}
.brand-text{font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--text);
  text-transform:uppercase;letter-spacing:.06em;line-height:1.3}
.brand-text span{color:var(--accent);display:block;font-size:11px;letter-spacing:.1em}

.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;
  overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,.04),0 20px 40px rgba(0,0,0,.07)}

/* Tabs */
.tabs{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--border)}
.tab-btn{padding:16px;background:none;border:none;border-bottom:2px solid transparent;
  margin-bottom:-1px;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;
  text-transform:uppercase;letter-spacing:.07em;color:var(--muted);cursor:pointer;transition:all .2s}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);background:rgba(242,140,40,.04)}
.tab-btn:not(.active):hover{color:var(--text);background:var(--surface2)}

.form-body{padding:32px 36px}
.tab-panel{display:none}.tab-panel.active{display:block}

.form-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--text);margin-bottom:4px}
.form-sub{color:var(--muted);font-size:13px;margin-bottom:26px}

.field{margin-bottom:18px}
label{display:block;font-size:11px;font-weight:700;color:var(--muted);
  text-transform:uppercase;letter-spacing:.09em;margin-bottom:7px}
.input-wrap{position:relative;display:flex;align-items:center}
.input-wrap i{position:absolute;left:14px;color:#9ca3af;font-size:15px;pointer-events:none;z-index:1}
.eye-toggle{position:absolute;right:12px;color:#9ca3af;font-size:15px;
  cursor:pointer;background:none;border:none;padding:4px;transition:color .2s;z-index:1;display:flex;align-items:center}
.eye-toggle:hover{color:var(--accent)}

input[type="text"],input[type="email"],input[type="password"]{
  width:100%;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;
  color:var(--text);padding:13px 14px 13px 42px;font-size:14px;font-family:'DM Sans',sans-serif;
  transition:border-color .2s,box-shadow .2s;outline:none}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(242,140,40,.1);background:#fff}
input::placeholder{color:#bfc5cc}

.row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.btn-submit{width:100%;margin-top:6px;background:var(--accent);color:#fff;border:none;
  border-radius:10px;padding:14px;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;
  letter-spacing:.04em;cursor:pointer;transition:background .2s,transform .1s,box-shadow .2s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 4px 14px rgba(242,140,40,.35)}
.btn-submit:hover{background:var(--accent-h);box-shadow:0 6px 20px rgba(242,140,40,.45);transform:translateY(-1px)}
.btn-submit:active{transform:none;box-shadow:none}

.alert{border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:9px}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red)}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:var(--green)}

.signup-note{margin-top:16px;background:rgba(242,140,40,.05);border:1px solid rgba(242,140,40,.15);
  border-radius:9px;padding:12px 14px;font-size:12px;color:var(--muted);display:flex;gap:9px;align-items:flex-start}
.signup-note i{color:var(--accent);font-size:14px;flex-shrink:0;margin-top:1px}

.divider{text-align:center;margin-top:0;padding:16px 36px;border-top:1px solid var(--border);
  font-size:12px;color:var(--muted)}

@media(max-width:480px){.form-body{padding:24px 20px}.row-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <img src="assets/img/fjb-logo.jpg" alt="FJB Logo" style="width:48px;height:48px;object-fit:contain">
    <div class="brand-text">FJB Pasir Gudang<span>IT Inventory System</span></div>
  </div>

  <div class="card">
    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn <?= $tab==='login'?'active':'' ?>" onclick="switchTab('login',this)">
        <i class="bi bi-box-arrow-in-right"></i> Sign In
      </button>
      <button class="tab-btn <?= $tab==='signup'?'active':'' ?>" onclick="switchTab('signup',this)">
        <i class="bi bi-person-plus"></i> Sign Up
      </button>
    </div>

    <div class="form-body">

      <!-- ── LOGIN ── -->
      <div class="tab-panel <?= $tab==='login'?'active':'' ?>" id="panel-login">
        <div class="form-title">Welcome back</div>
        <div class="form-sub">Sign in to access the inventory portal</div>

        <?php if ($error && $tab==='login'): ?>
        <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="tab" value="login">
          <div class="field">
            <label>Username</label>
            <div class="input-wrap">
              <i class="bi bi-person"></i>
              <input type="text" name="username" placeholder="Enter your username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
          </div>
          <div class="field">
            <label>Password</label>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="input-wrap" style="flex:1">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" id="password-input" placeholder="Enter your password" required>
              </div>
              <button type="button" onclick="togglePassword()"
                style="flex-shrink:0;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;padding:4px;transition:color .2s"
                onmouseover="this.style.color='#F28C28'" onmouseout="this.style.color='#9ca3af'">
                <i class="bi bi-eye" id="eye-icon"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn-submit">Sign In <i class="bi bi-arrow-right"></i></button>
        </form>
      </div>

      <!-- ── SIGN UP ── -->
      <div class="tab-panel <?= $tab==='signup'?'active':'' ?>" id="panel-signup">
        <div class="form-title">Create account</div>
        <div class="form-sub">Register a new account</div>

        <?php if ($error && $tab==='signup'): ?>
        <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="tab" value="signup">
          <div class="row-2">
            <div class="field">
              <label>Full Name *</label>
              <div class="input-wrap">
                <i class="bi bi-person-badge"></i>
                <input type="text" name="full_name" placeholder="Your full name"
                  value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
              </div>
            </div>
            <div class="field">
              <label>Username *</label>
              <div class="input-wrap">
                <i class="bi bi-at"></i>
                <input type="text" name="new_username" placeholder="Choose a username"
                  value="<?= htmlspecialchars($_POST['new_username'] ?? '') ?>" required>
              </div>
            </div>
          </div>
          <div class="row-2">
            <div class="field">
              <label>Email</label>
              <div class="input-wrap">
                <i class="bi bi-envelope"></i>
                <input type="email" name="email" placeholder="your@email.com"
                  value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
              </div>
            </div>
            <div class="field">
              <label>Staff ID</label>
              <div class="input-wrap">
                <i class="bi bi-person-badge"></i>
                <input type="text" name="department" placeholder="e.g. FJB-0012"
                  value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">
              </div>
            </div>
          </div>
          <div class="row-2">
            <div class="field">
              <label>Password *</label>
              <div class="input-wrap">
                <i class="bi bi-lock"></i>
                <input type="password" name="new_password" placeholder="Min. 6 characters" required>
              </div>
            </div>
            <div class="field">
              <label>Confirm Password *</label>
              <div class="input-wrap">
                <i class="bi bi-lock-fill"></i>
                <input type="password" name="confirm_password" placeholder="Repeat password" required>
              </div>
            </div>
          </div>
          <button type="submit" class="btn-submit">Create Account <i class="bi bi-arrow-right"></i></button>
        </form>
      </div>

    </div>
    <div class="divider">FJB Johor Bulkers Sdn Bhd</div>
  </div>

</div>
<script>
function switchTab(tab, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + tab).classList.add('active');
  btn.classList.add('active');
}
function togglePassword() {
  const input = document.getElementById('password-input');
  const icon  = document.getElementById('eye-icon');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { input.type = 'password'; icon.className = 'bi bi-eye'; }
}
</script>
</body>
</html>
