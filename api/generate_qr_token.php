<?php
/**
 * api/generate_qr_token.php – Secure Token Generator for QR Core
 * Returns JSON
 */
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || currentUser()['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$user = currentUser();

// Get locker_id from request
$lockerId = isset($_POST['locker_id']) ? (int)$_POST['locker_id'] : 0;

if (!$lockerId) {
    echo json_encode(['success' => false, 'message' => 'Locker ID is required.']);
    exit;
}

// Verify KYC & specific active locker assignment
$stmt = $db->prepare('
    SELECT c.id, la.end_date 
    FROM customers c 
    JOIN locker_assignments la ON la.customer_id = c.id 
    WHERE c.user_id = ? AND la.locker_id = ? AND la.is_active = 1
');
$stmt->execute([$user['id'], $lockerId]);
$cust = $stmt->fetch();

if (!$cust) {
    echo json_encode(['success' => false, 'message' => 'Active assignment for this locker not found.']);
    exit;
}

if ($cust['end_date'] && strtotime($cust['end_date']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Plan expired for this locker. Please renew to access.']);
    exit;
}

// Generate secure random token
$raw_token = bin2hex(random_bytes(32));

// Valid for 60 seconds from now
$expires = date('Y-m-d H:i:s', strtotime('+60 seconds'));

try {
    $ins = $db->prepare("INSERT INTO qr_tokens (customer_id, locker_id, token, expires_at) VALUES (?, ?, ?, ?)");
    if ($ins->execute([$cust['id'], $lockerId, $raw_token, $expires])) {
        echo json_encode([
            'success' => true,
            'token' => $raw_token,
            'expires_at' => $expires
        ]);
        
        logActivity("Generated Vault QR token", 'Access', $user['id']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate token.']);
}
