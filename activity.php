<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();

$logs = $db->query("SELECT al.*, u.full_name, u.username FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 200");

$totalRes    = $db->query("SELECT COUNT(*) as cnt FROM activity_log");
$totalCount  = $totalRes->fetch_assoc()['cnt'];

$today       = date('Y-m-d');
$todayRes    = $db->query("SELECT action, COUNT(*) as cnt FROM activity_log WHERE DATE(created_at)='$today' GROUP BY action");
$todayStats  = ['LOGIN' => 0, 'LOGOUT' => 0];
while ($row = $todayRes->fetch_assoc()) $todayStats[$row['action']] = (int)$row['cnt'];

$activeRes   = $db->query("SELECT COUNT(DISTINCT user_id) as cnt FROM activity_log WHERE action='LOGIN' AND DATE(created_at)='$today'");
$activeUsers = $activeRes->fetch_assoc()['cnt'];

$page_title = 'Activity Log';
$active_nav = 'activity';
require_once 'includes/layout.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">

<style>
/* ── Inherit project CSS variables so dark mode toggle works automatically ── */
.al-wrap {
    --al-bg-card:        #ffffff;
    --al-bg-sub:         #f4f5f8;
    --al-bg-input:       #f1f3f7;
    --al-bg-thead:       #f4f5f8;
    --al-bg-hover:       #f8f9fb;
    --al-border:         #e5e7eb;
    --al-border-light:   #eef0f3;
    --al-text-primary:   #111827;
    --al-text-secondary: #6b7280;
    --al-text-muted:     #9ca3af;
    --al-text-faint:     #d1d5db;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* ── Dark mode — toggled via JS observer ── */
.al-wrap.al-dark {
    --al-bg-card:        #1a1e2a;
    --al-bg-sub:         #141720;
    --al-bg-input:       #141720;
    --al-bg-thead:       #141720;
    --al-bg-hover:       #1e2230;
    --al-border:         #2a2f3d;
    --al-border-light:   #222636;
    --al-text-primary:   #e2e8f0;
    --al-text-secondary: #94a3b8;
    --al-text-muted:     #64748b;
    --al-text-faint:     #3f4558;
}

/* ── Stats ── */
.al-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
.al-stat {
    background: var(--al-bg-card);
    border: 1px solid var(--al-border);
    border-radius: 10px;
    padding: 16px 18px;
}
.al-stat-label {
    font-size: 11px;
    color: var(--al-text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 8px;
}
.al-stat-val       { font-size: 26px; font-weight: 600; line-height: 1; }
.al-stat-val.blue  { color: #3b82f6; }
.al-stat-val.green { color: #10b981; }
.al-stat-val.amber { color: #F28C28; }
.al-stat-val.gray  { color: var(--al-text-secondary); }
.al-stat-sub       { font-size: 11px; color: var(--al-text-muted); margin-top: 5px; }

/* ── Card ── */
.al-card {
    background: var(--al-bg-card);
    border: 1px solid var(--al-border);
    border-radius: 12px;
    overflow: visible;
}
.al-card-header {
    padding: 16px 22px;
    border-bottom: 1px solid var(--al-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.al-card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--al-text-primary);
    font-size: 15px;
    font-weight: 600;
}
.al-card-title i { color: #F28C28; }
.al-card-meta    { font-size: 12px; color: var(--al-text-muted); }

/* ── Filter bar ── */
.al-filters {
    padding: 14px 22px;
    border-bottom: 1px solid var(--al-border);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: var(--al-bg-card);
}
.al-search-wrap { flex: 1; min-width: 200px; position: relative; }
.al-search-wrap i {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--al-text-muted); font-size: 14px; pointer-events: none;
}
.al-search-input {
    width: 100%;
    background: var(--al-bg-input);
    border: 1px solid var(--al-border);
    border-radius: 8px;
    padding: 8px 12px 8px 34px;
    color: var(--al-text-primary);
    font-size: 13px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    outline: none;
    transition: border-color .15s;
    box-sizing: border-box;
}
.al-search-input:focus        { border-color: #F28C28; }
.al-search-input::placeholder { color: var(--al-text-muted); }

.al-filter-btn {
    background: var(--al-bg-input);
    border: 1px solid var(--al-border);
    border-radius: 8px;
    padding: 8px 14px;
    color: var(--al-text-secondary);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    transition: all .15s;
    white-space: nowrap;
}
.al-filter-btn:hover  { border-color: #F28C28; color: #F28C28; }
.al-filter-btn.active { border-color: #F28C28; color: #F28C28; background: rgba(242,140,40,0.08); }

.al-dot        { width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.dot-green     { background: #10b981; }
.dot-orange    { background: #F28C28; }
.dot-blue      { background: #3b82f6; }
.dot-red       { background: #ef4444; }

/* ── DataTables overrides ── */
#activityTable_wrapper .dataTables_filter,
#activityTable_wrapper .dataTables_length { display: none !important; }

div.dataTables_info {
    font-size: 12px !important;
    color: var(--al-text-muted) !important;
    padding: 14px 22px !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    background: transparent !important;
    border: none !important;
}
div.dataTables_paginate {
    padding: 10px 22px 14px !important;
    text-align: right !important;
    background: transparent !important;
    border: none !important;
}

.al-dt-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--al-border);
    flex-wrap: wrap;
    background: var(--al-bg-card);
}

/* ── Table ── */
table#activityTable {
    width: 100% !important;
    border-collapse: collapse;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
table#activityTable thead th {
    background: var(--al-bg-thead) !important;
    color: var(--al-text-muted) !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    letter-spacing: .06em !important;
    padding: 10px 12px !important;
    border-bottom: 1px solid var(--al-border) !important;
    border-top: none !important;
    white-space: nowrap;
}
table#activityTable thead th:first-child { padding-left: 22px !important; }
table#activityTable thead th:last-child  { padding-right: 22px !important; }
table#activityTable tbody tr             { border-top: 1px solid var(--al-border-light); transition: background .1s; }
table#activityTable tbody tr:hover td   { background: var(--al-bg-hover) !important; }
table#activityTable tbody td {
    padding: 12px 12px !important;
    vertical-align: middle !important;
    border: none !important;
    background: var(--al-bg-card) !important;
}
table#activityTable tbody td:first-child { padding-left: 22px !important; }
table#activityTable tbody td:last-child  { padding-right: 22px !important; }

/* ── Cell styles ── */
.al-row-num   { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--al-text-faint); }
.al-user-cell { display: flex; align-items: center; gap: 9px; }
.al-avatar {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 600; flex-shrink: 0;
}
.av-0 { background: rgba(59,130,246,0.12);  color: #2563eb; }
.av-1 { background: rgba(16,185,129,0.12);  color: #059669; }
.av-2 { background: rgba(242,140,40,0.12);  color: #d97706; }
.av-3 { background: rgba(139,92,246,0.12);  color: #7c3aed; }
.av-4 { background: rgba(239,68,68,0.12);   color: #dc2626; }
.av-5 { background: rgba(6,182,212,0.12);   color: #0891b2; }

.al-user-name { font-size: 13px; color: var(--al-text-primary); font-weight: 500; line-height: 1.2; }
.al-user-un   { font-size: 11px; color: var(--al-text-muted); }

.al-action-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 5px;
    font-size: 11px; font-weight: 600; letter-spacing: .04em; white-space: nowrap;
}
.ab-login   { background: rgba(16,185,129,0.1);  color: #059669; border: 1px solid rgba(16,185,129,0.25); }
.ab-logout  { background: rgba(242,140,40,0.1);  color: #d97706; border: 1px solid rgba(242,140,40,0.25); }
.ab-create  { background: rgba(59,130,246,0.1);  color: #2563eb; border: 1px solid rgba(59,130,246,0.25); }
.ab-update  { background: rgba(139,92,246,0.1);  color: #7c3aed; border: 1px solid rgba(139,92,246,0.25); }
.ab-delete  { background: rgba(239,68,68,0.1);   color: #dc2626; border: 1px solid rgba(239,68,68,0.25);  }
.ab-default { background: rgba(107,114,128,0.1); color: #6b7280; border: 1px solid rgba(107,114,128,0.25);}

.al-type-badge {
    font-size: 11px; font-weight: 500;
    color: var(--al-text-secondary);
    background: var(--al-bg-sub);
    border: 1px solid var(--al-border);
    border-radius: 4px; padding: 2px 8px;
    text-transform: uppercase;
}
.al-desc-text { color: var(--al-text-secondary); font-size: 13px; }
.al-ip-text   { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--al-text-muted); }
.al-ts-text   { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--al-text-secondary); white-space: nowrap; }

@media (max-width: 768px) { .al-stats { grid-template-columns: repeat(2,1fr); } }
.al-hidden-col { display: none !important; }
</style>

<div class="al-wrap">

  <div class="al-stats">
    <div class="al-stat">
      <div class="al-stat-label">Total Entries</div>
      <div class="al-stat-val blue"><?= number_format($totalCount) ?></div>
      <div class="al-stat-sub">All time</div>
    </div>
    <div class="al-stat">
      <div class="al-stat-label">Logins Today</div>
      <div class="al-stat-val green"><?= $todayStats['LOGIN'] ?></div>
      <div class="al-stat-sub"><?= date('d M Y') ?></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-label">Logouts Today</div>
      <div class="al-stat-val amber"><?= $todayStats['LOGOUT'] ?></div>
      <div class="al-stat-sub"><?= date('d M Y') ?></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-label">Active Users</div>
      <div class="al-stat-val gray"><?= $activeUsers ?></div>
      <div class="al-stat-sub">Logged in today</div>
    </div>
  </div>

  <div class="al-card">
    <div class="al-card-header">
      <div class="al-card-title">
        <i class="bi bi-clock-history"></i>
        System Activity Log
      </div>
      <div class="al-card-meta">Last 200 entries</div>
    </div>

    <div class="al-filters">
      <div class="al-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" class="al-search-input" id="alSearch" placeholder="Search user, action, or IP…">
      </div>
      <button class="al-filter-btn active" data-filter="">All</button>
      <button class="al-filter-btn" data-filter="LOGIN"><span class="al-dot dot-green"></span>Login</button>
      <button class="al-filter-btn" data-filter="LOGOUT"><span class="al-dot dot-orange"></span>Logout</button>
      <button class="al-filter-btn" data-filter="CREATE"><span class="al-dot dot-blue"></span>Create</button>
      <button class="al-filter-btn" data-filter="DELETE"><span class="al-dot dot-red"></span>Delete</button>
    </div>

    <table id="activityTable" class="display">
      <thead>
        <tr>
          <th>#</th>
          <th>User</th>
          <th>Action</th>
          <th>Type</th>
          <th>Description</th>
          <th>IP Address</th>
          <th>Timestamp</th>
          <th>ActionRaw</th><!-- hidden filter column -->
        </tr>
      </thead>
      <tbody>
      <?php
        $avColors    = ['av-0','av-1','av-2','av-3','av-4','av-5'];
        $colorMap    = [];
        $colorIdx    = 0;
        $i           = 1;
        $actionBadge = [
          'LOGIN'          => ['class'=>'ab-login',  'dot'=>'dot-green'],
          'LOGOUT'         => ['class'=>'ab-logout', 'dot'=>'dot-orange'],
          'CREATE'         => ['class'=>'ab-create', 'dot'=>'dot-blue'],
          'UPDATE'         => ['class'=>'ab-update', 'dot'=>'dot-blue'],
          'DELETE'         => ['class'=>'ab-delete', 'dot'=>'dot-red'],
          'FLAGGED_EWASTE' => ['class'=>'ab-delete', 'dot'=>'dot-red'],
          'USER_TOGGLE'    => ['class'=>'ab-update', 'dot'=>'dot-blue'],
        ];

        while ($log = $logs->fetch_assoc()):
          $uid      = $log['user_id'] ?? 0;
          $name     = strtoupper($log['full_name'] ?? 'Unknown');
          $uname    = $log['username'] ?? '';
          $action   = strtoupper($log['action'] ?? '');
          $itemType = $log['item_type'] ?? '';
          $desc     = $log['description'] ?? '';
          $ip       = $log['ip_address'] ?? '::1';
          $ts       = date('d/m/Y H:i:s', strtotime($log['created_at']));

          if (!isset($colorMap[$uid])) {
            $colorMap[$uid] = $avColors[$colorIdx % count($avColors)];
            $colorIdx++;
          }
          $avClass  = $colorMap[$uid];
          $words    = explode(' ', $name);
          $initials = implode('', array_map(fn($w) => $w[0] ?? '', array_slice($words, 0, 2)));
          $badge    = $actionBadge[$action] ?? ['class'=>'ab-default','dot'=>'dot-blue'];
      ?>
        <tr data-action="<?= h($action) ?>">
          <td><span class="al-row-num"><?= $i++ ?></span></td>
          <td>
            <div class="al-user-cell">
              <div class="al-avatar <?= $avClass ?>"><?= h($initials) ?></div>
              <div>
                <div class="al-user-name"><?= h($name) ?></div>
                <div class="al-user-un"><?= h($uname) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="al-action-badge <?= $badge['class'] ?>">
              <span class="al-dot <?= $badge['dot'] ?>"></span>
              <?= h($action) ?>
            </span>
          </td>
          <td><?= $itemType ? '<span class="al-type-badge">'.h($itemType).'</span>' : '<span style="color:var(--al-text-faint);font-size:12px;">—</span>' ?></td>
          <td><span class="al-desc-text"><?= h($desc) ?></span></td>
          <td><span class="al-ip-text"><?= h($ip) ?></span></td>
          <td><span class="al-ts-text"><?= $ts ?></span></td>
          <td><?= h($action) ?></td><!-- hidden filter column -->
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>

    <div class="al-dt-footer"></div>
  </div>

</div>

<script>
// Registered here (before jQuery loads), executed by layout_end.php after jQuery + DataTables are ready
window._pageInit = function () {
  window._actDt = $('#activityTable').DataTable({
    pageLength: 15,
    lengthChange: false,
    dom: 'tip',
    order: [],
    language: {
      info: 'Showing _START_–_END_ of _TOTAL_ entries',
      paginate: { previous: '← Previous', next: 'Next →' }
    },
    columnDefs: [
      { orderable: false, targets: [0, 4, 5] },
      { visible: false, targets: 7 }
    ]
  });

  $('#activityTable_info').appendTo('.al-dt-footer');
  $('#activityTable_paginate').appendTo('.al-dt-footer');

  // Global text search
  $('#alSearch').on('keyup', function () {
    window._actDt.search(this.value).draw();
  });

  // Filter buttons
  $('.al-filter-btn').on('click', function () {
    $('.al-filter-btn').removeClass('active');
    $(this).addClass('active');
    var val = $(this).data('filter');
    window._actDt.column(7).search(val ? '^' + val + '$' : '', true, false).draw();
  });

  // Dark mode sync
  function syncDark() {
    var target = document.querySelector('.al-wrap');
    if (!target) return;
    var b = document.body, h = document.documentElement;
    var isDark = b.classList.contains('dark-mode') || b.classList.contains('dark') ||
                 b.getAttribute('data-theme') === 'dark' ||
                 h.classList.contains('dark-mode') || h.classList.contains('dark') ||
                 h.getAttribute('data-theme') === 'dark' || h.getAttribute('data-bs-theme') === 'dark';
    target.classList.toggle('al-dark', isDark);
  }
  syncDark();
  var _obs = new MutationObserver(syncDark);
  _obs.observe(document.body, { attributes: true, attributeFilter: ['class','data-theme','data-bs-theme'] });
  _obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class','data-theme','data-bs-theme'] });
};
</script>

<?php require_once 'includes/layout_end.php'; ?>
