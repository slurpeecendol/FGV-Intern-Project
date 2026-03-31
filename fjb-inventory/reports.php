<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db   = getDB();
$type = $_GET['type'] ?? 'assets';
$page_title = $type === 'ewaste' ? 'E-Waste Report' : 'IT Assets Report';
$active_nav = $type === 'ewaste' ? 'reports_ewaste' : 'reports';

// ── IT ASSETS STATS ──
$total_assets   = $db->query("SELECT COUNT(*) c FROM inventory_items")->fetch_assoc()['c'];
$active_assets  = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE item_status='Active'")->fetch_assoc()['c'];
$disposed       = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE item_status='Disposed'")->fetch_assoc()['c'];
$ewaste_total   = $db->query("SELECT COUNT(*) c FROM ewaste_items")->fetch_assoc()['c'];
$ewaste_pending = $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE disposal_status='Pending'")->fetch_assoc()['c'];
$ewaste_approved= $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE disposal_status='Approved'")->fetch_assoc()['c'];
$ewaste_disposed= $db->query("SELECT COUNT(*) c FROM ewaste_items WHERE disposal_status='Disposed'")->fetch_assoc()['c'];

// ── CLASS BREAKDOWN ──
$class_res = $db->query("SELECT asset_class, COUNT(*) c FROM inventory_items GROUP BY asset_class ORDER BY c DESC");
$classes = []; while ($r = $class_res->fetch_assoc()) $classes[] = $r;

$ew_class_res = $db->query("SELECT asset_class, COUNT(*) c FROM ewaste_items GROUP BY asset_class ORDER BY c DESC");
$ew_classes = []; while ($r = $ew_class_res->fetch_assoc()) $ew_classes[] = $r;

// ── DATA QUALITY ──
$no_asset    = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE asset_number  IS NULL OR asset_number=''")->fetch_assoc()['c'];
$no_serial   = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE serial_number IS NULL OR serial_number=''")->fetch_assoc()['c'];
$no_location = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE location      IS NULL OR location=''")->fetch_assoc()['c'];
$no_brand    = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE brand         IS NULL OR brand=''")->fetch_assoc()['c'];

// ── RECENT EWASTE ──
$ew_recent = $db->query("SELECT * FROM ewaste_items ORDER BY created_at DESC LIMIT 20");

require_once 'includes/layout.php';
?>

<?php if ($type === 'ewaste'): ?>

<!-- ══ E-WASTE REPORT ══ -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['E-Waste Total',  'bi-recycle',           $ewaste_total,    '#e07818', 'rgba(242,140,40,.1)'],
    ['Pending',        'bi-hourglass-split',    $ewaste_pending,  '#d97706', 'rgba(245,158,11,.1)'],
    ['Approved',       'bi-check-circle-fill',  $ewaste_approved, '#16a34a', 'rgba(34,197,94,.1)'],
    ['Disposed',       'bi-trash3-fill',        $ewaste_disposed, '#dc2626', 'rgba(239,68,68,.1)'],
  ] as [$label,$icon,$val,$color,$bg]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="border-left-color:<?= $color ?>">
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
        <i class="bi <?= $icon ?>"></i>
      </div>
      <div class="stat-value" style="color:var(--text)"><?= $val ?></div>
      <div class="stat-label"><?= $label ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-recycle me-2" style="color:var(--accent)"></i>E-Waste by Class</div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset Class</th><th>Count</th><th>% of Total</th></tr></thead>
          <tbody>
          <?php foreach ($ew_classes as $r): ?>
          <tr>
            <td style="font-weight:500"><?= h($r['asset_class']) ?></td>
            <td><strong style="color:var(--accent)"><?= $r['c'] ?></strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;max-width:120px">
                  <div style="height:6px;border-radius:4px;background:var(--accent);width:<?= $ewaste_total > 0 ? round($r['c']/$ewaste_total*100) : 0 ?>%"></div>
                </div>
                <span style="font-size:12px;color:var(--muted);min-width:36px"><?= $ewaste_total > 0 ? round($r['c']/$ewaste_total*100,1) : 0 ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($ew_classes)): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:24px">No e-waste records yet</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-clock-history me-2" style="color:var(--accent)"></i>Recent E-Waste Items</div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset No.</th><th>Class</th><th>Description</th><th>Status</th><th>Date Flagged</th></tr></thead>
          <tbody>
          <?php
          $sc = ['Pending'=>'bs-pending','Approved'=>'bs-repair','Disposed'=>'bs-disposed'];
          while ($r = $ew_recent->fetch_assoc()):
          ?>
          <tr>
            <td><code style="color:var(--accent)"><?= h($r['asset_number'] ?: '—') ?></code></td>
            <td style="font-size:12px"><?= h($r['asset_class']) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($r['description']) ?></td>
            <td><span class="badge-status <?= $sc[$r['disposal_status']] ?? '' ?>"><?= h($r['disposal_status']) ?></span></td>
            <td style="font-size:12px;white-space:nowrap"><?= $r['date_flagged'] ? date('d/m/Y', strtotime($r['date_flagged'])) : '—' ?></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ══ IT ASSETS REPORT ══ -->
