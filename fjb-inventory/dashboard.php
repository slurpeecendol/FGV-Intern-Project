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
$ewaste_total   = $db->query("SELECT COUNT(*) as c FROM ewaste_items")->fetch_assoc()['c'];
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

// User's own assets (added by this user)
$my_assets_count = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE created_by=$uid AND item_status='Active'")->fetch_assoc()['c'];
$my_ewaste_pending = $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE created_by=$uid AND disposal_status='Pending'")->fetch_assoc()['c'];
$my_ewaste_approved = $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE created_by=$uid AND disposal_status='Approved'")->fetch_assoc()['c'];

$page_title = 'Dashboard'; $active_nav = 'dashboard';
require_once 'includes/layout.php';
?>

<!-- STATS ROW -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <a href="inventory.php" style="text-decoration:none">
    <div class="stat-card" style="border-left-color:#2563EB;cursor:pointer;transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
      <div class="stat-icon" style="background:rgba(37,99,235,.12);color:#2563EB"><i class="bi bi-box-seam-fill"></i></div>
      <div class="stat-value"><?= $total_assets ?></div>
      <div class="stat-label">Total IT Assets</div>
    </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="inventory.php" style="text-decoration:none">
    <div class="stat-card" style="border-left-color:#16a34a;cursor:pointer;transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
      <div class="stat-icon" style="background:rgba(22,163,74,.12);color:#16a34a"><i class="bi bi-check-circle-fill"></i></div>
      <div class="stat-value"><?= $active_assets ?></div>
      <div class="stat-label">Active Assets</div>
    </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="ewaste.php" style="text-decoration:none">
    <div class="stat-card" style="border-left-color:#E07818;cursor:pointer;transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
      <div class="stat-icon" style="background:rgba(224,120,24,.12);color:#E07818"><i class="bi bi-recycle"></i></div>
      <div class="stat-value"><?= $ewaste_total ?></div>
      <div class="stat-label">E-Waste Items</div>
    </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="ewaste.php" style="text-decoration:none">
    <div class="stat-card" style="border-left-color:#fb923c;cursor:pointer;transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
      <div class="stat-icon" style="background:rgba(251,146,60,.12);color:#fb923c"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div class="stat-value"><?= $ewaste_pending ?></div>
      <div class="stat-label">Pending Disposal</div>
    </div>
    </a>
  </div>
</div>

