<?php
/**
 * customer/history.php – Personal Vault Access History
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Access History';
$activePage = 'history';

// Get customer
$custSt = $db->prepare('SELECT id FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

// Fetch Access Logs
$stmt = $db->prepare('
    SELECT al.*, l.locker_no 
    FROM access_logs al 
    JOIN lockers l ON al.locker_id = l.id 
    WHERE al.customer_id = ? 
    ORDER BY al.entry_time DESC
');
$stmt->execute([$cust['id']]);
$logs = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / History</div>
    
    <div class="page-header">
      <div>
        <h1>Vault Access History</h1>
        <p>Monitor your locker entry and exit timestamps for security auditing.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <div class="card">
       <div class="card-header">
          <h3><i class="fas fa-shield-halved text-primary"></i> Security Logs</h3>
       </div>
       <div class="card-body">
          <div class="table-container">
             <table class="tbl">
                <thead>
                   <tr>
                      <th>Locker</th>
                      <th>Entry Time</th>
                      <th>Exit Time</th>
                      <th>Method</th>
                      <th>Verified By</th>
                   </tr>
                </thead>
                <tbody>
                   <?php if (empty($logs)): ?>
                      <tr><td colspan="5" class="text-center py-4">No access history found.</td></tr>
                   <?php else: ?>
                      <?php foreach ($logs as $log): ?>
                         <tr>
                            <td><strong class="text-primary"><?= h($log['locker_no']) ?></strong></td>
                            <td><?= date('M d, Y - H:i', strtotime($log['entry_time'])) ?></td>
                            <td><?= $log['exit_time'] ? date('M d, Y - H:i', strtotime($log['exit_time'])) : '<span class="text-muted">In Progress</span>' ?></td>
                            <td>
                               <?php if ($log['qr_token_used']): ?>
                                  <span class="badge badge-verified"><i class="fas fa-qrcode"></i> QR</span>
                               <?php elseif ($log['otp_used']): ?>
                                  <span class="badge badge-verified"><i class="fas fa-key"></i> OTP</span>
                               <?php else: ?>
                                  <span class="text-muted" style="font-size:.8rem">Manual</span>
                               <?php endif; ?>
                            </td>
                            <td><?= h($log['staff_id'] ? 'Staff Access' : 'Auto-Scanned') ?></td>
                         </tr>
                      <?php endforeach; ?>
                   <?php endif; ?>
                </tbody>
             </table>
          </div>
       </div>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
