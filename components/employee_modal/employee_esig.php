<?php
// components/employee_modal/employee_esig.php
// Outputs an employee's e-signature (PNG/JPG/etc.) by employee_id.

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
if ($employeeId <= 0) {
    http_response_code(404);
    exit;
}

$sql = "SELECT esignature, COALESCE(NULLIF(TRIM(esignature_mime), ''), 'image/png') AS mime
        FROM employee_list
        WHERE employee_id = ?
        LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $employeeId);
$stmt->execute();
$stmt->bind_result($blob, $mime);
$found = $stmt->fetch();
$stmt->close();

if ($found && !empty($blob)) {
    header('Content-Type: '.$mime);
    header('Cache-Control: public, max-age=86400, immutable');
    echo $blob;        // raw blob from DB
    exit;
}

// Optional tiny transparent PNG fallback (so the <img> still renders)
http_response_code(404);
header('Content-Type: image/png');
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottQAAAABJRU5ErkJggg==');
