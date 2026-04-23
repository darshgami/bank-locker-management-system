<?php
/**
 * customer/payment.php – Handle Locker Plan Payment
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Complete Payment';
$activePage = 'dashboard';

// Get customer record
$custSt = $db->prepare('SELECT * FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

$req_id = (int)($_GET['req_id'] ?? 0);
$error = '';
$success = '';

if (!$req_id) {
    header("Location: dashboard.php");
    exit;
}

// Fetch the approved request
$reqSt = $db->prepare('
    SELECT lr.*, l.locker_no, l.location, l.rent_amount, lp.monthly_fee, lp.yearly_fee 
    FROM locker_requests lr
    JOIN lockers l ON lr.assigned_locker_id = l.id
    JOIN locker_plans lp ON lp.size = lr.size
    WHERE lr.id = ? AND lr.customer_id = ? AND lr.status = "Approved"
');
$reqSt->execute([$req_id, $cust['id']]);
$request = $reqSt->fetch();

if (!$request) {
    die('<div style="text-align:center;padding:60px;font-family:sans-serif">
         <h2>Invalid Request</h2>
         <p>This payment request is invalid, already paid, or not yet approved.</p>
         <a href="dashboard.php" style="color:teal">Return to Dashboard</a></div>');
}

$amountDue = $request['plan_type'] === 'Yearly' ? $request['yearly_fee'] : $request['monthly_fee'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $pay_mode = $_POST['payment_mode'] ?? 'Card';
        
        try {
            $db->beginTransaction();

            // 1. Create the locker assignment
            $start_date = date('Y-m-d');
            $end_date = $request['plan_type'] === 'Yearly' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));
            
            // Assume the system/admin user ID 1 is doing the assignment automatically here
            $stmt = $db->prepare("INSERT INTO locker_assignments (locker_id, customer_id, assigned_by, start_date, end_date, is_active) VALUES (?, ?, 1, ?, ?, 1)");
            $stmt->execute([$request['assigned_locker_id'], $cust['id'], $start_date, $end_date]);
            $assignment_id = $db->lastInsertId();

            // 2. Record the payment
            $transaction_id = 'TXN' . strtoupper(uniqid());
            $invoice_no = 'INV-' . date('Ymd') . '-' . rand(1000,9999);
            
            $stmt = $db->prepare("INSERT INTO payments (assignment_id, amount, plan_type, payment_date, due_date, payment_mode, status, transaction_id, invoice_no) VALUES (?, ?, ?, ?, ?, ?, 'Paid', ?, ?)");
            $stmt->execute([$assignment_id, $amountDue, $request['plan_type'], $start_date, $end_date, $pay_mode, $transaction_id, $invoice_no]);

            // 3. Delete the locker request as it is fulfilled
            $stmt = $db->prepare("DELETE FROM locker_requests WHERE id = ?");
            $stmt->execute([$req_id]);

            // 4. Update the actual locker's rent_amount for record keeping
            $stmt = $db->prepare("UPDATE lockers SET rent_amount = ? WHERE id = ?");
            $stmt->execute([$amountDue, $request['assigned_locker_id']]);

            $db->commit();
            logActivity("Paid ₹$amountDue for locker {$request['locker_no']}", 'Payment', $user['id']);
            
            header("Location: receipt.php?inv=" . $invoice_no);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Payment failed. Please try again. " . $e->getMessage();
        }
    }
}

$csrf = csrf_generate();
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="topbar">
    <button class="topbar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <span class="topbar-title">Complete Payment</span>
  </div>

  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Payment
    </div>
    
    <div class="page-header">
      <h1>Complete Your Payment</h1>
      <p>Pay to activate your newly assigned locker.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-triangle-exclamation"></i> <?= h($error) ?>
      </div>
    <?php endif; ?>

    <div class="grid-2" style="gap:20px; align-items: start;">
      
      <!-- Order Summary -->
      <div class="card">
         <div class="card-header">
             <h3><i class="fas fa-receipt text-teal"></i> Order Summary</h3>
         </div>
         <div class="card-body">
             <table class="tbl" style="margin-bottom: 20px;">
                 <tr>
                     <th style="width: 40%; color:var(--text-muted);">Assigned Locker</th>
                     <td><strong style="font-size: 1.2rem; color: var(--teal);"><?= h($request['locker_no']) ?></strong></td>
                 </tr>
                 <tr>
                     <th style="color:var(--text-muted);">Location</th>
                     <td><?= h($request['location']) ?></td>
                 </tr>
                 <tr>
                     <th style="color:var(--text-muted);">Locker Size</th>
                     <td><?= h($request['size']) ?></td>
                 </tr>
                 <tr>
                     <th style="color:var(--text-muted);">Selected Plan</th>
                     <td><span class="badge badge-success"><?= h($request['plan_type']) ?></span></td>
                 </tr>
             </table>
             
             <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border-left: 4px solid var(--gold);">
                 <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                     <span style="color:var(--text-muted);">Subtotal</span>
                     <span>₹<?= number_format($amountDue, 2) ?></span>
                 </div>
                 <div style="display:flex; justify-content:space-between; margin-bottom: 10px;">
                     <span style="color:var(--text-muted);">Tax (0%)</span>
                     <span>₹0.00</span>
                 </div>
                 <hr style="border-color: rgba(255,255,255,0.05); margin-bottom: 10px;">
                 <div style="display:flex; justify-content:space-between; font-size: 1.2rem; font-weight: bold;">
                     <span>Total Due</span>
                     <span style="color: var(--gold);">₹<?= number_format($amountDue, 2) ?></span>
                 </div>
             </div>
         </div>
      </div>

      <!-- Payment Gateway Simulator -->
      <div class="card">
         <div class="card-header">
             <h3><i class="fas fa-credit-card text-teal"></i> Payment Details</h3>
         </div>
         <div class="card-body">
             <form method="POST" action="" onsubmit="return validatePayment()">
                 <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                 
                 <div class="form-group">
                     <label>Select Payment Method</label>
                     <select name="payment_mode" class="form-control" required>
                         <option value="Card">Credit / Debit Card</option>
                         <option value="UPI">UPI / QR Code</option>
                         <option value="NetBanking">Net Banking</option>
                     </select>
                 </div>
                 
                 <div style="background:rgba(255,255,255,0.02); padding: 20px; border-radius: 8px; border: 1px dashed rgba(255,255,255,0.1); margin-bottom: 20px; text-align:center;">
                     <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
                         This is a simulated payment gateway. Clicking "Pay Now" will process the transaction successfully.
                     </p>
                     <i class="fas fa-shield-alt text-teal" style="font-size: 2rem; margin-bottom: 10px;"></i>
                     <p style="font-size:0.85rem; color:var(--text-muted);">256-bit Secure Encrypted Connection</p>
                 </div>

                 <button type="submit" class="btn btn-primary w-100" id="payBtn" style="padding: 15px; font-size: 1.1rem;">
                     <i class="fas fa-lock"></i> Pay ₹<?= number_format($amountDue, 2) ?> Now
                 </button>
             </form>
         </div>
      </div>
      
    </div>
  </div>
</div>

<script>
function validatePayment() {
    const btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner spin"></i> Processing Payment...';
    return true;
}
</script>

<?php include '../includes/footer.php'; ?>
