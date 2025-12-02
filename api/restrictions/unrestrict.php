<?php
declare(strict_types=1);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'error'=>'Method not allowed']);
  exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']);
  exit;
}

$residentId = isset($_POST['resident_id']) ? (int)$_POST['resident_id'] : 0;
if ($residentId <= 0) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>'Invalid resident_id']);
  exit;
}

/* Mirror UI rule (who can unrestrict) if needed */
$role = strtolower($_SESSION['Role_Name'] ?? '');
if (in_array($role, ['lupon','punong barangay','barangay secretary'], true)) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'Forbidden']);
  exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
if ($mysqli->connect_error) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'DB connection failed']);
  exit;
}

/* ---------------- CONFIG ---------------- */
$daysThreshold = 3;  // “older than 3 days”
$badStatuses   = ['approvedcaptain','pending']; // treat as unclaimed (case-insensitive)

/* Helper to run a DELETE with 2 status placeholders and date threshold */
function deleteUnclaimed(mysqli $db, string $sql, int $residentId, int $days, array $badStatuses): int {
  // expects SQL with placeholders: res_id, status1, status2, days
  $st = $db->prepare($sql);
  $s1 = $badStatuses[0];
  $s2 = $badStatuses[1];
  $st->bind_param('issi', $residentId, $s1, $s2, $days);
  $st->execute();
  $rows = $st->affected_rows;
  $st->close();
  return $rows;
}

$deleted = [
  'schedules' => 0,
  'cedula' => 0,
  'urgent_request' => 0,
  'urgent_cedula_request' => 0,
];
$removedRestrictions = 0;

$mysqli->begin_transaction();
try {
  /* 1) Lift restriction */
  $stmt = $mysqli->prepare("DELETE FROM resident_restrictions WHERE resident_id = ?");
  $stmt->bind_param("i", $residentId);
  $stmt->execute();
  $removedRestrictions = $stmt->affected_rows;
  $stmt->close();

  /* 2) Delete offending appointments across the known tables */

  // schedules: res_id, status, selected_date
  $deleted['schedules'] = deleteUnclaimed(
    $mysqli,
    "DELETE FROM `schedules`
     WHERE `res_id` = ?
       AND LOWER(`status`) IN (?, ?)
       AND `selected_date` <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
    $residentId, $daysThreshold, $badStatuses
  );

  // cedula: res_id, cedula_status, appointment_date
  $deleted['cedula'] = deleteUnclaimed(
    $mysqli,
    "DELETE FROM `cedula`
     WHERE `res_id` = ?
       AND LOWER(`cedula_status`) IN (?, ?)
       AND `appointment_date` <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
    $residentId, $daysThreshold, $badStatuses
  );

  // urgent_request: res_id, status, selected_date
  $deleted['urgent_request'] = deleteUnclaimed(
    $mysqli,
    "DELETE FROM `urgent_request`
     WHERE `res_id` = ?
       AND LOWER(`status`) IN (?, ?)
       AND `selected_date` <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
    $residentId, $daysThreshold, $badStatuses
  );

  // urgent_cedula_request: res_id, cedula_status, appointment_date
  $deleted['urgent_cedula_request'] = deleteUnclaimed(
    $mysqli,
    "DELETE FROM `urgent_cedula_request`
     WHERE `res_id` = ?
       AND LOWER(`cedula_status`) IN (?, ?)
       AND `appointment_date` <= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
    $residentId, $daysThreshold, $badStatuses
  );

  $mysqli->commit();

  echo json_encode([
    'success' => true,
    'removed_restrictions' => $removedRestrictions,
    'deleted' => $deleted
  ]);
} catch (Throwable $e) {
  $mysqli->rollback();
  error_log("unrestrict.php error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Server error while unrestricting.']);
}
