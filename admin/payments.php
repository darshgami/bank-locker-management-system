<?php
/**
 * admin/payments.php — Payment Module
 * Record payments, view history, generate invoice
 */
require_once '../config/config.php';
requireRole('admin','staff');

$db         = getDB();
$pageTitle  = 'Payments';
$activePage = 'payments';
$csrf       = csrf_generate();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF error.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // ── ADD PAYMENT ──
        if ($action === 'add_payment') {
            $aid    = (int)$_POST['assignment_id'];
            $amount = (float)$_POST['amount'];
            $pdate  = $_POST['payment_date'] ?? date('Y-m-d');
            $due    = $_POST['due_date'] ?? date('Y-m-d', strtotime('+1 month'));
            $mode   = $_POST['payment_mode'] ?? 'Cash';
            $status = $_POST['status'] ?? 'Paid';
            $txn    = trim($_POST['transaction_id'] ?? '');
            $rem    = trim($_POST['remarks'] ?? '');

            // GST Calculation (18% Inclusive)
            $taxPercent = 18.00;
            $baseAmount = round($amount / (1 + ($taxPercent / 100)), 2);
            $taxAmount  = $amount - $baseAmount;
            $otherFees  = 0.00; // Future expandability

            // auto invoice number
            $last   = $db->query('SELECT MAX(id) FROM payments')->fetchColumn();
            $inv    = 'INV-' . str_pad((int)$last+1, 5, '0', STR_PAD_LEFT);
            $db->prepare('INSERT INTO payments (assignment_id,amount,base_amount,tax_percent,tax_amount,other_fees,payment_date,due_date,payment_mode,status,transaction_id,invoice_no,remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$aid,$amount,$baseAmount,$taxPercent,$taxAmount,$otherFees,$pdate,$due,$mode,$status,$txn,$inv,$rem]);
            // recalculate risk
            $st2 = $db->prepare('SELECT customer_id FROM locker_assignments WHERE id=?'); $st2->execute([$aid]);
            $cid = (int)$st2->fetchColumn();
            if ($cid) recalcRisk($cid);
            logActivity("Payment recorded INV $inv", 'Payments');
            $msg = "Payment recorded. Invoice: $inv";
        }

        // ── UPDATE STATUS ──
        elseif ($action === 'update_status') {
            $pid    = (int)$_POST['payment_id'];
            $status = $_POST['status'] ?? 'Paid';
            $db->prepare('UPDATE payments SET status=? WHERE id=?')->execute([$status,$pid]);
            // recalc risk
            $st2 = $db->prepare('SELECT la.customer_id FROM payments p JOIN locker_assignments la ON la.id=p.assignment_id WHERE p.id=?');
            $st2->execute([$pid]);
            $cid = (int)$st2->fetchColumn();
            if ($cid) recalcRisk($cid);
            $msg = "Payment status updated.";
        }
    }
}

// Fetch assignments (for dropdown)
$assignments = $db->query(
  'SELECT la.id, l.locker_no, u.full_name, l.rent_amount
   FROM locker_assignments la
   JOIN lockers l ON l.id=la.locker_id
   JOIN customers c ON c.id=la.customer_id
   JOIN users u ON u.id=c.user_id
   WHERE la.is_active=1
   ORDER BY u.full_name'
)->fetchAll();

// Fetch payments
$filter  = $_GET['filter'] ?? 'all';
$where   = $filter !== 'all' ? "WHERE p.status='$filter'" : '';
$payments = $db->query(
  "SELECT p.*, la.id AS la_id, l.locker_no, u.full_name,
          c.id AS cust_id
   FROM payments p
   JOIN locker_assignments la ON la.id=p.assignment_id
   JOIN lockers l ON l.id=la.locker_id
   JOIN customers c ON c.id=la.customer_id
   JOIN users u ON u.id=c.user_id
   $where
   ORDER BY p.created_at DESC LIMIT 200"
)->fetchAll();

