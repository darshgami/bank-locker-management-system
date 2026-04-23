<?php
/**
 * api/get_notifications.php – Fetch unread notifications
 */
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = currentUser();
$db = getDB();

try {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user['id']]);
    $notifs = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($notifs),
        'notifications' => $notifs
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
