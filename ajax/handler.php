<?php
/**
 * ajax/handler.php — Central AJAX Endpoint
 * Handles: search_suggest, generate_otp, verify_otp,
 *          suggest_lockers, invoice (GET)
 *
 * All responses: JSON  (except invoice = HTML)
 */
require_once '../config/config.php';

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$action = $_REQUEST['action'] ?? '';
$db     = getDB();
$user   = currentUser();
$role   = $user['role'];

// ── CSRF for mutating actions ─────────────────────────────────────────────────
$mutatingActions = ['generate_otp','verify_otp','log_exit'];
if (in_array($action, $mutatingActions)) {
    // AJAX calls carry CSRF in POST body (added by JS ajaxPost)
    // We trust session + logged-in check; optionally enforce token here
}

switch ($action) {

    // ── SEARCH SUGGESTION ─────────────────────────────────────────────────────
    case 'search_suggest':
        $q       = '%' . trim($_POST['q'] ?? '') . '%';
        $results = [];

        if ($role === 'admin' || $role === 'staff') {
            // Search lockers
            $st = $db->prepare(
              'SELECT id, locker_no AS label, status AS sub, "fa-lock" AS icon
               FROM lockers WHERE locker_no LIKE ? LIMIT 5'
            );
            $st->execute([$q]);
            $results = array_merge($results, $st->fetchAll());

            // Search customers
            $st2 = $db->prepare(
              'SELECT u.id, u.full_name AS label, u.phone AS sub, "fa-user" AS icon
               FROM users u WHERE u.role="customer" AND (u.full_name LIKE ? OR u.phone LIKE ?) LIMIT 5'
            );
            $st2->execute([$q, $q]);
            $results = array_merge($results, $st2->fetchAll());
        } else {
            // Customer can search their own payment invoices
            $custSt = $db->prepare('SELECT id FROM customers WHERE user_id=?');
            $custSt->execute([$user['id']]);
            $cust = $custSt->fetch();
            if ($cust) {
                $st = $db->prepare(
                  'SELECT p.id, p.invoice_no AS label, p.status AS sub, "fa-file-invoice" AS icon
                   FROM payments p
                   JOIN locker_assignments la ON la.id=p.assignment_id
                   WHERE la.customer_id=? AND p.invoice_no LIKE ? LIMIT 5'
                );
                $st->execute([$cust['id'], $q]);
                $results = $st->fetchAll();
            }
        }
        jsonResponse(['results' => $results]);

    // ── GENERATE OTP ─────────────────────────────────────────────────────────
    case 'generate_otp':
        if ($role === 'customer') jsonResponse(['error'=>'Not allowed'], 403);
        $custId = (int)($_POST['customer_id'] ?? 0);
        if (!$custId) jsonResponse(['error'=>'Invalid customer'], 400);

        // Validate customer exists
        $st = $db->prepare('SELECT user_id FROM customers WHERE id=?');
        $st->execute([$custId]);
        $row = $st->fetch();
        if (!$row) jsonResponse(['error'=>'Customer not found'], 404);

        $otp = generateOTP((int)$row['user_id'], 'locker_access');
        logActivity("OTP generated for customer $custId", 'OTP');
        jsonResponse(['otp' => $otp, 'expires_in' => OTP_EXPIRY_MINUTES * 60]);

    // ── VERIFY OTP ───────────────────────────────────────────────────────────
    case 'verify_otp':
        $custId = (int)($_POST['customer_id'] ?? 0);
        $otp    = trim($_POST['otp'] ?? '');
        if (!$custId || !$otp) jsonResponse(['verified'=>false,'error'=>'Missing params'], 400);

        $st = $db->prepare('SELECT user_id FROM customers WHERE id=?');
        $st->execute([$custId]);
        $row = $st->fetch();
        if (!$row) jsonResponse(['verified'=>false,'error'=>'Customer not found'], 404);

        $ok = verifyOTP((int)$row['user_id'], $otp, 'locker_access');
        logActivity("OTP " . ($ok?'verified':'failed') . " for customer $custId", 'OTP');
        jsonResponse(['verified' => $ok]);

    // ── SMART LOCKER SUGGESTION ───────────────────────────────────────────────
    case 'suggest_lockers':
        $size    = $_POST['size'] ?? 'Small';
        $allowed = ['Small','Medium','Large'];
        if (!in_array($size,$allowed)) $size = 'Small';

        $st = $db->prepare(
          'SELECT locker_no, size, rent_amount, location
           FROM lockers WHERE status="Available" AND size=?
           ORDER BY rent_amount ASC LIMIT 5'
        );
        $st->execute([$size]);
        $lockers = $st->fetchAll();
        jsonResponse(['lockers' => $lockers]);

    // ── INVOICE (GET) ─────────────────────────────────────────────────────────
    case 'invoice':
        $pid = (int)($_GET['id'] ?? 0);
        if (!$pid) die('Invalid invoice ID.');

        $st = $db->prepare(
          'SELECT p.*, la.*, l.locker_no, l.size, l.location, l.rent_amount,
                  u.full_name, u.email, u.phone,
                  c.address, c.id_type, c.id_number
           FROM payments p
           JOIN locker_assignments la ON la.id=p.assignment_id
           JOIN lockers l ON l.id=la.locker_id
           JOIN customers c ON c.id=la.customer_id
           JOIN users u ON u.id=c.user_id
           WHERE p.id=? LIMIT 1'
        );
        $st->execute([$pid]);
        $inv = $st->fetch();
        if (!$inv) die('Invoice not found.');

        // Access control
        if ($role === 'customer') {
            $check = $db->prepare('SELECT id FROM customers WHERE user_id=?');
            $check->execute([$user['id']]);
            $me = $check->fetch();
            if (!$me || $me['id'] !== $inv['customer_id']) die('Access denied.');
        }

        // Output HTML invoice (printable)
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice <?= h($inv['invoice_no']) ?></title>
  <style>
    body{font-family:'Segoe UI',sans-serif;background:#f5f5f5;margin:0;padding:30px}
    .inv{max-width:680px;margin:0 auto;background:#fff;padding:40px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.1)}
    .inv-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;border-bottom:2px solid #00a896;padding-bottom:18px}
    .brand-name{font-size:1.5rem;font-weight:800;color:#00a896}
    .brand-tag{font-size:.8rem;color:#888;margin-top:3px}
    .inv-info{text-align:right}
    .inv-info h2{font-size:1.1rem;color:#333;margin:0 0 5px}
    .inv-info p{font-size:.82rem;color:#888;margin:2px 0}
    .section{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px}
    .box h4{font-size:.78rem;text-transform:uppercase;letter-spacing:.8px;color:#888;margin-bottom:8px}
    .box p{margin:3px 0;font-size:.88rem;color:#333}
    table{width:100%;border-collapse:collapse;margin-bottom:18px}
    th{background:#f0faf9;padding:10px 14px;text-align:left;font-size:.8rem;color:#555;border-bottom:2px solid #00a896}
    td{padding:10px 14px;border-bottom:1px solid #eee;font-size:.88rem}
    .total-row{background:#f0faf9;font-weight:700}
    .status-paid{background:#d4f5ef;color:#00a896;padding:4px 12px;border-radius:20px;font-size:.8rem;font-weight:700;display:inline-block}
    .status-pending{background:#fff3cd;color:#856404;padding:4px 12px;border-radius:20px;font-size:.8rem;font-weight:700;display:inline-block}
    .status-overdue{background:#f8d7da;color:#721c24;padding:4px 12px;border-radius:20px;font-size:.8rem;font-weight:700;display:inline-block}
    .footer{margin-top:30px;text-align:center;font-size:.75rem;color:#bbb;border-top:1px solid #eee;padding-top:14px}
    @media print{body{background:#fff;padding:0}.inv{box-shadow:none;border-radius:0}.no-print{display:none}}
  </style>
</head>
<body>
  <p class="no-print" style="text-align:center;margin-bottom:16px">
    <button onclick="window.print()" style="padding:9px 22px;background:#00a896;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">
      🖨 Print Invoice
    </button>
    <button onclick="window.close()" style="padding:9px 22px;background:#eee;color:#333;border:none;border-radius:6px;cursor:pointer;margin-left:10px">
      Close
    </button>
  </p>

  <div class="inv">
    <div class="inv-head">
      <div>
        <div class="brand-name">🏦 <?= APP_NAME ?></div>
        <div class="brand-tag">Smart Bank Locker Management System</div>
      </div>
      <div class="inv-info">
        <h2>TAX INVOICE</h2>
        <p><b><?= h($inv['invoice_no']) ?></b></p>
        <p>Date: <?= date('d F Y', strtotime($inv['payment_date'])) ?></p>
        <p>Due: <?= date('d F Y', strtotime($inv['due_date'])) ?></p>
      </div>
    </div>

    <div class="section">
      <div class="box">
        <h4>Billed To</h4>
        <p><b><?= h($inv['full_name']) ?></b></p>
        <p><?= h($inv['email']) ?></p>
        <p><?= h($inv['phone']) ?></p>
        <?php if($inv['address']): ?><p><?= h($inv['address']) ?></p><?php endif; ?>
        <p><?= h($inv['id_type']) ?>: <?= h($inv['id_number']) ?></p>
      </div>
      <div class="box">
        <h4>Locker Details</h4>
        <p>Locker No: <b><?= h($inv['locker_no']) ?></b></p>
        <p>Size: <?= h($inv['size']) ?></p>
        <p>Location: <?= h($inv['location']) ?></p>
        <p>Assigned Since: <?= date('d M Y',strtotime($inv['start_date'])) ?></p>
      </div>
    </div>

    <table>
      <thead><tr>
        <th>#</th><th>Description</th><th>Period</th><th>Amount</th><th>Status</th>
      </tr></thead>
      <tbody>
        <tr>
          <td>1</td>
          <td>Locker Rent – <?= h($inv['locker_no']) ?> (<?= h($inv['size']) ?>)</td>
          <td><?= date('d M Y',strtotime($inv['payment_date'])) ?></td>
          <td>₹<?= number_format($inv['amount'],2) ?></td>
          <td><span class="status-<?= strtolower($inv['status']) ?>"><?= h($inv['status']) ?></span></td>
        </tr>
        <tr class="total-row">
          <td colspan="3" style="text-align:right">Total</td>
          <td>₹<?= number_format($inv['amount'],2) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>

    <?php if($inv['transaction_id']): ?>
    <p style="font-size:.83rem;color:#555"><b>Transaction Ref:</b> <?= h($inv['transaction_id']) ?></p>
    <?php endif; ?>
    <?php if($inv['payment_mode']): ?>
    <p style="font-size:.83rem;color:#555"><b>Payment Mode:</b> <?= h($inv['payment_mode']) ?></p>
    <?php endif; ?>
    <?php if($inv['remarks']): ?>
    <p style="font-size:.83rem;color:#555"><b>Remarks:</b> <?= h($inv['remarks']) ?></p>
    <?php endif; ?>

    <div class="footer">
      <p>This is a computer-generated invoice. No signature required.</p>
      <p><?= APP_NAME ?> · <?= BASE_URL ?></p>
    </div>
  </div>
</body>
</html><?php
        exit;

    // ── DEFAULT ───────────────────────────────────────────────────────────────
    default:
        jsonResponse(['error' => 'Unknown action: ' . h($action)], 400);
}
