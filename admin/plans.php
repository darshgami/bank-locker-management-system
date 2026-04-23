<?php
/**
 * admin/plans.php – Manage Locker Pricing Plans
 */
require_once '../config/config.php';
requireRole('admin');

$db = getDB();
$pageTitle = 'Locker Plans';
$activePage = 'plans';
$success = '';
$error = '';

// Handle Update Plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_plan') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $id = (int)($_POST['plan_id'] ?? 0);
        $monthly = (float)($_POST['monthly_fee'] ?? 0);
        $yearly = (float)($_POST['yearly_fee'] ?? 0);
        
        if ($id && $monthly >= 0 && $yearly >= 0) {
            $stmt = $db->prepare("UPDATE locker_plans SET monthly_fee = ?, yearly_fee = ? WHERE id = ?");
            if ($stmt->execute([$monthly, $yearly, $id])) {
                $success = "Plan updated successfully!";
                logActivity("Updated locker plan pricing (ID: $id)", 'Plans', currentUser()['id']);
            } else {
                $error = "Failed to update plan.";
            }
        } else {
            $error = "Invalid plan data provided.";
        }
    }
}

// Ensure at least default plans exist if table is empty
$count = $db->query("SELECT COUNT(*) FROM locker_plans")->fetchColumn();
if ($count == 0) {
    try {
        $db->query("INSERT IGNORE INTO `locker_plans` (`size`, `monthly_fee`, `yearly_fee`) VALUES 
        ('Small', 500.00, 5000.00),
        ('Medium', 900.00, 9500.00),
        ('Large', 1500.00, 16000.00)");
    } catch(Exception $e) {}
}

// Fetch all plans
$plans = $db->query("SELECT * FROM locker_plans ORDER BY id ASC")->fetchAll();

$csrf = csrf_generate();
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- MAIN CONTENT -->
<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav">
      <a href="dashboard.php">Home</a> / Locker Plans
    </div>
    
    <div class="page-header">
      <div>
        <h1>Locker Pricing Plans</h1>
        <p>Manage the monthly and yearly fees for each locker size configuration.</p>
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

    <div class="grid-3" style="gap:20px; display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
      <?php foreach ($plans as $plan): ?>
      <!-- Plan Card -->
      <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
          <h3 style="margin:0;"><i class="fas fa-box text-primary"></i> <?= h($plan['size']) ?> Locker</h3>
          <span class="badge badge-verified">Active</span>
        </div>
        <div class="card-body">
            <div style="margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid var(--border);">
                    <span style="color:var(--text-muted)">Monthly Fee:</span>
                    <strong style="font-size: 1.1rem; color: var(--text-main);">₹<?= number_format($plan['monthly_fee'], 2) ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                    <span style="color:var(--text-muted)">Yearly Fee:</span>
                    <strong style="font-size: 1.1rem; color: var(--text-main);">₹<?= number_format($plan['yearly_fee'], 2) ?></strong>
                </div>
            </div>
            
            <button class="btn btn-primary w-100" onclick="editPlan(<?= $plan['id'] ?>, '<?= h($plan['size']) ?>', <?= $plan['monthly_fee'] ?>, <?= $plan['yearly_fee'] ?>)">
               <i class="fas fa-pen-to-square"></i> Edit Pricing
            </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    
  </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal-overlay" id="editPlanModal">
  <div class="modal-box">
    <form method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="update_plan">
      <input type="hidden" name="plan_id" id="edit_plan_id" value="">
      
      <div class="modal-header">
        <h4><i class="fas fa-pen-to-square text-teal"></i> Edit <span id="edit_plan_size"></span> Plan Pricing</h4>
        <button type="button" class="modal-close" onclick="closeModal('editPlanModal')">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Monthly Fee (₹)</label>
          <input type="number" step="0.01" class="form-control" name="monthly_fee" id="edit_monthly" required>
        </div>
        <div class="form-group">
          <label>Yearly Fee (₹)</label>
          <input type="number" step="0.01" class="form-control" name="yearly_fee" id="edit_yearly" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editPlanModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php 
$extraJS = <<<HTML
<script>
function editPlan(id, size, monthly, yearly) {
    document.getElementById('edit_plan_id').value = id;
    document.getElementById('edit_plan_size').innerText = size;
    document.getElementById('edit_monthly').value = monthly;
    document.getElementById('edit_yearly').value = yearly;
    openModal('editPlanModal');
}
</script>
HTML;
include '../includes/footer.php'; 
?>
