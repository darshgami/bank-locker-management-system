<?php
/**
 * api/toggle_emergency.php – Admin Emergency Global Lock
 */
require_once '../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    
    $db = getDB();
    $currentStatus = $_POST['current_status'] ?? '0';
    $newStatus = ($currentStatus === '1') ? '0' : '1';
    $logMsg = ($newStatus === '1') ? 'ACTIVATED Emergency Global Lockdown' : 'DEACTIVATED Emergency Global Lockdown';
    
    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'emergency_global_lock'");
    if ($stmt->execute([$newStatus])) {
        logActivity($logMsg, 'System', currentUser()['id']);
    }
    
    header("Location: ../admin/dashboard.php");
    exit;
}
