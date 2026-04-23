<?php
/**
 * admin/settings.php – Centralized System Configuration
 */
require_once '../config/config.php';
requireRole('admin');

$db = getDB();
$pageTitle = 'System Settings';
$activePage = 'settings';
$msg = '';
$msgType = 'success';

// Handle Post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF error.'; $msgType = 'error';
    } else {
        try {
            $db->beginTransaction();
            foreach ($_POST['settings'] as $key => $value) {
                $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
            }
            $db->commit();
            logActivity("Updated system settings", 'Settings');
            $msg = "Settings updated successfully.";
        } catch (Exception $e) {
            $db->rollBack();
            $msg = "Error: " . $e->getMessage(); $msgType = 'error';
        }
    }
}

// Fetch Settings
$settingsRows = $db->query("SELECT * FROM system_settings")->fetchAll();
$s = [];
foreach ($settingsRows as $row) {
    $s[$row['setting_key']] = $row['setting_value'];
}

$csrf = csrf_generate();
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
  <div class="page-content">
    <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Settings</div>
    
    <div class="page-header">
      <div>
        <h1>System Settings</h1>
        <p>Configure global parameters, pricing, and system behavior.</p>
      </div>
      <a href="dashboard.php" class="page-close" title="Close"><i class="fas fa-times"></i></a>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
        <i class="fas <?= $msgType==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i> <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      
      <div class="grid-2" style="gap:25px; align-items: start;">
        
        <!-- Pricing Settings -->
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-tags text-primary"></i> Locker Pricing (Monthly / Yearly)</h3>
          </div>
          <div class="card-body">
            <div class="grid-2">
              <div class="form-group">
                <label>Small Locker (Monthly)</label>
                <input type="number" name="settings[rent_small_monthly]" class="form-control" value="<?= h($s['rent_small_monthly'] ?? '500') ?>">
              </div>
              <div class="form-group">
                <label>Small Locker (Yearly)</label>
                <input type="number" name="settings[rent_small_yearly]" class="form-control" value="<?= h($s['rent_small_yearly'] ?? '5000') ?>">
              </div>
            </div>
            <div class="grid-2">
              <div class="form-group">
                <label>Medium Locker (Monthly)</label>
                <input type="number" name="settings[rent_medium_monthly]" class="form-control" value="<?= h($s['rent_medium_monthly'] ?? '900') ?>">
              </div>
              <div class="form-group">
                <label>Medium Locker (Yearly)</label>
                <input type="number" name="settings[rent_medium_yearly]" class="form-control" value="<?= h($s['rent_medium_yearly'] ?? '9500') ?>">
              </div>
            </div>
            <div class="grid-2">
              <div class="form-group">
                <label>Large Locker (Monthly)</label>
                <input type="number" name="settings[rent_large_monthly]" class="form-control" value="<?= h($s['rent_large_monthly'] ?? '1500') ?>">
              </div>
              <div class="form-group">
                <label>Large Locker (Yearly)</label>
                <input type="number" name="settings[rent_large_yearly]" class="form-control" value="<?= h($s['rent_large_yearly'] ?? '16000') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- General Settings -->
        <div class="card">
          <div class="card-header">
            <h3><i class="fas fa-cogs text-primary"></i> Module Configurations</h3>
          </div>
          <div class="card-body">
            <div class="form-group">
              <label>Max Lockers per Customer</label>
              <input type="number" name="settings[max_lockers_per_customer]" class="form-control" value="<?= h($s['max_lockers_per_customer'] ?? '5') ?>">
              <small class="text-muted">Total active lockers a single customer can rent simultaneously.</small>
            </div>
            
            <div class="form-group">
              <label>Locker Access Method</label>
              <select class="form-select">
                <option value="dynamic">Dynamic QR + OTP (Smart)</option>
                <option value="manual">Manual Entry Only</option>
              </select>
            </div>

            <div class="form-group" style="margin-top:20px;">
              <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                <input type="hidden" name="settings[enable_cash_payment]" value="0">
                <input type="checkbox" name="settings[enable_cash_payment]" value="1" <?= ($s['enable_cash_payment'] ?? '1') == '1' ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:var(--teal)">
                Enable Cash Payment at Branch
              </label>
            </div>

            <div class="form-group" style="margin-top:15px;">
              <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                <input type="hidden" name="settings[emergency_global_lock]" value="0">
                <input type="checkbox" name="settings[emergency_global_lock]" value="1" <?= ($s['emergency_global_lock'] ?? '0') == '1' ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:var(--red)">
                <span style="color:var(--red); font-weight:600">EMERGENCY GLOBAL LOCKDOWN</span>
              </label>
              <small class="text-muted">Instantly disables all vault access QR codes.</small>
            </div>
          </div>
        </div>

      </div>

      <div class="card mt-3">
        <div class="card-body" style="text-align: right; padding: 20px;">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save All Settings
          </button>
        </div>
      </div>

    </form>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
