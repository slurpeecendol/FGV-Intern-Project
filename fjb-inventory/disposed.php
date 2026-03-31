<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';

$db = getDB();
$msg = $err = '';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ── RESTORE (set back to Active) ──
if ($action === 'restore' && isAdmin() && $id) {
    $item = $db->query("SELECT description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("UPDATE inventory_items SET item_status='Active' WHERE id=$id");
        logActivity($_SESSION['user_id'], 'RESTORE', 'inventory', $id, 'Restored asset: '.$item['description']);
        header('Location: disposed.php?msg=restored'); exit;
    }
}

// ── PERMANENT DELETE ──
if ($action === 'delete' && isAdmin() && $id) {
    $item = $db->query("SELECT description FROM inventory_items WHERE id=$id")->fetch_assoc();
    if ($item) {
        $db->query("DELETE FROM inventory_items WHERE id=$id");
        logActivity($_SESSION['user_id'], 'DELETE', 'inventory', $id, 'Permanently deleted: '.$item['description']);
        header('Location: disposed.php?msg=deleted'); exit;
    }
}

// Fetch all disposed items
$items = $db->query("SELECT i.*, u.full_name as added_by FROM inventory_items i LEFT JOIN users u ON i.created_by=u.id WHERE i.item_status='Disposed' ORDER BY i.updated_at DESC");
$total = $db->query("SELECT COUNT(*) c FROM inventory_items WHERE item_status='Disposed'")->fetch_assoc()['c'];

$url_msg = $_GET['msg'] ?? '';
if ($url_msg === 'added')    $msg = 'Asset marked as disposed.';
if ($url_msg === 'restored') $msg = 'Asset restored to Active successfully.';
if ($url_msg === 'deleted')  $msg = 'Asset permanently deleted.';

$page_title = 'Disposed Items'; $active_nav = 'disposed';
require_once 'includes/layout.php';
?>

<?php if ($msg): ?><div class="alert-success-custom"><i class="bi bi-check-circle-fill"></i><?= h($msg) ?></div><?php endif; ?>

<!-- INFO BANNER -->
<div style="background:rgba(248,81,73,.07);border:1px solid rgba(248,81,73,.2);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:var(--text)">
  <i class="bi bi-archive-fill" style="color:var(--red);font-size:20px;flex-shrink:0"></i>
  <span>These are IT assets that have been marked as <strong>Disposed</strong>. Admins can restore them to Active or permanently delete them.</span>
  <span style="margin-left:auto;background:rgba(248,81,73,.15);color:var(--red);border-radius:20px;padding:3px 14px;font-size:12px;font-weight:700;flex-shrink:0"><?= $total ?> item<?= $total !== 1 ? 's' : '' ?></span>
</div>

<!-- TABLE -->
<div class="table-card">
  <div class="table-card-header">
    <div class="table-card-title">
      <i class="bi bi-archive me-2" style="color:var(--red)"></i>Disposed Assets
    </div>
    <a href="inventory.php" class="btn-secondary-custom" style="font-size:12px;padding:6px 14px;">
      <i class="bi bi-arrow-left"></i> Back to IT Assets
    </a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table">
      <thead><tr>
        <th>#</th>
        <th>Asset No.</th>
        <th>Class</th>
        <th>Description</th>
        <th>Serial No.</th>
        <th>Brand</th>
        <th>Location</th>
        <th>Condition</th>
        <th>Date Disposed</th>
        <th>Notes</th>
        <?php if (isAdmin()): ?><th>Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php
      $i = 1;
      while ($row = $items->fetch_assoc()):
      ?>
      <tr>
        <td ><?= $i++ ?></td>
        <td><code style="color:var(--red);font-size:12px"><?= h($row['asset_number'] ?: '—') ?></code></td>
        <td style="font-size:12px"><?= h($row['asset_class']) ?></td>
        <td style="max-width:220px"><?= h($row['description']) ?></td>
        <td><code style="font-size:11px;color:var(--muted)"><?= h($row['serial_number'] ?: '—') ?></code></td>
        <td style="font-size:13px"><?= h($row['brand'] ?: '—') ?></td>
        <td style="font-size:13px"><?= h($row['location'] ?: '—') ?></td>
        <td>
          <?php
          $cc = ['Good'=>'bs-active','Fair'=>'bs-pending','Poor'=>'bs-repair','Damaged'=>'bs-disposed'];
          echo '<span class="badge-status '.($cc[$row['condition_status']] ?? '').'">'.$row['condition_status'].'</span>';
          ?>
        </td>
        <td style="font-size:12px">
          <?= $row['updated_at'] ? date('d/m/Y', strtotime($row['updated_at'])) : '—' ?>
        </td>
        <td style="font-size:12px;max-width:160px">
          <?= h($row['notes'] ?: '—') ?>
        </td>
        <?php if (isAdmin()): ?>
        <td>
          <div class="d-flex gap-1">
            <!-- Restore to Active -->
            <a href="disposed.php?action=restore&id=<?= $row['id'] ?>"
               class="btn-icon btn-view" title="Restore to Active"
               onclick="return confirm('Restore this asset back to Active?')"
               style="background:rgba(var(--accent-rgb),.1);color:var(--accent)">
              <i class="bi bi-arrow-counterclockwise"></i>
            </a>
            <!-- Permanent Delete -->
            <a href="disposed.php?action=delete&id=<?= $row['id'] ?>"
               class="btn-icon btn-delete" title="Permanently Delete"
               onclick="return confirm('Permanently delete this asset? This cannot be undone.')">
              <i class="bi bi-trash"></i>
            </a>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
