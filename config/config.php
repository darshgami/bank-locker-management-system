<?php
/**
 * config.php – Central configuration & PDO singleton
 * Smart Bank Locker Management System
 */

// ── Database credentials ──────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'bank_locker_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHAR', 'utf8mb4');

// ── Application constants ──────────────────────────────────────────────────────
define('APP_NAME',    'Smart Bank Locker');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    'http://localhost/bank_locker');   // change if needed
define('UPLOAD_DIR',  __DIR__ . '/../uploads/');
define('UPLOAD_URL',  BASE_URL . '/uploads/');
define('MAX_FILE_MB', 5);                                // Max upload size in MB
define('OTP_EXPIRY_MINUTES', 10);

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ── Error reporting (disable in production) ──────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── PDO singleton ─────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHAR;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ── CSRF helpers ──────────────────────────────────────────────────────────────
function csrf_generate(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── XSS helpers ───────────────────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Auth helpers ──────────────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireRole(string ...$roles): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php?msg=login_required');
        exit;
    }
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('<h2>Access Denied</h2><p>You do not have permission to view this page.</p>');
    }
}

// ── Activity logger ──────────────────────────────────────────────────────────
function logActivity(string $action, string $module = '', ?int $userId = null): void {
    try {
        $db = getDB();
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $st  = $db->prepare(
            'INSERT INTO activity_logs (user_id, action, module, ip) VALUES (?,?,?,?)'
        );
        $st->execute([$uid, $action, $module, $ip]);
    } catch (Exception $e) { /* silent */ }
}

// ── JSON response helper ──────────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── OTP generator ─────────────────────────────────────────────────────────────
function generateOTP(int $userId, string $purpose = 'locker_access'): string {
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db  = getDB();
    // invalidate old OTPs
    $db->prepare('UPDATE otp_requests SET is_used=1 WHERE user_id=? AND purpose=? AND is_used=0')
       ->execute([$userId, $purpose]);
    // insert new
    $exp = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));
    $db->prepare('INSERT INTO otp_requests (user_id, otp, purpose, expires_at) VALUES (?,?,?,?)')
       ->execute([$userId, $otp, $purpose, $exp]);
    return $otp;
}

function verifyOTP(int $userId, string $otp, string $purpose = 'locker_access'): bool {
    $db  = getDB();
    $st  = $db->prepare(
        'SELECT id FROM otp_requests
         WHERE user_id=? AND otp=? AND purpose=? AND is_used=0 AND expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$userId, $otp, $purpose]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('UPDATE otp_requests SET is_used=1 WHERE id=?')->execute([$row['id']]);
        return true;
    }
    return false;
}

// ── Risk level recalculator (called after payment changes) ────────────────────
// ── Notification helper ──────────────────────────────────────────────────────
function sendNotification(int $userId, string $title, string $message, string $type = 'info'): void {
    try {
        $db = getDB();
        $st = $db->prepare(
            'INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)'
        );
        $st->execute([$userId, $title, $message, $type]);
    } catch (Exception $e) { /* silent */ }
}

function recalcRisk(int $customerId): void {
    $db = getDB();
    $st = $db->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(status="Paid") AS paid,
            SUM(status="Overdue") AS overdue
         FROM payments p
         JOIN locker_assignments la ON la.id = p.assignment_id
         WHERE la.customer_id = ?'
    );
    $st->execute([$customerId]);
    $r = $st->fetch();
    $total   = (int)$r['total'];
    $overdue = (int)$r['overdue'];
    $paid    = (int)$r['paid'];

    if ($total === 0) { $risk = 'low'; }
    elseif ($overdue >= 3 || ($total > 0 && $overdue / $total > 0.4)) { $risk = 'high'; }
    elseif ($overdue >= 1 || ($total > 0 && $paid / $total < 0.7))    { $risk = 'medium'; }
    else   { $risk = 'low'; }

    $db->prepare('UPDATE customers SET risk_level=? WHERE id=?')->execute([$risk, $customerId]);
}

