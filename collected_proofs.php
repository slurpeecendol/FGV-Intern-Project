<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();
$msg = '';

// ── UNDO COLLECTED ──
$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);
if ($action === 'undispose' && $id) {
    $item = $db->query("SELECT * FROM ewaste_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE ewaste_items SET disposal_status='Approved', date_disposed=NULL, updated_at=NOW() WHERE id=$id");
        if ($item['original_inventory_id']) {
            $inv_id = (int)$item['original_inventory_id'];
            $db->query("UPDATE inventory_items SET item_status='Active', updated_at=NOW() WHERE id=$inv_id");
        }
        logActivity($_SESSION['user_id'],'UPDATE','ewaste',$id,'Reverted collection: '.$item['description']);
        header('Location: collected_proofs.php?msg=undone'); exit;
    }
}

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'undone') $msg = 'Item reverted back to Approved.';

// ── FETCH ──
$cp_res = $db->query("SELECT ew.*, u.full_name as submitted_by FROM ewaste_items ew LEFT JOIN users u ON ew.created_by=u.id WHERE ew.disposal_status='Collected' ORDER BY ew.date_disposed DESC, ew.updated_at DESC");
$cp_rows = [];
if ($cp_res) while ($r = $cp_res->fetch_assoc()) $cp_rows[] = $r;
$cp_count = count($cp_rows);
$cp_this_month = count(array_filter($cp_rows, fn($r) => $r['date_disposed'] && date('Y-m', strtotime($r['date_disposed'])) === date('Y-m')));
$cp_classes = array_count_values(array_column($cp_rows, 'asset_class'));
arsort($cp_classes);
$top_class = $cp_classes ? array_key_first($cp_classes) : '—';

$page_title = 'Collected Proofs';
$active_nav = 'collected_proofs';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>

<!-- PAGE HEADER -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:5px">
      E-Waste &rsaquo; <span style="color:var(--accent)">Collected Proofs</span>
    </div>
    <h4 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:22px;color:var(--text);margin:0">Collected Proofs</h4>
    <p style="font-size:13px;color:var(--muted);margin:4px 0 0">E-waste items confirmed as physically collected for disposal</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <form method="GET" action="collection_invoice.php" target="_blank" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:6px;background:var(--surface);border:1.5px solid var(--border);border-radius:9px;padding:5px 10px">
        <i class="bi bi-calendar3" style="color:var(--muted);font-size:13px;flex-shrink:0"></i>
        <input type="date" name="from" id="invoiceFrom" value="<?= date('Y-m-d') ?>"
          style="background:transparent;border:none;color:var(--text);font-size:12px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;cursor:pointer">
        <span style="font-size:12px;color:var(--muted)">—</span>
        <input type="date" name="to" id="invoiceTo" value="<?= date('Y-m-d') ?>"
          style="background:transparent;border:none;color:var(--text);font-size:12px;font-family:'Plus Jakarta Sans',sans-serif;outline:none;cursor:pointer">
        <button type="button" onclick="resetToToday()" title="Reset to today"
          style="background:rgba(242,140,40,.12);color:var(--accent);border:none;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap">
          Today
        </button>
      </div>
      <button type="submit" class="btn-primary-custom" style="padding:8px 16px;font-size:12px;gap:6px">
        <i class="bi bi-file-earmark-text-fill"></i> Generate Invoice
      </button>
    </form>
    <a href="collection_invoice.php" target="_blank" class="btn-secondary-custom" style="padding:8px 14px;font-size:12px">
      <i class="bi bi-printer"></i> All Items
    </a>
  </div>
</div>

<!-- STAT STRIP -->
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:24px">
  <?php foreach([
    ['bi-truck',          'rgba(22,163,74,.12)', '#16a34a', $cp_count,      'Total Collected', '#16a34a'],
    ['bi-calendar-check', 'rgba(37,99,235,.12)', '#2563eb', $cp_this_month, 'This Month',      '#2563eb'],
  ] as [$icon,$bg,$color,$val,$lbl,$border]): ?>
  <div style="background:var(--surface);border:1px solid var(--border);border-left:4px solid <?= $border ?>;border-radius:10px;padding:16px 20px;display:flex;align-items:center;gap:14px">
    <div style="width:42px;height:42px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">
      <i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i>
    </div>
    <div>
      <div style="font-size:<?= is_numeric($val) ? '26' : '15' ?>px;font-weight:800;color:var(--text);line-height:1"><?= $val ?></div>
      <div style="font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:3px"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- TABLE CARD -->
