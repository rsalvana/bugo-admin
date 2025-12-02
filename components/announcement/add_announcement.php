<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// PHP clock -> PH
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();                   // already sets MySQL time_zone +08:00
require_once __DIR__ . '/../../logs/logs_trig.php';
require_once __DIR__ . '/../../include/encryption.php';

if (isset($_POST['add_announcement'])) {
    $details     = trim($_POST['announcement_details'] ?? '');
    $employee_id = $_SESSION['employee_id'] ?? null;
    $role        = strtolower($_SESSION['Role_Name'] ?? '');

    if ($details !== '' && $employee_id) {
        // If your table has created_at, set it explicitly in PH time.
        $hasCreated = false;
        try {
            $chk = $mysqli->query("SHOW COLUMNS FROM `announcement` LIKE 'created_at'");
            $hasCreated = $chk && $chk->num_rows > 0; if ($chk) $chk->free();
        } catch (\Throwable $e) {}

        if ($hasCreated) {
            $now  = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $stmt = $mysqli->prepare("INSERT INTO `announcement` (announcement_details, employee_id, created_at) VALUES (?, ?, ?)");
            $stmt->bind_param("sis", $details, $employee_id, $now);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO `announcement` (announcement_details, employee_id) VALUES (?, ?)");
            $stmt->bind_param("si", $details, $employee_id);
        }

        if ($stmt->execute()) {
            (new Trigger())->isAdded(29, $stmt->insert_id);

            switch ($role) {
                case 'admin':               $redirectPage = enc_admin('admin_dashboard'); break;
                case 'punong barangay':     $redirectPage = enc_captain('admin_dashboard'); break;
                case 'beso':                $redirectPage = enc_beso('admin_dashboard'); break;
                case 'barangay secretary':  $redirectPage = enc_brgysec('admin_dashboard'); break;
                case 'lupon':               $redirectPage = enc_lupon('admin_dashboard'); break;
                case 'multimedia':          $redirectPage = enc_multimedia('admin_dashboard'); break;
                case 'revenue staff':       $redirectPage = enc_revenue('admin_dashboard'); break;
                case 'encoder':             $redirectPage = enc_encoder('admin_dashboard'); break;
                default:                    $redirectPage = '../../index.php'; break;
            }

            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <script>document.addEventListener('DOMContentLoaded',()=>{Swal.fire({
                icon:'success',title:'Announcement Added',text:'Your announcement has been successfully added!'
            }).then(()=>{location.href='{$redirectPage}';});});</script>";
        } else {
            $err = addslashes($mysqli->error);
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <script>document.addEventListener('DOMContentLoaded',()=>{Swal.fire({
                icon:'error',title:'Database Error',text:'{$err}'
            }).then(()=>{history.back();});});</script>";
        }
        if (isset($stmt)) $stmt->close();
    } else {
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>document.addEventListener('DOMContentLoaded',()=>{Swal.fire({
            icon:'warning',title:'Missing Fields',text:'Please complete all required fields.'
        }).then(()=>{history.back();});});</script>";
    }
}
