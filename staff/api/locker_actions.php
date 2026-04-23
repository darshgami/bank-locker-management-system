<?php
/**
 * staff/api/locker_actions.php – Handle Locker Request actions via AJAX
 */
require_once '../../config/config.php';
requireRole('admin', 'staff');

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid or expired CSRF token.'], 403);
}

$action = $_POST['action'] ?? '';
$req_id = (int)($_POST['req_id'] ?? 0);

if (!$req_id) {
    jsonResponse(['success' => false, 'message' => 'Locker request ID is required.'], 400);
}

try {
    if ($action === 'verify') {
        $stmt = $db->prepare("UPDATE locker_requests SET status = 'Verified' WHERE id = ?");
        if ($stmt->execute([$req_id])) {
            logActivity("Verified documents for locker request #$req_id", 'Requests', $user['id']);
            
            // Notify Customer
            $reqData = $db->query("SELECT c.user_id FROM locker_requests lr JOIN customers c ON lr.customer_id = c.id WHERE lr.id = $req_id")->fetch();
            if ($reqData) {
                sendNotification($reqData['user_id'], 'Documents Verified', 'Your locker documents have been verified. Please proceed to payment.', 'success');
            }
            jsonResponse(['success' => true, 'message' => 'Documents verified. Customer can now proceed to payment.']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to verify documents.']);
        }

    } elseif ($action === 'approve') {
        $locker_id = (int)($_POST['locker_id'] ?? 0);
        $db->beginTransaction();

        // 1. Get Request Info
        $reqInfoSt = $db->prepare("SELECT lr.*, c.id as cust_id, c.user_id as cust_user_id FROM locker_requests lr JOIN customers c ON lr.customer_id = c.id WHERE lr.id = ?");
        $reqInfoSt->execute([$req_id]);
        $reqInfo = $reqInfoSt->fetch();
        
        if (!$reqInfo) throw new Exception("Request not found.");
        if ($reqInfo['status'] === 'Approved') throw new Exception("This request is already approved.");

        // 2. Find/Assign Locker
        if (!$locker_id) {
            $lockerSt = $db->prepare('SELECT id FROM lockers WHERE size=? AND status="Available" LIMIT 1');
            $lockerSt->execute([$reqInfo['size']]);
            $locker_id = (int)$lockerSt->fetchColumn();
            
            if (!$locker_id) throw new Exception("No available " . $reqInfo['size'] . " lockers found for auto-assignment.");
        }

        // 3. Update locker status
        $db->prepare("UPDATE lockers SET status = 'Occupied' WHERE id = ?")->execute([$locker_id]);

        // 4. Update request status
        $db->prepare("UPDATE locker_requests SET status = 'Approved', assigned_locker_id = ? WHERE id = ?")
           ->execute([$locker_id, $req_id]);

        // 5. Generate Locker Credentials
        $sdate = date('Y-m-d');
        $lockerPassword = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        
        $db->prepare('INSERT INTO locker_assignments (locker_id,customer_id,assigned_by,start_date,locker_password) VALUES (?,?,?,?,?)')
           ->execute([$locker_id, $reqInfo['cust_id'], $user['id'], $sdate, $lockerPassword]);
        
        $aid = $db->lastInsertId();

        // 6. Calculate Amount
        $stPlan = $db->prepare('SELECT monthly_fee, yearly_fee FROM locker_plans WHERE size=?');
        $stPlan->execute([$reqInfo['size']]);
        $plan = $stPlan->fetch();
        $amount = ($reqInfo['plan_type'] === 'Yearly') ? $plan['yearly_fee'] : $plan['monthly_fee'];

        // 7. Create payment record
        $due = date('Y-m-d', strtotime('+1 month', strtotime($sdate)));
        if ($reqInfo['plan_type'] === 'Yearly') $due = date('Y-m-d', strtotime('+1 year', strtotime($sdate)));
        $inv = 'INV-' . str_pad($aid, 5, '0', STR_PAD_LEFT);
        
        $db->prepare('INSERT INTO payments (assignment_id,amount,plan_type,payment_date,due_date,status,invoice_no) VALUES (?,?,?,?,?, "Paid",?)')
           ->execute([$aid, $amount, $reqInfo['plan_type'], $sdate, $due, $inv]);

        $db->commit();
        
        logActivity("Confirmed cash and assigned locker #$locker_id to request #$req_id", 'Requests', $user['id']);
        sendNotification($reqInfo['cust_user_id'], 'Locker Assigned', 'Your cash payment is confirmed. Locker ' . $locker_id . ' is now active!', 'success');
        
        jsonResponse(['success' => true, 'message' => 'Cash payment confirmed and Locker successfully assigned.']);

    } elseif ($action === 'reject') {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        if (!$reject_reason) {
            jsonResponse(['success' => false, 'message' => 'A rejection reason is required.'], 400);
        }

        $stmt = $db->prepare("UPDATE locker_requests SET status = 'Rejected', reject_reason = ? WHERE id = ?");
        if ($stmt->execute([$reject_reason, $req_id])) {
            logActivity("Rejected locker request #$req_id", 'Requests', $user['id']);
            
            // Notify Customer
            $reqData = $db->query("SELECT c.user_id FROM locker_requests lr JOIN customers c ON lr.customer_id = c.id WHERE lr.id = $req_id")->fetch();
            if ($reqData) {
                sendNotification($reqData['user_id'], 'Application Rejected', 'Your locker application was rejected. Reason: ' . $reject_reason, 'danger');
            }
            jsonResponse(['success' => true, 'message' => 'Request rejected successfully.']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to reject request.']);
        }

    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("Locker Action Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