$totalPaid    = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="Paid"')->fetchColumn();
$totalPending = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="Pending"')->fetchColumn();
$totalOverdue = $db->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status="Overdue"')->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="page-header">
      <div>
        <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Payments</div>
        <h1>Payment Module</h1>
        <p>Record locker rent payments and generate invoices.</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" onclick="openModal('addPayModal')">
          <i class="fas fa-plus"></i> Record Payment
        </button>
        <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
        <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i> <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Summary -->
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
          <h4>Pending</h4>
          <div class="value">₹<?= number_format($totalPending, 0) ?></div>
          <div class="stat-trend"><i class="fas fa-hourglass-start"></i> Waiting</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-calendar-xmark"></i></div>
        <div class="stat-info">
          <h4>Overdue</h4>
          <div class="value">₹<?= number_format($totalOverdue, 0) ?></div>
          <div class="stat-trend down"><i class="fas fa-triangle-exclamation"></i> Action Required</div>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
      <div style="display:flex; gap:var(--sp-2);">
        <a href="?filter=all"     class="filter-pill <?= $filter==='all'?'active':'' ?>">All Payments</a>
        <a href="?filter=Paid"    class="filter-pill <?= $filter==='Paid'?'active':'' ?>">Paid</a>
        <a href="?filter=Pending" class="filter-pill <?= $filter==='Pending'?'active':'' ?>">Pending</a>
        <a href="?filter=Overdue" class="filter-pill <?= $filter==='Overdue'?'active':'' ?>">Overdue</a>
      </div>
      <div class="search-wrapper ms-auto">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="paySearch" class="form-control" placeholder="Search invoice, customer…">
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-credit-card text-primary"></i> Payment Transactions</h3>
      </div>
      <div class="table-container">
        <table class="tbl" id="payTable">
          <thead><tr>
            <th>Invoice</th><th>Customer</th><th>Locker</th><th>Amount</th>
            <th>Date</th><th>Due</th><th>Mode</th><th>Status</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td><code style="color:var(--teal)"><?= h($p['invoice_no']) ?></code></td>
            <td><?= h($p['full_name']) ?></td>
            <td><span class="badge badge-available"><?= h($p['locker_no']) ?></span></td>
            <td><b>₹<?= number_format($p['amount'],2) ?></b></td>
            <td style="font-size:.8rem"><?= date('d M Y',strtotime($p['payment_date'])) ?></td>
            <td style="font-size:.8rem;color:<?= strtotime($p['due_date'])<time()&&$p['status']!=='Paid'?'var(--red)':'var(--text-muted)' ?>">
              <?= date('d M Y',strtotime($p['due_date'])) ?>
            </td>
            <td style="color:var(--text-muted);font-size:.8rem"><?= h($p['payment_mode']) ?></td>
            <td><span class="badge badge-<?= strtolower($p['status']) ?>"><?= h($p['status']) ?></span></td>
            <td>
              <div style="display:flex;gap:6px">
                <!-- Invoice -->
                <a href="../customer/receipt.php?inv=<?= urlencode($p['invoice_no']) ?>" target="_blank" class="btn btn-ghost btn-icon btn-sm" title="Print Receipt">
                  <i class="fas fa-file-invoice"></i>
                </a>
                <!-- Update status -->
                <button class="btn btn-ghost btn-icon btn-sm" title="Edit status"
                        onclick='openStatusModal(<?= (int)$p["id"] ?>, "<?= h($p["status"]) ?>")'>
                  <i class="fas fa-pen"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">No payments found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ ADD PAYMENT MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addPayModal">
  <div class="modal-box">
    <div class="modal-header">
      <h4><i class="fas fa-plus text-teal"></i> Record Payment</h4>
      <button class="modal-close" onclick="closeModal('addPayModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group">
          <label>Locker Assignment *</label>
          <select name="assignment_id" id="assignSelect" class="form-select" required
                  onchange="fillRent(this)">
            <option value="">— Select Active Assignment —</option>
            <?php foreach ($assignments as $a): ?>
              <option value="<?= (int)$a['id'] ?>" data-rent="<?= h($a['rent_amount']) ?>">
                <?= h($a['full_name']) ?> → <?= h($a['locker_no']) ?> (₹<?= number_format($a['rent_amount'],0) ?>/mo)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Amount (₹) *</label>
            <input type="number" step="0.01" name="amount" id="payAmount" class="form-control" required placeholder="0.00">
          </div>
          <div class="form-group">
            <label>Payment Mode</label>
            <select name="payment_mode" class="form-select">
              <option>Cash</option><option>UPI</option><option>NetBanking</option><option>Card</option><option>Cheque</option>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Payment Date *</label>
            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Due Date *</label>
            <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d',strtotime('+1 month')) ?>" required>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-select">
              <option value="Paid">Paid</option>
              <option value="Pending">Pending</option>
              <option value="Overdue">Overdue</option>
            </select>
          </div>
          <div class="form-group">
            <label>Transaction ID</label>
            <input type="text" name="transaction_id" class="form-control" placeholder="UPI / Ref no.">
          </div>
        </div>
        <div class="form-group">
          <label>Remarks</label>
          <input type="text" name="remarks" class="form-control" placeholder="Optional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addPayModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ UPDATE STATUS MODAL ══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="statusModal">
  <div class="modal-box" style="max-width:380px">
    <div class="modal-header">
      <h4>Update Payment Status</h4>
      <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="payment_id" id="statusPayId">
        <div class="form-group">
          <label>New Status</label>
          <select name="status" id="statusSelect" class="form-select">
            <option value="Paid">Paid</option>
            <option value="Pending">Pending</option>
            <option value="Overdue">Overdue</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('statusModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<?php $extraJS = '<script>
tableSearch("paySearch","payTable");
function fillRent(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById("payAmount").value = opt.dataset.rent || "";
}
function openStatusModal(id, status) {
  document.getElementById("statusPayId").value = id;
  document.getElementById("statusSelect").value = status;
  openModal("statusModal");
}
</script>';
include '../includes/footer.php'; ?>
