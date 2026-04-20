<?php
$page_title = $page_title ?? 'Dashboard';
$active_nav = $active_nav ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title) ?> — FJB IT Inventory</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
:root {
  --sidebar-bg:   #1a2332;
  --sidebar-hover:#243044;
  --sidebar-active-bg: rgba(242,140,40,.15);
  --sidebar-text: #94a3b8;
  --sidebar-head: #ffffff;
  --sidebar-w:    250px;
  --accent:       #F28C28;
  --accent-h:     #e07818;
  --accent-rgb:   242,140,40;
  --body-bg:      #f1f5f9;
  --surface:      #ffffff;
  --border:       #e2e8f0;
  --text:         #1e293b;
  --muted:        #64748b;
  --red:          #ef4444;
  --green:        #22c55e;
  --yellow:       #f59e0b;
  --blue:         #3b82f6;
  --table-hover:  #f8fafc;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--body-bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;min-height:100vh}

/* ── SIDEBAR ── */
.sidebar{
  position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;
  background:var(--sidebar-bg);display:flex;flex-direction:column;z-index:100;
  transition:transform .3s;
}
.sidebar-brand{
  padding:20px 20px 16px;border-bottom:1px solid rgba(255,255,255,.08);
  display:flex;align-items:center;gap:12px;
}
.sidebar-brand img{width:42px;height:42px;object-fit:contain}
.brand-name{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;
  text-transform:uppercase;letter-spacing:.05em;line-height:1.3}
.brand-name span{color:var(--accent);display:block;font-size:10px;letter-spacing:.1em;font-weight:700}

.sidebar-nav{flex:1;overflow-y:auto;padding:16px 12px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.08) transparent}
.sidebar-nav::-webkit-scrollbar{width:3px}
.sidebar-nav::-webkit-scrollbar-track{background:transparent}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:99px}
.sidebar-nav::-webkit-scrollbar-thumb:hover{background:rgba(242,140,40,.4)}
.sidebar-nav:hover::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14)}
.nav-section-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.12em;
  color:rgba(255,255,255,.3);padding:0 8px;margin:20px 0 6px}
.nav-section-label:first-child{margin-top:4px}

