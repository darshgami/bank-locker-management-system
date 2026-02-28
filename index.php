<?php
/**
 * index.php – Unified Login Page
 * Smart Bank Locker Management System
 * Handles Admin / Staff / Customer login with tabs
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
$tab   = $_GET['tab'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'admin';
        $tab      = $role;

        if (!$email || !$password) {
            $error = 'Email and password are required.';
        } else {
            $db = getDB();
            $st = $db->prepare('SELECT * FROM users WHERE email=? AND role=? AND status="active" LIMIT 1');
            $st->execute([$email, $role]);
            $user = $st->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Regenerate session for security
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user']    = [
                    'id'        => $user['id'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'role'      => $user['role'],
                    'phone'     => $user['phone'],
                ];
                logActivity('Login', 'Auth', $user['id']);

                $redir = match($role) {
                    'admin'  => BASE_URL . '/admin/dashboard.php',
                    'staff'  => BASE_URL . '/staff/dashboard.php',
                    default  => BASE_URL . '/customer/dashboard.php',
                };
                header("Location: $redir");
                exit;
            } else {
                $error = 'Invalid email or password.';
                logActivity("Failed login attempt for: $email", 'Auth');
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
  <title>Login – <?= APP_NAME ?></title>
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
      <p>Secure Digital Vault Access Portal</p>
    </div>

    <!-- Error Alert -->
    <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        <i class="fas fa-circle-xmark"></i> <?= h($error) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['msg'])): ?>
      <?php $msgs = ['login_required'=>'Please login to continue.','logged_out'=>'You have been logged out safely.']; ?>
      <div class="alert alert-warning" data-auto-dismiss>
        <i class="fas fa-triangle-exclamation"></i>
        <?= h($msgs[$_GET['msg']] ?? 'Notice.') ?>
      </div>
    <?php endif; ?>

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

    <!-- Login Form -->
    <form method="POST" action="" id="loginForm" onsubmit="return validateForm('loginForm')">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="role" id="roleInput" value="<?= h($tab) ?>">

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
        <label for="password">Password</label>
        <div class="input-icon" style="position:relative">
          <i class="fas fa-lock"></i>
          <input type="password" id="passwordInput" name="password" class="form-control"
                 placeholder="Enter password" autocomplete="current-password" required>
          <button type="button" onclick="togglePwd()"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer">
            <i class="fas fa-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" id="loginBtn">
        <span id="loginBtnText"><i class="fas fa-right-to-bracket"></i> &nbsp;Sign In</span>
      </button>

      <div style="margin-top: 15px; text-align: center;">
        <span style="color: var(--text-muted); font-size: 0.9rem;">
          Don't have an account? <a href="<?= BASE_URL ?>/register.php?tab=<?= h($tab) ?>" style="color: var(--teal); text-decoration: none; font-weight: 600;">Register here</a>
        </span>
      </div>
    </form>

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
document.getElementById('loginForm').addEventListener('submit',()=>{
  const btn  = document.getElementById('loginBtn');
  const text = document.getElementById('loginBtnText');
  btn.disabled = true;
  text.innerHTML = '<i class="fas fa-spinner spin"></i> &nbsp;Signing in…';
});
</script>
</body>
</html>
