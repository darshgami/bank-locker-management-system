<?php
/**
 * customer/renew.php – Extend Locker Assignment
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$aid = (int)($_GET['aid'] ?? 0);

if (!$aid) { header("Location: dashboard.php"); exit; }

// Fetch assignment
$stmt = $db->prepare('
    SELECT la.*, l.locker_no, l.size, l.rent_amount 
    FROM locker_assignments la 
    JOIN lockers l ON la.locker_id = l.id 
    WHERE la.id = ? AND la.customer_id = (SELECT id FROM customers WHERE user_id = ?)
');
$stmt->execute([$aid, $user['id']]);
$asgn = $stmt->fetch();

if (!$asgn) { die("Assignment not found."); }

// Fetch plan pricing
$planSt = $db->prepare('SELECT monthly_fee, yearly_fee FROM locker_plans WHERE size=?');
$planSt->execute([$asgn['size']]);
$plan = $planSt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planType = $_POST['plan_type'] ?? 'Monthly';
    $amount = ($planType === 'Yearly') ? $plan['yearly_fee'] : $plan['monthly_fee'];
    $duration = ($planType === 'Yearly') ? '+1 year' : '+1 month';
    
    // New end date
    $currentEnd = $asgn['end_date'] ? strtotime($asgn['end_date']) : time();
    if ($currentEnd < time()) $currentEnd = time(); // If already expired, start from now
    $newEnd = date('Y-m-d', strtotime($duration, $currentEnd));
    
    try {
        $db->beginTransaction();
        
        // Update Assignment
        $db->prepare('UPDATE locker_assignments SET end_date = ? WHERE id = ?')
           ->execute([$newEnd, $aid]);
        
        // GST Calculation (18% Inclusive)
        $taxPercent = 18.00;
        $baseAmount = round($amount / (1 + ($taxPercent / 100)), 2);
        $taxAmount  = $amount - $baseAmount;
        $otherFees  = 0.00;

        $db->prepare('INSERT INTO payments (assignment_id, amount, base_amount, tax_percent, tax_amount, other_fees, payment_date, due_date, status, invoice_no, plan_type) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, "Paid", ?, ?)')
           ->execute([$aid, $amount, $baseAmount, $taxPercent, $taxAmount, $otherFees, $newEnd, $inv, $planType]);
        
        $db->commit();
        
        sendNotification($user['id'], 'Locker Renewed', "Your locker {$asgn['locker_no']} has been renewed until $newEnd.", 'success');
        logActivity("Renewed locker {$asgn['locker_no']} ($planType)", 'Locker', $user['id']);
        
        header("Location: dashboard.php?msg=renewal_success");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

$pageTitle = 'Renew Locker';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Renewal</div>
    
    <div class="page-header">
      <div>
        <h1>Renew Locker: <?= h($asgn['locker_no']) ?></h1>
        <p>Extend your locker subscription to keep your valuables safe.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <div class="card" style="max-width: 600px; margin: 0 auto;">
       <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrf_generate() ?>">
          <div class="card-body">
             <div class="info-alert mb-4" style="background:var(--primary-soft); padding:15px; border-radius:8px; border:1px solid var(--primary-light); margin-bottom:20px;">
                <p style="margin:0; font-size:0.9rem;"><i class="fas fa-info-circle text-primary"></i> Current Expiry: <b><?= $asgn['end_date'] ? date('M d, Y', strtotime($asgn['end_date'])) : 'Not Set' ?></b></p>
             </div>

             <div class="form-group">
                <label>Select Renewal Plan</label>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:10px;">
                   <label class="plan-option" style="border:1px solid var(--border); padding:15px; border-radius:10px; cursor:pointer;">
                      <input type="radio" name="plan_type" value="Monthly" checked>
                      <div style="font-weight:700; color:var(--text-primary); margin-top:5px;">Monthly</div>
                      <div style="color:var(--primary);">₹<?= number_format($plan['monthly_fee'], 0) ?></div>
                   </label>
                   <label class="plan-option" style="border:1px solid var(--border); padding:15px; border-radius:10px; cursor:pointer;">
                      <input type="radio" name="plan_type" value="Yearly">
                      <div style="font-weight:700; color:var(--text-primary); margin-top:5px;">Yearly (Save!)</div>
                      <div style="color:var(--primary);">₹<?= number_format($plan['yearly_fee'], 0) ?></div>
                   </label>
                </div>
             </div>

             <div style="margin-top:30px;">
                <button type="submit" class="btn btn-primary w-100" style="padding:15px; font-size:1.1rem;">
                   <i class="fas fa-arrows-rotate"></i> Process Renewal
                </button>
                <a href="dashboard.php" class="btn btn-ghost w-100 mt-2">Cancel</a>
             </div>
          </div>
       </form>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
