<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$ref  = $_GET['ref']  ?? ('COL-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5)));

$where = ["ew.disposal_status = 'Collected'"];
if ($from) $where[] = "ew.date_disposed >= '" . mysqli_real_escape_string($db, $from) . "'";
if ($to)   $where[] = "ew.date_disposed <= '" . mysqli_real_escape_string($db, $to)   . "'";
$sql = implode(' AND ', $where);

$items = $db->query("SELECT ew.*, u.full_name as submitted_by FROM ewaste_items ew LEFT JOIN users u ON ew.created_by=u.id WHERE $sql ORDER BY ew.date_disposed ASC, ew.asset_class ASC");
$rows = [];
while ($r = $items->fetch_assoc()) $rows[] = $r;
$total = count($rows);

$date_range = $from && $to
    ? date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to))
    : ($from ? 'From ' . date('d M Y', strtotime($from)) : 'All collected items');

function cl($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Collection Invoice <?= cl($ref) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#f0f2f5;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1a1a1a;min-height:100vh}

/* ── SCREEN NAV ── */
.screen-nav{background:#fff;border-bottom:1px solid #e4e8ef;padding:12px 32px;display:flex;align-items:center;justify-content:space-between}
.breadcrumb{font-size:12px;color:#7c8fa6;display:flex;align-items:center;gap:6px}
.breadcrumb a{color:#7c8fa6;text-decoration:none}
.breadcrumb span{color:#1a2332;font-weight:600}
.nav-actions{display:flex;gap:8px}
.btn-print{display:inline-flex;align-items:center;gap:6px;background:#F28C28;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer}
.btn-pdf{display:inline-flex;align-items:center;gap:6px;background:#1a2332;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer}
.btn-back{display:inline-flex;align-items:center;gap:6px;background:transparent;color:#7c8fa6;border:1px solid #e4e8ef;border-radius:7px;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none}

/* ── PAGE WRAPPER ── */
.page{max-width:780px;margin:28px auto 60px;padding:0 16px}

/* ── INVOICE CARD ── */
.invoice{background:#fff;border:1px solid #e4e8ef;border-radius:12px;overflow:hidden}

/* ── HEADER ── */
.inv-header{padding:28px 32px 20px;border-bottom:1px solid #f0f2f5;display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
.inv-logo-area{display:flex;align-items:center;gap:12px}
.inv-logo{width:44px;height:44px;object-fit:contain;flex-shrink:0}
.inv-company-name{font-size:17px;font-weight:800;color:#1a1a1a;letter-spacing:-.2px;line-height:1.2}
.inv-company-sub{font-size:11px;color:#7c8fa6;margin-top:2px;font-weight:500}
.inv-company-addr{font-size:11px;color:#9ca3af;margin-top:8px;line-height:1.6}
.inv-doc-area{text-align:right;flex-shrink:0}
.inv-doc-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#7c8fa6;margin-bottom:3px}
.inv-doc-type{font-size:18px;font-weight:800;color:#1a1a1a;letter-spacing:.02em}
.inv-doc-ref{font-size:11px;color:#7c8fa6;margin-top:5px}

/* ── INFO STRIP ── */
.inv-strip{background:#fafbfc;border-bottom:1px solid #f0f2f5;padding:14px 32px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.strip-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-bottom:4px}
.strip-value{font-size:13px;font-weight:700;color:#1a1a1a}
.badge-collected{display:inline-block;background:#16a34a;color:#fff;border-radius:4px;padding:3px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}

/* ── TABLE ── */
.inv-table-wrap{padding:0 0 0 0}
table.inv-tbl{width:100%;border-collapse:collapse;font-size:12px}
table.inv-tbl thead th{padding:10px 14px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#9ca3af;border-bottom:1px solid #f0f2f5;text-align:left;white-space:nowrap}
table.inv-tbl thead th:first-child{padding-left:32px}
table.inv-tbl thead th:last-child{padding-right:32px}
table.inv-tbl tbody tr{border-bottom:1px solid #f9fafb}
table.inv-tbl tbody tr:last-child{border-bottom:none}
table.inv-tbl tbody td{padding:12px 14px;vertical-align:middle;color:#1a1a1a}
table.inv-tbl tbody td:first-child{padding-left:32px;color:#9ca3af;font-size:11px}
table.inv-tbl tbody td:last-child{padding-right:32px}
.cls-tag{display:inline-block;background:#f0f2f5;border-radius:4px;padding:2px 8px;font-size:10px;font-weight:700;color:#4b5563;letter-spacing:.03em}
.proof-link{display:inline-flex;align-items:center;gap:3px;color:#2563eb;font-size:11px;font-weight:600;text-decoration:none}
.no-proof{color:#d1d5db;font-size:11px}

/* ── SUMMARY ── */
.inv-summary{padding:24px 32px;border-top:1px solid #f0f2f5;display:flex;justify-content:flex-end}
.summary-box{background:#fafbfc;border:1px solid #e4e8ef;border-radius:10px;padding:18px 24px;min-width:180px;text-align:right}
.summary-box-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-bottom:4px}
.summary-box-val{font-size:28px;font-weight:800;color:#F28C28;line-height:1}
.summary-box-unit{font-size:10px;color:#9ca3af;margin-top:2px;text-transform:uppercase;letter-spacing:.06em}
.summary-divider{border:none;border-top:1px solid #e4e8ef;margin:10px 0}
.summary-grand-label{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin-bottom:2px}
.summary-grand-val{font-size:13px;font-weight:700;color:#1a1a1a}

/* ── SIGNATURES ── */
.inv-sig{padding:24px 32px;border-top:1px solid #f0f2f5;display:grid;grid-template-columns:1fr 1fr;gap:40px;page-break-inside:avoid}
.sig-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#9ca3af;margin-bottom:32px}
.sig-line{border-top:1.5px solid #1a1a1a;padding-top:6px}
.sig-name{font-size:12px;font-weight:700;color:#1a1a1a}
.sig-role{font-size:10px;color:#9ca3af;margin-top:1px}

/* ── FOOTER ── */
.inv-foot{padding:14px 32px;background:#fafbfc;border-top:1px solid #f0f2f5;text-align:center;font-size:10px;color:#9ca3af;line-height:1.6;page-break-inside:avoid}

/* ── BOTTOM ACTION BAR (screen only) ── */
.action-bar{max-width:780px;margin:0 auto;padding:0 16px 40px;display:flex;justify-content:center;gap:10px}

@media print{
  .screen-nav,.action-bar{display:none!important}
  body{background:#fff}
  .page{max-width:100%;margin:0;padding:0}
  .invoice{border:none;border-radius:0;box-shadow:none}
}
</style>
</head>
<body>

<!-- SCREEN NAV -->
<div class="screen-nav">
  <div class="breadcrumb">
    <a href="collected_proofs.php">Invoices</a>
    <i class="bi bi-chevron-right" style="font-size:10px"></i>
    <span><?= cl($ref) ?></span>
  </div>
  <div class="nav-actions">
    <a href="collected_proofs.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="page">
<div class="invoice">

  <!-- HEADER -->
  <div class="inv-header">
    <div>
      <div class="inv-logo-area">
        <img src="assets/img/fjb-logo.png" class="inv-logo" alt="FJB Logo">
        <div>
          <div class="inv-company-name">FJB JOHOR BULKERS SDN BHD</div>
          <div class="inv-company-sub">IT Department &mdash; FGV Holdings</div>
        </div>
      </div>
      <div class="inv-company-addr">
        Lorong Sawit Satu, Johor Port Area,<br>
        81700 Pasir Gudang, Johor Darul<br>
        Ta'zim
      </div>
    </div>
    <div class="inv-doc-area">
      <div class="inv-doc-label">Collection Invoice</div>
      <div class="inv-doc-type">OFFICIAL RECEIPT</div>
      <div class="inv-doc-ref">Ref: <strong><?= cl($ref) ?></strong></div>
    </div>
  </div>

  <!-- INFO STRIP -->
  <div class="inv-strip">
    <div>
      <div class="strip-label">Date Range</div>
      <div class="strip-value"><?= cl($date_range) ?></div>
    </div>
    <div>
      <div class="strip-label">Total Assets</div>
      <div class="strip-value"><?= $total ?> item<?= $total !== 1 ? 's' : '' ?></div>
    </div>
    <div>
      <div class="strip-label">Status</div>
      <div class="strip-value">
        <?php if ($total > 0): ?>
        <span class="badge-collected">Collected</span>
        <?php else: ?>
        <span style="color:#9ca3af;font-weight:600">—</span>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="strip-label">Prepared By</div>
      <div class="strip-value"><?= cl($_SESSION['full_name'] ?? 'Admin') ?></div>
    </div>
  </div>

  <!-- TABLE -->
  <?php if ($total === 0): ?>
  <div style="padding:48px;text-align:center;color:#9ca3af;font-size:13px">No collected items found for this period.</div>
  <?php else: ?>
  <table class="inv-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Asset No.</th>
        <th>Class</th>
        <th>Description</th>
        <th>Serial No.</th>
        <th>Authorized By</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $row):
      preg_match('/Proof: ([^\s|]+)/', $row['notes'] ?? '', $pm);
      $proof = $pm[1] ?? '';
      $has_proof = $proof && file_exists($proof);
    ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td style="font-weight:700;color:#1a1a1a"><?= cl($row['asset_number'] ?: '—') ?></td>
      <td><span class="cls-tag"><?= cl($row['asset_class']) ?></span></td>
      <td style="font-weight:500"><?= cl($row['description']) ?></td>
      <td style="color:#6b7280"><?= cl($row['serial_number'] ?: '—') ?></td>
      <td style="font-weight:500"><?= cl($row['writeoff_name'] ?: ($row['submitted_by'] ?: '—')) ?></td>
      <td style="color:#6b7280;white-space:nowrap"><?= $row['date_disposed'] ? date('d/m/Y', strtotime($row['date_disposed'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- SUMMARY -->
  <div class="inv-summary">
    <div class="summary-box">
      <div class="summary-box-label">Total Collected</div>
      <div class="summary-box-val"><?= $total ?></div>
      <div class="summary-box-unit">Asset<?= $total !== 1 ? 's' : '' ?></div>
      <hr class="summary-divider">
      <div class="summary-grand-label">Grand Total</div>
      <div class="summary-grand-val"><?= $total ?> Item<?= $total !== 1 ? 's' : '' ?></div>
    </div>
  </div>

  <!-- SIGNATURES -->
  <div class="inv-sig">
    <div>
      <div class="sig-label">Prepared By (IT Department)</div>
      <div class="sig-line">
        <div class="sig-name"><?= cl($_SESSION['full_name'] ?? '') ?></div>
        <div class="sig-role">Prepared By (IT Department)</div>
      </div>
    </div>
    <div>
      <div class="sig-label">Acknowledged By (Collector / Vendor)</div>
      <div class="sig-line">
        <div class="sig-name">&nbsp;</div>
        <div class="sig-role">Acknowledged By (Collector / Vendor)</div>
      </div>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="inv-foot">
    This document serves as an official collection record for IT assets. All items listed have been<br>confirmed as physically collected for disposal.
  </div>

</div>
</div>

<!-- ACTION BUTTONS -->
<div class="action-bar">
  <button class="btn-print" onclick="window.print()">
    <i class="bi bi-printer-fill"></i> Print Invoice
  </button>
  <button class="btn-pdf" onclick="downloadPDF()">
    <i class="bi bi-download"></i> Download PDF
  </button>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF() {
  var btn = document.querySelector('.btn-pdf');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating...';

  // Hide screen-only elements temporarily
  var nav = document.querySelector('.screen-nav');
  var bar = document.querySelector('.action-bar');
  if (nav) nav.style.display = 'none';
  if (bar) bar.style.display = 'none';

  // Capture full page wrapper (includes entire invoice)
  var element = document.querySelector('.page');
  var filename = 'Collection-Invoice-<?= cl($ref) ?>.pdf';

  var opt = {
    margin:      0,
    filename:    filename,
    image:       { type: 'jpeg', quality: 1 },
    html2canvas: {
      scale: 2,
      useCORS: true,
      logging: false,
      scrollX: 0,
      scrollY: 0,
      windowWidth: document.documentElement.scrollWidth
    },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
  };

  html2pdf().set(opt).from(element).save().then(function() {
    // Restore hidden elements
    if (nav) nav.style.display = '';
    if (bar) bar.style.display = '';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-download"></i> Download PDF';
  });
}
</script>
</div>

</body>
</html>
