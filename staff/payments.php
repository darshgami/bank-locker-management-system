<?php
/**
 * staff/payments.php – View all customer payment transactions
 */
require_once '../config/config.php';
requireRole('admin', 'staff');

$db = getDB();
$pageTitle = 'Transaction History';
$activePage = 'payments';
$user = currentUser();

// Filter
$statusFilter = $_GET['status'] ?? 'all';
$where = '';
if ($statusFilter !== 'all') {
    $where = "WHERE p.status = " . $db->quote($statusFilter);
}

// Fetch all payments with customer + locker info
$payments = $db->query("
    SELECT p.*, l.locker_no, l.size, u.full_name AS customer_name, u.phone AS customer_phone
    FROM payments p
    JOIN locker_assignments la ON la.id = p.assignment_id
    JOIN lockers l ON l.id = la.locker_id
    JOIN customers c ON c.id = la.customer_id
    JOIN users u ON u.id = c.user_id
    $where
    ORDER BY p.created_at DESC
    LIMIT 100
")->fetchAll();

// Stats
$totalPaid = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn();
$totalPending = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Pending'")->fetchColumn();
$totalOverdue = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Overdue'")->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Payments
    </div>
    
    <div class="page-header">
      <div>
        <h1>Payment & Transaction History</h1>
        <p>View all locker rental payments across customers.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-indian-rupee-sign"></i></div>
        <div class="stat-info">
          <h4>Total Collected</h4>
          <div class="value">₹<?= number_format($totalPaid, 0) ?></div>
          <div class="stat-trend up"><i class="fas fa-check-circle"></i> Success</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
          <h4>Pending Payments</h4>
          <div class="value">₹<?= number_format($totalPending, 0) ?></div>
          <div class="stat-trend down"><i class="fas fa-triangle-exclamation"></i> Upcoming</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-info">
          <h4>Overdue Amount</h4>
          <div class="value">₹<?= number_format($totalOverdue, 0) ?></div>
          <div class="stat-trend down"><i class="fas fa-circle-xmark"></i> Immediate Action</div>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
      <a href="?status=all" class="filter-pill <?= $statusFilter==='all'?'active':'' ?>">All</a>
      <a href="?status=Paid" class="filter-pill <?= $statusFilter==='Paid'?'active':'' ?>">Paid</a>
      <a href="?status=Pending" class="filter-pill <?= $statusFilter==='Pending'?'active':'' ?>">Pending</a>
      <a href="?status=Overdue" class="filter-pill <?= $statusFilter==='Overdue'?'active':'' ?>">Overdue</a>
      <div class="search-wrapper ms-auto" style="min-width:220px">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="paymentSearch" class="form-control" placeholder="Search payments…">
      </div>
    </div>

    <!-- Payments Table -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar text-teal"></i> All Transactions <small style="color:var(--text-muted);font-weight:400">(<?= count($payments) ?>)</small></h3>
      </div>
      <div class="table-container">
        <table class="tbl" id="paymentsTable">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Customer</th>
              <th>Locker</th>
              <th>Amount</th>
              <th>Date</th>
              <th>Due Date</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
            <tr>
              <td><strong style="color:var(--text-primary);"><?= h($p['invoice_no'] ?: 'N/A') ?></strong></td>
              <td>
                <strong><?= h($p['customer_name']) ?></strong><br>
                <span style="font-size:0.8rem; color:var(--text-muted);"><?= h($p['customer_phone']) ?></span>
              </td>
              <td>
                <span class="badge badge-pending"><?= h($p['locker_no']) ?></span>
                <span style="font-size:0.75rem; color:var(--text-muted); display:block; margin-top:3px;"><?= h($p['size']) ?></span>
              </td>
              <td style="font-weight:600;">₹<?= number_format($p['amount'], 0) ?></td>
              <td style="font-size:0.9rem; color:var(--text-muted);"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
              <td style="font-size:0.9rem; color:var(--text-muted);"><?= $p['due_date'] ? date('d M Y', strtotime($p['due_date'])) : '—' ?></td>
              <td>
                <?php
                    $badge = 'pending';
                    if ($p['status'] === 'Paid') $badge = 'verified';
                    if ($p['status'] === 'Overdue') $badge = 'rejected';
                ?>
                <span class="badge badge-<?= $badge ?>"><?= h($p['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-receipt" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                No payment records found.
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php $extraJS = '<script>tableSearch("paymentSearch","paymentsTable");</script>';
include '../includes/footer.php'; ?>