<div class="row g-3 mb-4">
  <?php foreach([
    ['Total Assets',   'bi-box-seam-fill',     $total_assets,   '#2563eb', 'rgba(59,130,246,.1)'],
    ['Active',         'bi-check-circle-fill',  $active_assets,  '#16a34a', 'rgba(34,197,94,.1)'],
    ['Collected',      'bi-bag-check-fill',     $disposed,       '#0d9488', 'rgba(13,148,136,.1)'],
    ['E-Waste Pending','bi-hourglass-split',    $ewaste_pending, '#d97706', 'rgba(245,158,11,.1)'],
  ] as [$label,$icon,$val,$color,$bg]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="border-left-color:<?= $color ?>">
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
        <i class="bi <?= $icon ?>"></i>
      </div>
      <div class="stat-value" style="color:var(--text)"><?= $val ?></div>
      <div class="stat-label"><?= $label ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Assets by Class -->
  <div class="col-md-5">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-bar-chart-fill me-2" style="color:var(--accent)"></i>Assets by Class</div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset Class</th><th>Count</th><th>% of Total</th></tr></thead>
          <tbody>
          <?php foreach ($classes as $c): ?>
          <tr>
            <td style="font-weight:500"><?= h($c['asset_class']) ?></td>
            <td><strong style="color:var(--accent)"><?= $c['c'] ?></strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;max-width:120px">
                  <div style="height:6px;border-radius:4px;background:var(--accent);width:<?= $total_assets > 0 ? round($c['c']/$total_assets*100) : 0 ?>%"></div>
                </div>
                <span style="font-size:12px;color:var(--muted);min-width:36px"><?= $total_assets > 0 ? round($c['c']/$total_assets*100,1) : 0 ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Data Quality -->
  <div class="col-md-7">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div class="table-card-title"><i class="bi bi-shield-exclamation me-2" style="color:#d97706"></i>Data Quality Issues</div>
      </div>
      <div style="padding:20px 20px 8px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:14px">IT Assets</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px">
          <?php foreach([
            ['Missing Asset No.',    $no_asset],
            ['Missing Serial No.',   $no_serial],
            ['Missing Location',     $no_location],
            ['Missing Brand',        $no_brand],
          ] as [$label,$val]):
            $ok = $val === 0;
          ?>
          <div style="background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center">
            <i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"
              style="color:<?= $ok ? '#16a34a' : '#d97706' ?>;font-size:20px;margin-bottom:8px;display:block"></i>
            <div style="font-size:22px;font-weight:800;color:<?= $ok ? '#16a34a' : '#d97706' ?>;font-family:'Plus Jakarta Sans',sans-serif;line-height:1"><?= $val ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;line-height:1.3"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once 'includes/layout_end.php'; ?>
