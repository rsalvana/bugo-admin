<?php
// dashboard/dashboard_beso.php — JSON API for BESO dashboard (BESO Application only)

declare(strict_types=1);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Always JSON
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

set_error_handler(function($sev, $msg, $file=null, $line=null){
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$msg]); exit;
});
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['error'=>'Server exception','detail'=>$e->getMessage()]); exit;
});

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ---- Auth (BESO-only; allow Admin fallback if you prefer) ----
$role = strtolower($_SESSION['Role_Name'] ?? '');
if (!in_array($role, ['beso','admin'], true)) {
  http_response_code(403);
  echo json_encode(['error'=>'Forbidden']); exit;
}

// ---- DB ----
require_once dirname(__DIR__) . '/include/connection.php';
$mysqli = db_connection();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');
$mysqli->query("SET time_zone = '+08:00'");

// ---- Helpers ----
function s(string $v): string { return trim((string)$v); }
function i_or_null($v) { return ($v === '' || $v === null) ? null : (int)$v; }

// Pretty labels for audit_info (from your reference)
function transform_logs_filename($n){
  static $map = [
    1=>'EMPLOYEE',2=>'RESIDENTS',3=>'APPOINTMENTS',4=>'CEDULA',5=>'CASES',6=>'ARCHIVE',
    7=>'LOGIN',8=>'LOGOUT',9=>'URGENT REQUEST',10=>'URGENT CEDULA',11=>'EVENTS',
    12=>'BARANGAY OFFICIALS',13=>'BARANGAY INFO',14=>'BARANGAY LOGO',15=>'BARANGAY CERTIFICATES',
    16=>'BARANGAY CERTIFICATES PURPOSES',17=>'ZONE LEADERS',18=>'ZONE',19=>'GUIDELINES',
    20=>'FEEDBACKS',21=>'TIME SLOT',22=>'HOLIDAY',23=>'ARCHIVED RESIDENTS',24=>'ARCHIVED EMPLOYEE',
    25=>'ARCHIVED APPOINTMENTS',26=>'ARCHIVED EVENTS',27=>'ARCHIVED FEEDBACKS',28=>'BESO LIST',
    29=>'ANNOUNCEMENTS',30=>'EMPLOYEE FORGOT PASSWORD'
  ];
  return $map[(int)$n] ?? (string)$n;
}
function transform_action_made($n){
  static $map = [
    1=>'ARCHIVED',2=>'EDITED',3=>'ADDED',4=>'VIEWED',5=>'RESTORED',6=>'LOGIN',7=>'LOGOUT',
    8=>'UPDATE_STATUS',9=>'BATCH_ADD',10=>'URGENT_REQUEST',11=>'PRINT'
  ];
  return $map[(int)$n] ?? (string)$n;
}

// ---- Incoming filters ----
$CERT           = 'BESO Application'; // hard rule: ONLY this certificate
$gender         = s($_GET['gender'] ?? '');               // Male | Female | (empty)
$status         = s($_GET['status'] ?? '');               // Pending | ApprovedCaptain | Released | ...
$min_age        = i_or_null($_GET['min_age'] ?? null);
$max_age        = i_or_null($_GET['max_age'] ?? null);
$urgent_only    = isset($_GET['urgent_only']) && $_GET['urgent_only'] === '1';
$education_only = s($_GET['education'] ?? '');            // filters BESO table

// Build WHERE for urgent_request + residents join (BESO Application only)
$uWhere = ["u.certificate = ?","u.urgent_delete_status = 0"];
$uTypes = "s";
$uVals  = [$CERT];

if ($urgent_only) { $uWhere[] = "u.selected_time = 'URGENT'"; } // apply urgent-only filter
if ($status !== '') { $uWhere[] = "u.status = ?"; $uTypes .= "s"; $uVals[] = $status; }
if ($gender !== '') { $uWhere[] = "r.gender = ?"; $uTypes .= "s"; $uVals[] = $gender; }
if ($min_age !== null) { $uWhere[] = "r.age >= ?"; $uTypes .= "i"; $uVals[] = $min_age; }
if ($max_age !== null) { $uWhere[] = "r.age <= ?"; $uTypes .= "i"; $uVals[] = $max_age; }
$uWhereSql = implode(' AND ', $uWhere);

// Build WHERE for schedules (also force BESO Application only)
$sWhere = ["s.certificate = ?","s.appointment_delete_status = 0"];
$sTypes = "s";
$sVals  = [$CERT];
if ($status !== '') { $sWhere[] = "s.status = ?"; $sTypes .= "s"; $sVals[] = $status; }
$sWhereSql = implode(' AND ', $sWhere);

// Build WHERE for BESO table (applications list)
$bWhere = ["b.beso_delete_status = 0"];
$bTypes = "";
$bVals  = [];
if ($education_only !== '') { $bWhere[] = "b.education_attainment = ?"; $bTypes .= "s"; $bVals[] = $education_only; }
$bWhereSql = implode(' AND ', $bWhere);

// ---- Fetch metrics ----

// 1) Total BESO applications (from `beso` table)
$total = 0;
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM beso b WHERE $bWhereSql");
if ($bTypes) { $stmt->bind_param($bTypes, ...$bVals); }
$stmt->execute(); $stmt->bind_result($total); $stmt->fetch(); $stmt->close();

// 2) Urgent requests count (BESO Application only, optional filters)
$urgent = 0;
$sql = "SELECT COUNT(*)
        FROM urgent_request u
        JOIN residents r ON r.id = u.res_id
        WHERE $uWhereSql";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($uTypes, ...$uVals);
