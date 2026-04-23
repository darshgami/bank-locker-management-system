<?php
/**
 * customer/qr_access.php – Dynamic QR Code Generator for Locker Access
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Locker Access QR';
$activePage = 'dashboard';

// Verify customer and active locker
$custSt = $db->prepare('SELECT id FROM customers WHERE user_id=? AND kyc_status="verified"');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

if (!$cust) {
    header("Location: dashboard.php?msg=kyc_missing");
    exit;
}

// Check specific locker assignment
$lockerId = isset($_GET['lid']) ? (int)$_GET['lid'] : 0;
if (!$lockerId) {
    header("Location: dashboard.php");
    exit;
}

$assignSt = $db->prepare('
    SELECT l.locker_no, la.end_date, la.locker_id 
    FROM locker_assignments la 
    JOIN lockers l ON l.id=la.locker_id 
    WHERE la.customer_id=? AND la.locker_id=? AND la.is_active=1
');
$assignSt->execute([$cust['id'], $lockerId]);
$assignment = $assignSt->fetch();

if (!$assignment) {
    die('<div style="text-align:center;padding:60px;font-family:sans-serif">
         <h2>No Active Locker Access</h2>
         <p>You either do not have an active assignment for this locker or your plan is in-active.</p>
         <a href="dashboard.php" style="color:teal">Return to Dashboard</a></div>');
}

// Verify Plan Expiry
$expired = ($assignment['end_date'] && strtotime($assignment['end_date']) < time());

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Vault Access QR
    </div>
    
    <div class="page-header" style="justify-content: center; position: relative;">
      <div>
        <h1>Locker Vault Access</h1>
        <p>Scan this QR code at the vault terminal to unlock your locker.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close" style="position: absolute; right: 0; top: 0;"><i class="fas fa-times"></i></a>
    </div>

    <div style="max-width: 500px; margin: 0 auto;">
        
      <?php if ($expired): ?>
        <div class="alert alert-error text-center">
            <i class="fas fa-ban" style="font-size: 2rem; margin-bottom: 10px; display:block;"></i>
            <strong>Access Denied.</strong> Your plan expired on <?= date('d M Y', strtotime($assignment['end_date'])) ?>. Please renew your plan to restore access.
        </div>
      <?php else: ?>
        <div class="card" style="text-align:center; padding: 40px 20px;">
           <h2 style="color:var(--primary); margin-top:0;">Locker: <?= h($assignment['locker_no']) ?></h2>
           <p style="color:var(--text-muted); font-size: 0.9rem; margin-bottom: 30px;">
               Generate a one-time use QR Code. The code is valid for <strong>60 seconds</strong>.
           </p>

           <!-- QR Container -->
           <div id="qr-container" style="background: white; padding: 20px; display: inline-block; border-radius: 12px; margin-bottom: 20px; min-height: 240px; min-width: 240px; display:flex; align-items:center; justify-content:center;">
               <div id="qrcode"></div>
               <div id="qr-placeholder" style="color:#888;">
                   <i class="fas fa-qrcode" style="font-size: 4rem; opacity: 0.3;"></i>
               </div>
           </div>

           <div id="timer-box" style="display:none; font-size: 1.2rem; font-weight: bold; color: var(--gold); margin-bottom: 20px;">
               Expires in <span id="time-left">60</span>s
           </div>

           <button id="generateBtn" class="btn btn-primary w-100" style="padding: 15px; font-size: 1.1rem; border-radius: 8px;">
               <i class="fas fa-qrcode"></i> Generate New QR Code
           </button>
           
           <!-- Simulated Scanner link -->
           <div style="margin-top: 30px; border-top: 1px solid var(--border); padding-top: 20px;">
               <a href="../scanner.php" target="_blank" class="btn btn-ghost" style="font-size: 0.8rem; color: var(--text-muted);"><i class="fas fa-camera"></i> Open Simulated Scanner (For Demo)</a>
           </div>
        </div>
      <?php endif; ?>
      
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    let timerInterval = null;
    let qrGenerator = null;

    document.getElementById('generateBtn')?.addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner spin"></i> Generating...';

        // Call backend to get a secure token
        const formData = new FormData();
        formData.append('locker_id', '<?= $assignment['locker_id'] ?>');

        fetch('../api/generate_qr_token.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Clear previous
                    document.getElementById('qr-placeholder').style.display = 'none';
                    const qrDiv = document.getElementById('qrcode');
                    qrDiv.innerHTML = '';
                    
                    // Generate new QR Image
                    qrGenerator = new QRCode(qrDiv, {
                        text: data.token,
                        width: 220,
                        height: 220,
                        colorDark : "#0d9488",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.H
                    });

                    // Start Timer
                    startTimer(60);

                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-qrcode"></i> Generate New QR Code';
                } else {
                    alert('Error: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-qrcode"></i> Generate New QR Code';
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-qrcode"></i> Generate New QR Code';
            });
    });

    function startTimer(seconds) {
        if (timerInterval) clearInterval(timerInterval);
        
        let timeLeft = seconds;
        const timeSpan = document.getElementById('time-left');
        const timerBox = document.getElementById('timer-box');
        
        timerBox.style.display = 'block';
        timeSpan.innerText = timeLeft;

        timerInterval = setInterval(() => {
            timeLeft--;
            timeSpan.innerText = timeLeft;

            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                document.getElementById('qrcode').innerHTML = '<div style="color:var(--red); font-weight:bold; height:220px; display:flex; align-items:center; justify-content:center;">QR Expired</div>';
                timerBox.style.display = 'none';
            }
        }, 1000);
    }
</script>

<?php include '../includes/footer.php'; ?>
