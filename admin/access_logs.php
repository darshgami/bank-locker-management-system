<?php
/**
 * admin/access_logs.php — Security & Access Logs
 * OTP generation, biometric simulation, entry/exit tracking
 */
require_once '../config/config.php';
requireRole('admin','staff');

$db         = getDB();
$pageTitle  = 'Access Logs';
$activePage = 'access_logs';
$csrf       = csrf_generate();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF error.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // ── LOG ENTRY ──
        if ($action === 'log_entry') {
            $cid     = (int)$_POST['customer_id'];
            $lid     = (int)$_POST['locker_id'];
            $otp     = trim($_POST['otp_used'] ?? '');
            $bio     = isset($_POST['biometric_ok']) ? 1 : 0;
            $staff   = (int)currentUser()['id'];
            $entry   = date('Y-m-d H:i:s');

            // verify OTP
            $otpOk = verifyOTP($cid, $otp, 'locker_access');
            if (!$otpOk && $otp !== '') {
                $msg = 'OTP verification failed.'; $msgType = 'error';
            } else {
                $db->prepare(
                  'INSERT INTO access_logs (customer_id,locker_id,entry_time,otp_used,biometric_ok,staff_id,notes)
                   VALUES (?,?,?,?,?,?,?)'
                )->execute([$cid,$lid,$entry,$otp,$bio,$staff,$_POST['notes']??'']);
                logActivity("Locker access granted for customer $cid on locker $lid", 'Access');
                $msg = 'Entry logged successfully.';
            }
        }

        // ── LOG EXIT ──
        elseif ($action === 'log_exit') {
            $logId = (int)$_POST['log_id'];
            $db->prepare('UPDATE access_logs SET exit_time=NOW() WHERE id=?')->execute([$logId]);
            $msg = 'Exit time recorded.';
        }
    }
}

// Active assignments for dropdown
$assignments = $db->query(
  'SELECT la.id, l.id AS locker_id, l.locker_no, c.id AS cust_id, u.full_name
   FROM locker_assignments la
   JOIN lockers l ON l.id=la.locker_id
   JOIN customers c ON c.id=la.customer_id
   JOIN users u ON u.id=c.user_id
   WHERE la.is_active=1 ORDER BY u.full_name'
)->fetchAll();

