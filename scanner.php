<?php
/**
 * scanner.php – Simulated QR Scanner for Locker Vault Access
 * This page simulates a hardware scanner at the vault door.
 */
require_once 'config/config.php';

// Publicly accessible but we will simulate reading a token 
// Optionally you can require an admin/staff login to operate the scanner
// but for demo purposes, it's open.
$db = getDB();

$message = '';
$statusClass = '';
$scannedToken = $_POST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $scannedToken) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $message = "Invalid session token.";
        $statusClass = "error";
    } else {
        // 1. Find the token and its associated locker/customer
        $stmt = $db->prepare('
            SELECT q.*, c.user_id, c.risk_level, l.locker_no, la.end_date, la.is_active
            FROM qr_tokens q
            JOIN customers c ON q.customer_id = c.id
            JOIN lockers l ON q.locker_id = l.id
            JOIN locker_assignments la ON la.locker_id = l.id AND la.customer_id = c.id AND la.is_active = 1
            WHERE q.token = ?
        ');
        $stmt->execute([$scannedToken]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            $message = "ACCESS DENIED - Invalid or unrecognized QR Code.";
            $statusClass = "error";
        } elseif ($tokenData['is_used']) {
            $message = "ACCESS DENIED - This QR Code has already been used.";
            $statusClass = "error";
        } else {
            // Check expiry
            if (strtotime($tokenData['expires_at']) < time()) {
                $message = "ACCESS DENIED - QR Code Expired. Please generate a new one.";
                $statusClass = "error";
                
                // Log failed attempt
                $db->prepare("INSERT INTO access_logs (customer_id, locker_id, entry_time, qr_token_used, access_status, notes) VALUES (?, ?, NOW(), ?, 'Failed', 'QR Expired')")
                   ->execute([$tokenData['customer_id'], $tokenData['locker_id'], substr($scannedToken, 0, 16)]);
            } else {
                // Check Plan Status
                if (strtotime($tokenData['end_date']) < time()) {
                    $message = "ACCESS DENIED - Your locker plan has expired.";
                    $statusClass = "error";
                    
                    // Log failed attempt
                    $db->prepare("INSERT INTO access_logs (customer_id, locker_id, entry_time, qr_token_used, access_status, notes) VALUES (?, ?, NOW(), ?, 'Failed', 'Plan Expired')")
                       ->execute([$tokenData['customer_id'], $tokenData['locker_id'], substr($scannedToken, 0, 16)]);
                } else {
                    // Check Emergency Global Lock
                    $sys = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='emergency_global_lock'")->fetchColumn();
                    if ($sys === '1') {
                        $message = "ACCESS DENIED - EMERGENCY LOCKDOWN IN EFFECT.";
                        $statusClass = "error";
                    } else {
                        // SUCCESS!
                        $message = "ACCESS GRANTED - Locker {$tokenData['locker_no']} unlocked.";
                        $statusClass = "success";
                        
                        // Mark token used
                        $db->prepare("UPDATE qr_tokens SET is_used = 1 WHERE id = ?")->execute([$tokenData['id']]);
                        
                        // Log successful access
                        $db->prepare("INSERT INTO access_logs (customer_id, locker_id, entry_time, qr_token_used, access_status, notes) VALUES (?, ?, NOW(), ?, 'Success', 'QR Auth')")
                           ->execute([$tokenData['customer_id'], $tokenData['locker_id'], substr($scannedToken, 0, 16)]);
                           
                        // Could also check Risk Level here to trigger a secondary auth / biometric in a full system
                    }
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
  <title>Locker Scanner Simulation</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
      :root {
          --primary: #0d9488;
          --red: #ef476f;
          --bg: #f8fafc;
          --card: #ffffff;
      }
      body {
          margin: 0;
          height: 100vh;
          background: var(--bg);
          color: #1e293b;
          font-family: 'Inter', sans-serif;
          display: flex;
          align-items: center;
          justify-content: center;
      }
      .scanner-ui {
          background: var(--card);
          padding: 40px;
          border-radius: 20px;
          box-shadow: 0 20px 50px rgba(0,0,0,0.05);
          width: 100%;
          max-width: 500px;
          text-align: center;
          border: 1px solid #e2e8f0;
      }
      .camera-feed {
          background: #f1f5f9;
          height: 250px;
          border-radius: 12px;
          margin-bottom: 20px;
          border: 2px dashed #cbd5e1;
          display: flex;
          align-items: center;
          justify-content: center;
          position: relative;
          overflow: hidden;
      }
      .scan-line {
          position: absolute;
          width: 100%;
          height: 4px;
          background: rgba(13, 148, 136, 0.5);
          box-shadow: 0 0 10px var(--primary);
          top: 0;
          animation: scan 2s linear infinite;
      }
      @keyframes scan {
          0% { top: 0; opacity: 0;}
          5% { opacity: 1;}
          95% { opacity: 1;}
          100% { top: 100%; opacity: 0;}
      }
      input[type="text"] {
          width: 100%;
          padding: 15px;
          background: #f1f5f9;
          border: 1px solid #e2e8f0;
          color: #1e293b;
          border-radius: 8px;
          font-family: monospace;
          margin-bottom: 15px;
          box-sizing: border-box;
      }
      button {
          width: 100%;
          padding: 15px;
          background: var(--primary);
          color: #fff;
          border: none;
          border-radius: 8px;
          font-weight: bold;
          font-size: 1.1rem;
          cursor: pointer;
          transition: 0.2s;
      }
      button:hover { background: #0b7a70; }
      
      .status { margin-top: 25px; padding: 20px; border-radius: 8px; font-weight: bold; display:none; }
      .status.success { background: rgba(13, 148, 136, 0.1); color: var(--primary); border: 1px solid var(--primary); display:block; }
      .status.error { background: rgba(239, 71, 111, 0.1); color: var(--red); border: 1px solid var(--red); display:block; }
  </style>
</head>
<body>

<div class="scanner-ui">
    <h2 style="margin-top:0;"><i class="fas fa-expand"></i> Vault Scanner Simulation</h2>
    <p style="color:#94a3b8; font-size:0.9rem; margin-bottom:30px;">
        To test, generate a QR token on the Customer Dashboard, then paste the 64-character token here to simulate scanning it within the 60-second window.
    </p>
    
    <div class="camera-feed">
        <div class="scan-line"></div>
        <i class="fas fa-qrcode" style="font-size: 5rem; color: rgba(255,255,255,0.05);"></i>
    </div>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="text" name="token" placeholder="Paste Token String Here..." required autocomplete="off">
        <button type="submit"><i class="fas fa-camera"></i> Simulate Scan</button>
    </form>
    
    <?php if ($message): ?>
    <div class="status <?= $statusClass ?>">
        <?= h($message) ?>
    </div>
    <?php endif; ?>
    
    <div style="margin-top: 20px;">
        <a href="index.php" style="color: #94a3b8; text-decoration: none; font-size: 0.85rem;"><i class="fas fa-home"></i> Back to Main Site</a>
    </div>
</div>

</body>
</html>
