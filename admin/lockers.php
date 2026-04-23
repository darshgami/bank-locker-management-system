<?php
/**
 * admin/lockers.php — Full Locker Management
 * Add / Edit / Delete / Assign / Filter / Search
 */
require_once '../config/config.php';
requireRole('admin','staff');

$db         = getDB();
$pageTitle  = 'Locker Management';
$activePage = 'lockers';
$user       = currentUser();
$csrf       = csrf_generate();
$msg        = ''; $msgType = 'success';

// ── POST ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF token mismatch.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // ── ADD LOCKER ──
        if ($action === 'add_locker') {
            $no   = trim($_POST['locker_no'] ?? '');
            $size = $_POST['size'] ?? 'Small';
            $rent = (float)($_POST['rent_amount'] ?? 0);
            $loc  = trim($_POST['location'] ?? 'Branch A');
            $desc = trim($_POST['description'] ?? '');
            if (!$no || !$rent) { $msg='Locker number and rent are required.'; $msgType='error'; }
            else {
                try {
                    $db->prepare('INSERT INTO lockers (locker_no,size,rent_amount,location,description) VALUES (?,?,?,?,?)')
                       ->execute([$no,$size,$rent,$loc,$desc]);
                    logActivity("Added locker: $no", 'Lockers');
                    $msg = "Locker $no added successfully.";
                } catch (PDOException $e) {
                    $msg = "Error: Locker number already exists."; $msgType = 'error';
                }
            }
        }

        // ── EDIT LOCKER ──
        elseif ($action === 'edit_locker') {
            $id   = (int)$_POST['locker_id'];
            $no   = trim($_POST['locker_no'] ?? '');
            $size = $_POST['size'] ?? 'Small';
            $rent = (float)($_POST['rent_amount'] ?? 0);
            $stat = $_POST['status'] ?? 'Available';
            $loc  = trim($_POST['location'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $db->prepare('UPDATE lockers SET locker_no=?,size=?,rent_amount=?,status=?,location=?,description=? WHERE id=?')
               ->execute([$no,$size,$rent,$stat,$loc,$desc,$id]);
            logActivity("Edited locker ID $id", 'Lockers');
            $msg = "Locker updated successfully.";
        }

        // ── DELETE LOCKER ──
        elseif ($action === 'delete_locker') {
            $id = (int)$_POST['locker_id'];
            // check not occupied
            $st = $db->prepare('SELECT status FROM lockers WHERE id=?'); $st->execute([$id]);
            $l  = $st->fetch();
            if ($l && $l['status'] === 'Occupied') {
                $msg = "Cannot delete an occupied locker."; $msgType = 'error';
            } else {
                $db->prepare('DELETE FROM lockers WHERE id=?')->execute([$id]);
                logActivity("Deleted locker ID $id", 'Lockers');
                $msg = "Locker deleted.";
            }
        }

        // ── ASSIGN LOCKER ──
        elseif ($action === 'assign_locker') {
            $lid   = (int)$_POST['locker_id'];
            $cid   = (int)$_POST['customer_id'];
            $sdate = $_POST['start_date'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');
            // check available
            $st = $db->prepare('SELECT status FROM lockers WHERE id=?'); $st->execute([$lid]);
            $l  = $st->fetch();
            if (!$l || $l['status'] !== 'Available') {
                $msg = "Locker is not available."; $msgType = 'error';
            } else {
                $db->prepare('INSERT INTO locker_assignments (locker_id,customer_id,assigned_by,start_date,notes) VALUES (?,?,?,?,?)')
                   ->execute([$lid,$cid,$user['id'],$sdate,$notes]);
                $db->prepare('UPDATE lockers SET status="Occupied" WHERE id=?')->execute([$lid]);
                // auto-create first payment record
                $st2 = $db->prepare('SELECT rent_amount FROM lockers WHERE id=?'); $st2->execute([$lid]);
                $rent = (float)$st2->fetchColumn();
                $aid  = $db->lastInsertId();
                $due  = date('Y-m-d', strtotime('+1 month', strtotime($sdate)));
                $inv  = 'INV-' . str_pad($aid, 5, '0', STR_PAD_LEFT);

                // GST Calculation (18% Inclusive)
                $taxPercent = 18.00;
                $baseAmount = round($rent / (1 + ($taxPercent / 100)), 2);
                $taxAmount  = $rent - $baseAmount;
                $otherFees  = 0.00;

                $db->prepare('INSERT INTO payments (assignment_id,amount,base_amount,tax_percent,tax_amount,other_fees,payment_date,due_date,status,invoice_no) VALUES (?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$aid,$rent,$baseAmount,$taxPercent,$taxAmount,$otherFees,$sdate,$due,$inv]);
                logActivity("Assigned locker ID $lid to customer $cid", 'Lockers');
                $msg = "Locker assigned successfully.";
            }
        }

        // ── UNASSIGN LOCKER ──
        elseif ($action === 'unassign_locker') {
            $lid = (int)$_POST['locker_id'];
            $db->prepare('UPDATE locker_assignments SET is_active=0,end_date=CURDATE() WHERE locker_id=? AND is_active=1')
               ->execute([$lid]);
            $db->prepare('UPDATE lockers SET status="Available" WHERE id=?')->execute([$lid]);
            logActivity("Unassigned locker ID $lid", 'Lockers');
            $msg = "Locker unassigned and now available.";
        }
        // ── GENERATE 100 LOCKERS ──
        elseif ($action === 'generate_100') {
            try {
                $db->beginTransaction();
                $inserted = 0;
                
                // 40 Small, 35 Medium, 25 Large
                $distribution = [
                    ['size' => 'Small', 'count' => 40, 'rent' => 500, 'prefix' => 'S'],
                    ['size' => 'Medium', 'count' => 35, 'rent' => 900, 'prefix' => 'M'],
                    ['size' => 'Large', 'count' => 25, 'rent' => 1500, 'prefix' => 'L']
                ];
                
                $stmt = $db->prepare('INSERT INTO lockers (locker_no, size, rent_amount, status, location) VALUES (?,?,?,?,?)');
                
                foreach ($distribution as $d) {
                    for ($i = 1; $i <= $d['count']; $i++) {
                        $no = $d['prefix'] . '-' . str_pad($i, 3, '0', STR_PAD_LEFT) . '-' . rand(10,99);
                        
                        // Check if locker exists to avoid unique constraint failure
                        $check = $db->prepare('SELECT id FROM lockers WHERE locker_no=?');
                        $check->execute([$no]);
                        if (!$check->fetch()) {
                            $stmt->execute([$no, $d['size'], $d['rent'], 'Available', 'Main Vault']);
                            $inserted++;
                        }
                    }
                }
                $db->commit();
                logActivity("Auto-generated $inserted new lockers", 'Lockers');
                $msg = "$inserted Lockers Generated Successfully.";
            } catch (Exception $e) {
                $db->rollBack();
                $msg = "Error generating lockers: " . $e->getMessage(); $msgType = 'error';
            }
        }
    }
}

// ── FETCH DATA ─────────────────────────────────────────────────────────────────
$filter  = $_GET['filter'] ?? 'all';
$where   = $filter !== 'all' ? "WHERE l.status = '$filter'" : '';
$lockers = $db->query(
  "SELECT l.*,
          c.id AS cust_db_id,
          u.full_name AS cust_name,
          la.start_date,
          la.id AS assignment_id
   FROM lockers l
   LEFT JOIN locker_assignments la ON la.locker_id=l.id AND la.is_active=1
   LEFT JOIN customers c ON c.id=la.customer_id
   LEFT JOIN users u ON u.id=c.user_id
   $where
   ORDER BY l.locker_no ASC"
)->fetchAll();

// Get all verified customers for assign dropdown
$customers = $db->query(
  'SELECT c.id, u.full_name, u.phone FROM customers c JOIN users u ON u.id=c.user_id
   WHERE c.kyc_status="verified" ORDER BY u.full_name'
)->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="page-header">
      <div>
        <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Lockers</div>
        <h1>Locker Management</h1>
        <p>Manage all locker units, assign to customers, and track status.</p>
      </div>
      <div class="page-header-actions">
        <form method="POST" onsubmit="return confirm('Are you sure you want to generate 100 new lockers? This may take a moment.');">
          <input type="hidden" name="action" value="generate_100">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button type="submit" class="btn btn-ghost btn-sm">
            <i class="fas fa-magic"></i> Generate 100 Lockers
          </button>
        </form>
        <button class="btn btn-primary btn-sm" onclick="openModal('addLockerModal')">
          <i class="fas fa-plus"></i> Add Locker
        </button>
        <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
        <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i>
        <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Filter + Search Bar -->
    <div class="filter-bar">
      <div style="display:flex; gap:var(--sp-2);">
        <a href="?filter=all"         class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="?filter=Available"   class="filter-pill <?= $filter==='Available'?'active':'' ?>">Available</a>
        <a href="?filter=Occupied"    class="filter-pill <?= $filter==='Occupied'?'active':'' ?>">Occupied</a>
        <a href="?filter=Maintenance" class="filter-pill <?= $filter==='Maintenance'?'active':'' ?>">Maintenance</a>
      </div>
      <div class="search-wrapper ms-auto">
        <i class="fas fa-magnifying-glass search-icon"></i>
        <input type="text" id="lockerSearch" class="form-control" placeholder="Search locker…">
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-lock text-primary"></i> All Lockers <small style="color:var(--text-muted); font-weight:400;">(<?= count($lockers) ?>)</small></h3>
      </div>
      <div class="table-container">
        <table class="tbl" id="lockersTable">
          <thead><tr>
            <th>#</th><th>Locker No</th><th>Size</th><th>Location</th>
            <th>Rent/Mo</th><th>Status</th><th>Assigned To</th><th>Since</th>
            <th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($lockers as $i => $l): ?>
            <tr>
              <td style="color:var(--text-muted)"><?= $i+1 ?></td>
              <td><b><?= h($l['locker_no']) ?></b></td>
              <td><?= h($l['size']) ?></td>
              <td style="color:var(--text-muted)"><?= h($l['location']) ?></td>
              <td>₹<?= number_format($l['rent_amount'],0) ?></td>
              <td>
                <span class="badge badge-<?= strtolower($l['status'] === 'Maintenance' ? 'maintenance' : ($l['status'] === 'Occupied' ? 'occupied' : 'available')) ?>">
                  <?= h($l['status']) ?>
                </span>
              </td>
              <td><?= $l['cust_name'] ? h($l['cust_name']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
              <td style="font-size:.8rem;color:var(--text-muted)"><?= $l['start_date'] ? date('d M Y',strtotime($l['start_date'])) : '—' ?></td>
              <td>
                <div style="display:flex;gap:6px">
                  <!-- Edit -->
                  <button class="btn btn-ghost btn-icon btn-sm" title="Edit"
                    onclick='editLocker(<?= json_encode($l) ?>)'>
                    <i class="fas fa-pen"></i>
                  </button>
                  <!-- Assign / Unassign -->
                  <?php if ($l['status'] === 'Available'): ?>
                    <button class="btn btn-primary btn-icon btn-sm" title="Assign"
                      onclick='openAssignModal(<?= (int)$l["id"] ?>, "<?= h($l["locker_no"]) ?>")'>
                      <i class="fas fa-user-plus"></i>
                    </button>
                  <?php elseif ($l['status'] === 'Occupied'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="unassign_locker">
                      <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <button type="submit" class="btn btn-ghost btn-icon btn-sm" title="Unassign"
                        onclick="return confirm('Unassign this locker?')">
                        <i class="fas fa-user-minus"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <!-- Delete -->
                  <?php if ($l['status'] !== 'Occupied'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="delete_locker">
                    <input type="hidden" name="locker_id" value="<?= (int)$l['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn btn-red btn-icon btn-sm" title="Delete"
                      onclick="return confirm('Delete this locker permanently?')">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lockers): ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted)">No lockers found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Smart Suggestion Box -->
    <div class="suggestion-card mt-2">
      <h5><i class="fas fa-lightbulb"></i> Smart Locker Availability Suggestion</h5>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
        <select id="suggestSize" class="form-select" style="max-width:160px;padding:8px 12px">
          <option value="Small">Small</option>
          <option value="Medium">Medium</option>
          <option value="Large">Large</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="loadLockerSuggestions(document.getElementById('suggestSize').value)">
          <i class="fas fa-search"></i> Find Available
        </button>
      </div>
      <div id="suggestionBox"><p style="color:var(--text-muted);font-size:.85rem">Select a size and click Find Available.</p></div>
    </div>
  </div><!-- /.page-content -->
</div><!-- /.main-content -->

<!-- ══ ADD LOCKER MODAL ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addLockerModal">
  <div class="modal-box">
    <div class="modal-header">
      <h4><i class="fas fa-plus text-teal"></i> Add New Locker</h4>
      <button class="modal-close" onclick="closeModal('addLockerModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_locker">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="grid-2">
          <div class="form-group">
            <label>Locker Number *</label>
            <input type="text" name="locker_no" class="form-control" placeholder="e.g. L-009" required>
          </div>
          <div class="form-group">
            <label>Size *</label>
            <select name="size" class="form-select">
              <option>Small</option><option>Medium</option><option>Large</option>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Rent/Month (₹) *</label>
            <input type="number" name="rent_amount" class="form-control" placeholder="500" min="1" required>
          </div>
          <div class="form-group">
            <label>Branch Location</label>
            <input type="text" name="location" class="form-control" value="Branch A">
          </div>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addLockerModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Locker</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT LOCKER MODAL ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editLockerModal">
  <div class="modal-box">
    <div class="modal-header">
      <h4><i class="fas fa-pen text-teal"></i> Edit Locker</h4>
      <button class="modal-close" onclick="closeModal('editLockerModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_locker">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="locker_id" id="editLockerId">
        <div class="grid-2">
          <div class="form-group">
            <label>Locker Number</label>
            <input type="text" name="locker_no" id="editLockerNo" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Size</label>
            <select name="size" id="editLockerSize" class="form-select">
              <option>Small</option><option>Medium</option><option>Large</option>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label>Rent/Month (₹)</label>
            <input type="number" name="rent_amount" id="editLockerRent" class="form-control">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="editLockerStatus" class="form-select">
              <option>Available</option><option>Occupied</option><option>Maintenance</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Location</label>
          <input type="text" name="location" id="editLockerLoc" class="form-control">
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" id="editLockerDesc" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editLockerModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Locker</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ ASSIGN LOCKER MODAL ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="assignModal">
  <div class="modal-box">
    <div class="modal-header">
      <h4><i class="fas fa-user-plus text-teal"></i> Assign Locker <span id="assignLockerLabel" class="text-teal"></span></h4>
      <button class="modal-close" onclick="closeModal('assignModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="assign_locker">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="locker_id" id="assignLockerId">
        <div class="form-group">
          <label>Customer *</label>
          <select name="customer_id" class="form-select" required>
            <option value="">— Select Verified Customer —</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['full_name']) ?> (<?= h($c['phone']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Start Date *</label>
          <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('assignModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-check"></i> Assign</button>
      </div>
    </form>
  </div>
</div>

<?php $extraJS = '<script>
tableSearch("lockerSearch","lockersTable");

function editLocker(l) {
  document.getElementById("editLockerId").value   = l.id;
  document.getElementById("editLockerNo").value   = l.locker_no;
  document.getElementById("editLockerSize").value = l.size;
  document.getElementById("editLockerRent").value = l.rent_amount;
  document.getElementById("editLockerStatus").value = l.status;
  document.getElementById("editLockerLoc").value  = l.location;
  document.getElementById("editLockerDesc").value = l.description || "";
  openModal("editLockerModal");
}
function openAssignModal(id, no) {
  document.getElementById("assignLockerId").value = id;
  document.getElementById("assignLockerLabel").textContent = no;
  openModal("assignModal");
}
</script>';
include '../includes/footer.php'; ?>
