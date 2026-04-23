<?php
/**
 * logout.php – Destroys session and redirects to login
 */
require_once 'config/config.php';
logActivity('Logout', 'Auth');
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/index.php?msg=logged_out');
exit;
