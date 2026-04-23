<?php
/**
 * staff/locker_requests.php – Manage Locker Requests
 */
require_once '../config/config.php';
requireRole('admin', 'staff');

$db = getDB();
$pageTitle = 'Locker Requests';
$activePage = 'requests';
$success = '';
$error = '';
$user = currentUser();

// Handle Verification/Approval/Rejection logic moved to staff/api/locker_actions.php

// Fetch Pending and Verified (Cash Payment) Requests
$requests = $db->query('
    SELECT lr.*, u.full_name, u.email, u.phone, c.kyc_status, c.risk_level
    FROM locker_requests lr
    JOIN customers c ON lr.customer_id = c.id
    JOIN users u ON c.user_id = u.id
    WHERE lr.status = "Pending" 
       OR (lr.status = "Verified" AND lr.payment_mode = "Cash")
    ORDER BY lr.created_at ASC
')->fetchAll();

// Fetch available lockers by size for the modal
$availableLockers = [];
$lockers = $db->query('SELECT id, locker_no, size, location FROM lockers WHERE status = "Available"')->fetchAll();
foreach ($lockers as $l) {
    if (!isset($availableLockers[$l['size']])) {
        $availableLockers[$l['size']] = [];
    }
    $availableLockers[$l['size']][] = $l;
}

$csrf = csrf_generate();
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Locker Requests
    </div>
    
    <div class="page-header">
      <div>
        <h1>Locker Applications & Payments</h1>
        <p>Review documents (Stage 1) and confirm cash payments to assign lockers (Stage 2).</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success" data-auto-dismiss>
        <i class="fas fa-check-circle"></i> <?= h($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        <i class="fas fa-circle-xmark"></i> <?= h($error) ?>
      </div>
    <?php endif; ?>

    <div class="card">
        <div class="table-container">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date & Applicant</th>
                        <th>Documents</th>
                        <th>Request Details</th>
                        <th>Stage / Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($requests as $req): ?>
                    <tr>
                        <td>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom:4px;"><?= date('d M Y H:i', strtotime($req['created_at'])) ?></div>
                            <strong><?= h($req['full_name']) ?></strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><?= h($req['phone']) ?></span>
                        </td>
                        <td>
                           <?php if ($req['status'] === 'Pending'): ?>
                               <div style="display:flex; gap:8px;">
                                 <?php if ($req['aadhar_file']): ?>
                                     <a href="<?= BASE_URL ?>/uploads/requests/<?= h($req['aadhar_file']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View Aadhar"><i class="fas fa-id-card"></i> Aadhar</a>
                                 <?php endif; ?>
                                 <?php if ($req['photo_file']): ?>
                                     <a href="<?= BASE_URL ?>/uploads/requests/<?= h($req['photo_file']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View Photo"><i class="fas fa-image"></i> Photo</a>
                                 <?php endif; ?>
                                 <?php if(!$req['aadhar_file'] && !$req['photo_file']) echo '<span class="text-muted">No files</span>'; ?>
                               </div>
                           <?php else: ?>
                               <span class="badge badge-verified"><i class="fas fa-check"></i> Docs Verified</span>
                           <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color:var(--text-primary);"><?= h($req['size']) ?> Locker</strong><br>
                            <span style="font-size:0.85rem; color:var(--text-muted);"><?= h($req['plan_type']) ?> Plan</span>
                        </td>
                        <td>
                            <?php if ($req['status'] === 'Pending'): ?>
                                <span class="badge badge-pending">Stage 1: Doc Review</span>
                            <?php elseif ($req['status'] === 'Verified' && $req['payment_mode'] === 'Cash'): ?>
                                <div class="badge badge-verified" style="background: var(--primary-soft); color: var(--primary); border: 1px solid var(--primary-light);"><i class="fas fa-money-bill-wave"></i> Payment: Cash</div>
                                <div style="margin-top:4px;"><span class="badge badge-pending">Stage 2: Confirm & Assign</span></div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 5px; font-size: 0.75rem;">
                                Risk: <?php
                                $riskClass = $req['risk_level'] === 'high' ? 'rejected' : ($req['risk_level'] === 'medium' ? 'pending' : 'verified');
                                echo '<span class="badge badge-' . $riskClass . '" style="text-transform:capitalize;">'.$req['risk_level'].'</span>';
                                ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($req['status'] === 'Pending'): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="handleVerify(<?= $req['id'] ?>)">Verify Docs</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="color: var(--red);" onclick="openRejectModal(<?= $req['id'] ?>)">Reject</button>
                            
                            <?php elseif ($req['status'] === 'Verified' && $req['payment_mode'] === 'Cash'): ?>
                                <button class="btn btn-primary btn-sm" onclick="openApproveModal(<?= $req['id'] ?>, '<?= h($req['size']) ?>', '<?= h(addslashes($req['full_name'])) ?>')">Confirm Cash & Assign</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="color: var(--red);" onclick="openRejectModal(<?= $req['id'] ?>)">Cancel</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$requests): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                            No applications or cash payments pending at the moment.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>
</div>

<!-- Approve Modal -->
<div class="modal-overlay" id="approveModal">
  <div class="modal-box">
    <div class="modal-content-inner">
      <input type="hidden" name="csrf_token" id="approve_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="req_id" id="approve_req_id" value="">
      
      <div class="modal-header">
        <h3 class="modal-title">Approve <span id="approve_size_lbl"></span> Locker</h3>
        <button type="button" class="close-modal" onclick="closeModal('approveModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom: 15px;">Assiging locker to: <strong id="approve_name_lbl" style="color: var(--teal);"></strong></p>
        
        <div class="form-group">
          <label>Assign Physical Locker</label>
          <select name="locker_id" id="locker_select" class="form-control">
              <option value="0">-- Auto-Assign Available Locker --</option>
              <!-- Options populated by JS based on size -->
          </select>
          <p style="font-size:0.75rem; color:var(--text-muted); margin-top:5px;">Leave as "Auto-Assign" to pick the first available locker automatically.</p>
        </div>
        
        <div class="alert alert-verified" style="margin-top:20px; font-size: 0.9rem;">
            <i class="fas fa-check-circle"></i> This action confirms that you have received the <b>Cash Payment</b> from the customer. The system will activate the locker and generate the password/QR code immediately.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('approveModal')">Cancel</button>
        <button type="button" class="btn btn-primary" id="approveSubmitBtn" onclick="handleApprove()">Approve Request</button>
      </div>
    </div>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-box">
      <input type="hidden" name="csrf_token" id="reject_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="req_id" id="reject_req_id" value="">
      
      <div class="modal-header">
        <h3 class="modal-title">Reject Application</h3>
        <button type="button" class="close-modal" onclick="closeModal('rejectModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Reason for Rejection *</label>
          <textarea name="reject_reason" class="form-control" rows="3" required placeholder="e.g. Blurry photo, mismatched IDs, unpaid cash..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="button" class="btn btn-red" id="rejectSubmitBtn" onclick="handleReject()">Confirm Rejection</button>
      </div>
    </div>
  </div>
</div>

<script>
// Available lockers grouped by size
const availableLockers = <?= json_encode($availableLockers) ?>;
const API_URL = 'api/locker_actions.php';

async function handleVerify(reqId) {
    if(!confirm('Verify these documents?')) return;
    
    try {
        const res = await ajaxPost(API_URL, {
            action: 'verify',
            req_id: reqId,
            csrf_token: '<?= $csrf ?>'
        });
        
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(res.message, 'error');
        }
    } catch (e) {
        showToast('System error occurred.', 'error');
        console.error(e);
    }
}

