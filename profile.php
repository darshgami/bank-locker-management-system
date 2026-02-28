<?php
/**
 * profile.php – Unified Profile Management
 */
require_once 'config/config.php';
requireRole('admin', 'staff', 'customer');

$db = getDB();
$user = currentUser();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        
        if (!$name || !$email) {
            $error = "Name and Email are required.";
        } else {
            try {
                $db->beginTransaction();
                
                // Update basic info
                $st = $db->prepare('UPDATE users SET full_name=?, email=?, phone=? WHERE id=?');
                $st->execute([$name, $email, $phone, $user['id']]);
                
                // Update password if provided
                if ($newPass) {
                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $user['id']]);
                }
                
                $db->commit();
                
                // Refresh session
                $userSt = $db->prepare('SELECT * FROM users WHERE id=?');
                $userSt->execute([$user['id']]);
                $_SESSION['user'] = $userSt->fetch();
                
                $success = "Profile updated successfully.";
                logActivity("Updated profile", 'User', $user['id']);
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'My Profile';
$activePage = 'profile';
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="page-header">
       <div>
          <h1>My Profile</h1>
          <p>Manage your personal information and security settings.</p>
       </div>
       <?php 
          $dashLink = 'customer/dashboard.php';
          if ($user['role'] === 'admin') $dashLink = 'admin/dashboard.php';
          if ($user['role'] === 'staff') $dashLink = 'staff/dashboard.php';
       ?>
       <a href="<?= $dashLink ?>" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <?php if ($success): ?>
       <div class="alert alert-success" data-auto-dismiss><i class="fas fa-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
       <div class="alert alert-error" data-auto-dismiss><i class="fas fa-circle-xmark"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="grid-2" style="gap:25px;">
       <!-- Profile Info -->
       <div class="card">
          <div class="card-header">
             <h3><i class="fas fa-user-gear text-teal"></i> Account Information</h3>
          </div>
          <div class="card-body">
             <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_generate() ?>">
                <div class="form-group">
                   <label>Full Name</label>
                   <input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>" required>
                </div>
                <div class="form-group">
                   <label>Email Address</label>
                   <input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>" required>
                </div>
                <div class="form-group">
                   <label>Phone Number</label>
                   <input type="text" name="phone" class="form-control" value="<?= h($user['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                   <label>New Password (Leave blank to keep current)</label>
                   <input type="password" name="new_password" class="form-control" placeholder="••••••••">
                </div>
                <div style="margin-top:20px;">
                   <button type="submit" class="btn btn-primary w-100">Save Changes</button>
                </div>
             </form>
          </div>
       </div>

       <!-- Security & Meta -->
       <div class="card">
          <div class="card-header">
             <h3><i class="fas fa-shield-halved text-teal"></i> Security Status</h3>
          </div>
          <div class="card-body">
             <div style="display:flex; flex-direction:column; gap:15px;">
                <div style="background:rgba(255,255,255,0.03); padding:15px; border-radius:10px; border:1px solid var(--border);">
                   <span style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:5px;">Role</span>
                   <strong style="text-transform:uppercase; letter-spacing:1px; color:var(--teal);"><?= h($user['role']) ?></strong>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:15px; border-radius:10px; border:1px solid var(--border);">
                   <span style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:5px;">Member Since</span>
                   <strong><?= date('M d, Y', strtotime($user['created_at'])) ?></strong>
                </div>
                <div style="background:rgba(0,201,177,0.05); padding:15px; border-radius:10px; border:1px solid rgba(0,201,177,0.2);">
                   <p style="margin:0; font-size:0.85rem; color:var(--text-muted);">
                      <i class="fas fa-circle-info text-teal"></i> Biometric simulation and OTP-based access are enabled for your account for enhanced vault security.
                   </p>
                </div>
             </div>
          </div>
       </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