$stmt->execute(); $stmt->bind_result($urgent); $stmt->fetch(); $stmt->close();

// 3) Pending count (from urgent_request, respects filters unless status provided)
$pending = 0;
if ($status === '' || strtolower($status) === 'pending') {
  $pWhere = $uWhere;
  $pTypes = $uTypes;
  $pVals  = $uVals;
  if ($status === '') { // ensure Pending when no explicit status chosen
    $pWhere[] = "u.status = 'Pending'";
  }
  $pWhereSql = implode(' AND ', $pWhere);
  $sql = "SELECT COUNT(*)
          FROM urgent_request u
          JOIN residents r ON r.id = u.res_id
          WHERE $pWhereSql";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param($pTypes, ...$pVals);
  $stmt->execute(); $stmt->bind_result($pending); $stmt->fetch(); $stmt->close();
}

// 4) Scheduled requests (from schedules; BESO Application only)
$scheduled = 0;
$sql = "SELECT COUNT(*) FROM schedules s WHERE $sWhereSql";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($sTypes, ...$sVals);
$stmt->execute(); $stmt->bind_result($scheduled); $stmt->fetch(); $stmt->close();

// 5) Gender distribution (urgent_request × residents; BESO Application only)
$genderData = [];
$sql = "SELECT r.gender, COUNT(*)
        FROM urgent_request u
        JOIN residents r ON r.id = u.res_id
        WHERE $uWhereSql
        GROUP BY r.gender";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($uTypes, ...$uVals);
$stmt->execute();
$stmt->bind_result($g, $cnt);
while ($stmt->fetch()) { $genderData[$g ?: 'Unknown'] = (int)$cnt; }
$stmt->close();

// 6) Age distribution buckets (urgent_request × residents; BESO Application only)
$ageData = [0,0,0,0,0];
$sql = "SELECT
          SUM(CASE WHEN r.age IS NOT NULL AND r.age <= 18 THEN 1 ELSE 0 END) AS a0_18,
          SUM(CASE WHEN r.age BETWEEN 19 AND 35 THEN 1 ELSE 0 END)          AS a19_35,
          SUM(CASE WHEN r.age BETWEEN 36 AND 50 THEN 1 ELSE 0 END)          AS a36_50,
          SUM(CASE WHEN r.age BETWEEN 51 AND 65 THEN 1 ELSE 0 END)          AS a51_65,
          SUM(CASE WHEN r.age >= 66 THEN 1 ELSE 0 END)                      AS a65p
        FROM urgent_request u
        JOIN residents r ON r.id = u.res_id
        WHERE $uWhereSql";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($uTypes, ...$uVals);
$stmt->execute();
$stmt->bind_result($a0_18, $a19_35, $a36_50, $a51_65, $a65p);
if ($stmt->fetch()) {
  $ageData = [ (int)$a0_18, (int)$a19_35, (int)$a36_50, (int)$a51_65, (int)$a65p ];
}
$stmt->close();

// 7) Education attainment distribution (from `beso` table)
$educationData = [];
$sql = "SELECT COALESCE(NULLIF(TRIM(b.education_attainment),''),'Unknown') AS edu, COUNT(*)
        FROM beso b
        WHERE $bWhereSql
        GROUP BY edu
        ORDER BY edu";
$stmt = $mysqli->prepare($sql);
if ($bTypes) { $stmt->bind_param($bTypes, ...$bVals); }
$stmt->execute();
$stmt->bind_result($edu, $cnt);
while ($stmt->fetch()) { $educationData[$edu] = (int)$cnt; }
$stmt->close();

/* ------------------------------------------------------------------
   8) Recent activity — MATCHES YOUR REFERENCE (audit_info for user)
   ------------------------------------------------------------------ */
$recentActivities = [];
$employee_id = (int)($_SESSION['employee_id'] ?? 0);

if ($employee_id > 0) {
  $sql = "SELECT
            ai.id,
            ai.logs_name,
            ai.action_made,
            ai.date_created,
            CONCAT(el.employee_fname, ' ', el.employee_lname) AS employee_name
          FROM audit_info ai
          JOIN employee_list el ON ai.action_by = el.employee_id
          WHERE ai.action_by = ?
          ORDER BY ai.date_created DESC
          LIMIT 10";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param('i', $employee_id);
  $stmt->execute();
  $stmt->bind_result($id, $logs_name, $action_made, $date_created, $employee_name);
  while ($stmt->fetch()) {
    $recentActivities[] = [
      'id'         => (int)$id,
      'module'     => transform_logs_filename($logs_name),
      'action'     => transform_action_made($action_made),
      'action_by'  => $employee_name,
      'date'       => $date_created,
      'date_human' => date('M d, Y h:i A', strtotime($date_created)),
    ];
  }
  $stmt->close();
}

// Derive male/female counts from genderData
$males   = (int)($genderData['Male'] ?? 0);
$females = (int)($genderData['Female'] ?? 0);

// ---- Output ----
echo json_encode([
  'total'           => (int)$total,         // from `beso`
  'males'           => $males,              // from urgent BESO Application join
  'females'         => $females,            // from urgent BESO Application join
  'urgent'          => (int)$urgent,        // urgent_request, BESO Application only
  'pending'         => (int)$pending,       // pending among urgent_request
  'scheduled'       => (int)$scheduled,     // schedules with BESO Application (likely 0)
  'ageData'         => $ageData,            // [0-18,19-35,36-50,51-65,65+]
  'genderData'      => $genderData,         // {Male:n, Female:n, ...}
  'educationData'   => $educationData,      // from `beso`
  'recentActivities'=> $recentActivities
], JSON_UNESCAPED_UNICODE);
