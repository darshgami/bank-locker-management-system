<?php
/**
 * includes/header.php
 * Outputs the full <head> + opening layout divs.
 * Usage: include at top of every protected page.
 *   $pageTitle  – string, e.g. "Dashboard"
 *   $activePage – string key matching sidebar links
 */
if (!defined('BASE_URL')) {
    // allow include from any depth
    $depth  = substr_count($_SERVER['PHP_SELF'], '/');
    $prefix = str_repeat('../', max(0, $depth - 2));
    require_once $prefix . 'config/config.php';
}
$pageTitle  = $pageTitle  ?? APP_NAME;
$activePage = $activePage ?? '';
$user       = currentUser();
$csrf       = csrf_generate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Smart Bank Locker Management System">
  <title><?= h($pageTitle) ?> – <?= APP_NAME ?></title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">


  <!-- Theme Initialization (Prevents flickering) -->
  <script>
    (function() {
      const savedTheme = localStorage.getItem('bank_locker_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', savedTheme);
    })();
  </script>
  <script src="<?= BASE_URL ?>/assets/js/theme.js" defer></script>
</head>
<body>

<!-- Mobile sidebar overlay -->
<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999"
     class="d-lg-none" onclick="this.style.display='none'; document.getElementById('sidebar').classList.remove('open')"></div>

<div class="app-layout">
    
    <!-- Global Header -->
    <header class="topbar">
        <div class="topbar-actions">
            <button class="topbar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h2 class="topbar-title d-none d-md-block"><?= h($pageTitle) ?></h2>
        </div>
        <div class="topbar-actions">
            <!-- Theme Toggle -->
            <button class="topbar-btn" id="themeToggle" title="Toggle Light/Dark Mode">
                <i class="fas fa-moon"></i>
            </button>

            <!-- Notifications -->
            <div class="notif-dropdown">
                <button class="topbar-btn" id="notifBtn">
                    <i class="fas fa-bell"></i>
                    <span class="badge" id="notifBadge" style="display:none">0</span>
                </button>
                <div class="notif-menu" id="notifMenu">
                    <div class="notif-header">
                        <span>Notifications</span>
                        <a href="#" id="markReadBtn">Mark all as read</a>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">No new notifications</div>
                    </div>
                </div>
            </div>

            <!-- Profile Dropdown -->
            <div class="user-dropdown">
                <button class="user-btn" id="userBtn">
                    <div class="avatar"><?= strtoupper($user['full_name'][0] ?? 'U') ?></div>
                    <span class="d-none d-sm-inline"><?= h($user['full_name'] ?? 'User') ?></span>
                    <i class="fas fa-chevron-down ms-2" style="font-size:0.75rem"></i>
                </button>
                <ul class="dropdown-content" id="userMenu">
                    <li><a href="<?= BASE_URL ?>/profile.php"><i class="fas fa-user-circle"></i> My Profile</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <li class="divider"></li>
                    <li><a href="<?= BASE_URL ?>/logout.php" style="color:var(--red)"><i class="fas fa-right-from-bracket"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </header>