async function handleApprove() {
    const reqId = document.getElementById('approve_req_id').value;
    const lockerId = document.getElementById('locker_select').value;
    const btn = document.getElementById('approveSubmitBtn');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    try {
        const res = await ajaxPost(API_URL, {
            action: 'approve',
            req_id: reqId,
            locker_id: lockerId,
            csrf_token: document.getElementById('approve_csrf').value
        });
        
        if (res.success) {
            showToast(res.message, 'success');
            closeModal('approveModal');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(res.message, 'error');
            btn.disabled = false;
            btn.innerHTML = 'Approve Request';
        }
    } catch (e) {
        showToast('System error occurred.', 'error');
        console.error(e);
        btn.disabled = false;
        btn.innerHTML = 'Approve Request';
    }
}

async function handleReject() {
    const reqId = document.getElementById('reject_req_id').value;
    const reason = document.querySelector('[name="reject_reason"]').value;
    const btn = document.getElementById('rejectSubmitBtn');
    
    if(!reason) {
        showToast('Please provide a reason.', 'warning');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    try {
        const res = await ajaxPost(API_URL, {
            action: 'reject',
            req_id: reqId,
            reject_reason: reason,
            csrf_token: document.getElementById('reject_csrf').value
        });
        
        if (res.success) {
            showToast(res.message, 'success');
            closeModal('rejectModal');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(res.message, 'error');
            btn.disabled = false;
            btn.innerHTML = 'Confirm Rejection';
        }
    } catch (e) {
        showToast('System error occurred.', 'error');
        console.error(e);
        btn.disabled = false;
        btn.innerHTML = 'Confirm Rejection';
    }
}

function openApproveModal(reqId, size, custName) {
    document.getElementById('approve_req_id').value = reqId;
    document.getElementById('approve_size_lbl').innerText = size;
    document.getElementById('approve_name_lbl').innerText = custName;
    
    // Populate dropdown
    const select = document.getElementById('locker_select');
    select.innerHTML = '<option value="0">-- Auto-Assign Available Locker --</option>';
    
    if (availableLockers[size] && availableLockers[size].length > 0) {
        availableLockers[size].forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.id;
            opt.innerText = l.locker_no + ' (' + l.location + ')';
            select.appendChild(opt);
        });
    } else {
        // Option already exists for "No available lockers" if needed, but "Auto-Assign" is default
    }
    
    openModal('approveModal');
}
function openRejectModal(reqId) {
    document.getElementById('reject_req_id').value = reqId;
    openModal('rejectModal');
}
</script>

<?php include '../includes/footer.php'; ?>
