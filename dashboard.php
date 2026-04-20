<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();

// Stats
$total_assets   = $db->query("SELECT COUNT(*) as c FROM inventory_items WHERE item_status != 'Disposed'")->fetch_assoc()['c'];
$active_assets  = $db->query("SELECT COUNT(*) as c FROM inventory_items WHERE item_status = 'Active'")->fetch_assoc()['c'];
$in_repair      = $db->query("SELECT COUNT(*) as c FROM inventory_items WHERE item_status = 'In Repair'")->fetch_assoc()['c'];
$ewaste_pending = $db->query("SELECT COUNT(*) as c FROM ewaste_items WHERE disposal_status = 'Pending'")->fetch_assoc()['c'];
$ewaste_total   = $db->query("SELECT COUNT(*) as c FROM ewaste_items WHERE disposal_status = 'Approved'")->fetch_assoc()['c'];
$total_users    = $db->query("SELECT COUNT(*) as c FROM users WHERE is_active = 1")->fetch_assoc()['c'];

// Class breakdown
$class_data = $db->query("SELECT asset_class, COUNT(*) as total FROM inventory_items WHERE item_status != 'Disposed' GROUP BY asset_class ORDER BY total DESC");
$classes = []; $class_labels = []; $class_values = [];
while ($r = $class_data->fetch_assoc()) {
    $classes[] = $r;
    $class_labels[] = $r['asset_class'];
    $class_values[] = $r['total'];
}

// E-waste by status
$ew_status = $db->query("SELECT disposal_status, COUNT(*) as c FROM ewaste_items GROUP BY disposal_status");
$ew_status_data = [];
while ($r = $ew_status->fetch_assoc()) $ew_status_data[$r['disposal_status']] = $r['c'];

// Recent inventory
$recent = $db->query("SELECT * FROM inventory_items ORDER BY created_at DESC LIMIT 8");

// Recent activity (admin only)
$activity = $db->query("SELECT al.*, u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 10");

// User's own write-off submissions
$uid = (int)$_SESSION['user_id'];
$my_writeoffs = $db->query("SELECT ew.*, inv.description as inv_desc FROM ewaste_items ew LEFT JOIN inventory_items inv ON ew.original_inventory_id=inv.id WHERE ew.created_by=$uid ORDER BY ew.created_at DESC LIMIT 6");

// User's own assets
$my_assets_count   = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE created_by=$uid AND item_status='Active'")->fetch_assoc()['c'];
$my_ewaste_pending = $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE created_by=$uid AND disposal_status='Pending'")->fetch_assoc()['c'];
$my_ewaste_approved= $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE created_by=$uid AND disposal_status='Approved'")->fetch_assoc()['c'];

$page_title = 'Dashboard'; $active_nav = 'dashboard';
require_once 'includes/layout.php';
?>

<style>
.dash-stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px 22px;display:flex;align-items:center;gap:16px;text-decoration:none;transition:box-shadow .2s,transform .2s;border-left:4px solid transparent}
.dash-stat-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.1);transform:translateY(-2px)}
.dash-stat-icon{width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.dash-stat-val{font-size:28px;font-weight:800;color:var(--text);line-height:1;font-family:'Plus Jakarta Sans',sans-serif}
.dash-stat-lbl{font-size:12px;color:var(--muted);margin-top:3px;font-weight:500}
.quick-action{display:flex;align-items:center;gap:11px;padding:11px 14px;background:var(--body-bg);border:1.5px solid var(--border);border-radius:9px;text-decoration:none;transition:border-color .15s,background .15s}
.quick-action:hover{border-color:var(--accent);background:rgba(242,140,40,.04)}
.quick-action-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.quick-action-title{font-size:13px;font-weight:600;color:var(--text)}
.quick-action-sub{font-size:11px;color:var(--muted);margin-top:1px}
.activity-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0;margin-top:5px}
</style>

