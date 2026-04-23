<?php
/**
 * customer/payment_checkout.php – Phase 2 of locker application after document verification.
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Locker Payment & Activation';
$activePage = 'dashboard';

// Fetch customer ID
$custSt = $db->prepare('SELECT id FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

if (!$cust) {
    header("Location: dashboard.php");
    exit;
}

// Fetch verified request - support specific request ID or latest
$reqId = isset($_GET['req_id']) ? (int)$_GET['req_id'] : 0;
if ($reqId > 0) {
    $reqSt = $db->prepare("SELECT * FROM locker_requests WHERE id=? AND customer_id=? AND status='Verified' AND payment_mode IS NULL");
    $reqSt->execute([$reqId, $cust['id']]);
} else {
    $reqSt = $db->prepare("SELECT * FROM locker_requests WHERE customer_id=? AND status='Verified' AND payment_mode IS NULL ORDER BY id DESC LIMIT 1");
    $reqSt->execute([$cust['id']]);
}
$request = $reqSt->fetch();

if (!$request) {
    die('<div style="text-align:center;padding:60px;color:var(--text-primary);font-family:sans-serif">
         <h2>No Pending Payments Found</h2>
         <p>You either do not have an approved application, or you have already selected a payment method.</p>
         <a href="dashboard.php" style="color:var(--teal)">Return to Dashboard</a></div>');
}

// Get Pricing
$planSt = $db->prepare("SELECT * FROM locker_plans WHERE size=?");
$planSt->execute([$request['size']]);
$plan = $planSt->fetch();
$amount = $request['plan_type'] === 'Yearly' ? $plan['yearly_fee'] : $plan['monthly_fee'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = "Session expired. Please try again.";
    } else {
        $mode = $_POST['payment_mode'] ?? '';
        if (!in_array($mode, ['Online', 'Cash'])) {
            $error = "Invalid payment method.";
        } else {
            try {
                $db->beginTransaction();
                
                if ($mode === 'Cash') {
                    // Just record the intent, branch staff must verify
                    $stmt = $db->prepare("UPDATE locker_requests SET payment_mode = 'Cash' WHERE id = ?");
                    $stmt->execute([$request['id']]);
                    $db->commit();
                    logActivity("Customer selected Cash payment for request #{$request['id']}", 'Locker', $user['id']);
                    
                    // Notify Customer & Staff (simulated staff notif)
                    sendNotification($user['id'], 'Payment Method Selected', 'You have selected Cash payment. Please visit the branch to complete the process.', 'warning');
                    
                    header("Location: dashboard.php?msg=cash_payment_pending");
                    exit;
                } else {
                    // Simulate Online Payment Success & Auto-Assign Locker
                    $lockerSt = $db->prepare('SELECT id FROM lockers WHERE size=? AND status="Available" LIMIT 1');
                    $lockerSt->execute([$request['size']]);
                    $lockerId = $lockerSt->fetchColumn();

                    if (!$lockerId) {
                        throw new Exception("Sorry, no physical lockers of size {$request['size']} are currently available. Please contact the branch.");
                    }

                    // 1. Update Locker Status
                    $db->prepare("UPDATE lockers SET status = 'Occupied' WHERE id = ?")->execute([$lockerId]);
                    
                    // 2. Assign Locker with generated password
                    $sdate = date('Y-m-d');
                    $lockerPassword = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $db->prepare('INSERT INTO locker_assignments (locker_id,customer_id,assigned_by,start_date,locker_password) VALUES (?,?,?,?,?)')
                       ->execute([$lockerId, $cust['id'], $user['id'], $sdate, $lockerPassword]);
                    
                    $aid = $db->lastInsertId();

                    // 3. Mark request as Approved
                    $db->prepare("UPDATE locker_requests SET status = 'Approved', assigned_locker_id = ?, payment_mode = 'Online' WHERE id = ?")
                       ->execute([$lockerId, $request['id']]);

                    // 4. Record Payment Invoice
                    $due = date('Y-m-d', strtotime('+1 month', strtotime($sdate)));
                    if ($request['plan_type'] === 'Yearly') {
                        $due = date('Y-m-d', strtotime('+1 year', strtotime($sdate)));
                    }
                    $inv = 'INV-' . str_pad($aid, 5, '0', STR_PAD_LEFT);
                    
                    $db->prepare('INSERT INTO payments (assignment_id,amount,plan_type,payment_date,due_date,status,invoice_no) VALUES (?,?,?,?,?, "Paid",?)')
                       ->execute([$aid, $amount, $request['plan_type'], $sdate, $due, $inv]);

                    $db->commit();
                    logActivity("Paid online and activated locker {$lockerId}", 'Locker', $user['id']);
                    
                    // Notify Customer
                    sendNotification($user['id'], 'Locker Activated', 'Payment successful! Your locker ' . $lockerId . ' is now active and ready for use.', 'success');
                    
                    header("Location: dashboard.php?msg=payment_successful");
                    exit;
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$csrf = csrf_generate();
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="page-header" style="text-align: center; margin-bottom: 40px;">
      <h1><i class="fas fa-check-circle text-primary" style="font-size: 2.5rem; margin-bottom: 15px; display: block;"></i> Documents Verified!</h1>
      <p>Your locker application has been approved. Please make the initial payment to activate your locker.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error text-center" style="max-width: 600px; margin: 0 auto 20px;">
         <i class="fas fa-triangle-exclamation"></i> <?= h($error) ?>
      </div>
    <?php endif; ?>

    <div class="card" style="max-width: 600px; margin: 0 auto;">
       <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid var(--border);">
          <h3 style="margin:0;">Order Summary</h3>
          <span class="badge badge-pending">Checkout</span>
       </div>
       <div class="card-body">
          <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border);">
              <span>Locker Size:</span>
              <strong style="color:var(--text-main);"><?= h($request['size']) ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border);">
              <span>Plan Type:</span>
              <strong style="color:var(--text-main);"><?= h($request['plan_type']) ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; padding: 20px 0 10px; font-size: 1.2rem;">
              <span>Total Amount Due:</span>
              <strong class="text-primary">₹<?= number_format($amount, 2) ?></strong>
          </div>

          <form method="POST" action="" onsubmit="document.getElementById('checkoutBtn').disabled = true; document.getElementById('checkoutBtn').innerHTML = '<i class=\'fas fa-spinner spin\'></i> Processing...';">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              
              <h4 style="margin: 30px 0 15px;">Select Payment Method</h4>
              
              <label style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; display:flex; align-items:center; gap: 15px; margin-bottom: 15px; transition: all 0.3s; background: var(--bg-alt);" onchange="highlightLabel(this)">
                <input type="radio" name="payment_mode" value="Online" required style="display:none;">
                <div style="font-size: 1.8rem; color: var(--primary); width: 40px; text-align: center;"><i class="fas fa-credit-card"></i></div>
                <div>
                   <h4 style="margin:0; color: var(--text-main);">Pay Online Now</h4>
                   <span style="font-size:0.85rem; color: var(--text-muted);">Instant activation via Bank Transfer / UPI / Cards.</span>
                </div>
              </label>

              <label style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; display:flex; align-items:center; gap: 15px; margin-bottom: 30px; transition: all 0.3s; background: var(--bg-alt);" onchange="highlightLabel(this)">
                <input type="radio" name="payment_mode" value="Cash" required style="display:none;">
                <div style="font-size: 1.8rem; color: var(--gold); width: 40px; text-align: center;"><i class="fas fa-money-bill-wave"></i></div>
                <div>
                   <h4 style="margin:0; color: var(--text-main);">Pay Cash at Branch</h4>
                   <span style="font-size:0.85rem; color: var(--text-muted);">Reserve locker now. Hand cash to staff to activate.</span>
                </div>
              </label>

              <button type="submit" id="checkoutBtn" class="btn btn-primary w-100" style="padding: 15px; font-size: 1.1rem; border-radius: 8px;">
                 <i class="fas fa-lock"></i> Complete Payment & Activate
              </button>
          </form>
       </div>
    </div>
  </div>
</div>

<script>
function highlightLabel(element) {
    document.querySelectorAll('input[name="payment_mode"]').forEach(r => {
        r.closest('label').style.borderColor = 'var(--border)';
        r.closest('label').style.background = 'var(--bg-alt)';
    });
    element.style.borderColor = 'var(--primary)';
    element.style.background = 'var(--primary-soft)';
    element.querySelector('input').checked = true;
}
</script>

<?php include '../includes/footer.php'; ?>
