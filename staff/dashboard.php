<?php
/**
 * staff/dashboard.php — Staff Dashboard
 * Quick overview and access controls.
 */
require_once '../config/config.php';
requireRole('admin','staff');

$db         = getDB();
$pageTitle  = 'Staff Dashboard';
$activePage = 'dashboard';
$user       = currentUser();

// Stats
$stats = $db->query('SELECT
  COUNT(*) AS total,
  SUM(status="Available") AS available,
  SUM(status="Occupied")  AS occupied
  FROM lockers')->fetch();

$todayAccess  = $db->query('SELECT COUNT(*) FROM access_logs WHERE DATE(entry_time)=CURDATE() AND access_status="Success"')->fetchColumn();
$pendingPayments = $db->query('SELECT COUNT(*) FROM payments WHERE status="Pending"')->fetchColumn();
$pendingApps = $db->query('SELECT COUNT(*) FROM locker_requests WHERE status IN ("Pending","Verified")')->fetchColumn();

// Recent Access Logs
$recentLogs = $db->query(
  'SELECT ac.*, u.full_name, l.locker_no
   FROM access_logs ac
   JOIN customers c ON c.id=ac.customer_id
   JOIN users u ON u.id=c.user_id
   JOIN lockers l ON l.id=ac.locker_id
   ORDER BY ac.entry_time DESC LIMIT 10'
)->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-content">
  <div class="page-content">
    <div class="page-header">
      <h1>Welcome, <?= h(explode(' ', $user['full_name'])[0]) ?> 👋</h1>
      <p>Staff Dashboard — quick overview and access controls.</p>
    </div>

    <!-- STATS WIDGETS -->
    <div class="stats-grid">
      
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-lock"></i></div>
        <div class="stat-info">
          <h4>Total Lockers</h4>
          <div class="value"><?= (int)$stats['total'] ?></div>
          <div class="stat-trend up"><i class="fas fa-layer-group"></i> Total Capacity</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-lock-open"></i></div>
        <div class="stat-info">
          <h4>Available</h4>
          <div class="value"><?= (int)$stats['available'] ?></div>
          <div class="stat-trend up"><i class="fas fa-check-circle"></i> Ready to Assign</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-lock"></i></div>
        <div class="stat-info">
          <h4>Occupied</h4>
          <div class="value"><?= (int)$stats['occupied'] ?></div>
          <div class="stat-trend down"><i class="fas fa-user-lock"></i> Currently in Use</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-door-open"></i></div>
        <div class="stat-info">
          <h4>Today's Accesses</h4>
          <div class="value"><?= (int)$todayAccess ?></div>
          <div class="stat-trend up"><i class="fas fa-clock"></i> Successful Entries</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-credit-card"></i></div>
        <div class="stat-info">
          <h4>Pending Payments</h4>
          <div class="value"><?= (int)$pendingPayments ?></div>
          <div class="stat-trend down"><i class="fas fa-triangle-exclamation"></i> Action Required</div>
        </div>
      </div>

    </div>

    <!-- QUICK ACTIONS -->
    <div class="grid-3" style="gap:20px; margin-bottom:30px;">
        <a href="../admin/lockers.php" class="card" style="text-align:center; text-decoration:none; padding:40px 20px; border-bottom: 3px solid transparent; transition: all .3s ease;" onmouseover="this.style.borderColor='var(--teal)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='transparent'; this.style.transform='translateY(0)'">
            <i class="fas fa-lock" style="font-size:2.5rem; color:var(--teal); margin-bottom:20px; display:block;"></i>
            <h3 style="color:var(--text-muted); font-size:1.1rem; font-weight:500;">Manage Lockers</h3>
        </a>
        <a href="../admin/customers.php" class="card" style="text-align:center; text-decoration:none; padding:40px 20px; border-bottom: 3px solid transparent; transition: all .3s ease;" onmouseover="this.style.borderColor='var(--teal)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='transparent'; this.style.transform='translateY(0)'">
            <i class="fas fa-users" style="font-size:2.5rem; color:var(--teal); margin-bottom:20px; display:block;"></i>
            <h3 style="color:var(--text-muted); font-size:1.1rem; font-weight:500;">Manage Customers</h3>
        </a>
        <a href="../admin/access_logs.php" class="card" style="text-align:center; text-decoration:none; padding:40px 20px; border-bottom: 3px solid transparent; transition: all .3s ease;" onmouseover="this.style.borderColor='var(--teal)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='transparent'; this.style.transform='translateY(0)'">
            <i class="fas fa-shield-halved" style="font-size:2.5rem; color:var(--teal); margin-bottom:20px; display:block;"></i>
            <h3 style="color:var(--text-muted); font-size:1.1rem; font-weight:500;">Access Control</h3>
        </a>
    </div>

    <!-- RECENT ACCESS LOGS -->
    <div class="card">
      <div class="card-header">
          <h3><i class="fas fa-clock-rotate-left text-primary"></i> Recent Access Logs</h3>
      </div>
      <div class="table-container">
        <table class="tbl">
          <thead>
            <tr>
              <th>CUSTOMER</th>
              <th>LOCKER</th>
              <th>ENTRY</th>
              <th>EXIT</th>
              <th>AUTH METHOD</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($recentLogs as $l): ?>
            <tr>
              <td><strong><?= h($l['full_name']) ?></strong></td>
              <td><span class="badge badge-verified"><?= h($l['locker_no']) ?></span></td>
              <td><span style="font-size:0.9rem; color:var(--text-muted);"><?= date('d M H:i', strtotime($l['entry_time'])) ?></span></td>
              <td><?= $l['exit_time'] ? '<span style="font-size:0.9rem; color:var(--text-muted);">'.date('d M H:i', strtotime($l['exit_time'])).'</span>' : '<span class="badge badge-pending">Inside</span>' ?></td>
              <td>
                <div style="font-size:0.9rem;">
                <?php if ($l['qr_token_used']): ?>
                    <span class="text-primary"><i class="fas fa-qrcode"></i> QR Token</span>
                <?php elseif ($l['biometric_ok']): ?>
                    <span class="text-primary"><i class="fas fa-fingerprint"></i> Biometric</span>
                <?php elseif ($l['otp_used']): ?>
                    <span class="text-primary"><i class="fas fa-message"></i> SMS</span>
                <?php else: ?>
                    <span class="text-muted">Manual</span>
                <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$recentLogs): ?>
            <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:30px;">No logs yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>