<?php if ($cp_count === 0): ?>
<div style="background:var(--surface);border:1.5px dashed var(--border);border-radius:14px;padding:64px 20px;text-align:center">
  <i class="bi bi-truck" style="font-size:36px;color:var(--muted);display:block;margin-bottom:14px"></i>
  <div style="font-weight:700;font-size:15px;color:var(--text);margin-bottom:4px">No items collected yet</div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:16px">Items marked as Collected in E-Waste will appear here</div>
  <a href="ewaste.php" class="btn-primary-custom" style="font-size:13px">
    <i class="bi bi-recycle"></i> Go to E-Waste
  </a>
</div>

<?php else: ?>
<div class="table-card">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;color:var(--muted)">
      <strong style="color:var(--text)"><?= $cp_count ?></strong> collected item<?= $cp_count !== 1 ? 's' : '' ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover" style="font-family:'Plus Jakarta Sans',sans-serif;margin:0">
      <thead><tr>
        <th style="width:36px">#</th>
        <th>Asset</th>
        <th>Serial No.</th>
        <th>Authorised By</th>
        <th>Date Collected</th>
        <th>Proof Doc</th>
        <th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($cp_rows as $i => $row):
        preg_match('/Proof: ([^\s|]+)/', $row['notes'] ?? '', $pm);
        $proof_file = $pm[1] ?? '';
        $has_proof  = $proof_file && file_exists($proof_file);
      ?>
      <tr>
        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
        <td>
          <div style="font-size:13px;font-weight:700;color:var(--text)"><?= h($row['description']) ?></div>
          <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
            <span style="background:rgba(59,130,246,.1);color:#2563eb;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:700"><?= h($row['asset_class']) ?></span>
            <?php if ($row['asset_number']): ?>
            <span style="font-size:12px;color:var(--accent);font-weight:600"><?= h($row['asset_number']) ?></span>
            <?php endif; ?>
          </div>
        </td>
        <td style="font-size:13px;color:var(--muted)"><?= h($row['serial_number'] ?: '—') ?></td>
        <td>
          <?php if ($row['writeoff_name']): ?>
          <div style="font-size:13px;font-weight:600;color:var(--text)"><?= h($row['writeoff_name']) ?></div>
          <?php if ($row['writeoff_designation']): ?>
          <div style="font-size:11px;color:var(--muted)"><?= h($row['writeoff_designation']) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <span style="font-size:13px;color:var(--muted)"><?= h($row['submitted_by'] ?: '—') ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($row['date_disposed']): ?>
          <div style="font-size:13px;font-weight:600;color:var(--text)"><?= date('d M Y', strtotime($row['date_disposed'])) ?></div>
          <div style="font-size:11px;color:var(--muted)"><?= date('l', strtotime($row['date_disposed'])) ?></div>
          <?php else: ?>
          <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($has_proof): ?>
          <a href="<?= h($proof_file) ?>" target="_blank"
            style="display:inline-flex;align-items:center;gap:5px;background:rgba(37,99,235,.08);color:#2563eb;border:1px solid rgba(37,99,235,.2);border-radius:7px;padding:5px 12px;font-size:12px;font-weight:700;text-decoration:none">
            <i class="bi bi-file-earmark-text"></i> View
          </a>
          <?php else: ?>
          <span style="display:inline-flex;align-items:center;gap:5px;background:var(--body-bg);color:var(--muted);border:1px solid var(--border);border-radius:7px;padding:5px 12px;font-size:12px;font-weight:600">
            <i class="bi bi-dash"></i> None
          </span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;align-items:center">
            <a href="collection_invoice.php?from=<?= urlencode($row['date_disposed'] ?? '') ?>&to=<?= urlencode($row['date_disposed'] ?? '') ?>" target="_blank"
              style="display:inline-flex;align-items:center;gap:4px;background:rgba(242,140,40,.1);color:var(--accent);border:1px solid rgba(242,140,40,.25);border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;text-decoration:none">
              <i class="bi bi-receipt"></i> Invoice
            </a>
            <a href="collected_proofs.php?action=undispose&id=<?= $row['id'] ?>"
              onclick="return confirm('Revert this item back to Approved?')"
              style="display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,.07);color:#dc2626;border:1px solid rgba(239,68,68,.2);border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;text-decoration:none">
              <i class="bi bi-arrow-counterclockwise"></i> Undo
            </a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
function resetToToday() {
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('invoiceFrom').value = today;
  document.getElementById('invoiceTo').value   = today;
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