<!-- ── STAT CARDS ── -->
<div class="row g-3 mb-4">
  <?php
  $cards = isAdmin() ? [
    ['inventory.php','#2563EB','rgba(37,99,235,.12)','bi-box-seam-fill',$total_assets,  'Total IT Assets'],
    ['inventory.php','#16a34a','rgba(22,163,74,.12)', 'bi-check-circle-fill',$active_assets,'Active Assets'],
    ['ewaste.php',   '#F28C28','rgba(242,140,40,.12)','bi-recycle',       $ewaste_total, 'In E-Waste Queue'],
    ['ewaste.php',   '#d97706','rgba(245,158,11,.12)','bi-hourglass-split',$ewaste_pending,'Pending Approval'],
  ] : [
    ['inventory.php','#2563EB','rgba(37,99,235,.12)','bi-box-seam-fill',$my_assets_count,  'My Active Assets'],
    ['writeoff.php', '#d97706','rgba(245,158,11,.12)','bi-hourglass-split',$my_ewaste_pending,'My Pending Write-Offs'],
    ['writeoff.php', '#16a34a','rgba(22,163,74,.12)', 'bi-check-circle-fill',$my_ewaste_approved,'My Approved Write-Offs'],
    ['inventory.php','#F28C28','rgba(242,140,40,.12)','bi-box-seam-fill',$total_assets,  'Total IT Assets'],
  ];
  foreach ($cards as [$href,$color,$bg,$icon,$val,$lbl]):
  ?>
  <div class="col-6 col-xl-3">
    <a href="<?= $href ?>" class="dash-stat-card" style="border-left-color:<?= $color ?>">
      <div class="dash-stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
        <i class="bi <?= $icon ?>"></i>
      </div>
      <div>
        <div class="dash-stat-val"><?= $val ?></div>
        <div class="dash-stat-lbl"><?= $lbl ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── CHARTS ── -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;height:100%">
      <!-- Industrial header bar -->
      <div style="background:#1a2332;padding:14px 22px;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:3px;height:20px;background:#F28C28;border-radius:2px"></div>
          <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.4)">Inventory Analysis</div>
            <div style="font-size:14px;font-weight:700;color:#fff;margin-top:1px">Asset Class Distribution</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;background:rgba(242,140,40,.15);border:1px solid rgba(242,140,40,.3);border-radius:5px;padding:5px 12px">
          <div style="width:6px;height:6px;border-radius:50%;background:#F28C28"></div>
          <span style="font-size:11px;font-weight:700;color:#F28C28;text-transform:uppercase;letter-spacing:.06em"><?= array_sum($class_values) ?> Units</span>
        </div>
      </div>
      <!-- Chart area -->
      <div style="padding:20px 22px 18px;background:var(--surface)">
        <canvas id="classChart" height="200"></canvas>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;height:100%;display:flex;flex-direction:column">
      <!-- Industrial header -->
      <div style="background:#1a2332;padding:14px 22px;display:flex;align-items:center;gap:10px">
        <div style="width:3px;height:20px;background:#16a34a;border-radius:2px"></div>
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.4)">E-Waste Monitor</div>
          <div style="font-size:14px;font-weight:700;color:#fff;margin-top:1px">Disposal Pipeline</div>
        </div>
      </div>
      <!-- Doughnut -->
      <div style="flex:1;padding:20px 22px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px">
        <div style="position:relative;width:260px;height:260px">
          <canvas id="ewChart"></canvas>
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none">
            <div style="font-size:28px;font-weight:800;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;line-height:1"><?= array_sum(array_values($ew_status_data)) ?></div>
            <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Total</div>
          </div>
        </div>
        <!-- Status rows — industrial style -->
        <div style="width:100%;display:flex;flex-direction:column;gap:6px">
          <?php
          $ew_colors = ['Pending'=>['#E07818','rgba(224,120,24,.08)','#f59e0b'],'Approved'=>['#2563EB','rgba(37,99,235,.08)','#60a5fa'],'Collected'=>['#16A34A','rgba(22,163,74,.08)','#4ade80']];
          $ew_total_sum = array_sum(array_values($ew_status_data));
          foreach ($ew_status_data as $status => $count):
            [$clr,$bg,$light] = $ew_colors[$status] ?? ['#94a3b8','rgba(148,163,184,.08)','#cbd5e1'];
            $pct = $ew_total_sum > 0 ? round($count/$ew_total_sum*100) : 0;
          ?>
          <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:7px;padding:10px 14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:7px">
              <div style="display:flex;align-items:center;gap:7px">
                <div style="width:8px;height:8px;border-radius:2px;background:<?= $clr ?>;flex-shrink:0"></div>
                <span style="font-size:12px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.05em"><?= $status ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:800;color:<?= $clr ?>"><?= $count ?></span>
                <span style="font-size:10px;font-weight:600;color:var(--muted)"><?= $pct ?>%</span>
              </div>
            </div>
            <!-- Progress bar -->
            <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;background:<?= $clr ?>;border-radius:2px;transition:width .8s ease"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($ew_status_data)): ?>
          <div style="text-align:center;padding:16px;color:var(--muted);font-size:13px">No e-waste data yet</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── BOTTOM ROW ── -->
