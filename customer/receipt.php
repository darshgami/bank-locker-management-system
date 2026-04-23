<?php
/**
 * customer/receipt.php – Generate Payment Receipt (Printable/Save as PDF)
 */
require_once '../config/config.php';
requireRole('customer');

$db = getDB();
$user = currentUser();
$invoice = $_GET['inv'] ?? '';

if (!$invoice) {
    header("Location: dashboard.php");
    exit;
}

// Fetch payment details
$stmt = $db->prepare('
    SELECT p.*, l.locker_no, l.size, l.location, c.full_name, c.email, c.phone, cust.address
    FROM payments p
    JOIN locker_assignments la ON p.assignment_id = la.id
    JOIN lockers l ON la.locker_id = l.id
    JOIN customers cust ON la.customer_id = cust.id
    JOIN users c ON cust.user_id = c.id
    WHERE p.invoice_no = ? AND cust.user_id = ?
');
$stmt->execute([$invoice, $user['id']]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die('<div style="text-align:center;padding:60px;font-family:sans-serif">
         <h2>Receipt Not Found</h2>
         <p>Invalid invoice number or unauthorized access.</p>
         <a href="dashboard.php" style="color:teal">Return to Dashboard</a></div>');
}

$pageTitle = 'Receipt - ' . h($receipt['invoice_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
      :root {
          --primary: #00c9b1;
          --bg: #0f1923;
          --bg-card: #162230;
          --text-main: #e4eaf0;
          --text-muted: #7a93ac;
          --border: rgba(0, 201, 177, 0.18);
      }
      body {
          margin: 0;
          padding: 40px;
          background: var(--bg);
          color: var(--text-main);
          font-family: 'Inter', sans-serif;
          -webkit-font-smoothing: antialiased;
      }
      .receipt-container {
          max-width: 800px;
          margin: 0 auto;
          background: var(--bg-card);
          border-radius: 12px;
          box-shadow: 0 10px 40px rgba(0,0,0,0.4);
          overflow: hidden;
          border: 1px solid var(--border);
      }
      .receipt-header {
          background: rgba(0, 201, 177, 0.1);
          padding: 40px;
          border-bottom: 2px solid var(--primary);
          display: flex;
          justify-content: space-between;
          align-items: center;
      }
      .brand h1 {
          margin: 0;
          font-size: 2rem;
          color: var(--text-main);
          display: flex;
          align-items: center;
          gap: 10px;
      }
      .brand p {
          margin: 5px 0 0 45px;
          color: var(--primary);
          font-weight: 500;
      }
      .invoice-details {
          text-align: right;
      }
      .invoice-details h2 {
          margin: 0 0 10px 0;
          color: var(--text-main);
          text-transform: uppercase;
          letter-spacing: 2px;
      }
      .receipt-body {
          padding: 40px;
      }
      .info-grid {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 40px;
          margin-bottom: 40px;
      }
      .info-block h4 {
          color: var(--text-muted);
          text-transform: uppercase;
          font-size: 0.8rem;
          letter-spacing: 1px;
          margin: 0 0 10px 0;
      }
      .info-block p {
          margin: 5px 0;
          font-size: 1rem;
      }
      .table {
          width: 100%;
          border-collapse: collapse;
          margin-bottom: 40px;
      }
      .table th {
          background: rgba(0, 201, 177, 0.1);
          color: var(--text-muted);
          text-align: left;
          padding: 15px;
          text-transform: uppercase;
          font-size: 0.85rem;
          letter-spacing: 1px;
      }
      .table td {
          padding: 20px 15px;
          border-bottom: 1px solid var(--border);
          font-size: 1.1rem;
          color: var(--text-main);
      }
      .totals {
          width: 300px;
          margin-left: auto;
      }
      .totals-row {
          display: flex;
          justify-content: space-between;
          padding: 10px 0;
          color: var(--text-muted);
      }
      .totals-row.grand-total {
          color: var(--primary);
          font-size: 1.5rem;
          font-weight: bold;
          border-top: 2px solid var(--border);
          padding-top: 15px;
          margin-top: 10px;
      }
      .receipt-footer {
          padding: 30px 40px;
          text-align: center;
          background: rgba(0, 0, 0, 0.2);
          color: var(--text-muted);
          font-size: 0.9rem;
      }
      .actions {
          text-align: center;
          margin-top: 30px;
      }
      .btn {
          display: inline-block;
          padding: 12px 24px;
          background: var(--primary);
          color: #0b1520;
          text-decoration: none;
          font-weight: 700;
          border-radius: 8px;
          margin: 0 10px;
          transition: 0.2s;
          cursor: pointer;
          border: none;
          font-size: 1rem;
          box-shadow: 0 4px 12px rgba(0, 201, 177, 0.3);
      }
      .btn:hover {
          opacity: 0.9;
          transform: translateY(-1px);
      }
      .btn-ghost {
          background: rgba(255, 255, 255, 0.05);
          color: var(--text-main);
          border: 1px solid var(--border);
      }
      .btn-ghost:hover {
          background: rgba(255, 255, 255, 0.1);
          border-color: var(--primary);
      }
      
      @media print {
          body {
              background: #fff !important;
              color: #000 !important;
              padding: 0;
          }
          .receipt-container {
              box-shadow: none;
              border-radius: 0;
              background: #fff !important;
              border: 1px solid #ddd;
              color: #000 !important;
          }
          .brand h1, .brand p, .invoice-details h2, .totals-row.grand-total, .table td {
              color: #000 !important;
          }
          .receipt-header {
              border-bottom: 2px solid #000;
              background: #f8f9fa !important;
              color: #000 !important;
          }
          .table th {
              background: #f8f9fa !important;
              color: #000 !important;
          }
          .table td {
              border-bottom: 1px solid #ddd !important;
          }
          .actions {
              display: none !important;
          }
          .info-block h4, .totals-row, .receipt-footer {
              color: #555 !important;
              background: none !important;
          }
          .status-banner {
              background: #f0fdfa !important;
              color: #0d9488 !important;
              border: 1px solid #ccfbf1 !important;
          }
      }
  </style>
</head>
<body>

<div class="receipt-container">
    <div class="receipt-header">
        <div class="brand">
            <h1><i class="fas fa-bank"></i> <?= h(APP_NAME) ?></h1>
            <p>Payment Receipt</p>
        </div>
        <div class="invoice-details">
            <h2>RECEIPT</h2>
            <p style="margin:0;color:var(--text-muted)">No: <strong><?= h($receipt['invoice_no']) ?></strong></p>
            <p style="margin:5px 0 0 0;color:var(--text-muted)">Date: <?= date('d M Y', strtotime($receipt['payment_date'])) ?></p>
        </div>
    </div>

    <div class="receipt-body">
        
        <div class="status-banner" style="background:rgba(13, 148, 136, 0.1); color:var(--primary); padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 40px; font-weight: 600; display:flex; align-items:center; justify-content:center; gap: 10px;">
            <i class="fas fa-check-circle" style="font-size: 1.5rem;"></i> Payment Successful
        </div>

        <div class="info-grid">
            <div class="info-block">
                <h4>Billed To</h4>
                <p><strong><?= h($receipt['full_name']) ?></strong></p>
                <p><?= h($receipt['phone']) ?></p>
                <p><?= h($receipt['email']) ?></p>
            </div>
            <div class="info-block" style="text-align: right;">
                <h4>Payment Details</h4>
                <p>Status: <span style="color:var(--primary); font-weight:bold;">PAID</span></p>
                <p>Tax Rate: <?= number_format($receipt['tax_percent'] ?? 18, 1) ?>% (GST)</p>
                <p>Method: <?= h($receipt['payment_mode']) ?></p>
                <p>Ref ID: <?= h($receipt['transaction_id']) ?></p>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: center;">Plan Type</th>
                    <th style="text-align: center;">Validity</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong style="color:var(--primary)"><?= h($receipt['size']) ?> Locker Rental</strong><br>
                        <span style="font-size: 0.9rem; color: var(--text-muted);">Locker No: <?= h($receipt['locker_no']) ?> (<?= h($receipt['location']) ?>)</span>
                    </td>
                    <td style="text-align: center;"><?= h($receipt['plan_type']) ?></td>
                    <td style="text-align: center;">
                        <span style="font-size: 0.85rem; color: var(--text-muted);">
                            <?= date('d M Y', strtotime($receipt['payment_date'])) ?> to<br>
                            <?= date('d M Y', strtotime($receipt['due_date'])) ?>
                        </span>
                    </td>
                    <td style="text-align: right; font-weight: 500;">₹<?= number_format($receipt['amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="totals">
            <?php 
                $baseAmount = (float)($receipt['base_amount'] ?? ($receipt['amount'] / 1.18));
                $taxAmount  = (float)($receipt['tax_amount'] ?? ($receipt['amount'] - $baseAmount));
                $fees       = (float)($receipt['other_fees'] ?? 0.00);
                $cgst       = $taxAmount / 2;
                $sgst       = $taxAmount / 2;
            ?>
            <div class="totals-row">
                <span>Base Amount</span>
                <span>₹<?= number_format($baseAmount, 2) ?></span>
            </div>
            <div class="totals-row">
                <span>CGST (9%)</span>
                <span>₹<?= number_format($cgst, 2) ?></span>
            </div>
            <div class="totals-row">
                <span>SGST (9%)</span>
                <span>₹<?= number_format($sgst, 2) ?></span>
            </div>
            <div class="totals-row">
                <span>Other Fees</span>
                <span>₹<?= number_format($fees, 2) ?></span>
            </div>
            <div class="totals-row grand-total">
                <span>Total Paid</span>
                <span>₹<?= number_format($receipt['amount'], 2) ?></span>
            </div>
        </div>
    </div>

    <div class="receipt-footer">
        <p style="margin:0;">Thank you for trusting <?= h(APP_NAME) ?>. For inquiries, contact support at 1-800-LOCKER.</p>
        <p style="margin:5px 0 0 0; font-size: 0.8rem; opacity: 0.5;">This is an electronically generated receipt and does not require a physical signature.</p>
    </div>
</div>

<div class="actions">
    <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
    <a href="dashboard.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <a href="dashboard.php" class="page-close" title="Close" style="vertical-align: middle;"><i class="fas fa-times"></i></a>
</div>

</body>
</html>
