<?php

// --- DYNAMIC SECURITY SETTINGS ---
// Detect if we are on Localhost or a Local IP (192.168.x.x)
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (strpos($host, 'localhost') !== false || strpos($host, '192.168.') !== false || strpos($host, '127.0.0.1') !== false);

// If we are Local, disable Secure cookies. If Live, enable them.
$cookieSecure = !$isLocal; 
$sameSite     = $isLocal ? 'Lax' : 'Strict'; // Lax is friendlier for local dev

// --- Enforce Session Settings ---
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $cookieSecure ? 1 : 0); // Dynamic

session_set_cookie_params([
    'lifetime' => 3600,
    'secure'   => $cookieSecure, // Dynamic: False for IP, True for Production
    'httponly' => true, 
    'samesite' => $sameSite 
]);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function record_failed_attempt(mysqli $db, int $employee_id): void {
    $max = 3;        // failures allowed (Increased to 3 for better UX)
    $lock = 300;     // 5 minutes lock (60s is too short for security)

    $q = $db->prepare("SELECT attempts FROM login_attempts WHERE employee_id = ?");
    $q->bind_param("i", $employee_id);
    $q->execute();
    $r = $q->get_result();

    if ($row = $r->fetch_assoc()) {
        $attempts = $row['attempts'] + 1;
        if ($attempts >= $max) {
            $u = $db->prepare("UPDATE login_attempts SET attempts=?, locked_until=DATE_ADD(NOW(), INTERVAL ? SECOND), last_attempt=NOW() WHERE employee_id=?");
            $u->bind_param("iii", $attempts, $lock, $employee_id);
        } else {
            $u = $db->prepare("UPDATE login_attempts SET attempts=?, last_attempt=NOW() WHERE employee_id=?");
            $u->bind_param("ii", $attempts, $employee_id);
        }
        $u->execute();
    } else {
        $i = $db->prepare("INSERT INTO login_attempts (employee_id, attempts, last_attempt) VALUES (?, 1, NOW())");
        $i->bind_param("i", $employee_id);
        $i->execute();
    }
}

function is_locked_out(mysqli $db, int $employee_id) {
    $q = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS remaining 
                       FROM login_attempts 
                       WHERE employee_id = ?");
    $q->bind_param("i", $employee_id);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
        if ($row['remaining'] > 0) {
            return (int)$row['remaining']; // already in seconds, timezone-safe
        }
    }
    return false;
}

function reset_attempts(mysqli $db, int $employee_id): void {
    $d = $db->prepare("DELETE FROM login_attempts WHERE employee_id = ?");
    $d->bind_param("i", $employee_id);
    $d->execute();
}
?>