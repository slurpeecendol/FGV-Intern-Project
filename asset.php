<?php
require_once 'config/db.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Asset not found.'); }
$asset = $db->query("SELECT * FROM inventory_items WHERE id=$id")->fetch_assoc();
if (!$asset) { http_response_code(404); die('Asset not found.'); }

$status = $asset['item_status'] ?? 'Unknown';
$ew = $db->query("SELECT disposal_status FROM ewaste_items WHERE original_inventory_id=$id ORDER BY id DESC LIMIT 1")->fetch_assoc();
if ($ew) {
    if ($ew['disposal_status'] === 'Pending')   $status = 'Pending E-Waste';
    if ($ew['disposal_status'] === 'Approved')  $status = 'E-Waste';
    if ($ew['disposal_status'] === 'Collected') $status = 'Collected';
}
$statusColors = [
    'Active'           => ['#f0fdf4','#bbf7d0','#15803d','#16a34a'],
    'Pending E-Waste'  => ['#fffbeb','#fde68a','#92400e','#d97706'],
    'E-Waste'          => ['#fffbeb','#fde68a','#92400e','#d97706'],
    'Collected'        => ['#fefce8','#fef08a','#713f12','#ca8a04'],
    'In Repair'        => ['#eff6ff','#bfdbfe','#1e40af','#2563eb'],
    'Disposed'         => ['#fef2f2','#fecaca','#991b1b','#dc2626'],
];
[$sbg,$sbdr,$stxt,$sdot] = $statusColors[$status] ?? ['#f1f5f9','#e2e8f0','#475569','#64748b'];
function clean($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
$public_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . dirname($_SERVER['SCRIPT_NAME']) . '/asset.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= clean($asset['asset_number'] ?: 'Asset #'.$id) ?> — FJB IT Inventory</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#f0f2f5;font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;color:#1a2332;min-height:100vh}

/* ── TOPBAR ── */
.topbar{background:#1a2332;padding:0 24px;height:54px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-brand{display:flex;align-items:center;gap:10px}
.topbar-logo{width:36px;height:36px;object-fit:contain;flex-shrink:0}
.topbar-name{font-size:12px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.05em;line-height:1.2}
.topbar-name span{color:#F28C28;display:block;font-size:10px;letter-spacing:.08em;font-weight:600}
.topbar-badge{background:rgba(242,140,40,.15);color:#F28C28;border:1px solid rgba(242,140,40,.3);border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px}

/* ── PAGE ── */
.page{max-width:800px;margin:28px auto 48px;padding:0 16px;display:flex;flex-direction:column;gap:16px}

/* ── HERO CARD ── */
.hero{background:#fff;border-radius:14px;border:1px solid #e4e8ef;overflow:hidden}
.hero-accent{height:4px;background:#F28C28}
.hero-body{padding:24px 28px;display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
.hero-breadcrumb{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:8px}
.hero-title{font-size:28px;font-weight:800;color:#1a2332;letter-spacing:-.3px;line-height:1.1;margin-bottom:4px}
.hero-assetno{font-size:13px;color:#64748b;font-weight:500;margin-bottom:14px}
.badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.status-pill{display:inline-flex;align-items:center;gap:6px;border-radius:20px;padding:5px 13px;font-size:12px;font-weight:700;border:1px solid}
.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.class-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:20px;padding:5px 13px;font-size:12px;font-weight:700;color:#1d4ed8}
.hero-logo{width:52px;height:52px;object-fit:contain;flex-shrink:0}

/* ── INFO STRIP ── */
.info-strip{background:#f8fafc;border-top:1px solid #e4e8ef;padding:14px 28px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.strip-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:4px}
.strip-value{font-size:13px;font-weight:600;color:#1a2332}
.strip-value.muted{font-weight:400;font-style:italic;color:#94a3b8}

/* ── DETAILS CARD ── */
.card{background:#fff;border-radius:14px;border:1px solid #e4e8ef;overflow:hidden}
.card-head{padding:14px 24px;border-bottom:1px solid #e4e8ef;display:flex;align-items:center;gap:8px;background:#f8fafc}
.card-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.card-title{font-size:13px;font-weight:700;color:#1a2332}
.fields-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.field{padding:16px 24px;border-bottom:1px solid #f1f5f9}
.field:nth-child(odd){border-right:1px solid #f1f5f9}
.field:nth-last-child(-n+2){border-bottom:none}
.field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;margin-bottom:5px}
.field-value{font-size:13px;font-weight:600;color:#1a2332}
.field-value.muted{color:#94a3b8;font-weight:400;font-style:italic}

/* ── QR CARD ── */
.qr-card{background:#fff;border-radius:14px;border:1px solid #e4e8ef;padding:22px 24px;display:flex;gap:22px;align-items:flex-start}
.qr-box{background:#f8fafc;border:1px solid #e4e8ef;border-radius:10px;padding:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;width:128px;height:128px}
.qr-info{}
.qr-title{font-size:14px;font-weight:700;color:#1a2332;margin-bottom:5px;display:flex;align-items:center;gap:6px}
.qr-sub{font-size:12px;color:#64748b;line-height:1.5;margin-bottom:12px}
.link-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:9px 12px;display:flex;align-items:center;gap:8px;margin-bottom:10px}
.link-text{font-size:11px;color:#92400e;font-family:monospace;flex:1;word-break:break-all}
.btn-copy{background:#F28C28;color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0}
.btn-print{display:inline-flex;align-items:center;gap:6px;background:#1a2332;color:#fff;border:none;border-radius:7px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer}

/* ── FOOTER ── */
.footer-note{background:#fff;border:1px solid #e4e8ef;border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:12px}
.footer-icon{width:34px;height:34px;background:rgba(242,140,40,.1);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.footer-text{font-size:12px;color:#7c8fa6;line-height:1.5}
.footer-text strong{color:#1a2332;display:block;font-size:13px;font-weight:700;margin-bottom:2px}

@media(max-width:560px){
  .info-strip{grid-template-columns:1fr 1fr}
  .fields-grid{grid-template-columns:1fr}
  .field:nth-child(odd){border-right:none}
  .field:nth-last-child(-n+2){border-bottom:1px solid #f1f5f9}
  .field:last-child{border-bottom:none}
  .qr-card{flex-direction:column;align-items:center;text-align:center}
  .link-box{flex-direction:column}
  .btn-copy{width:100%}
  .hero-body{flex-direction:column-reverse}
}
@media print{
  .topbar,.link-box,.btn-copy,.btn-print,.footer-note{display:none!important}
  body{background:#fff}
  .page{max-width:100%;margin:0;padding:0}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-brand">
    <img src="assets/img/fjb-logo.png" class="topbar-logo" alt="FJB Logo">
    <div class="topbar-name">FJB Pasir Gudang<span>IT Inventory System</span></div>
  </div>
  <span class="topbar-badge"><i class="bi bi-eye-fill"></i> Read-Only View</span>
</div>

<div class="page">

  <!-- HERO CARD -->
  <div class="hero">
    <div class="hero-accent"></div>
    <div class="hero-body">
      <div>
        <div class="hero-breadcrumb">IT Inventory &rsaquo; Asset Record</div>
        <div class="hero-title"><?= clean($asset['description'] ?: '(No Description)') ?></div>
        <div class="hero-assetno">Asset No. <?= clean($asset['asset_number'] ?: '—') ?></div>
        <div class="badges">
          <span class="status-pill" style="background:<?= $sbg ?>;border-color:<?= $sbdr ?>;color:<?= $stxt ?>">
            <span class="status-dot" style="background:<?= $sdot ?>"></span>
            <?= clean($status) ?>
          </span>
          <?php if ($asset['asset_class']): ?>
          <span class="class-pill"><i class="bi bi-tag-fill" style="font-size:10px"></i><?= clean($asset['asset_class']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <img src="assets/img/fjb-logo.png" class="hero-logo" alt="FJB">
    </div>
    <!-- Quick-glance info strip -->
    <div class="info-strip">
      <?php foreach([
        ['Serial No.', $asset['serial_number']],
        ['Location',   $asset['location']],
        ['Brand',      $asset['brand']],
        ['Model',      $asset['model']],
      ] as [$lbl,$val]): ?>
      <div>
        <div class="strip-label"><?= $lbl ?></div>
        <div class="strip-value <?= $val ? '' : 'muted' ?>"><?= $val ? clean($val) : 'Not recorded' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ASSET DETAILS CARD -->
  <div class="card">
    <div class="card-head">
      <div class="card-icon" style="background:rgba(37,99,235,.1)">
        <i class="bi bi-info-circle-fill" style="color:#2563eb"></i>
      </div>
      <div class="card-title">Asset Details</div>
    </div>
    <div class="fields-grid">
      <?php foreach([
        ['Asset Number',  $asset['asset_number']],
        ['Asset Class',   $asset['asset_class']],
        ['Description',   $asset['description']],
        ['Serial Number', $asset['serial_number']],
        ['Brand',         $asset['brand']],
        ['Model',         $asset['model']],
        ['Location',      $asset['location']],
        ['Status',        $status],
      ] as [$label,$value]): ?>
      <div class="field">
        <div class="field-label"><?= $label ?></div>
        <?php if ($value): ?>
        <div class="field-value"><?= clean($value) ?></div>
        <?php else: ?>
        <div class="field-value muted">Not recorded</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($asset['notes']): ?>
  <!-- NOTES -->
  <div class="card">
    <div class="card-head">
      <div class="card-icon" style="background:rgba(245,158,11,.1)">
        <i class="bi bi-sticky-fill" style="color:#d97706"></i>
      </div>
      <div class="card-title">Notes</div>
    </div>
    <div style="padding:16px 24px;font-size:13px;color:#4b5563;line-height:1.6"><?= nl2br(clean($asset['notes'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- QR CODE -->
  <div class="qr-card">
    <div class="qr-box">
      <div id="qrcode"></div>
    </div>
    <div class="qr-info">
      <div class="qr-title"><i class="bi bi-qr-code" style="color:#F28C28"></i> Asset QR Code</div>
      <div class="qr-sub">Scan to share this asset record. No login required to view.</div>
      <div class="link-box">
        <span class="link-text"><?= clean($public_url) ?></span>
        <button class="btn-copy" onclick="copyLink()">Copy Link</button>
      </div>
      <button class="btn-print" onclick="window.print()">
        <i class="bi bi-printer-fill"></i> Print This Page
      </button>
    </div>
  </div>

  <!-- FOOTER NOTE -->
  <div class="footer-note">
    <div class="footer-icon"><i class="bi bi-shield-lock-fill" style="color:#F28C28"></i></div>
    <div class="footer-text">
      <strong>Read-only public view</strong>
      This page shows registered asset information only. No edits can be made here. Managed by FJB Johor Bulkers Sdn Bhd — IT Department.
    </div>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
<?php
$lines = array_filter([
  'FJB JOHOR BULKERS SDN BHD',
  '------------------------------',
  'ASET NO: '  . ($asset['asset_number'] ?: '-'),
  'DESC: '     . ($asset['description'] ?: '-'),
  'CLASS: '    . ($asset['asset_class'] ?: '-'),
  $asset['serial_number'] ? 'S/N: '   . $asset['serial_number'] : '',
  $asset['brand']         ? 'BRAND: ' . $asset['brand']         : '',
  $asset['model']         ? 'MODEL: ' . $asset['model']         : '',
  $asset['location']      ? 'LOC: '   . $asset['location']      : '',
  'STATUS: '   . $status,
  '------------------------------',
  'FGV JOHOR BULKERS SDN BHD',
]);
$qr_text = implode("\n", $lines);
?>
new QRCode(document.getElementById('qrcode'), {
  text: <?= json_encode($qr_text) ?>,
  width: 108, height: 108,
  colorDark: '#1a2332', colorLight: '#f8fafc',
  correctLevel: QRCode.CorrectLevel.M
});
function copyLink() {
  navigator.clipboard.writeText(<?= json_encode($public_url) ?>).then(() => {
    const btn = document.querySelector('.btn-copy');
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.style.background = '#16a34a';
    setTimeout(() => { btn.textContent = orig; btn.style.background = '#F28C28'; }, 2000);
  });
}
</script>
</body>
</html>
