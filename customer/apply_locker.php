<?php
/**
 * customer/apply_locker.php – Apply for a New Locker
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$pageTitle = 'Apply for Locker';
$activePage = 'dashboard';

// Get customer record
$custSt = $db->prepare('SELECT * FROM customers WHERE user_id=?');
$custSt->execute([$user['id']]);
$cust = $custSt->fetch();

if (!$cust || $cust['kyc_status'] !== 'verified') {
    header("Location: dashboard.php?msg=kyc_missing");
    exit;
}

// Note: Multiple lockers allowed per customer. No restriction on active assignments.
// We only prevent submitting an identical pending request for the same size.
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $size = $_POST['size'] ?? '';
        $plan_type = $_POST['plan_type'] ?? '';

        if (!in_array($size, ['Small', 'Medium', 'Large']) || !in_array($plan_type, ['Monthly', 'Yearly'])) {
            $error = "Invalid selection.";
        } else {
            // Handle File Uploads
            $uploadDir = '../uploads/requests/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $aadharFile = '';
            $photoFile = '';
            $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];

            // Validate Aadhar
            if (isset($_FILES['aadhar']) && $_FILES['aadhar']['error'] === UPLOAD_ERR_OK) {
                if (in_array($_FILES['aadhar']['type'], $allowedTypes)) {
                    $ext = pathinfo($_FILES['aadhar']['name'], PATHINFO_EXTENSION);
                    $aadharFile = 'aadhar_' . time() . '_' . $cust['id'] . '.' . $ext;
                    move_uploaded_file($_FILES['aadhar']['tmp_name'], $uploadDir . $aadharFile);
                } else {
                    $error = "Invalid Aadhar file format. JPG, PNG, or PDF only.";
                }
            } else {
                $error = "Aadhar card upload is required.";
            }

            // Validate Photo
            if (!$error && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                if (in_array($_FILES['photo']['type'], ['image/jpeg', 'image/png'])) {
                    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                    $photoFile = 'photo_' . time() . '_' . $cust['id'] . '.' . $ext;
                    move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoFile);
                } else {
                    $error = "Invalid Photo file format. JPG or PNG only.";
                }
            } elseif (!$error) {
                $error = "Passport photo upload is required.";
            }

            if (!$error) {
                $stmt = $db->prepare("INSERT INTO locker_requests (customer_id, size, plan_type, status, aadhar_file, photo_file) VALUES (?, ?, ?, 'Pending', ?, ?)");
                if ($stmt->execute([$cust['id'], $size, $plan_type, $aadharFile, $photoFile])) {
                    logActivity("Applied for $size locker ($plan_type plan) with documents", 'Locker', $user['id']);
                    header("Location: dashboard.php?msg=application_submitted");
                    exit;
                } else {
                    $error = "Failed to submit application. Please try again.";
                }
            }
        }
    }
}

$plans = $db->query("SELECT * FROM locker_plans ORDER BY monthly_fee ASC")->fetchAll();
$csrf = csrf_generate();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Apply for Locker
    </div>
    
    <div class="page-header">
      <div>
        <h1>Apply for a New Locker</h1>
        <p>Select your desired locker size and payment plan.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-triangle-exclamation"></i> <?= h($error) ?>
        <div style="margin-top: 10px;">
           <a href="dashboard.php" class="btn btn-ghost">Return to Dashboard</a>
        </div>
      </div>
    <?php else: ?>
      <div class="card" style="max-width: 800px; margin: 0 auto;">
        <div class="card-header">
          <h3><i class="fas fa-file-signature text-teal"></i> Application Form</h3>
        </div>
        <div class="card-body">
          <form method="POST" action="" id="applyForm" enctype="multipart/form-data" onsubmit="return validateForm('applyForm')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            
            <h4 style="margin-bottom: 15px; color: var(--text-primary);">1. Select Locker Size</h4>
            <div class="grid-3" style="gap: 15px; margin-bottom: 30px;">
              <?php foreach ($plans as $p): ?>
              <label class="plan-card" style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; text-align: center; transition: all 0.3s; color: var(--text-main); background: var(--bg-alt);">
                <input type="radio" name="size" value="<?= h($p['size']) ?>" required style="display:none;" onchange="updateSelection(this)">
                <div class="icon" style="font-size: 2rem; color: var(--primary); margin-bottom: 10px;">
                   <i class="fas fa-box<?php echo $p['size'] == 'Small' ? '' : ($p['size'] == 'Medium' ? '-open' : 'es'); ?>"></i>
                </div>
                <h3 style="margin:0 0 10px 0; color: var(--text-main);"><?= h($p['size']) ?></h3>
                <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 5px;">
                  Monthly: ₹<?= number_format($p['monthly_fee'], 0) ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--text-muted);">
                  Yearly: ₹<?= number_format($p['yearly_fee'], 0) ?>
                </div>
              </label>
              <?php endforeach; ?>
            </div>

            <h4 style="margin-bottom: 15px; color: var(--text-primary);">2. Select Payment Plan</h4>
            <div class="grid-2" style="gap: 15px; margin-bottom: 30px;">
              <label style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; transition: all 0.3s; display:flex; align-items:center; gap: 15px; color: var(--text-main); background: var(--bg-alt);">
                <input type="radio" name="plan_type" value="Monthly" required onchange="updatePlanSelection(this)">
                <div>
                   <h4 style="margin:0; color: var(--text-main);">Monthly Plan</h4>
                   <span style="font-size:0.85rem; color: var(--text-muted);">Pay on a month-to-month basis.</span>
                </div>
              </label>
              <label style="border: 2px solid var(--border); border-radius: 8px; padding: 20px; cursor: pointer; transition: all 0.3s; display:flex; align-items:center; gap: 15px; color: var(--text-main); background: var(--bg-alt);">
                <input type="radio" name="plan_type" value="Yearly" required onchange="updatePlanSelection(this)">
                <div>
                   <h4 style="margin:0; color: var(--text-main);">Yearly Plan</h4>
                   <span style="font-size:0.85rem; color: var(--text-muted);">Pay annually and save on rent.</span>
                </div>
              </label>
            </div>

            <h4 style="margin-bottom: 15px; color: var(--text-primary);">3. Upload Documents</h4>
            <div class="grid-2" style="gap: 15px; margin-bottom: 30px;">
              <div class="form-group" style="border: 2px dashed var(--border); border-radius: 8px; padding: 20px; text-align: center;">
                <label style="display: block; margin-bottom: 10px; font-weight: bold;"><i class="fas fa-id-card text-primary"></i> Aadhar Card</label>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">Accepted formats: JPG, PNG, PDF (Max 2MB)</div>
                <input type="file" name="aadhar" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required style="background: transparent; border: none; padding: 0;">
              </div>
              <div class="form-group" style="border: 2px dashed var(--border); border-radius: 8px; padding: 20px; text-align: center;">
                <label style="display: block; margin-bottom: 10px; font-weight: bold;"><i class="fas fa-camera text-primary"></i> Passport Photo</label>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">Accepted formats: JPG, PNG (Max 2MB)</div>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png" required style="background: transparent; border: none; padding: 0;">
              </div>
            </div>

            <div style="background: var(--primary-soft); border: 1px solid var(--primary-light); border-radius: 8px; padding: 20px; margin-bottom: 25px; color: var(--text-main); line-height: 1.5;">
               <i class="fas fa-info-circle text-primary" style="margin-right: 8px;"></i>
               <strong style="color: var(--primary);">What happens next?</strong> After submission, branch staff will review your request. Once approved, a locker will be assigned and you will be prompted to make your first payment to activate it.
            </div>

            <button type="submit" class="btn btn-primary w-100" style="padding: 14px; font-size: 1.1rem;">
               <i class="fas fa-paper-plane"></i> Submit Locker Application
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php 
$extraJS = <<<HTML
<script>
function updateSelection(radio) {
    document.querySelectorAll('input[name="size"]').forEach(el => {
        el.closest('label').style.borderColor = 'var(--border)';
        el.closest('label').style.background = 'var(--bg-alt)';
    });
    radio.closest('label').style.borderColor = 'var(--primary)';
    radio.closest('label').style.background = 'var(--primary-soft)';
}
function updatePlanSelection(radio) {
    document.querySelectorAll('input[name="plan_type"]').forEach(el => {
        el.closest('label').style.borderColor = 'var(--border)';
        el.closest('label').style.background = 'var(--bg-alt)';
    });
    radio.closest('label').style.borderColor = 'var(--primary)';
    radio.closest('label').style.background = 'var(--primary-soft)';
}
</script>
HTML;
include '../includes/footer.php'; 
?>
