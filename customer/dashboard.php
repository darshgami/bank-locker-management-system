<?php
/**
 * customer/dashboard.php — Advanced Customer Self-Service Portal
 * Fully dynamic interface connected to database.
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$pageTitle = 'My Dashboard';
$activePage = 'dashboard';
$user = currentUser();

// 1. Fetch Customer Record & KYC Status
$custSt = $db->prepare('SELECT * FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

if (!$cust) {
    die('<div style="text-align:center;padding:60px;color:var(--text-primary);font-family:sans-serif">
         <h2 style="color:var(--red)">Profile Incomplete</h2>
         <p>Contact branch staff to complete your profile setup.</p>
         <a href="../logout.php" style="color:var(--teal)">Logout</a></div>');
}

$kycStatus = $cust['kyc_status']; // 'pending', 'verified', 'rejected'

// 2. Fetch ALL Active Locker Assignments & Locker Details
$assignSt = $db->prepare(
    'SELECT la.*, l.locker_no, l.size, l.rent_amount, l.location, l.status AS locker_status
     FROM locker_assignments la 
     JOIN lockers l ON l.id = la.locker_id
     WHERE la.customer_id = ? AND la.is_active = 1 ORDER BY la.created_at DESC'
);
$assignSt->execute([$cust['id']]);
$allAssignments = $assignSt->fetchAll();

// Get latest payment for each assignment
$assignmentPayments = [];
foreach ($allAssignments as $a) {
    $lpSt = $db->prepare('SELECT * FROM payments WHERE assignment_id=? ORDER BY id DESC LIMIT 1');
    $lpSt->execute([$a['id']]);
    $assignmentPayments[$a['id']] = $lpSt->fetch();
}

// Fetch ALL pending/verified locker requests (not yet assigned)
$reqSt = $db->prepare('SELECT * FROM locker_requests WHERE customer_id=? AND status IN ("Pending","Verified","Rejected") ORDER BY id DESC');
$reqSt->execute([$cust['id']]);
$allRequests = $reqSt->fetchAll();

// 3. Stats Data (Dynamic Count)
// a. Active Lockers
$activeLockersCount = count($allAssignments);

// b. Pending Payments
$pendingCount = 0;
if ($activeLockersCount > 0) {
    $assignIds = array_column($allAssignments, 'id');
    $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
    $pendingSt = $db->prepare("SELECT COUNT(*) FROM payments WHERE assignment_id IN ($placeholders) AND status='Pending'");
    $pendingSt->execute($assignIds);
    $pendingCount = (int)$pendingSt->fetchColumn();
}

// c. Recent Visits (Last 30 Days Successful)
$recentVisitsCount = 0;
$recentVisitsSt = $db->prepare('SELECT COUNT(*) FROM access_logs WHERE customer_id=? AND access_status="Success" AND entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
$recentVisitsSt->execute([$cust['id']]);
$recentVisitsCount = (int)$recentVisitsSt->fetchColumn();

// d. Risk Level Calculation
$riskLevel = $cust['risk_level']; // Base risk from DB
// Optionally auto-calculate right here if needed dynamically vs cron job. 
// E.g., if failed attempts > 3, risk = high. For this demo, we use the value stored in the `customers` table.

// e. All payments across all lockers for the payment history table
$payments = [];
if ($activeLockersCount > 0) {
    $assignIds = array_column($allAssignments, 'id');
    $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
    $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
    $paySt = $db->prepare("SELECT p.*, l.locker_no FROM payments p JOIN locker_assignments la ON la.id=p.assignment_id JOIN lockers l ON l.id=la.locker_id WHERE p.assignment_id IN ($placeholders) ORDER BY p.payment_date DESC LIMIT 10");
    $paySt->execute($assignIds);
    $payments = $paySt->fetchAll();
}

// b. Access History
$accessSt = $db->prepare('SELECT al.*, l.locker_no FROM access_logs al JOIN lockers l ON l.id = al.locker_id WHERE al.customer_id=? ORDER BY al.entry_time DESC LIMIT 5');
$accessSt->execute([$cust['id']]);
$accessLogs = $accessSt->fetchAll();


include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">

    <?php
    // Show success/info messages from redirects
    $msgParam = $_GET['msg'] ?? '';
    $msgMap = [
        'application_submitted' => ['success', 'Your locker application has been submitted! Staff will review your documents shortly.'],
        'payment_successful'    => ['success', 'Payment successful! Your locker is now active. Check "My Locker" below for your details and QR code.'],
        'cash_payment_pending'  => ['info',    'Cash payment selected. Please visit the branch to hand over the payment. Staff will confirm and activate your locker.'],
        'renewal_success'       => ['success', 'Locker renewal successful! Your expiry date has been extended.'],
    ];
    if (isset($msgMap[$msgParam])):
        [$msgType, $msgText] = $msgMap[$msgParam];
    ?>
    <div class="alert alert-<?= $msgType === 'info' ? 'warning' : $msgType ?>" data-auto-dismiss style="margin-bottom:20px;">
      <i class="fas <?= $msgType === 'success' ? 'fa-check-circle' : 'fa-info-circle' ?>"></i>
      <?= $msgText ?>
    </div>
    <?php endif; ?>
    
    <!-- TOP SECTION: Welcome & KYC Banner -->
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
      <div>
        <h1>Welcome, <?= h(explode(' ', $user['full_name'])[0]) ?> 👋</h1>
        <p style="color:var(--text-muted);">Manage your safe deposit locker, payments, and access history.</p>
      </div>
      
      <?php if ($kycStatus === 'verified'): ?>
          <div class="badge badge-verified" style="padding:10px 15px; font-size:0.95rem; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-check-circle" style="font-size:1.1rem;"></i> KYC Verified
          </div>
      <?php elseif ($kycStatus === 'pending'): ?>
          <div class="badge badge-pending" style="padding:10px 15px; font-size:0.95rem; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-clock" style="font-size:1.1rem;"></i> KYC Pending Validation
          </div>
      <?php else: ?>
          <div class="badge badge-rejected" style="padding:10px 15px; font-size:0.95rem; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-circle-xmark" style="font-size:1.1rem;"></i> KYC Rejected
          </div>
      <?php endif; ?>
    </div>

    <!-- STATS WIDGETS -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 25px;">
      <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-lock"></i></div>
        <div class="stat-info">
          <h4>Active Lockers</h4>
          <div class="value"><?= $activeLockersCount ?></div>
          <div class="stat-trend up"><i class="fas fa-user-lock"></i> Secured</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon <?= $pendingCount > 0 ? 'red' : 'gold' ?>">
            <i class="fas <?= $pendingCount > 0 ? 'fa-triangle-exclamation' : 'fa-indian-rupee-sign' ?>"></i>
        </div>
        <div class="stat-info">
          <h4>Pending Payments</h4>
          <div class="value"><?= $pendingCount ?></div>
          <div class="stat-trend <?= $pendingCount > 0 ? 'down' : 'up' ?>">
            <i class="fas <?= $pendingCount > 0 ? 'fa-circle-exclamation' : 'fa-check' ?>"></i> 
            <?= $pendingCount > 0 ? 'Action Required' : 'All cleared' ?>
          </div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-shoe-prints"></i></div>
        <div class="stat-info">
          <h4>Visits (Last 30 Days)</h4>
          <div class="value"><?= $recentVisitsCount ?></div>
          <div class="stat-trend up"><i class="fas fa-history"></i> Logged</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon <?= $riskLevel === 'high' ? 'red' : ($riskLevel === 'medium' ? 'gold' : 'teal') ?>">
           <i class="fas fa-shield-halved"></i>
        </div>
        <div class="stat-info">
          <h4>Risk Level</h4>
          <div class="value" style="text-transform: capitalize;"><?= $riskLevel ?></div>
          <div class="stat-trend <?= $riskLevel === 'low' ? 'up' : 'down' ?>">
             <i class="fas <?= $riskLevel === 'low' ? 'fa-check-circle' : 'fa-triangle-exclamation' ?>"></i>
             <?= $riskLevel === 'low' ? 'Normal' : 'Security Warning' ?>
          </div>
        </div>
      </div>
    </div>

    <div class="grid-2" style="gap:25px;">
      
      <!-- MY LOCKERS SECTION (Multi-Locker) -->
      <div class="card" style="display:flex; flex-direction:column;">
        <div class="card-header" style="border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;"><i class="fas fa-vault text-primary"></i> My Lockers</h3>
            <?php if ($kycStatus === 'verified'): ?>
                <a href="apply_locker.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Apply for New Locker</a>
            <?php endif; ?>
        </div>
        
        <div class="card-body" style="flex: 1;">

          <!-- Active Lockers -->
          <?php if ($allAssignments): ?>
            <?php foreach ($allAssignments as $idx => $asgn): ?>
              <?php
                $isExpired = ($asgn['end_date'] && strtotime($asgn['end_date']) < time());
                $lp = $assignmentPayments[$asgn['id']] ?? null;
                $payStatus = $lp ? $lp['status'] : 'N/A';
                $payInvoice = $lp ? $lp['invoice_no'] : '';
                $pBadge = 'pending';
                if ($payStatus === 'Paid') $pBadge = 'verified';
                if ($payStatus === 'Overdue') $pBadge = 'rejected';
              ?>
              <div style="background: var(--bg-main); border-radius: 12px; padding: 20px; border: 1px solid var(--border); margin-bottom: 15px;">
                
                <!-- Header Row -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
                    <div>
                        <span style="font-size: 0.7rem; color: var(--text-muted); text-transform:uppercase; letter-spacing:1px;">Locker #<?= $idx + 1 ?></span>
                        <h3 style="margin: 3px 0 0 0; color: var(--primary); font-size: 1.5rem;"><?= h($asgn['locker_no']) ?></h3>
                    </div>
                    <?php if ($isExpired): ?>
                        <span class="badge badge-rejected" style="font-size:0.8rem; padding: 6px 12px;">Expired</span>
                    <?php else: ?>
                        <span class="badge badge-verified" style="font-size:0.8rem; padding: 6px 12px;"><i class="fas fa-check-circle"></i> Active</span>
                    <?php endif; ?>
                </div>

                <!-- Details Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap:10px; margin-bottom: 15px;">
                    <div style="background: var(--bg-alt); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <span style="font-size: 0.7rem; color: var(--text-muted); display:block; margin-bottom:3px;">Size</span>
                        <strong style="font-size: 0.9rem;"><?= h($asgn['size']) ?></strong>
                    </div>
                    <div style="background: var(--bg-alt); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <span style="font-size: 0.7rem; color: var(--text-muted); display:block; margin-bottom:3px;">Assigned</span>
                        <strong style="font-size: 0.9rem;"><?= date('d M Y', strtotime($asgn['start_date'])) ?></strong>
                    </div>
                    <div style="background: var(--bg-alt); padding: 10px; border-radius: 6px; border: 1px solid var(--border);">
                        <span style="font-size: 0.7rem; color: var(--text-muted); display:block; margin-bottom:3px;">Payment</span>
                        <span class="badge badge-<?= $pBadge ?>" style="font-size:0.75rem;"><?= h($payStatus) ?></span>
                    </div>
                    <div style="background: var(--primary-soft); padding: 10px; border-radius: 6px; display:flex; align-items:center; justify-content:space-between; border: 1px solid var(--primary-light);">
                        <div>
                            <span style="font-size: 0.7rem; color: var(--primary); display:block; margin-bottom:3px;"><i class="fas fa-key"></i> Password</span>
                            <strong id="pwd_<?= $idx ?>" style="font-family:monospace; font-size:0.9rem; letter-spacing:2px; color:var(--primary);"><?= $asgn['locker_password'] ? '••••••••' : 'N/A' ?></strong>
                        </div>
                        <?php if ($asgn['locker_password']): ?>
                        <button type="button" onclick="togglePwd(<?= $idx ?>,'<?= h($asgn['locker_password']) ?>')" class="btn btn-ghost btn-sm" style="padding:4px 8px;"><i class="fas fa-eye" id="pwdIcon_<?= $idx ?>"></i></button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- QR + Actions Row -->
                <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
                    <div style="background: white; border-radius: 8px; padding: 10px; flex-shrink:0;">
                        <div id="qr_<?= $idx ?>" style="width:80px;height:80px;"></div>
                    </div>
                    <div style="flex:1; display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="renew.php?aid=<?= $asgn['id'] ?>" class="btn btn-sm" style="<?= $isExpired ? 'background:var(--red); color:white;' : 'background:var(--bg-alt); color:var(--gold); border:1px solid var(--border);' ?>">
                           <i class="fas fa-arrows-rotate"></i> <?= $isExpired ? 'Expired — Renew' : 'Renew / Extend' ?>
                        </a>
                        <?php if (!$isExpired): ?>
                            <a href="qr_access.php?lid=<?= $asgn['locker_id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-qrcode"></i> Access QR</a>
                        <?php endif; ?>
                        <?php if ($payInvoice): ?>
                            <a href="receipt.php?inv=<?= h($payInvoice) ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Receipt</a>
                        <?php endif; ?>
                    </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- Pending/Verified/Rejected Requests -->
          <?php
          $activeRequests = array_filter($allRequests, fn($r) => in_array($r['status'], ['Pending','Verified']));
          $rejectedRequests = array_filter($allRequests, fn($r) => $r['status'] === 'Rejected');
          ?>

          <?php foreach ($activeRequests as $req): ?>
            <div style="background: var(--bg-alt); border-radius: 10px; padding: 18px; border: 1px dashed var(--border); margin-bottom: 12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
              <div>
                <strong style="font-size:0.95rem;"><?= h($req['size']) ?> Locker</strong>
                <span style="color:var(--text-muted); font-size:0.85rem;"> — <?= h($req['plan_type']) ?> plan</span>
                <div style="margin-top:5px;">
                  <?php if ($req['status'] === 'Pending'): ?>
                    <span class="badge badge-pending"><i class="fas fa-clock"></i> Docs Under Review</span>
                  <?php elseif ($req['status'] === 'Verified' && !$req['payment_mode']): ?>
                    <span class="badge badge-verified"><i class="fas fa-check"></i> Verified — Pay Now</span>
                  <?php elseif ($req['status'] === 'Verified' && $req['payment_mode'] === 'Cash'): ?>
                    <span class="badge badge-pending"><i class="fas fa-money-bill"></i> Cash — Awaiting Staff</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($req['status'] === 'Verified' && !$req['payment_mode']): ?>
                <a href="payment_checkout.php?req_id=<?= $req['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-credit-card"></i> Pay Now</a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php foreach (array_slice($rejectedRequests, 0, 2) as $rej): ?>
            <div style="background: rgba(239,71,111,0.05); border-radius: 10px; padding: 15px; border: 1px solid rgba(239,71,111,0.15); margin-bottom: 12px;">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                  <strong style="color:var(--red);"><?= h($rej['size']) ?> Locker — Rejected</strong>
                  <?php if ($rej['reject_reason']): ?>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">Reason: <?= h($rej['reject_reason']) ?></div>
                  <?php endif; ?>
                </div>
                <a href="apply_locker.php" class="btn btn-ghost btn-sm"><i class="fas fa-redo"></i> Re-Apply</a>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if (!$allAssignments && !$activeRequests && !$rejectedRequests): ?>
             <div style="text-align:center; padding: 40px 20px;">
                <i class="fas fa-vault" style="font-size: 3.5rem; color: var(--border); margin-bottom: 20px; display:block;"></i>
                <h3 style="margin-bottom: 10px;">No Lockers Yet</h3>
                <p style="color:var(--text-muted); line-height:1.5; margin-bottom: 25px;">You haven't applied for any safe deposit lockers yet.</p>
                <?php if ($kycStatus === 'verified'): ?>
                    <a href="apply_locker.php" class="btn btn-primary" style="padding: 12px 25px; font-size:1.05rem;"><i class="fas fa-plus"></i> Apply for a Locker</a>
                <?php else: ?>
                    <div class="alert alert-warning" style="text-align:left; display:inline-block;"><i class="fas fa-lock"></i> KYC Verification required.</div>
                <?php endif; ?>
             </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- MIDDLE PANEL: PAYMENT HISTORY -->
      <div class="card" style="display:flex; flex-direction:column;">
        <div class="card-header" style="border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;"><i class="fas fa-file-invoice-dollar text-primary"></i> Payment History</h3>
            <a href="payments.php" class="btn btn-ghost btn-sm">All Payments</a>
        </div>
        
        <div class="card-body" style="padding:0; flex:1;">
            <div class="table-container" style="border:none; margin:0;">
                <table class="tbl" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="padding-left: 20px;">Invoice</th>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th style="text-align:right; padding-right:20px;">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $p): ?>
                        <tr>
                            <td style="padding-left: 20px;"><strong style="color:var(--text-primary);"><?= h($p['invoice_no']) ?></strong><br><span style="font-size:0.8rem; color:var(--text-muted);"><?= isset($p['locker_no']) ? h($p['locker_no']).' · ' : '' ?><?= h($p['plan_type']) ?></span></td>
                            <td style="font-weight:600;">₹<?= number_format($p['amount'],0) ?></td>
                            <td style="font-size:0.9rem; color:var(--text-muted);"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td>
                                <?php
                                    $pbadge = 'pending';
                                    if ($p['status'] === 'Paid') $pbadge = 'verified';
                                    if ($p['status'] === 'Overdue') $pbadge = 'rejected';
                                ?>
                                <span class="badge badge-<?= $pbadge ?>"><?= h($p['status']) ?></span>
                            </td>
                            <td style="text-align:right; padding-right:20px;">
                                <?php if ($p['status'] === 'Paid'): ?>
                                    <a href="receipt.php?inv=<?= h($p['invoice_no']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Download PDF"><i class="fas fa-download"></i> PDF</a>
                                <?php else: ?>
                                    <a href="payments.php" class="btn btn-primary btn-sm">Pay Now</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($payments)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 40px 20px; color:var(--text-muted);">
                                <i class="fas fa-receipt" style="font-size:2rem; margin-bottom:10px; opacity:0.3; display:block;"></i>
                                No payment history available.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
      </div>
      
      <!-- FULL WIDTH BOTTOM: ACCESS HISTORY -->
      <div class="card" style="grid-column: 1 / -1;">
        <div class="card-header" style="border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;"><i class="fas fa-history text-primary"></i> Recent Access Logs</h3>
            <a href="access_logs.php" class="btn btn-ghost btn-sm">View All Logs</a>
        </div>
        
        <div class="card-body" style="padding:0;">
            <div class="table-container" style="border:none; margin:0;">
                <table class="tbl" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="padding-left: 20px;">Date & Time</th>
                            <th>Locker No</th>
                            <th>Authentication</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($accessLogs as $al): ?>
                        <tr>
                            <td style="padding-left: 20px;">
                                <strong><?= date('d M Y', strtotime($al['entry_time'])) ?></strong><br>
                                <span style="font-size:0.85rem; color:var(--text-muted);"><?= date('h:i A', strtotime($al['entry_time'])) ?></span>
                            </td>
                            <td><?= h($al['locker_no'] ?? 'N/A') ?></td>
                            <td>
                                <?php if ($al['qr_token_used']): ?>
                                    <span style="color:var(--text-primary);"><i class="fas fa-qrcode text-teal" style="width:20px;"></i> QR Token</span>
                                <?php elseif ($al['biometric_ok']): ?>
                                    <span style="color:var(--text-primary);"><i class="fas fa-fingerprint text-teal" style="width:20px;"></i> Biometric</span>
                                <?php elseif ($al['otp_used']): ?>
                                    <span style="color:var(--text-primary);"><i class="fas fa-message text-teal" style="width:20px;"></i> SMS OTP</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">Manual / Keys</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($al['access_status'] === 'Success'): ?>
                                    <span class="badge badge-verified"><i class="fas fa-check"></i> Granted</span>
                                <?php else: ?>
                                    <span class="badge badge-rejected"><i class="fas fa-xmark"></i> Denied</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text-muted); font-size:0.9rem;">
                                <?= h($al['notes'] ?: '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($accessLogs)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 40px 20px; color:var(--text-muted);">
                                <i class="fas fa-shoe-prints" style="font-size:2rem; margin-bottom:10px; opacity:0.3; display:block;"></i>
                                No vault access recorded yet.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
      </div>
      
    </div>

  </div>
</div>

<?php
// Build multi-locker QR + password toggle JS
$lockerDataJson = [];
foreach ($allAssignments as $idx => $a) {
    $lockerDataJson[] = [
        'idx' => $idx,
        'no' => $a['locker_no'],
        'pwd' => $a['locker_password'] ?? ''
    ];
}
$jsonLockers = json_encode($lockerDataJson);
$extraJS = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
var lockerData = {$jsonLockers};
var pwdState = {};

function togglePwd(idx, pwd) {
    var el = document.getElementById('pwd_' + idx);
    var icon = document.getElementById('pwdIcon_' + idx);
    if (!el) return;
    pwdState[idx] = !pwdState[idx];
    if (pwdState[idx]) {
        el.textContent = pwd;
        icon.className = 'fas fa-eye-slash';
    } else {
        el.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
        icon.className = 'fas fa-eye';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    lockerData.forEach(function(l) {
        var container = document.getElementById('qr_' + l.idx);
        if (container && l.no) {
            new QRCode(container, {
                text: 'LOCKER:' + l.no + '|PWD:' + l.pwd,
                width: 80,
                height: 80,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }
    });
});
</script>
HTML;
include '../includes/footer.php';
?>

