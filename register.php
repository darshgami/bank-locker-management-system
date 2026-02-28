<?php
/**
 * register.php – Unified Registration Page
 * Smart Bank Locker Management System
 * Handles Admin / Staff / Customer registration
 */
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $r = currentUser()['role'];
    header('Location: ' . match($r) {
        'admin'  => BASE_URL . '/admin/dashboard.php',
        'staff'  => BASE_URL . '/staff/dashboard.php',
        default  => BASE_URL . '/customer/dashboard.php',
    });
    exit;
}

$error = '';
$success = '';
$tab   = $_GET['tab'] ?? 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role'] ?? 'customer';
        $tab       = $role;

        // Validation
        if (!$full_name || !$email || !$phone || !$password) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $db = getDB();
            
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $error = 'Email address is already registered.';
            } else {
                try {
                    $db->beginTransaction();

                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insert user
                    $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([$full_name, $email, $phone, $hashed_password, $role]);
                    $user_id = $db->lastInsertId();

                    // If customer, insert into customers table
                    if ($role === 'customer') {
                        $stmt = $db->prepare("INSERT INTO customers (user_id, kyc_status, risk_level) VALUES (?, 'pending', 'low')");
                        $stmt->execute([$user_id]);
                    }

                    $db->commit();
                    
                    logActivity("New registration: $role", 'Auth', $user_id);
                    
                    $success = 'Registration successful! You can now login.';
                    
                    // Clear post data so form isn't repopulated
                    $_POST = [];
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Registration failed. Please try again later. Error: ' . $e->getMessage();
                    error_log("Registration error: " . $e->getMessage());
                }
            }
        }
    }
}

$csrf = csrf_generate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register – <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">


  <!-- Theme Initialization (Prevents flickering) -->
  <script>
    (function() {
      const savedTheme = localStorage.getItem('bank_locker_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', savedTheme);
    })();
  </script>
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">

    <!-- Logo -->
    <div class="login-logo">
      <div class="logo-icon">🏦</div>
      <h1><?= APP_NAME ?></h1>
      <p>Create Your Account</p>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        <i class="fas fa-circle-xmark"></i> <?= h($error) ?>
      </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= h($success) ?>
        <div style="margin-top: 10px; text-align: center;">
            <a href="<?= BASE_URL ?>/index.php?tab=<?= h($tab) ?>" class="btn btn-primary" style="display: inline-block; padding: 8px 16px; border-radius: 4px; text-decoration: none;">Go to Login</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <!-- Role Tabs -->
    <div class="login-tabs" role="tablist">
      <button class="login-tab-btn <?= $tab==='admin'?'active':'' ?>"
              onclick="switchTab('admin')" type="button" id="tab-admin">
        <i class="fas fa-user-shield"></i> Admin
      </button>
      <button class="login-tab-btn <?= $tab==='staff'?'active':'' ?>"
              onclick="switchTab('staff')" type="button" id="tab-staff">
        <i class="fas fa-user-tie"></i> Staff
      </button>
      <button class="login-tab-btn <?= $tab==='customer'?'active':'' ?>"
              onclick="switchTab('customer')" type="button" id="tab-customer">
        <i class="fas fa-user"></i> Customer
      </button>
    </div>

    <!-- Register Form -->
    <form method="POST" action="" id="registerForm" onsubmit="return validateForm('registerForm')">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="role" id="roleInput" value="<?= h($tab) ?>">

      <div class="form-group">
        <label for="full_name">Full Name</label>
        <div class="input-icon">
          <i class="fas fa-user"></i>
          <input type="text" id="full_name" name="full_name" class="form-control"
                 placeholder="Enter your full name" value="<?= h($_POST['full_name'] ?? '') ?>"
                 required>
        </div>
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <div class="input-icon">
          <i class="fas fa-envelope"></i>
          <input type="email" id="email" name="email" class="form-control"
                 placeholder="Enter your email" value="<?= h($_POST['email'] ?? '') ?>"
                 autocomplete="email" required>
        </div>
      </div>

      <div class="form-group">
        <label for="phone">Phone Number</label>
        <div class="input-icon">
          <i class="fas fa-phone"></i>
          <input type="tel" id="phone" name="phone" class="form-control"
                 placeholder="Enter your phone number" value="<?= h($_POST['phone'] ?? '') ?>"
                 required>
        </div>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-icon" style="position:relative">
          <i class="fas fa-lock"></i>
          <input type="password" id="passwordInput" name="password" class="form-control"
                 placeholder="Create a password (min 6 chars)" autocomplete="new-password" required minlength="6">
          <button type="button" onclick="togglePwd()"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
            <i class="fas fa-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" id="registerBtn">
        <span id="registerBtnText"><i class="fas fa-user-plus"></i> &nbsp;Register Account</span>
      </button>

      <div style="margin-top: 15px; text-align: center;">
        <span style="color: var(--text-muted); font-size: 0.9rem;">
          Already have an account? <a href="<?= BASE_URL ?>/index.php" style="color: var(--teal); text-decoration: none; font-weight: 600;">Sign in here</a>
        </span>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
function switchTab(role) {
  document.getElementById('roleInput').value = role;
  ['admin','staff','customer'].forEach(r => {
    document.getElementById('tab-'+r).classList.toggle('active', r===role);
  });
}
function togglePwd() {
  const inp = document.getElementById('passwordInput');
  const ico = document.getElementById('eyeIcon');
  if (inp.type === 'password') { inp.type='text'; ico.className='fas fa-eye-slash'; }
  else { inp.type='password'; ico.className='fas fa-eye'; }
}
<?php if (!$success): ?>
document.getElementById('registerForm').addEventListener('submit',()=>{
  const btn  = document.getElementById('registerBtn');
  const text = document.getElementById('registerBtnText');
  btn.disabled = true;
  text.innerHTML = '<i class="fas fa-spinner spin"></i> &nbsp;Registering…';
});
<?php endif; ?>
</script>
</body>
</html>
