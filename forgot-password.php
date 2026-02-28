<?php
/**
 * forgot-password.php – Password Recovery Simulation
 */
require_once 'config/config.php';
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $msg = 'Please enter your email address.'; $msgType = 'error';
    } else {
        // In a real app, send email with a token.
        // For this demo, we simulate success.
        $msg = "A password reset link has been sent to your email address (Simulated).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <div class="logo-icon"><i class="fas fa-key"></i></div>
            <h1>Recovery</h1>
            <p>Enter your email to reset password</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?>" style="margin-bottom:20px">
                <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-exmark' ?>"></i> <?= h($msg) ?>
            </div>
            <div style="text-align:center">
                <a href="index.php" class="btn btn-primary" style="display:inline-block;width:auto">Back to Login</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" class="form-control" placeholder="name@example.com" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
            </form>
            <div style="text-align:center;margin-top:20px">
                <a href="index.php" style="font-size:.85rem;color:var(--text-muted)">Wait, I remember it! Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
