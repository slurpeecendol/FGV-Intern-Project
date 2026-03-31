<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
requireAdmin();

$db = getDB();
$logs = $db->query("SELECT al.*, u.full_name, u.username FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 200");

$page_title = 'Activity Log'; $active_nav = 'activity';
require_once 'includes/layout.php';
?>

<div class="table-card">
  <div class="table-card-header">
    <div class="table-card-title"><i class="bi bi-clock-history me-2" style="color:var(--green)"></i>System Activity Log</div>
    <span style="font-size:12px;color:var(--muted)">Last 200 entries</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover data-table">
      <thead><tr><th>#</th><th>User</th><th>Action</th><th>Type</th><th>Description</th><th>IP Address</th><th>Timestamp</th></tr></thead>
      <tbody>
      <?php $i=1; while ($log=$logs->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--muted)"><?= $i++ ?></td>
        <td>
          <div style="font-size:13px"><?= h($log['full_name'] ?? 'System') ?></div>
          <div style="font-size:11px;color:var(--muted)"><?= h($log['username'] ?? '') ?></div>
        </td>
        <td>
          <?php
          $ac = ['CREATE'=>'bs-active','UPDATE'=>'bs-repair','DELETE'=>'bs-disposed','LOGIN'=>'bs-collected','LOGOUT'=>'bs-pending','FLAGGED_EWASTE'=>'bs-disposed','USER_TOGGLE'=>'bs-repair'];
          echo '<span class="badge-status '.($ac[$log['action']] ?? '').'">'.$log['action'].'</span>';
          ?>
        </td>
        <td style="font-size:12px;color:var(--muted);text-transform:uppercase"><?= h($log['item_type']) ?></td>
        <td style="max-width:280px;font-size:13px"><?= h($log['description']) ?></td>
        <td><code style="font-size:11px;color:var(--muted)"><?= h($log['ip_address']) ?></code></td>
        <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