// Fetch logs
$logs = $db->query(
  'SELECT ac.*, u.full_name AS cust_name, l.locker_no,
          su.full_name AS staff_name
   FROM access_logs ac
   JOIN customers c ON c.id=ac.customer_id
   JOIN users u ON u.id=c.user_id
   JOIN lockers l ON l.id=ac.locker_id
   LEFT JOIN users su ON su.id=ac.staff_id
   ORDER BY ac.entry_time DESC LIMIT 200'
)->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Access Logs</div>
    
    <div class="page-header">
      <div>
        <h1>Security & Access Logs</h1>
        <p>OTP verification, biometric simulation, and locker entry/exit tracking.</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" onclick="openModal('logEntryModal')">
          <i class="fas fa-plus"></i> Log New Entry
        </button>
        <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
        <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i> <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <!-- OTP Generator Panel -->
    <div class="card mb-3" style="margin-bottom:20px">
      <div class="card-header">
        <h3><i class="fas fa-key text-teal"></i> OTP Generator</h3>
      </div>
      <div class="card-body">
        <div class="grid-2" style="align-items:start;gap:28px">
          <!-- Left: Controls -->
          <div>
            <div class="form-group">
              <label>Select Customer Assignment</label>
              <select id="otpAssignSelect" class="form-select" onchange="updateOtpIds(this)">
                <option value="">— Select —</option>
                <?php foreach ($assignments as $a): ?>
                  <option value="<?= (int)$a['cust_id'] ?>"
                          data-locker="<?= h($a['locker_no']) ?>">
                    <?= h($a['full_name']) ?> → <?= h($a['locker_no']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary" onclick="triggerOTPGeneration()">
              <i class="fas fa-key"></i> Generate OTP
            </button>
          </div>
          <!-- Right: OTP Display -->
          <div>
            <div id="otpBox" style="display:none">
              <div class="otp-display" id="otpDisplay">------</div>
              <p id="otpInfo" style="font-size:.82rem;color:var(--text-muted);text-align:center;margin-top:6px"></p>
              <p id="otpCountdown" style="font-size:.8rem;color:var(--teal);text-align:center;font-weight:600"></p>

              <!-- Verify input -->
              <div style="display:flex;gap:8px;margin-top:14px">
                <input type="text" id="otpInput" class="form-control" placeholder="Enter OTP to verify" maxlength="6">
                <button class="btn btn-ghost" id="otpVerifyBtn" onclick="verifyOTPUI(window._otpCustId)">
                  <i class="fas fa-check"></i> Verify
                </button>
              </div>
            </div>
            <div id="bioSection" style="text-align:center;display:none">
              <p style="color:var(--text-muted);font-size:.82rem;margin-top:12px">Biometric Verification</p>
              <div class="biometric-ring" id="bio-ring" onclick="simulateBiometric('bio-ring')">
                <i class="fas fa-fingerprint"></i>
              </div>
              <p style="font-size:.75rem;color:var(--text-muted)">Tap to scan fingerprint</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-shield-halved text-teal"></i> Access Log History</h3>
        <div class="search-wrapper" style="min-width:220px">
          <i class="fas fa-magnifying-glass search-icon"></i>
          <input type="text" id="logSearch" class="form-control" placeholder="Search logs…">
        </div>
      </div>
      <div class="table-container">
        <table class="tbl" id="logTable">
          <thead><tr>
            <th>#</th><th>Customer</th><th>Locker</th><th>Entry Time</th>
            <th>Exit Time</th><th>Method</th><th>Biometric</th><th>Staff</th><th>Action</th>
          </tr></thead>
          <tbody>
          <?php foreach($logs as $i => $log): ?>
          <tr>
            <td style="color:var(--text-muted)"><?= $i+1 ?></td>
            <td><b><?= h($log['cust_name']) ?></b></td>
            <td><span class="badge badge-available"><?= h($log['locker_no']) ?></span></td>
            <td style="font-size:.82rem"><?= date('d M H:i:s', strtotime($log['entry_time'])) ?></td>
            <td style="font-size:.82rem;color:var(--text-muted)">
              <?= $log['exit_time'] ? date('d M H:i:s', strtotime($log['exit_time'])) : '<span class="badge badge-pending">Inside</span>' ?>
            </td>
            <td>
              <?php if ($log['qr_token_used']): ?>
                <span class="badge badge-verified" style="background:rgba(0,201,177,0.1); color:var(--teal); border: 1px solid rgba(0,201,177,0.2)">
                    <i class="fas fa-qrcode"></i> QR
                </span>
              <?php elseif ($log['otp_used']): ?>
                <span class="badge badge-verified"><i class="fas fa-key"></i> OTP</span>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.8rem">Manual</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($log['biometric_ok']): ?>
                <span class="badge badge-verified"><i class="fas fa-fingerprint"></i> OK</span>
              <?php else: ?>
                <span class="badge badge-pending"><i class="fas fa-fingerprint"></i> Skip</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.8rem;color:var(--text-muted)"><?= h($log['staff_name'] ?? '—') ?></td>
            <td>
              <?php if (!$log['exit_time']): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="log_exit">
                <input type="hidden" name="log_id" value="<?= (int)$log['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">
                  <i class="fas fa-right-from-bracket"></i> Exit
                </button>
              </form>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.78rem">Completed</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">No access logs yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ LOG ENTRY MODAL ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="logEntryModal">
  <div class="modal-box">
    <div class="modal-header">
      <h4><i class="fas fa-plus text-teal"></i> Log Locker Entry</h4>
      <button class="modal-close" onclick="closeModal('logEntryModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="log_entry">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group">
          <label>Customer & Locker *</label>
          <select name="customer_id" id="entryAssignSelect" class="form-select" required
                  onchange="fillLockerId(this)">
            <option value="">— Select Active Assignment —</option>
            <?php foreach ($assignments as $a): ?>
              <option value="<?= (int)$a['cust_id'] ?>" data-locker-id="<?= (int)$a['locker_id'] ?>">
                <?= h($a['full_name']) ?> → <?= h($a['locker_no']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <input type="hidden" name="locker_id" id="entryLockerId">
        <div class="grid-2">
          <div class="form-group">
            <label>OTP Used (leave blank to skip)</label>
            <input type="text" name="otp_used" class="form-control" maxlength="6" placeholder="6-digit OTP">
          </div>
          <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:6px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="biometric_ok" id="bioCheck" style="width:18px;height:18px;accent-color:var(--teal)">
              Biometric Verified
            </label>
          </div>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('logEntryModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-door-open"></i> Log Entry</button>
      </div>
    </form>
  </div>
</div>

<?php $extraJS = '<script>
tableSearch("logSearch","logTable");

window._otpCustId = null;

function updateOtpIds(sel) {
  const opt = sel.options[sel.selectedIndex];
  window._otpCustId = sel.value;
}

function triggerOTPGeneration() {
  if (!window._otpCustId) { showToast("Select a customer first","warning"); return; }
  const lockerLabel = document.getElementById("otpAssignSelect").options[
    document.getElementById("otpAssignSelect").selectedIndex
  ].dataset.locker;
  requestOTP(window._otpCustId, lockerLabel);
  document.getElementById("bioSection").style.display = "block";
}

function fillLockerId(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById("entryLockerId").value = opt.dataset.lockerId || "";
}
</script>';
include '../includes/footer.php'; ?>
