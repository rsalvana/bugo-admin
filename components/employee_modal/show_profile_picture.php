<?php
// components/employee_modal/show_profile_picture.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

$resId = isset($_GET['res_id']) ? (int)$_GET['res_id'] : 0;

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$stmt = $mysqli->prepare("SELECT profile_picture FROM residents WHERE id = ?");
$stmt->bind_param("i", $resId);
$stmt->execute();
$stmt->bind_result($blob);
$hasRow = $stmt->fetch();
$stmt->close();

if ($hasRow && !empty($blob)) {
    // Detect mime; default to jpeg if unknown
    $mime = 'image/jpeg';
    if (function_exists('finfo_buffer')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($blob);
        if ($detected) $mime = $detected;
    }
    header('Content-Type: ' . $mime);
    echo $blob;
    exit;
}

// Fallback: minimal transparent PNG (1x1)
header('Content-Type: image/png');
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
