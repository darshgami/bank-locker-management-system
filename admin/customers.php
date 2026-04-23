<?php
/**
 * admin/customers.php — Customer Management
 * Add / Edit / View KYC / Upload ID Proof / Risk Level
 */
require_once '../config/config.php';
requireRole('admin','staff');

$db         = getDB();
$pageTitle  = 'Customer Management';
$activePage = 'customers';
$csrf       = csrf_generate();
$msg = ''; $msgType = 'success';

// ── POST ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF token mismatch.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // ── ADD CUSTOMER ──
        if ($action === 'add_customer') {
            $name    = trim($_POST['full_name'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $pass    = $_POST['password'] ?? '';
            $addr    = trim($_POST['address'] ?? '');
            $dob     = $_POST['dob'] ?? null;
            $idType  = $_POST['id_type'] ?? 'Aadhar';
            $idNo    = trim($_POST['id_number'] ?? '');

            if (!$name||!$email||!$phone||!$pass) {
                $msg = 'Name, email, phone and password are required.'; $msgType = 'error';
            } else {
                try {
                    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
                    $db->prepare('INSERT INTO users (full_name,email,phone,password,role) VALUES (?,?,?,?,"customer")')
                       ->execute([$name,$email,$phone,$hash]);
                    $uid = (int)$db->lastInsertId();

                    // Handle file upload
                    $fileName = null;
                    if (!empty($_FILES['id_proof']['name'])) {
                        $allowed = ['jpg','jpeg','png','pdf'];
                        $ext     = strtolower(pathinfo($_FILES['id_proof']['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext,$allowed)) throw new Exception('Invalid file type. Allowed: JPG, PNG, PDF');
                        if ($_FILES['id_proof']['size'] > MAX_FILE_MB*1024*1024) throw new Exception('File too large (max '.MAX_FILE_MB.'MB)');
                        $fileName = 'id_' . $uid . '_' . time() . '.' . $ext;
                        move_uploaded_file($_FILES['id_proof']['tmp_name'], UPLOAD_DIR . $fileName);
                    }

                    $db->prepare('INSERT INTO customers (user_id,address,dob,id_type,id_number,id_proof_file) VALUES (?,?,?,?,?,?)')
                       ->execute([$uid,$addr,$dob?:null,$idType,$idNo,$fileName]);
                    logActivity("Added customer: $name (uid=$uid)", 'Customers');
                    $msg = "Customer '$name' added successfully.";
                } catch (Exception $e) {
                    $msg = 'Error: ' . $e->getMessage(); $msgType = 'error';
                    // rollback user if customer insert failed
                    if (isset($uid)) $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
                }
            }
        }

        // ── UPDATE KYC ──
        elseif ($action === 'update_kyc') {
            $cid    = (int)$_POST['customer_id'];
            $status = $_POST['kyc_status'] ?? 'pending';
            $db->prepare('UPDATE customers SET kyc_status=? WHERE id=?')->execute([$status,$cid]);
            logActivity("Updated KYC for customer $cid to $status", 'Customers');
            $msg = "KYC status updated.";
        }

        // ── DELETE CUSTOMER ──
        elseif ($action === 'delete_customer') {
            $uid = (int)$_POST['user_id'];
            
            // 1. Security Check: Active Lockers
            $chk = $db->prepare(
                'SELECT la.id, c.full_name, cu.id_proof_file 
                 FROM locker_assignments la
                 JOIN customers cu ON cu.id = la.customer_id
                 JOIN users c ON c.id = cu.user_id
                 WHERE c.id = ? AND la.is_active = 1'
            );
            $chk->execute([$uid]);
            $activeLocker = $chk->fetch();

            if ($activeLocker) {
                $msg = 'Cannot delete: customer has an active locker assignment.'; 
                $msgType = 'error';
            } else {
                try {
                    $db->beginTransaction();

                    // Get customer ID and file info before deletion
                    $custInfo = $db->prepare('SELECT id, id_proof_file FROM customers WHERE user_id = ?');
                    $custInfo->execute([$uid]);
                    $cu = $custInfo->fetch();

                    if ($cu) {
                        // 2. Decouple Activity Logs (set user_id to NULL to preserve logs)
                        $db->prepare('UPDATE activity_logs SET user_id = NULL WHERE user_id = ?')->execute([$uid]);

                        // 3. Delete Physical Files
                        if ($cu['id_proof_file'] && file_exists(UPLOAD_DIR . $cu['id_proof_file'])) {
                            unlink(UPLOAD_DIR . $cu['id_proof_file']);
                        }

                        // 4. Delete related data that might not cascade perfectly or needs explicit handling
                        $db->prepare('DELETE FROM otp_requests WHERE user_id = ?')->execute([$uid]);
                        $db->prepare('DELETE FROM csrf_tokens WHERE user_id = ?')->execute([$uid]);

                        // 5. Delete User Account (Cascades to customers, locker_assignments (inactive), etc.)
                        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

                        logActivity("Admin/Staff deleted customer account (User ID: $uid)", 'Customers');
                        $db->commit();
                        $msg = "Customer and all associated records deleted successfully.";
                    } else {
                        throw new Exception("Customer record not found.");
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $msg = 'Deletion failed: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
        // ── UNASSIGN LOCKER (Quick Action) ──
        elseif ($action === 'unassign_locker') {
            $uid = (int)$_POST['user_id'];
            try {
                $db->beginTransaction();
                // Find active assignment for this user's customer record
                $st = $db->prepare('SELECT la.id, la.locker_id FROM locker_assignments la JOIN customers c ON c.id = la.customer_id WHERE c.user_id = ? AND la.is_active = 1');
                $st->execute([$uid]);
                $asgn = $st->fetch();

                if ($asgn) {
                    $db->prepare('UPDATE locker_assignments SET is_active=0, end_date=CURDATE() WHERE id=?')->execute([$asgn['id']]);
                    $db->prepare('UPDATE lockers SET status="Available" WHERE id=?')->execute([$asgn['locker_id']]);
                    logActivity("Unassigned locker ID {$asgn['locker_id']} from user $uid", 'Customers');
                    $db->commit();
                    $msg = "Locker unassigned. Customer can now be deleted.";
                } else {
                    throw new Exception("No active locker assignment found for this customer.");
                }
            } catch (Exception $e) {
                $db->rollBack();
                $msg = "Unassign failed: " . $e->getMessage(); $msgType = 'error';
            }
        }
    }
}

// ── Fetch customers ────────────────────────────────────────────────────────────
$customers = $db->query(
  'SELECT u.id AS user_id, u.full_name, u.email, u.phone, u.status AS user_status,
          c.id AS cust_id, c.id_type, c.id_number, c.kyc_status, c.risk_level,
          c.id_proof_file, c.address, c.dob, c.created_at,
          (SELECT l.locker_no FROM locker_assignments la
           JOIN lockers l ON l.id=la.locker_id
           WHERE la.customer_id=c.id AND la.is_active=1 LIMIT 1) AS locker_no
   FROM users u JOIN customers c ON c.user_id=u.id
   ORDER BY u.created_at DESC'
)->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="page-header">
      <div>
        <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Customers</div>
        <h1>Customer Management</h1>
        <p>Manage customer accounts, KYC verification, and risk levels.</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" onclick="openModal('addCustModal')">
          <i class="fas fa-user-plus"></i> Add Customer
        </button>
        <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
        <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i> <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="filter-bar">
      <div style="display:flex; gap:var(--sp-2);">
        <div data-filter-table="custTable" data-filter-col="4" data-filter-val="all"    class="filter-pill active">All KYC</div>
        <div data-filter-table="custTable" data-filter-col="4" data-filter-val="verified" class="filter-pill">Verified</div>
        <div data-filter-table="custTable" data-filter-col="4" data-filter-val="pending"  class="filter-pill">Pending</div>
        <div data-filter-table="custTable" data-filter-col="4" data-filter-val="rejected" class="filter-pill">Rejected</div>
      </div>
      <div class="search-wrapper ms-auto">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="custSearch" class="form-control" placeholder="Search customer by name, email…">
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-users text-primary"></i> All Customers <small style="color:var(--text-muted); font-weight:400;">(<?= count($customers) ?>)</small></h3>
      </div>
      <div class="table-container">
        <table class="tbl" id="custTable">
          <thead><tr>
            <th>#</th><th>Name</th><th>Phone</th><th>Locker</th>
            <th>KYC</th><th>Risk</th><th>ID Proof</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($customers as $i => $c): ?>
          <tr>
            <td style="color:var(--text-muted)"><?= $i+1 ?></td>
            <td>
              <div style="font-weight:600; color:var(--text-main);"><?= h($c['full_name']) ?></div>
              <div style="font-size:0.75rem; color:var(--text-muted);"><?= h($c['email']) ?></div>
            </td>
            <td><?= h($c['phone']) ?></td>
            <td>
              <?php if ($c['locker_no']): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span class="badge badge-occupied"><?= h($c['locker_no']) ?></span>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Unassign locker from this customer?')">
                    <input type="hidden" name="action" value="unassign_locker">
                    <input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 6px; color:var(--red);" title="Quick Unassign">
                      <i class="fas fa-times-circle"></i>
                    </button>
                  </form>
                </div>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.8rem">None</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $c['kyc_status']==='verified'?'verified':($c['kyc_status']==='rejected'?'rejected':'pending') ?>"><?= ucfirst($c['kyc_status']) ?></span></td>
            <td>
              <span class="badge badge-<?= $c['risk_level'] ?>"><?= ucfirst($c['risk_level']) ?></span>
              <div class="risk-bar risk-<?= $c['risk_level'] ?>" style="margin-top:var(--sp-1); width:72px;">
                <div class="risk-fill"></div>
              </div>
            </td>
            <td>
              <?php if ($c['id_proof_file']): ?>
                <a href="<?= UPLOAD_URL . h($c['id_proof_file']) ?>" target="_blank"
                   class="btn btn-ghost btn-sm"><i class="fas fa-file"></i> View</a>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.8rem">Not uploaded</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex; gap:var(--sp-2); flex-wrap:wrap;">
                <!-- KYC update -->
                <button class="btn btn-ghost btn-sm" onclick='openKyc(<?= (int)$c["cust_id"] ?>, "<?= h($c['kyc_status']) ?>")'>
                  <i class="fas fa-id-card"></i> KYC
                </button>
                <!-- View details -->
                <button class="btn btn-ghost btn-sm" onclick='viewCust(<?= json_encode($c) ?>)'>
                  <i class="fas fa-eye"></i>
                </button>
                <!-- Delete -->
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="delete_customer">
                  <input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <button type="submit" class="btn btn-red btn-sm"
                    onclick="return confirm('Delete this customer permanently?')">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$customers): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">No customers found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ ADD CUSTOMER MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addCustModal">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-header">
      <h4><i class="fas fa-user-plus text-teal"></i> Add New Customer</h4>
      <button class="modal-close" onclick="closeModal('addCustModal')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_customer">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="grid-2">
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" class="form-control" required placeholder="e.g. Rohan Mehta">
          </div>
          <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" class="form-control" required placeholder="email@example.com">
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Phone *</label>
            <input type="text" name="phone" class="form-control" required placeholder="9XXXXXXXXX">
          </div>
          <div class="form-group">
            <label>Password *</label>
            <input type="password" name="password" class="form-control" required placeholder="Minimum 8 chars">
          </div>
        </div>
        <div class="form-group">
          <label>Address</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Full address"></textarea>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" name="dob" class="form-control">
          </div>
          <div class="form-group">
            <label>ID Type</label>
            <select name="id_type" class="form-select">
              <option>Aadhar</option><option>PAN</option><option>Passport</option>
              <option>Voter ID</option><option>Driving Licence</option>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>ID Number</label>
            <input type="text" name="id_number" class="form-control" placeholder="ID document number">
          </div>
          <div class="form-group">
            <label>Upload ID Proof <small style="color:var(--text-muted)">(JPG/PNG/PDF, max <?= MAX_FILE_MB ?>MB)</small></label>
            <input type="file" name="id_proof" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addCustModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ KYC MODAL ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="kycModal">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-header">
      <h4><i class="fas fa-id-card text-teal"></i> Update KYC Status</h4>
      <button class="modal-close" onclick="closeModal('kycModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="update_kyc">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="customer_id" id="kycCustId">
        <div class="form-group">
          <label>KYC Status</label>
          <select name="kyc_status" id="kycStatus" class="form-select">
            <option value="pending">Pending</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('kycModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ VIEW CUSTOMER MODAL ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewCustModal">
  <div class="modal-box" style="max-width:500px">
    <div class="modal-header">
      <h4><i class="fas fa-user text-teal"></i> Customer Details</h4>
      <button class="modal-close" onclick="closeModal('viewCustModal')">✕</button>
    </div>
    <div class="modal-body" id="viewCustBody">—</div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('viewCustModal')">Close</button>
    </div>
  </div>
</div>

<?php $extraJS = '<script>
tableSearch("custSearch","custTable");
function openKyc(id, status) {
  document.getElementById("kycCustId").value = id;
  document.getElementById("kycStatus").value  = status;
  openModal("kycModal");
}
function viewCust(c) {
  document.getElementById("viewCustBody").innerHTML = `
    <table class="tbl">
      <tr><th>Name</th><td>${c.full_name}</td></tr>
      <tr><th>Email</th><td>${c.email}</td></tr>
      <tr><th>Phone</th><td>${c.phone}</td></tr>
      <tr><th>DOB</th><td>${c.dob||"—"}</td></tr>
      <tr><th>Address</th><td>${c.address||"—"}</td></tr>
      <tr><th>ID Type</th><td>${c.id_type}</td></tr>
      <tr><th>ID No</th><td>${c.id_number||"—"}</td></tr>
      <tr><th>KYC</th><td>${c.kyc_status}</td></tr>
      <tr><th>Risk</th><td>${c.risk_level}</td></tr>
      <tr><th>Locker</th><td>${c.locker_no||"None"}</td></tr>
    </table>`;
  openModal("viewCustModal");
}
</script>';
include "../includes/footer.php"; ?>