.nav-link{
  display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;
  color:var(--sidebar-text);text-decoration:none;font-size:13.5px;font-weight:500;
  transition:all .15s;margin-bottom:2px;
}
.nav-link i{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.nav-link:hover{background:var(--sidebar-hover);color:#fff}
.nav-link.active{background:var(--sidebar-active-bg);color:var(--accent);font-weight:600}
.nav-link .badge-count, button .badge-count{
  margin-left:auto;background:var(--red);color:#fff;
  border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;flex-shrink:0;
}
button .badge-count{ margin-left:0; }

.sidebar-footer{padding:14px 12px;border-top:1px solid rgba(255,255,255,.08)}
.user-card{
  display:flex;align-items:center;gap:10px;padding:10px 12px;
  border-radius:8px;background:rgba(255,255,255,.06);
  margin-bottom:10px;text-decoration:none;transition:background .15s;cursor:pointer;
}
.user-card:hover{background:rgba(255,255,255,.1)}
.user-avatar{
  width:34px;height:34px;border-radius:50%;background:var(--accent);
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.user-info{min-width:0;flex:1}
.user-name{font-size:13px;font-weight:500;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;text-transform:uppercase;letter-spacing:.06em;
  color:<?= isAdmin() ? 'var(--accent)' : 'rgba(255,255,255,.4)' ?>;font-weight:600}
.btn-logout{
  display:flex;align-items:center;gap:8px;width:100%;padding:9px 12px;
  background:rgba(255,255,255,.06);border:none;border-radius:8px;
  color:rgba(255,255,255,.5);font-size:13px;cursor:pointer;text-decoration:none;
  transition:all .15s;font-family:'Plus Jakarta Sans',sans-serif;
}
.btn-logout:hover{background:rgba(239,68,68,.15);color:#ef4444}

/* ── MAIN ── */
.main-content{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.topbar{
  background:var(--surface);border-bottom:1px solid var(--border);
  padding:0 28px;height:60px;display:flex;align-items:center;gap:16px;
  position:sticky;top:0;z-index:50;
}
.topbar-left{flex:1}
.topbar-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--text)}
.topbar-breadcrumb{font-size:11px;color:var(--muted);margin-top:1px}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-user{
  display:flex;align-items:center;gap:8px;
  background:var(--body-bg);border:1px solid var(--border);
  border-radius:8px;padding:6px 12px;
}
.topbar-user-name{font-size:13px;font-weight:600;color:var(--text)}
.topbar-role-badge{
  background:rgba(var(--accent-rgb),.12);color:var(--accent);
  border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:uppercase;
}
.theme-toggle{
  width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;
  background:var(--body-bg);border:1px solid var(--border);
  color:var(--muted);cursor:pointer;font-size:16px;transition:all .15s;
}
.theme-toggle:hover{border-color:var(--accent);color:var(--accent)}
.page-body{padding:24px 28px;flex:1}

/* ── STAT CARDS ── */
.stat-card{
  background:var(--surface);border:1px solid var(--border);
  border-radius:12px;padding:20px 22px;
  border-left:4px solid var(--accent);
  transition:box-shadow .2s,transform .2s;
  cursor:default;
}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}
.stat-icon{
  width:44px;height:44px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:14px;
}
.stat-value{font-family:'Syne',sans-serif;font-size:30px;font-weight:800;color:var(--text);line-height:1}
.stat-label{font-size:12px;color:var(--muted);margin-top:4px;font-weight:500}

/* ── TABLE CARD ── */
.table-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.table-card-header{
  padding:16px 20px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:12px;background:var(--surface);
}
.table-card-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--text);flex:1}
.table{color:var(--text);margin:0}
.table thead th{
  background:var(--table-head-bg, #e2e8f0) !important;
  border-color:var(--border) !important;
  color:var(--table-head-color, #475569);font-size:11px;font-weight:700;
  text-transform:uppercase;letter-spacing:.08em;
  padding:12px 16px;white-space:nowrap;
}
.table tbody tr{background:var(--surface) !important;color:var(--text) !important}
.table tbody tr:hover{background:var(--table-hover) !important}
.table tbody td{
  border-color:var(--border) !important;padding:12px 16px;
  vertical-align:middle;color:var(--text) !important;background:transparent !important;
}
.table tbody td span:not(.badge-status),.table tbody td div,.table tbody td a{color:var(--text) !important}
.table tbody td code{color:var(--accent) !important}

/* ── BADGES ── */
.badge-status{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.bs-active  {background:rgba(34,197,94,.12);color:#16a34a}
.bs-disposed{background:rgba(239,68,68,.12);color:#dc2626}
.bs-pending {background:rgba(245,158,11,.12);color:#d97706}
.bs-repair  {background:rgba(59,130,246,.12);color:#2563eb}

/* ── FORM ── */
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px}
.form-label{color:var(--muted);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.form-control,.form-select{
  background:var(--form-input-bg, #fff) !important;
  border:1.5px solid var(--form-input-border, var(--border)) !important;
  color:var(--form-input-color, var(--text)) !important;
  border-radius:8px;padding:9px 13px;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;transition:border-color .2s;
}
.form-control:focus,.form-select:focus{
  border-color:var(--accent) !important;
  box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1) !important;outline:none;
}
textarea.form-control{min-height:90px;resize:vertical}
.form-control::placeholder{color:var(--muted);opacity:.7}

/* ── BUTTONS ── */
.btn-primary-custom{
  background:var(--accent);color:#fff;border:none;border-radius:8px;
  padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;
  cursor:pointer;transition:all .15s;text-decoration:none;
  display:inline-flex;align-items:center;gap:7px;
}
.btn-primary-custom:hover{background:var(--accent-h);color:#fff;transform:translateY(-1px)}
.btn-secondary-custom{
  background:#fff;color:var(--text);border:1.5px solid var(--border);border-radius:8px;
  padding:9px 18px;font-size:13px;font-weight:500;cursor:pointer;
  transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:7px;
}
.btn-secondary-custom:hover{border-color:var(--accent);color:var(--accent)}
.btn-icon{
  width:30px;height:30px;border-radius:6px;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:14px;border:none;cursor:pointer;transition:all .15s;text-decoration:none;
}
.btn-edit      {background:rgba(59,130,246,.1);color:#2563eb}
.btn-edit:hover{background:rgba(59,130,246,.2);color:#2563eb}
.btn-delete      {background:rgba(239,68,68,.1);color:#dc2626}
.btn-delete:hover{background:rgba(239,68,68,.2);color:#dc2626}
.btn-view      {background:rgba(var(--accent-rgb),.1);color:var(--accent)}
.btn-view:hover{background:rgba(var(--accent-rgb),.2);color:var(--accent)}

/* ── ALERTS ── */
.alert-success-custom{
  background:rgba(var(--accent-rgb),.08);border:1px solid rgba(var(--accent-rgb),.25);
  color:var(--accent);border-radius:8px;padding:12px 16px;
  margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;
}
.alert-danger-custom{
  background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);
  color:#dc2626;border-radius:8px;padding:12px 16px;
  margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;
}

/* ── DATATABLES ── */
.dataTables_wrapper .dataTables_filter input,
.dataTables_wrapper .dataTables_length select{
  background:var(--form-input-bg, #fff) !important;
  border:1.5px solid var(--form-input-border, var(--border)) !important;
  color:var(--form-input-color, var(--text)) !important;
  border-radius:6px;padding:5px 10px;
}
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_filter label,
.dataTables_wrapper .dataTables_length label{color:var(--muted)}
/* DataTables Bootstrap5 pagination overrides */
.dataTables_wrapper .dataTables_paginate .paginate_button{
  background:transparent !important;
  border:none !important;
  margin:0 !important;padding:0 !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover{
  opacity:.35 !important;cursor:default !important;
}
/* Bootstrap5 pagination (used by dataTables.bootstrap5) */
.dataTables_wrapper .pagination{gap:3px;flex-wrap:wrap}
.dataTables_wrapper .pagination .page-item .page-link{
  font-size:12px !important;font-weight:600 !important;
  font-family:'Plus Jakarta Sans',sans-serif !important;
  padding:4px 10px !important;line-height:1.5 !important;
  border-radius:6px !important;border:1px solid var(--border) !important;
  background:transparent !important;color:var(--muted) !important;
  transition:all .15s;
}
.dataTables_wrapper .pagination .page-item.previous .page-link,
.dataTables_wrapper .pagination .page-item.next .page-link{
  color:var(--text) !important;padding:4px 12px !important;
}
.dataTables_wrapper .pagination .page-item.active .page-link{
  background:var(--accent) !important;border-color:var(--accent) !important;color:#fff !important;
}
.dataTables_wrapper .pagination .page-item .page-link:hover{
  background:var(--surface2) !important;color:var(--text) !important;border-color:var(--border) !important;
}
.dataTables_wrapper .pagination .page-item.disabled .page-link{
  opacity:.35 !important;background:transparent !important;border-color:var(--border) !important;
}
.dataTables_wrapper .dataTables_info{
  font-size:13px;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif;padding-top:6px;
}
table.dataTable tbody tr,
table.dataTable tbody tr.odd,
table.dataTable tbody tr.even{background-color:var(--surface) !important;color:var(--text) !important}
table.dataTable tbody tr:hover,
table.dataTable tbody tr.odd:hover,
table.dataTable tbody tr.even:hover{background-color:var(--table-hover) !important}
table.dataTable tbody td{color:var(--text) !important}

code{color:var(--accent);background:rgba(var(--accent-rgb),.08);
  padding:1px 5px;border-radius:4px;font-size:12px}

/* ── MOBILE ── */
.sidebar-toggle{display:none;background:none;border:1.5px solid var(--border);
  border-radius:7px;color:var(--text);padding:6px 10px;cursor:pointer;font-size:18px}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main-content{margin-left:0}
  .sidebar-toggle{display:inline-flex;align-items:center}
  .page-body{padding:16px}
}
#reportsToggle:hover{background:rgba(255,255,255,.06);color:#fff}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="assets/img/fjb-logo.png" alt="FJB Logo">
    <div class="brand-name">FJB Pasir Gudang<span>IT Inventory</span></div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <a href="dashboard.php" class="nav-link <?= $active_nav==='dashboard'?'active':'' ?>">
      <i class="bi bi-grid-1x2-fill"></i> Dashboard
    </a>
    <div class="nav-section-label">Inventory</div>
    <!-- IT Request Form -->
    <a href="it_request_form.php" class="nav-link <?= $active_nav==='it_request_form'?'active':'' ?>">
      <i class="bi bi-file-earmark-text-fill"></i> IT Request Form
    </a>
    <?php
      $del_req_count = 0;
      if (isAdmin()) {
        $dr_exists = $db->query("SHOW TABLES LIKE 'delete_requests'")->num_rows > 0;
        if ($dr_exists) {
          $dr_res = $db->query("SELECT COUNT(*) c FROM delete_requests WHERE status='Pending'");
          if ($dr_res) $del_req_count = (int)$dr_res->fetch_assoc()['c'];
        }
        $ar_exists = $db->query("SHOW TABLES LIKE 'add_asset_requests'")->num_rows > 0;
        if ($ar_exists) {
          $ar_res = $db->query("SELECT COUNT(*) c FROM add_asset_requests WHERE status='Pending'");
          if ($ar_res) $del_req_count += (int)$ar_res->fetch_assoc()['c'];
        }
        $er_exists = $db->query("SHOW TABLES LIKE 'ewaste_requests'")->num_rows > 0;
        if ($er_exists) {
          $er_res = $db->query("SELECT COUNT(*) c FROM ewaste_requests WHERE status='Pending'");
          if ($er_res) $del_req_count += (int)$er_res->fetch_assoc()['c'];
        }
      }
      $it_active = in_array($active_nav, ['inventory','inventory_pending']);
    ?>
    <?php if (isAdmin()): ?>
    <!-- IT Assets Dropdown (admin) -->
    <div>
      <button onclick="toggleITAssets()" id="itAssetsToggle"
        style="width:100%;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;
          background:<?= $it_active ? 'rgba(242,140,40,.15)' : 'none' ?>;
          color:<?= $it_active ? 'var(--accent)' : '#94a3b8' ?>;
          border:none;font-size:13.5px;font-weight:<?= $it_active ? '600' : '500' ?>;
          cursor:pointer;font-family:inherit;margin-bottom:2px;transition:all .15s;text-align:left">
        <i class="bi bi-box-seam-fill" style="font-size:16px;width:20px;text-align:center;flex-shrink:0"></i>
        <span style="flex:1">IT Assets</span>
        <?php if ($del_req_count > 0): ?>
        <span class="badge-count" style="background:#dc2626"><?= $del_req_count ?></span>
        <?php endif; ?>
        <i class="bi bi-chevron-down" id="itAssetsChevron"
          style="font-size:11px;transition:transform .2s;<?= $it_active ? 'transform:rotate(180deg)' : '' ?>"></i>
      </button>
      <div id="itAssetsMenu" style="<?= $it_active ? '' : 'display:none;' ?>padding-left:14px;margin-top:2px">
        <a href="inventory.php" class="nav-link <?= $active_nav==='inventory'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-box-seam" style="font-size:14px"></i> All Assets
        </a>
        <a href="inventory.php?view=pending_requests" class="nav-link <?= $active_nav==='inventory_pending'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-hourglass-split" style="font-size:14px"></i> Pending Requests
          <?php if ($del_req_count > 0): ?>
          <span class="badge-count" style="background:#dc2626"><?= $del_req_count ?></span>
          <?php endif; ?>
        </a>
      </div>
    </div>
    <script>
    function toggleITAssets() {
      const menu = document.getElementById('itAssetsMenu');
      const chevron = document.getElementById('itAssetsChevron');
      const open = menu.style.display !== 'none';
      menu.style.display = open ? 'none' : 'block';
      chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }
    </script>
    <?php else: ?>
    <!-- IT Assets Dropdown (user) -->
    <?php
      $user_req_count = 0;
      $dr_exists_u = $db->query("SHOW TABLES LIKE 'delete_requests'")->num_rows > 0;
      if ($dr_exists_u) {
        $urc_res = $db->query("SELECT COUNT(*) c FROM delete_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'");
        if ($urc_res) $user_req_count = (int)$urc_res->fetch_assoc()['c'];
      }
      $ar_exists_u = $db->query("SHOW TABLES LIKE 'add_asset_requests'")->num_rows > 0;
      if ($ar_exists_u) {
        $uarc_res = $db->query("SELECT COUNT(*) c FROM add_asset_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'");
        if ($uarc_res) $user_req_count += (int)$uarc_res->fetch_assoc()['c'];
      }
      $er_exists_u = $db->query("SHOW TABLES LIKE 'ewaste_requests'")->num_rows > 0;
      if ($er_exists_u) {
        $uerc_res = $db->query("SELECT COUNT(*) c FROM ewaste_requests WHERE requested_by={$_SESSION['user_id']} AND status='Pending'");
        if ($uerc_res) $user_req_count += (int)$uerc_res->fetch_assoc()['c'];
      }
      $it_active_user = in_array($active_nav, ['inventory','inventory_my_requests']);
    ?>
    <div>
      <button onclick="toggleITAssets()" id="itAssetsToggle"
        style="width:100%;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;
          background:<?= $it_active_user ? 'rgba(242,140,40,.15)' : 'none' ?>;
          color:<?= $it_active_user ? 'var(--accent)' : '#94a3b8' ?>;
          border:none;font-size:13.5px;font-weight:<?= $it_active_user ? '600' : '500' ?>;
          cursor:pointer;font-family:inherit;margin-bottom:2px;transition:all .15s;text-align:left">
        <i class="bi bi-box-seam-fill" style="font-size:16px;width:20px;text-align:center;flex-shrink:0"></i>
        <span style="flex:1">IT Assets</span>
        <?php if ($user_req_count > 0): ?>
        <span class="badge-count" style="background:#d97706"><?= $user_req_count ?></span>
        <?php endif; ?>
        <i class="bi bi-chevron-down" id="itAssetsChevron"
          style="font-size:11px;transition:transform .2s;<?= $it_active_user ? 'transform:rotate(180deg)' : '' ?>"></i>
      </button>
      <div id="itAssetsMenu" style="<?= $it_active_user ? '' : 'display:none;' ?>padding-left:14px;margin-top:2px">
        <a href="inventory.php" class="nav-link <?= $active_nav==='inventory'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-box-seam" style="font-size:14px"></i> All Assets
        </a>
        <a href="inventory.php?view=my_requests" class="nav-link <?= $active_nav==='inventory_my_requests'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-clock-history" style="font-size:14px"></i> My Requests
          <?php if ($user_req_count > 0): ?>
          <span class="badge-count" style="background:#d97706"><?= $user_req_count ?></span>
          <?php endif; ?>
        </a>
      </div>
    </div>
    <script>
    function toggleITAssets() {
      const menu = document.getElementById('itAssetsMenu');
      const chevron = document.getElementById('itAssetsChevron');
      const open = menu.style.display !== 'none';
      menu.style.display = open ? 'none' : 'block';
      chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }
    </script>
    <?php endif; ?>
    <?php
      $wo_pending_count = $db->query("SELECT COUNT(*) as c FROM ewaste_items WHERE disposal_status='Pending'")->fetch_assoc()['c'];
      $cp_count = $db->query("SELECT COUNT(*) as c FROM ewaste_items WHERE disposal_status='Collected'")->fetch_assoc()['c'];
      $ew_active = in_array($active_nav, ['ewaste','collected_proofs']);
    ?>
    <a href="writeoff.php" class="nav-link <?= $active_nav==='writeoff'?'active':'' ?>">
      <i class="bi bi-pen-fill"></i> Write Off
      <?php if ($wo_pending_count > 0) echo '<span class="badge-count">'.$wo_pending_count.'</span>'; ?>
    </a>
    <!-- E-Waste Dropdown -->
    <div>
      <button onclick="toggleEwaste()" id="ewasteToggle"
        style="width:100%;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;
          background:<?= $ew_active ? 'rgba(242,140,40,.15)' : 'none' ?>;
          color:<?= $ew_active ? 'var(--accent)' : '#94a3b8' ?>;
          border:none;font-size:13.5px;font-weight:<?= $ew_active ? '600' : '500' ?>;
          cursor:pointer;font-family:inherit;margin-bottom:2px;transition:all .15s;text-align:left">
        <i class="bi bi-recycle" style="font-size:16px;width:20px;text-align:center;flex-shrink:0"></i>
        <span style="flex:1">E-Waste</span>
        <i class="bi bi-chevron-down" id="ewasteChevron"
          style="font-size:11px;transition:transform .2s;<?= $ew_active ? 'transform:rotate(180deg)' : '' ?>"></i>
      </button>
      <div id="ewasteMenu" style="<?= $ew_active ? '' : 'display:none;' ?>padding-left:14px;margin-top:2px">
        <a href="ewaste.php" class="nav-link <?= $active_nav==='ewaste'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-recycle" style="font-size:14px"></i> E-Waste Items
        </a>
        <?php if (isAdmin()): ?>
        <a href="collected_proofs.php" class="nav-link <?= $active_nav==='collected_proofs'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-patch-check-fill" style="font-size:14px"></i> Collected Proofs
          <?php if ($cp_count > 0): ?>
          <span class="badge-count" style="background:#16a34a"><?= $cp_count ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <script>
    function toggleEwaste() {
      const menu = document.getElementById('ewasteMenu');
      const chevron = document.getElementById('ewasteChevron');
      const open = menu.style.display !== 'none';
      menu.style.display = open ? 'none' : 'block';
      chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }
    </script>
    <?php if (isAdmin()): ?>
    <div class="nav-section-label">Admin</div>
    <a href="users.php" class="nav-link <?= $active_nav==='users'?'active':'' ?>">
      <i class="bi bi-people-fill"></i> Manage Users
    </a>
    <a href="asset_classes.php" class="nav-link <?= $active_nav==='asset_classes'?'active':'' ?>">
      <i class="bi bi-tags-fill"></i> Asset Classes
    </a>
    <!-- Reports Dropdown -->
    <div>
      <button onclick="toggleReports()" id="reportsToggle"
        style="width:100%;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;
          background:<?= in_array($active_nav,['reports','reports_ewaste']) ? 'rgba(242,140,40,.15)' : 'none' ?>;
          color:<?= in_array($active_nav,['reports','reports_ewaste']) ? 'var(--accent)' : '#94a3b8' ?>;
          border:none;font-size:13.5px;font-weight:<?= in_array($active_nav,['reports','reports_ewaste']) ? '600' : '500' ?>;
          cursor:pointer;font-family:inherit;margin-bottom:2px;transition:all .15s;text-align:left">
        <i class="bi bi-bar-chart-line-fill" style="font-size:16px;width:20px;text-align:center;flex-shrink:0"></i>
        <span style="flex:1">Reports</span>
        <i class="bi bi-chevron-down" id="reportsChevron"
          style="font-size:11px;transition:transform .2s;<?= in_array($active_nav,['reports','reports_ewaste']) ? 'transform:rotate(180deg)' : '' ?>"></i>
      </button>
      <div id="reportsMenu" style="<?= in_array($active_nav,['reports','reports_ewaste']) ? '' : 'display:none;' ?>padding-left:14px;margin-top:2px">
        <a href="reports.php" class="nav-link <?= $active_nav==='reports'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-box-seam" style="font-size:14px"></i> IT Assets
        </a>
        <a href="reports.php?type=ewaste" class="nav-link <?= $active_nav==='reports_ewaste'?'active':'' ?>"
          style="padding:7px 12px;font-size:13px">
          <i class="bi bi-recycle" style="font-size:14px"></i> E-Waste Records
        </a>
      </div>
    </div>
    <a href="activity.php" class="nav-link <?= $active_nav==='activity'?'active':'' ?>">
      <i class="bi bi-clock-history"></i> Activity Log
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="profile.php" class="user-card">
      <?php if (!empty($_SESSION['avatar'])): ?>
      <img src="<?= h($_SESSION['avatar']) ?>" alt="avatar"
        style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0">
      <?php else: ?>
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
      <?php endif; ?>
      <div class="user-info">
        <div class="user-name"><?= h($_SESSION['full_name']) ?></div>
        <div class="user-role"><?= h($_SESSION['role']) ?></div>
      </div>
      <i class="bi bi-gear" style="color:rgba(255,255,255,.3);font-size:13px;flex-shrink:0"></i>
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
      <i class="bi bi-list"></i>
    </button>
    <div class="topbar-left">
      <div class="topbar-title"><?= h($page_title) ?></div>
      <div class="topbar-breadcrumb">FJB Johor Bulkers Sdn Bhd &rsaquo; <?= h($page_title) ?></div>
    </div>
    <div class="topbar-right">
      <span style="font-size:12px;color:var(--muted)"><?= date('l, d F Y') ?></span>
      <div class="topbar-user">
        <span class="topbar-role-badge"><?= ucfirst(h($_SESSION['role'])) ?></span>
        <span class="topbar-user-name"><?= h($_SESSION['full_name']) ?></span>
      </div>
      <button class="theme-toggle" id="themeToggle" title="Toggle light/dark" onclick="toggleTheme()">
        <i class="bi bi-sun-fill" id="themeIcon"></i>
      </button>
      <a href="auth/logout.php"
        style="display:flex;align-items:center;gap:7px;padding:7px 14px;background:rgba(239,68,68,.08);border:1.5px solid rgba(239,68,68,.2);border-radius:8px;color:#dc2626;font-size:13px;font-weight:600;text-decoration:none;transition:all .15s"
        onmouseover="this.style.background='rgba(239,68,68,.15)'" onmouseout="this.style.background='rgba(239,68,68,.08)'">
        <i class="bi bi-box-arrow-right"></i> Logout
      </a>
    </div>
  </div>
  <div class="page-body">
