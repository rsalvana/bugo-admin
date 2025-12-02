<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

if (session_status() === PHP_SESSION_NONE) session_start();

/* --- Role gate (normalized) --- */
$allowed = ['lupon', 'punong barangay', 'barangay secretary', 'revenue staff'];
$user_role = strtolower($_SESSION['Role_Name'] ?? '');
if (!in_array($user_role, $allowed, true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

/* --- Input --- */
$res_id = isset($_GET['res_id']) ? (int)$_GET['res_id'] : 0;
if ($res_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<strong>No case history found.</strong>";
    exit;
}

/* --- Step 1: get resident name parts from residents --- */
$qr = $mysqli->prepare("
    SELECT 
        TRIM(COALESCE(first_name,''))  AS first_name,
        TRIM(COALESCE(middle_name,'')) AS middle_name,
        TRIM(COALESCE(last_name,''))   AS last_name,
        TRIM(COALESCE(suffix_name,'')) AS suffix_name
    FROM residents
    WHERE id = ?
    LIMIT 1
");
if (!$qr) {
    http_response_code(500);
    echo "<strong>Error loading case history.</strong>";
    exit;
}
$qr->bind_param("i", $res_id);
$qr->execute();
$res = $qr->get_result();
if (!$res || $res->num_rows === 0) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<strong>No case history found.</strong>";
    exit;
}
$resident = $res->fetch_assoc();
$f = $resident['first_name']  ?? '';
$m = $resident['middle_name'] ?? '';
$l = $resident['last_name']   ?? '';
$s = $resident['suffix_name'] ?? '';

/* --- Step 2: query cases by Resp_* fields --- */
$sql = "
    SELECT `case_number`, `nature_offense`, `date_filed`, `action_taken`
    FROM `cases`
    WHERE
        LOWER(TRIM(COALESCE(`Resp_First_Name`,  ''))) = LOWER(TRIM(?)) AND
        LOWER(TRIM(COALESCE(`Resp_Middle_Name`, ''))) = LOWER(TRIM(?)) AND
        LOWER(TRIM(COALESCE(`Resp_Last_Name`,   ''))) = LOWER(TRIM(?)) AND
        LOWER(TRIM(COALESCE(`Resp_Suffix_Name`, ''))) = LOWER(TRIM(?))
    ORDER BY `date_filed` DESC
";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "<strong>Error loading case history.</strong>";
    exit;
}
$stmt->bind_param("ssss", $f, $m, $l, $s);
$stmt->execute();
$result = $stmt->get_result();

/* --- Render small HTML snippet --- */
header('Content-Type: text/html; charset=UTF-8');
if ($result && $result->num_rows > 0) {
    echo "<ul class='mb-0'>";
    while ($row = $result->fetch_assoc()) {
        $case_no = htmlspecialchars($row['case_number'] ?? '');
        $nature  = htmlspecialchars($row['nature_offense'] ?? '');
        $filed   = htmlspecialchars($row['date_filed'] ?? '');
        $action  = htmlspecialchars($row['action_taken'] ?? '');
        echo "<li><strong>Case #{$case_no}</strong>: {$nature} ({$filed}) - <em>{$action}</em></li>";
    }
    echo "</ul>";
} else {
    echo "<strong>No case history found.</strong>";
}