<!-- CHARTS + BREAKDOWN -->
<div class="row g-3 mb-4">
  <!-- Bar chart -->
  <div class="col-lg-8">
    <div class="table-card h-100">
      <div class="table-card-header" style="padding:18px 24px">
        <div>
          <div class="table-card-title" style="font-size:15px">Asset Class Distribution</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Inventory breakdown by category</div>
        </div>
        <span style="font-size:12px;font-weight:600;color:var(--accent);background:rgba(242,140,40,.1);padding:4px 12px;border-radius:20px">
          <?= array_sum($class_values) ?> total
        </span>
      </div>
      <div style="padding:8px 24px 24px">
        <canvas id="classChart" height="210"></canvas>
      </div>
    </div>
  </div>

  <!-- Donut chart -->
  <div class="col-lg-4">
    <div class="table-card h-100" style="display:flex;flex-direction:column">
      <div class="table-card-header" style="padding:18px 24px">
        <div>
          <div class="table-card-title" style="font-size:15px">E-Waste Status</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Current disposal pipeline</div>
        </div>
      </div>
      <div style="flex:1;padding:16px 24px 24px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px">
        <div style="position:relative;width:260px;height:260px">
          <canvas id="ewChart"></canvas>
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none">
            <div style="font-size:26px;font-weight:800;color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;line-height:1"><?= array_sum(array_values($ew_status_data)) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">Total</div>
          </div>
        </div>
        <!-- Custom legend -->
        <div style="width:100%;display:flex;flex-direction:column;gap:8px">
          <?php
          $ew_colors = ['Pending'=>['#E07818','rgba(224,120,24,.12)'],'Approved'=>['#2563EB','rgba(37,99,235,.12)'],'Collected'=>['#16A34A','rgba(22,163,74,.12)']];
          foreach ($ew_status_data as $status => $count):
            [$clr,$bg] = $ew_colors[$status] ?? ['#94a3b8','rgba(148,163,184,.12)'];
            $pct = array_sum(array_values($ew_status_data)) > 0 ? round($count / array_sum(array_values($ew_status_data)) * 100) : 0;
          ?>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:<?= $bg ?>;border-radius:8px">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $clr ?>;flex-shrink:0"></div>
            <span style="flex:1;font-size:13px;font-weight:500;color:var(--text)"><?= $status ?></span>
            <span style="font-size:13px;font-weight:700;color:<?= $clr ?>"><?= $count ?></span>
            <span style="font-size:11px;color:var(--muted)"><?= $pct ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- RECENT ASSETS + USER PANEL -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="table-card">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-clock me-2" style="color:var(--green)"></i>Recently Added Assets</div>
        <a href="inventory.php" class="btn-secondary-custom" style="font-size:12px;padding:6px 14px;">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr>
            <th>Asset #</th><th>Class</th><th>Description</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php while ($row = $recent->fetch_assoc()): ?>
          <tr>
            <td><a href="inventory.php?action=edit&id=<?= $row['id'] ?>" style="color:var(--accent);font-size:12px;font-weight:600;text-decoration:none"><?= h($row['asset_number'] ?: '—') ?></a></td>
            <td><span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:700"><?= h($row['asset_class']) ?></span></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px"><?= h($row['description']) ?></td>
            <td>
              <?php
              $sc = ['Active'=>'bs-active','In Repair'=>'bs-repair','Disposed'=>'bs-disposed','Reserved'=>'bs-pending'];
              echo '<span class="badge-status '.($sc[$row['item_status']] ?? '').'">'.$row['item_status'].'</span>';
              ?>
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
        <div class="table-card-title"><i class="bi bi-activity me-2" style="color:var(--green)"></i>Recent Activity</div>
      </div>
      <div style="padding:12px 20px">
        <?php while ($log = $activity->fetch_assoc()): ?>
        <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)">
          <div style="width:8px;height:8px;border-radius:50%;background:var(--green);margin-top:5px;flex-shrink:0"></div>
          <div>
            <div style="font-size:13px;color:var(--text)"><?= h($log['description'] ?? $log['action']) ?></div>
            <div style="font-size:11px;color:var(--muted)"><?= h($log['full_name'] ?? 'System') ?> &bull; <?= date('d M, H:i', strtotime($log['created_at'])) ?></div>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- User: My Write-Off Submissions -->
    <div class="table-card mb-3">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-pen-fill me-2" style="color:var(--accent)"></i>My Write-Off Status</div>
        <a href="writeoff.php" style="font-size:12px;font-weight:600;color:var(--accent);text-decoration:none">View All</a>
      </div>
      <!-- Mini stats -->
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--border)">
        <div style="padding:14px 16px;text-align:center;border-right:1px solid var(--border)">
          <div style="font-size:22px;font-weight:800;color:#d97706;font-family:'Plus Jakarta Sans',sans-serif"><?= $my_ewaste_pending ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Pending</div>
        </div>
        <div style="padding:14px 16px;text-align:center;border-right:1px solid var(--border)">
          <div style="font-size:22px;font-weight:800;color:#2563eb;font-family:'Plus Jakarta Sans',sans-serif"><?= $my_ewaste_approved ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Approved</div>
        </div>
        <div style="padding:14px 16px;text-align:center">
          <div style="font-size:22px;font-weight:800;color:#16a34a;font-family:'Plus Jakarta Sans',sans-serif"><?= $my_assets_count ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">My Assets</div>
        </div>
      </div>
      <!-- Recent submissions list -->
      <div style="padding:8px 0">
        <?php
        $has_wo = false;
        while ($wo = $my_writeoffs->fetch_assoc()):
          $has_wo = true;
          $sc = ['Pending'=>['#d97706','bi-hourglass-split'],'Approved'=>['#2563eb','bi-check-circle-fill'],'Disposed'=>['#dc2626','bi-trash-fill']];
          [$clr,$ico] = $sc[$wo['disposal_status']] ?? ['#94a3b8','bi-circle'];
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border)">
          <i class="bi <?= $ico ?>" style="color:<?= $clr ?>;font-size:15px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= h($wo['description'] ?: ($wo['inv_desc'] ?: '—')) ?>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:1px">
              <?= h($wo['asset_number'] ?: 'No asset no.') ?> &bull; <?= $wo['date_flagged'] ? date('d M Y', strtotime($wo['date_flagged'])) : '' ?>
            </div>
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

    <!-- Quick Actions for User -->
    <div class="table-card">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
        <div style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:14px;color:var(--text)">Quick Actions</div>
      </div>
      <div style="padding:12px 16px;display:flex;flex-direction:column;gap:8px">
        <a href="inventory.php?action=add" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--body-bg);border:1.5px solid var(--border);border-radius:8px;text-decoration:none;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
          <i class="bi bi-plus-circle-fill" style="color:var(--accent);font-size:18px"></i>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text)">Add New Asset</div>
            <div style="font-size:11px;color:var(--muted)">Register a new IT equipment</div>
          </div>
        </a>
        <a href="writeoff.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--body-bg);border:1.5px solid var(--border);border-radius:8px;text-decoration:none;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
          <i class="bi bi-pen-fill" style="color:#d97706;font-size:18px"></i>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text)">Submit Write-Off</div>
            <div style="font-size:11px;color:var(--muted)">Flag an asset for E-Waste disposal</div>
          </div>
        </a>
        <a href="profile.php" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--body-bg);border:1.5px solid var(--border);border-radius:8px;text-decoration:none;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
          <i class="bi bi-person-circle" style="color:#2563eb;font-size:18px"></i>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text)">My Profile</div>
            <div style="font-size:11px;color:var(--muted)">Update your details & password</div>
          </div>
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const isDark = localStorage.getItem('fjb-theme') === 'dark';
const gridColor  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
const labelColor = isDark ? '#9ca3af' : '#64748b';
const accent = '#F28C28';

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size   = 12;

