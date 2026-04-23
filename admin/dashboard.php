<?php
/**
 * admin/dashboard.php – Admin Dashboard
 * Shows stats, charts, recent activity
 */
require_once '../config/config.php';
requireRole('admin');

$db        = getDB();
$pageTitle = 'Dashboard';
$activePage= 'dashboard';

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query('SELECT
  COUNT(*)                                           AS total_lockers,
  SUM(status="Available")                            AS available,
  SUM(status="Occupied")                             AS occupied,
  SUM(status="Maintenance")                          AS maintenance
  FROM lockers')->fetch();

$totalCustomers = $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$totalRevenue   = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="Paid"')->fetchColumn();
$pendingAmount  = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="Pending"')->fetchColumn();

// ── Monthly Revenue (last 6 months) ──────────────────────────────────────────
$monthRevenue = $db->query(
  'SELECT DATE_FORMAT(payment_date,"%b %Y") AS mon, SUM(amount) AS total
   FROM payments WHERE status="Paid" AND payment_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
   GROUP BY DATE_FORMAT(payment_date,"%Y-%m")
   ORDER BY payment_date ASC'
)->fetchAll();

// ── Payment breakdown last 6 months ──────────────────────────────────────────
$payBreak = $db->query(
  'SELECT DATE_FORMAT(payment_date,"%b") AS mon,
          SUM(status="Paid") AS paid,
          SUM(status="Pending") AS pending,
          SUM(status="Overdue") AS overdue
   FROM payments
   WHERE payment_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
   GROUP BY DATE_FORMAT(payment_date,"%Y-%m")
   ORDER BY payment_date ASC'
)->fetchAll();

// ── Recent activity ────────────────────────────────────────────────────────────
$recentActivity = $db->query(
  'SELECT al.*, u.full_name FROM activity_logs al
   LEFT JOIN users u ON u.id=al.user_id
   ORDER BY al.created_at DESC LIMIT 12'
)->fetchAll();

// ── Recent Access Logs ─────────────────────────────────────────────────────────
$recentAccess = $db->query(
  'SELECT ac.*, u.full_name, l.locker_no
   FROM access_logs ac
   JOIN customers c  ON c.id  = ac.customer_id
   JOIN users u      ON u.id  = c.user_id
   JOIN lockers l    ON l.id  = ac.locker_id
   ORDER BY ac.entry_time DESC LIMIT 8'
)->fetchAll();

// Prepare JS chart data
$revLabels  = json_encode(array_column($monthRevenue, 'mon'));
$revValues  = json_encode(array_map('floatval', array_column($monthRevenue, 'total')));
$payLabels  = json_encode(array_column($payBreak, 'mon'));
$paidArr    = json_encode(array_map('intval', array_column($payBreak, 'paid')));
$pendArr    = json_encode(array_map('intval', array_column($payBreak, 'pending')));
$overdArr   = json_encode(array_map('intval', array_column($payBreak, 'overdue')));

// Fetch Emergency Lock Setting
$sysSetting = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='emergency_global_lock'")->fetchColumn();
$isEmergencyMode = ($sysSetting === '1');
$payLabels  = json_encode(array_column($payBreak, 'mon'));
$paidArr    = json_encode(array_map('intval', array_column($payBreak, 'paid')));
$pendArr    = json_encode(array_map('intval', array_column($payBreak, 'pending')));
$overdArr   = json_encode(array_map('intval', array_column($payBreak, 'overdue')));

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- MAIN -->
<div class="main-content">
  <div class="page-content">
    <div class="emergency-action-bar mb-4" style="justify-content: flex-end; display: flex;">
      <!-- Emergency Toggle Form -->
      <form action="api/toggle_emergency.php" method="POST" onsubmit="return confirm('Are you sure you want to toggle emergency mode? This will lock OUT all users.');">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_generate()) ?>">
          <input type="hidden" name="current_status" value="<?= $isEmergencyMode ? '1' : '0' ?>">
          <?php if ($isEmergencyMode): ?>
              <button type="submit" class="btn btn-sm btn-red" title="Click to disable emergency mode">
                  <i class="fas fa-lock"></i> EMERGENCY LOCK ON
              </button>
          <?php else: ?>
              <button type="submit" class="btn btn-sm btn-ghost" title="Click to activate global lock">
                  <i class="fas fa-unlock"></i> Global Lock Off
              </button>
          <?php endif; ?>
      </form>
    </div>

    <!-- Breadcrumb -->
    <div class="breadcrumb-nav">
      <a href="#">Home</a> / Dashboard
    </div>
    <div class="page-header">
      <h1>Admin Dashboard</h1>
      <p>Welcome back, <b><?= h(currentUser()['full_name']) ?></b>! Here's what's happening today.</p>
    </div>

    <!-- Stat Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-lock"></i></div>
        <div class="stat-info">
          <h4>Total Lockers</h4>
          <div class="value"><?= (int)$stats['total_lockers'] ?></div>
          <div class="stat-trend"><i class="fas fa-circle-info"></i> All branches</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-lock-open"></i></div>
        <div class="stat-info">
          <h4>Available</h4>
          <div class="value"><?= (int)$stats['available'] ?></div>
          <div class="stat-trend up"><i class="fas fa-arrow-up"></i> Ready</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-lock"></i></div>
        <div class="stat-info">
          <h4>Occupied</h4>
          <div class="value"><?= (int)$stats['occupied'] ?></div>
          <div class="stat-trend down"><i class="fas fa-user-check"></i> Active</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-wrench"></i></div>
        <div class="stat-info">
          <h4>Maintenance</h4>
          <div class="value"><?= (int)$stats['maintenance'] ?></div>
          <div class="stat-trend down"><i class="fas fa-wrench"></i> Service</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div class="stat-info">
          <h4>Customers</h4>
          <div class="value"><?= (int)$totalCustomers ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-indian-rupee-sign"></i></div>
        <div class="stat-info">
          <h4>Revenue</h4>
          <div class="value">₹<?= number_format($totalRevenue, 0) ?></div>
          <div class="stat-trend"><i class="fas fa-clock"></i> ₹<?= number_format($pendingAmount, 0) ?> pending</div>
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="grid-2 mb-2" style="gap:20px">
      <!-- Locker Status Doughnut -->
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-chart-pie text-teal"></i> Locker Status</h3>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:240px">
            <canvas id="lockerStatusChart"></canvas>
          </div>
        </div>
      </div>
      <!-- Revenue Line -->
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-chart-line text-teal"></i> Monthly Revenue (6 Months)</h3>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:240px">
            <canvas id="revenueChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Status Bar Chart -->
    <div class="card mb-4">
      <div class="card-header">
        <h3><i class="fas fa-chart-bar text-primary"></i> Payment Status (6 Months)</h3>
        <a href="payments.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <div class="card-body">
        <div class="chart-wrap" style="height:220px">
          <canvas id="paymentStatusChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Bottom Grid -->
    <div class="grid-2">
      <!-- Recent Access Logs -->
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-shield-halved text-primary"></i> Recent Access Logs</h3>
          <a href="access_logs.php" class="btn btn-ghost btn-sm">All Logs</a>
        </div>
        <div class="table-container">
          <table class="tbl">
            <thead><tr>
              <th>Customer</th><th>Locker</th><th>Entry</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recentAccess as $ac): ?>
              <tr>
                <td><?= h($ac['full_name']) ?></td>
                <td><span class="badge badge-available"><?= h($ac['locker_no']) ?></span></td>
                <td style="font-size:.8rem;color:var(--text-muted)"><?= date('d M H:i', strtotime($ac['entry_time'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentAccess): ?>
              <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:20px">No access logs yet</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Activity Log -->
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-clock-rotate-left text-primary"></i> Activity Log</h3>
        </div>
        <div class="card-body p-0" style="max-height:320px; overflow-y:auto;">
          <?php foreach ($recentActivity as $act): ?>
          <div class="activity-item" style="display:flex; gap:var(--sp-2); padding:var(--sp-2) var(--sp-3); border-bottom:1px solid var(--border); align-items:flex-start;">
            <div style="width:8px; height:8px; border-radius:50%; background:var(--primary); margin-top:6px; flex-shrink:0;"></div>
            <div>
              <div style="font-size:0.85rem; color:var(--text-main); font-weight:600;"><?= h($act['action']) ?></div>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                <?= h($act['full_name'] ?? 'System') ?> · <?= date('d M H:i', strtotime($act['created_at'])) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$recentActivity): ?>
            <p style="text-align:center; color:var(--text-muted); padding:var(--sp-4);">No activity yet</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /.page-content -->
</div><!-- /.main-content -->

<?php
$extraJS = <<<JS
<script>
initDashboardCharts({
  lockerStatus: {
    available:   {$stats['available']},
    occupied:    {$stats['occupied']},
    maintenance: {$stats['maintenance']}
  },
  revenue: {
    labels: {$revLabels},
    values: {$revValues}
  },
  payments: {
    labels:  {$payLabels},
    paid:    {$paidArr},
    pending: {$pendArr},
    overdue: {$overdArr}
  }
});
</script>
JS;
include '../includes/footer.php';
?>
