<?php
/**
 * includes/sidebar.php
 * Role-aware sidebar navigation.
 * $activePage must be set before including.
 */
$user = currentUser();
$role = $user['role'] ?? 'customer';
$initial = strtoupper($user['full_name'][0] ?? 'U');

$adminNav = [
  ['icon'=>'fa-gauge-high',    'label'=>'Dashboard',    'href'=>BASE_URL.'/admin/dashboard.php',   'key'=>'dashboard'],
  ['icon'=>'fa-layer-group',   'label'=>'Plans',        'href'=>BASE_URL.'/admin/plans.php',       'key'=>'plans'],
  ['icon'=>'fa-lock',          'label'=>'Lockers',      'href'=>BASE_URL.'/admin/lockers.php',     'key'=>'lockers'],
  ['icon'=>'fa-users',         'label'=>'Customers',    'href'=>BASE_URL.'/admin/customers.php',   'key'=>'customers'],
  ['icon'=>'fa-credit-card',   'label'=>'Payments',     'href'=>BASE_URL.'/admin/payments.php',    'key'=>'payments'],
  ['icon'=>'fa-shield-halved', 'label'=>'Access Logs',  'href'=>BASE_URL.'/admin/access_logs.php','key'=>'access_logs'],
  ['icon'=>'fa-chart-line',    'label'=>'Reports',      'href'=>BASE_URL.'/admin/reports.php',     'key'=>'reports'],
];

$staffNav = [
  ['icon'=>'fa-gauge-high',    'label'=>'Dashboard',    'href'=>BASE_URL.'/staff/dashboard.php',  'key'=>'dashboard'],
  ['icon'=>'fa-file-signature','label'=>'Locker Requests','href'=>BASE_URL.'/staff/locker_requests.php','key'=>'requests'],
  ['icon'=>'fa-lock',          'label'=>'Lockers',      'href'=>BASE_URL.'/admin/lockers.php',    'key'=>'lockers'],
  ['icon'=>'fa-users',         'label'=>'Customers',    'href'=>BASE_URL.'/admin/customers.php',  'key'=>'customers'],
  ['icon'=>'fa-credit-card',   'label'=>'Payments',     'href'=>BASE_URL.'/staff/payments.php',   'key'=>'payments'],
  ['icon'=>'fa-shield-halved', 'label'=>'Access Logs',  'href'=>BASE_URL.'/admin/access_logs.php','key'=>'access_logs'],
];

$customerNav = [
  ['icon'=>'fa-gauge-high',    'label'=>'My Dashboard', 'href'=>BASE_URL.'/customer/dashboard.php','key'=>'dashboard'],
  ['icon'=>'fa-plus-circle',   'label'=>'Apply for Locker','href'=>BASE_URL.'/customer/apply_locker.php','key'=>'apply'],
  ['icon'=>'fa-lock',          'label'=>'My Locker',    'href'=>BASE_URL.'/customer/dashboard.php','key'=>'my_locker'],
  ['icon'=>'fa-file-invoice',  'label'=>'Payments',     'href'=>BASE_URL.'/customer/payments.php', 'key'=>'payments'],
  ['icon'=>'fa-clock-rotate-left','label'=>'Access History','href'=>BASE_URL.'/customer/history.php','key'=>'history'],
];

$navItems = match($role) {
  'admin'    => $adminNav,
  'staff'    => $staffNav,
  default    => $customerNav,
};
?>
<aside class="sidebar" id="sidebar">
  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon">🏦</div>
    <div class="brand-name"><?= APP_NAME ?></div>
  </div>

  <!-- Navigation -->
  <nav class="sidebar-nav">
    <p class="nav-label">Navigation</p>

    <?php foreach ($navItems as $item): ?>
      <div class="nav-item">
        <a href="<?= $item['href'] ?>"
           class="nav-link <?= ($activePage === $item['key']) ? 'active' : '' ?>">
          <i class="fas <?= $item['icon'] ?>"></i>
          <?= h($item['label']) ?>
        </a>
      </div>
    <?php endforeach; ?>

    <?php if ($role === 'admin'): ?>
    <p class="nav-section-label" style="margin-top:14px">System</p>
    <div class="nav-item">
      <a href="<?= BASE_URL ?>/admin/settings.php" class="nav-link <?= $activePage==='settings'?'active':'' ?>">
        <i class="fas fa-gear"></i> Settings
      </a>
    </div>
    <?php endif; ?>
  </nav>

  <!-- User info + logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= h($initial) ?></div>
      <div class="user-info">
        <div class="name"><?= h($user['full_name'] ?? '') ?></div>
        <div class="role"><?= h($role) ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php"
       style="display:flex;align-items:center;gap:8px;padding:10px 14px;margin-top:6px;border-radius:8px;font-size:.82rem;color:var(--red);transition:background .25s"
       class="nav-link-logout">
      <i class="fas fa-right-from-bracket"></i> Logout
    </a>
  </div>
</aside>