<div class="row g-3">
  <!-- Recent Assets -->
  <div class="col-lg-7">
    <div class="table-card">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">Recently Added Assets</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Latest entries in the inventory</div>
        </div>
        <a href="inventory.php" class="btn-secondary-custom" style="font-size:12px;padding:6px 14px">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr>
            <th>Asset #</th><th>Class</th><th>Description</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php while ($row = $recent->fetch_assoc()): ?>
          <tr>
            <td><a href="inventory.php?action=edit&id=<?= $row['id'] ?>" style="color:var(--accent);font-size:12px;font-weight:700;text-decoration:none"><?= h($row['asset_number'] ?: '—') ?></a></td>
            <td><span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700"><?= h($row['asset_class']) ?></span></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px"><?= h($row['description']) ?></td>
            <td>
              <?php $sc=['Active'=>'bs-active','In Repair'=>'bs-repair','Disposed'=>'bs-disposed','Reserved'=>'bs-pending'];
              echo '<span class="badge-status '.($sc[$row['item_status']]??'').'">'.$row['item_status'].'</span>'; ?>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if (isAdmin()): ?>
    <!-- Admin: Recent Activity -->
    <div class="table-card">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">Recent Activity</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Latest system actions</div>
        </div>
        <a href="activity.php" class="btn-secondary-custom" style="font-size:12px;padding:6px 14px">View All</a>
      </div>
      <div style="padding:4px 0">
        <?php while ($log = $activity->fetch_assoc()): ?>
        <div style="display:flex;gap:12px;padding:11px 20px;border-bottom:1px solid var(--border)">
          <div class="activity-dot"></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($log['description'] ?? $log['action']) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= h($log['full_name'] ?? 'System') ?> &bull; <?= date('d M, H:i', strtotime($log['created_at'])) ?></div>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- User: Write-Off Status -->
    <div class="table-card mb-3">
      <div class="table-card-header">
        <div class="table-card-title">My Write-Off Status</div>
        <a href="writeoff.php" style="font-size:12px;font-weight:600;color:var(--accent);text-decoration:none">View All</a>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--border)">
        <?php foreach([['#d97706',$my_ewaste_pending,'Pending'],['#2563eb',$my_ewaste_approved,'Approved'],['#16a34a',$my_assets_count,'My Assets']] as [$clr,$val,$lbl]): ?>
        <div style="padding:14px 16px;text-align:center;border-right:1px solid var(--border)">
          <div style="font-size:22px;font-weight:800;color:<?= $clr ?>;font-family:'Plus Jakarta Sans',sans-serif"><?= $val ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="padding:4px 0">
        <?php $has_wo=false; while($wo=$my_writeoffs->fetch_assoc()):$has_wo=true;
          $sc=['Pending'=>['#d97706','bi-hourglass-split'],'Approved'=>['#2563eb','bi-check-circle-fill'],'Disposed'=>['#dc2626','bi-trash-fill']];
          [$clr,$ico]=$sc[$wo['disposal_status']]??['#94a3b8','bi-circle']; ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border)">
          <i class="bi <?= $ico ?>" style="color:<?= $clr ?>;font-size:15px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($wo['description']?:($wo['inv_desc']?:'—')) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= h($wo['asset_number']?:'No asset no.') ?> &bull; <?= $wo['date_flagged']?date('d M Y',strtotime($wo['date_flagged'])):'' ?></div>
          </div>
          <span style="font-size:11px;font-weight:700;color:<?= $clr ?>;white-space:nowrap"><?= $wo['disposal_status'] ?></span>
        </div>
        <?php endwhile; ?>
        <?php if (!$has_wo): ?>
        <div style="padding:28px 16px;text-align:center">
          <i class="bi bi-pen" style="font-size:28px;color:var(--muted);display:block;margin-bottom:8px"></i>
          <div style="font-size:13px;color:var(--muted)">No write-offs submitted yet</div>
          <a href="inventory.php" style="font-size:12px;color:var(--accent);font-weight:600;text-decoration:none;margin-top:6px;display:inline-block">Browse IT Assets →</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="table-card">
      <div class="table-card-header"><div class="table-card-title">Quick Actions</div></div>
      <div style="padding:12px 16px;display:flex;flex-direction:column;gap:8px">
        <?php foreach([
          ['inventory.php?action=add','bi-plus-circle-fill','rgba(242,140,40,.1)','var(--accent)','Add New Asset','Register a new IT equipment'],
          ['writeoff.php','bi-pen-fill','rgba(245,158,11,.1)','#d97706','Submit Write-Off','Flag an asset for E-Waste disposal'],
          ['profile.php','bi-person-circle','rgba(37,99,235,.1)','#2563eb','My Profile','Update your details & password'],
        ] as [$href,$icon,$bg,$clr,$title,$sub]): ?>
        <a href="<?= $href ?>" class="quick-action">
          <div class="quick-action-icon" style="background:<?= $bg ?>"><i class="bi <?= $icon ?>" style="color:<?= $clr ?>"></i></div>
          <div>
            <div class="quick-action-title"><?= $title ?></div>
            <div class="quick-action-sub"><?= $sub ?></div>
          </div>
          <i class="bi bi-chevron-right" style="color:var(--muted);font-size:12px;margin-left:auto"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size   = 12;