// ── BAR CHART — Asset Class Distribution ──
const ctxBar = document.getElementById('classChart').getContext('2d');
const labels  = <?= json_encode($class_labels) ?>;
const values  = <?= json_encode($class_values) ?>;

// Gradient fill
const grad = ctxBar.createLinearGradient(0, 0, 0, 300);
grad.addColorStop(0, 'rgba(242,140,40,0.9)');
grad.addColorStop(1, 'rgba(242,140,40,0.2)');

new Chart(ctxBar, {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Assets',
      data: values,
      backgroundColor: grad,
      borderColor: accent,
      borderWidth: 0,
      borderRadius: { topLeft: 6, topRight: 6 },
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    animation: { duration: 800, easing: 'easeOutQuart' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: isDark ? '#1f2937' : '#fff',
        titleColor: isDark ? '#d1d5db' : '#1e293b',
        bodyColor: accent,
        borderColor: isDark ? '#374151' : '#e2e8f0',
        borderWidth: 1,
        padding: 12,
        cornerRadius: 8,
        callbacks: {
          label: ctx => ` ${ctx.parsed.y} asset${ctx.parsed.y !== 1 ? 's' : ''}`
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: { color: labelColor, stepSize: 1, padding: 8 },
        grid: { color: gridColor, drawBorder: false },
        border: { display: false },
      },
      x: {
        ticks: { color: labelColor, padding: 8 },
        grid: { display: false },
        border: { display: false },
      }
    }
  }
});

// ── DOUGHNUT CHART — E-Waste Status ──
const ewData = <?= json_encode($ew_status_data) ?>;
const ewColorMap = { Pending: '#E07818', Approved: '#2563EB', Collected: '#16A34A' };
const ewColors = Object.keys(ewData).map(k => ewColorMap[k] || '#94a3b8');
const ctxPie = document.getElementById('ewChart').getContext('2d');
new Chart(ctxPie, {
  type: 'doughnut',
  data: {
    labels: Object.keys(ewData),
    datasets: [{
      data: Object.values(ewData),
      backgroundColor: ewColors,
      borderWidth: 3,
      borderColor: isDark ? '#1f2937' : '#ffffff',
      hoverBorderColor: isDark ? '#1f2937' : '#ffffff',
      hoverOffset: 6,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '72%',
    animation: { duration: 900, easing: 'easeOutQuart' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: isDark ? '#1f2937' : '#fff',
        titleColor: isDark ? '#d1d5db' : '#1e293b',
        bodyColor: isDark ? '#9ca3af' : '#64748b',
        borderColor: isDark ? '#374151' : '#e2e8f0',
        borderWidth: 1,
        padding: 12,
        cornerRadius: 8,
      }
    }
  }
});
</script>

<?php require_once 'includes/layout_end.php'; ?>
