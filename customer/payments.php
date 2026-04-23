<?php
/**
 * customer/payments.php – Personal Transaction History
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Payment History';
$activePage = 'payments';

// Get customer
$custSt = $db->prepare('SELECT id FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

// Fetch Payments
$stmt = $db->prepare('
    SELECT p.*, l.locker_no
    FROM payments p 
    JOIN locker_assignments la ON p.assignment_id = la.id 
    JOIN lockers l ON la.locker_id = l.id 
    WHERE la.customer_id = ? 
    ORDER BY p.payment_date DESC
');
$stmt->execute([$cust['id']]);
$payments = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Payments</div>
    
    <div class="page-header">
      <div>
        <h1>Payment History</h1>
        <p>Review your transaction logs and download receipts.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <div class="card">
       <div class="card-header">
          <h3><i class="fas fa-history text-primary"></i> All Transactions</h3>
       </div>
       <div class="card-body">
          <div class="table-container">
             <table class="tbl">
                <thead>
                   <tr>
                      <th>Invoice</th>
                      <th>Locker</th>
                      <th>Plan</th>
                      <th>Amount</th>
                      <th>Date</th>
                      <th>Status</th>
                      <th>Action</th>
                   </tr>
                </thead>
                <tbody>
                   <?php if (empty($payments)): ?>
                      <tr><td colspan="7" class="text-center py-4">No payment records found.</td></tr>
                   <?php else: ?>
                      <?php foreach ($payments as $p): ?>
                         <tr>
                            <td><strong class="text-primary"><?= h($p['invoice_no']) ?></strong></td>
                            <td><?= h($p['locker_no']) ?></td>
                            <td><?= h($p['plan_type']) ?></td>
                            <td>₹<?= number_format($p['amount'], 2) ?></td>
                            <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                            <td><span class="badge badge-verified">Paid</span></td>
                            <td>
                               <a href="receipt.php?inv=<?= h($p['invoice_no']) ?>" class="btn btn-ghost btn-sm">
                                  <i class="fas fa-file-pdf"></i> Receipt
                               </a>
                            </td>
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