function isDarkNow() { return localStorage.getItem('fjb-theme') === 'dark'; }
function gridColor()  { return isDarkNow() ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.07)'; }
function labelColor() { return isDarkNow() ? '#6b7280' : '#94a3b8'; }

// ── BAR CHART ──
const ctxBar = document.getElementById('classChart').getContext('2d');

const barChart = new Chart(ctxBar, {
  type: 'bar',
  data: {
    labels: <?= json_encode($class_labels) ?>,
    datasets: [{
      label: 'Assets',
      data: <?= json_encode($class_values) ?>,
      backgroundColor: 'rgba(242,140,40,0.85)',
      borderWidth: 0,
      borderRadius: { topLeft: 4, topRight: 4 },
      borderSkipped: false
    }]
  },
  options: {
    responsive: true,
    animation: { duration: 700, easing: 'easeOutQuart' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: isDarkNow() ? '#0f172a' : '#1a2332',
        titleColor: '#F28C28',
        bodyColor: '#fff',
        borderColor: '#F28C28',
        borderWidth: 1, padding: 12, cornerRadius: 4,
        callbacks: { label: ctx => ` ${ctx.parsed.y} asset${ctx.parsed.y !== 1 ? 's' : ''}` }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: { color: labelColor(), stepSize: 2, padding: 8, font: { size: 11 } },
        grid: { color: gridColor(), drawBorder: false, lineWidth: 1 },
        border: { display: false, dash: [4, 4] }
      },
      x: {
        ticks: { color: labelColor(), padding: 8, maxRotation: 30, font: { size: 11 } },
        grid: { display: false },
        border: { display: false }
      }
    }
  }
});

// ── DOUGHNUT CHART ──
const ewData = <?= json_encode($ew_status_data) ?>;
const ewColorMap = { Pending:'#E07818', Approved:'#2563EB', Collected:'#16A34A' };

const ewChartInstance = new Chart(document.getElementById('ewChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: Object.keys(ewData),
    datasets: [{
      data: Object.values(ewData),
      backgroundColor: Object.keys(ewData).map(k => ewColorMap[k] || '#94a3b8'),
      borderWidth: 2,
      borderColor: 'transparent',
      hoverOffset: 6
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false, cutout: '72%',
    animation: { duration: 900, easing: 'easeOutQuart' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: isDarkNow() ? '#1f2937' : '#fff',
        titleColor: isDarkNow() ? '#d1d5db' : '#1e293b',
        bodyColor: isDarkNow() ? '#9ca3af' : '#64748b',
        borderColor: isDarkNow() ? '#374151' : '#e2e8f0',
        borderWidth: 1, padding: 12, cornerRadius: 8
      }
    }
  }
});

// ── THEME TOGGLE — update both charts live ──
const _origToggle = window.toggleTheme;
window.toggleTheme = function() {
  _origToggle();
  const dark = isDarkNow();

  // Update bar chart
  barChart.options.scales.y.ticks.color     = labelColor();
  barChart.options.scales.x.ticks.color     = labelColor();
  barChart.options.scales.y.grid.color      = gridColor();
  barChart.options.plugins.tooltip.backgroundColor = dark ? '#0f172a' : '#1a2332';
  barChart.update();

  // Update doughnut chart
  ewChartInstance.data.datasets[0].borderColor = 'transparent';
  ewChartInstance.options.plugins.tooltip.backgroundColor = dark ? '#1f2937' : '#fff';
  ewChartInstance.options.plugins.tooltip.titleColor      = dark ? '#d1d5db' : '#1e293b';
  ewChartInstance.options.plugins.tooltip.bodyColor       = dark ? '#9ca3af' : '#64748b';
  ewChartInstance.options.plugins.tooltip.borderColor     = dark ? '#374151' : '#e2e8f0';
  ewChartInstance.update();
};
</script>

<?php require_once 'includes/layout_end.php'; ?>
