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

<style>
.rpt-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px;border-left:4px solid transparent}
.rpt-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.rpt-stat-val{font-size:26px;font-weight:800;color:var(--text);line-height:1}
.rpt-stat-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:3px}
.rpt-tab{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;transition:all .15s;border:1.5px solid var(--border);color:var(--muted)}
.rpt-tab:hover{border-color:var(--accent);color:var(--accent)}
.rpt-tab.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.dq-cell{background:var(--body-bg);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;gap:16px;flex-wrap:wrap">
  <div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">
      Admin &rsaquo; <span style="color:var(--accent)">Reports</span>
    </div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0"><?= $page_title ?></h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">System-wide inventory and disposal analytics</p>
  </div>
  <!-- Tab switcher -->
  <div style="display:flex;align-items:center;gap:8px">
    <a href="reports.php?type=assets" class="rpt-tab <?= $type==='assets'?'active':'' ?>">
      <i class="bi bi-box-seam-fill"></i> IT Assets
    </a>
    <a href="reports.php?type=ewaste" class="rpt-tab <?= $type==='ewaste'?'active':'' ?>">
      <i class="bi bi-recycle"></i> E-Waste
    </a>
    <button onclick="window.print()"
      style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--surface);color:var(--muted);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s"
      onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'"
      onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
      <i class="bi bi-printer"></i> Print
    </button>
  </div>
</div>

<?php if ($type === 'ewaste'): ?>

<!-- ══ E-WASTE REPORT ══ -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px">
  <?php foreach([
    ['E-Waste Total', 'bi-recycle',          '#e07818','rgba(224,120,24,.12)', $ewaste_total],
    ['Pending',       'bi-hourglass-split',   '#d97706','rgba(245,158,11,.12)', $ewaste_pending],
    ['Approved',      'bi-check-circle-fill', '#16a34a','rgba(22,163,74,.12)',  $ewaste_approved],
  ] as [$lbl,$icon,$color,$bg,$val]): ?>
  <div class="rpt-stat" style="border-left-color:<?= $color ?>">
    <div class="rpt-stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
      <i class="bi <?= $icon ?>"></i>
    </div>
    <div>
      <div class="rpt-stat-val"><?= $val ?></div>
      <div class="rpt-stat-lbl"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">E-Waste by Class</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Breakdown by asset category</div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset Class</th><th>Count</th><th>% of Total</th></tr></thead>
          <tbody>
          <?php foreach ($ew_classes as $r): ?>
          <tr>
            <td>
              <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(242,140,40,.08);border:1px solid rgba(242,140,40,.2);border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700;color:var(--accent-h)">
                <?= h($r['asset_class']) ?>
              </span>
            </td>
            <td><strong style="color:var(--accent)"><?= $r['c'] ?></strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;max-width:100px">
                  <div style="height:6px;border-radius:4px;background:var(--accent);width:<?= $ewaste_total>0?round($r['c']/$ewaste_total*100):0 ?>%"></div>
                </div>
                <span style="font-size:12px;color:var(--muted);min-width:36px"><?= $ewaste_total>0?round($r['c']/$ewaste_total*100,1):0 ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($ew_classes)): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:28px">No e-waste records yet</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">Recent E-Waste Items</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Latest 20 entries</div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset No.</th><th>Class</th><th>Description</th><th>Status</th><th>Date Flagged</th></tr></thead>
          <tbody>
          <?php $sc=['Pending'=>'bs-pending','Approved'=>'bs-repair','Disposed'=>'bs-disposed'];
          while ($r = $ew_recent->fetch_assoc()): ?>
          <tr>
            <td><code style="color:var(--accent)"><?= h($r['asset_number']?:'—') ?></code></td>
            <td style="font-size:12px"><?= h($r['asset_class']) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($r['description']) ?></td>
            <td><span class="badge-status <?= $sc[$r['disposal_status']]??'' ?>"><?= h($r['disposal_status']) ?></span></td>
            <td style="font-size:12px;white-space:nowrap"><?= $r['date_flagged']?date('d/m/Y',strtotime($r['date_flagged'])):'—' ?></td>
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
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px">
  <?php foreach([
    ['Total Assets',    'bi-box-seam-fill',    '#2563eb','rgba(37,99,235,.12)',  $total_assets],
    ['Active',          'bi-check-circle-fill', '#16a34a','rgba(22,163,74,.12)', $active_assets],
    ['Collected',       'bi-bag-check-fill',    '#0d9488','rgba(13,148,136,.12)',$disposed],
    ['E-Waste Pending', 'bi-hourglass-split',   '#d97706','rgba(245,158,11,.12)',$ewaste_pending],
  ] as [$lbl,$icon,$color,$bg,$val]): ?>
  <div class="rpt-stat" style="border-left-color:<?= $color ?>">
    <div class="rpt-stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
      <i class="bi <?= $icon ?>"></i>
    </div>
    <div>
      <div class="rpt-stat-val"><?= $val ?></div>
      <div class="rpt-stat-lbl"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">Assets by Class</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Inventory distribution</div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Asset Class</th><th>Count</th><th>% of Total</th></tr></thead>
          <tbody>
          <?php foreach ($classes as $c): ?>
          <tr>
            <td>
              <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(242,140,40,.08);border:1px solid rgba(242,140,40,.2);border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700;color:var(--accent-h)">
                <?= h($c['asset_class']) ?>
              </span>
            </td>
            <td><strong style="color:var(--accent)"><?= $c['c'] ?></strong></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;max-width:100px">
                  <div style="height:6px;border-radius:4px;background:var(--accent);width:<?= $total_assets>0?round($c['c']/$total_assets*100):0 ?>%"></div>
                </div>
                <span style="font-size:12px;color:var(--muted);min-width:36px"><?= $total_assets>0?round($c['c']/$total_assets*100,1):0 ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div class="table-card h-100">
      <div class="table-card-header">
        <div>
          <div class="table-card-title">Data Quality Issues</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">Missing fields across IT assets</div>
        </div>
      </div>
      <div style="padding:20px">
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
          <?php foreach([
            ['Missing Asset No.',  $no_asset],
            ['Missing Serial No.', $no_serial],
            ['Missing Location',   $no_location],
            ['Missing Brand',      $no_brand],
          ] as [$lbl,$val]):
            $ok = $val === 0; ?>
          <div class="dq-cell">
            <i class="bi <?= $ok?'bi-check-circle-fill':'bi-exclamation-circle-fill' ?>"
              style="color:<?= $ok?'#16a34a':'#d97706' ?>;font-size:22px;margin-bottom:8px;display:block"></i>
            <div style="font-size:24px;font-weight:800;color:<?= $ok?'#16a34a':'#d97706' ?>;font-family:'Plus Jakarta Sans',sans-serif;line-height:1"><?= $val ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px;line-height:1.4"><?= $lbl ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;padding:12px 16px;background:rgba(22,163,74,.06);border:1px solid rgba(22,163,74,.2);border-radius:8px;font-size:12px;color:#15803d;display:flex;align-items:center;gap:8px">
          <i class="bi bi-info-circle-fill"></i>
          Data quality score: <strong><?= $total_assets > 0 ? round((1 - (($no_asset+$no_serial+$no_location+$no_brand)/(4*$total_assets)))*100) : 100 ?>%</strong> — based on 4 key fields across <?= $total_assets ?> assets
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once 'includes/layout_end.php'; ?>
