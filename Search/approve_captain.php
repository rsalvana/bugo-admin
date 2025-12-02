<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

/* ---- Timezone (PHP + MySQL session) ---- */
$mysqli->query("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');

require_once '../logs/logs_trig.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// session_start(); // Assuming session is started elsewhere
$user_role   = strtolower($_SESSION['Role_Name'] ?? '');
$employee_id = intval($_SESSION['employee_id'] ?? 0);

// === 1. Input and Role Validation ===
header('Content-Type: application/json; charset=UTF-8');

// ✅ Role check
if (
    $user_role !== 'admin' &&
    $user_role !== 'punong barangay' &&
    $user_role !== 'barangay secretary' &&
    $user_role !== 'revenue staff'
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Forbidden: insufficient role.'
    ]);
    exit;
}

$res_id                = intval($_POST['res_id'] ?? 0);
$selected_date         = $_POST['selected_date'] ?? '';
$assigned_kag_name     = trim($_POST['assignedKagName'] ?? '');
$assigned_witness_name = trim($_POST['assignedWitnessName'] ?? '');

if (!$res_id || !$selected_date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing res_id or selected_date.']);
    exit;
}

$trigger      = new Trigger();
$totalUpdated = 0;

/* ====================================================================
   1.5  RESPONDENT-CASE GUARD for Barangay Clearance (bulk approval)
   - If resident is RESPONDENT in an Ongoing/Pending case,
     block bulk set to Approved/ApprovedCaptain for Clearance rows
==================================================================== */

/** Check if resident is RESPONDENT with ongoing/pending case (matches by full name to cases.Resp_* fields). */
function respondent_has_ongoing_case(mysqli $db, int $resId): bool {
    if ($resId <= 0) return false;

    // Fetch resident name (normalize)
    $sqlName = "SELECT first_name, IFNULL(middle_name,''), last_name, IFNULL(suffix_name,'')
                  FROM residents WHERE id = ? LIMIT 1";
    $st = $db->prepare($sqlName);
    if (!$st) return false;
    $st->bind_param("i", $resId);
    $st->execute();
    $st->bind_result($f,$m,$l,$s);
    if (!$st->fetch()) { $st->close(); return false; }
    $st->close();

    $f = mb_strtolower(trim((string)$f));
    $m = mb_strtolower(trim((string)$m));
    $l = mb_strtolower(trim((string)$l));
    $s = mb_strtolower(trim((string)$s));

    // Check cases table for respondent rows with unresolved action_taken
    $sqlCase = "
        SELECT COUNT(*) AS cnt
          FROM cases
         WHERE LOWER(TRIM(COALESCE(Resp_First_Name,'')))  = ?
           AND LOWER(TRIM(COALESCE(Resp_Middle_Name,''))) = ?
           AND LOWER(TRIM(COALESCE(Resp_Last_Name,'')))   = ?
           AND LOWER(TRIM(COALESCE(Resp_Suffix_Name,''))) = ?
           AND LOWER(TRIM(COALESCE(action_taken,''))) IN ('ongoing','pending')
        LIMIT 1
    ";
    $st2 = $db->prepare($sqlCase);
    if (!$st2) return false;
    $st2->bind_param("ssss", $f,$m,$l,$s);
    $st2->execute();
    $rs2 = $st2->get_result();
    $cnt = (int)($rs2->fetch_assoc()['cnt'] ?? 0);
    $st2->close();

    return $cnt > 0;
}

/** Check if there is any same-day Barangay Clearance appointment (sched/urgent) subject to approval. */
function has_same_day_clearance(mysqli $db, int $resId, string $date): bool {
    $q = $db->prepare("
        SELECT (
            SELECT COUNT(*) FROM schedules
             WHERE res_id = ? AND selected_date = ? 
               AND LOWER(TRIM(certificate)) = 'barangay clearance'
               AND LOWER(TRIM(COALESCE(status,''))) IN ('pending','approved','approvedcaptain')
        ) +
        (
            SELECT COUNT(*) FROM urgent_request
             WHERE res_id = ? AND selected_date = ? 
               AND LOWER(TRIM(certificate)) = 'barangay clearance'
               AND LOWER(TRIM(COALESCE(status,''))) IN ('pending','approved','approvedcaptain')
        ) AS cnt
    ");
    if (!$q) return false;
    $q->bind_param('isis', $resId, $date, $resId, $date);
    $q->execute();
    $cnt = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
    $q->close();
    return $cnt > 0;
}

// 🔒 Block bulk approval if both conditions meet
if (respondent_has_ongoing_case($mysqli, $res_id) && has_same_day_clearance($mysqli, $res_id, $selected_date)) {
    http_response_code(409); // Conflict
    echo json_encode([
        'success' => false,
        'message' => 'Cannot set to Approved/ApprovedCaptain for Barangay Clearance: resident is a RESPONDENT in an Ongoing/Pending case.'
    ]);
    exit;
}

// === 2. Helper Functions for Dynamic Column Resolution ===
/** Check if a column exists using information_schema (safe for prepared statements) */
function table_has_column(mysqli $db, string $table, string $column): bool {
    $sql = "
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME  = ?
           AND COLUMN_NAME = ?
         LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok  = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

/** Resolves the name of the Kagawad assignment column. */
function resolve_kag_col(mysqli $db, string $table): ?string {
    if (table_has_column($db, $table, 'assignedKagName'))   return 'assignedKagName';
    if (table_has_column($db, $table, 'assigned_kag_name')) return 'assigned_kag_name';
    if (table_has_column($db, $table, 'assignedKag'))       return 'assignedKag';
    return null;
}

// ⭐ Resolves the name of the Witness assignment column.
function resolve_witness_col(mysqli $db, string $table): ?string {
    if (table_has_column($db, $table, 'assigned_witness_name')) return 'assigned_witness_name';
    return null;
}

// === 3. Bulk Update Logic ===
$tables = [
    // The 'assign' flag indicates tables that support Kagawad/Witness fields (non-cedula).
    ['table' => 'schedules',             'status_col' => 'status',        'date_col' => 'selected_date',    'log_file' => 3,  'assign' => true],
    ['table' => 'cedula',                'status_col' => 'cedula_status', 'date_col' => 'appointment_date', 'log_file' => 4,  'assign' => false],
    ['table' => 'urgent_request',        'status_col' => 'status',        'date_col' => 'selected_date',    'log_file' => 9,  'assign' => true],
    ['table' => 'urgent_cedula_request', 'status_col' => 'cedula_status', 'date_col' => 'appointment_date', 'log_file' => 10, 'assign' => false],
];

foreach ($tables as $t) {
    $table           = $t['table'];
    $statusCol       = $t['status_col'];
    $dateCol         = $t['date_col'];
    $supports_assign = $t['assign'];

    // 3.1. Build SET Clause and Dynamic Parameters
    $setParts = [
        "`$statusCol` = 'ApprovedCaptain'",
        "is_read = 0",
        "employee_id = ?",
        "update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')"
    ];

    $bindTypes  = "i";                // employee_id
    $bindValues = [&$employee_id];    // values by reference for bind_param

    // Track dynamic string parameters and types
    $dynamicStringTypes  = '';
    $dynamicStringValues = [];

    if ($supports_assign) {
        $kagCol = ($assigned_kag_name !== '') ? resolve_kag_col($mysqli, $table) : null;
        $witCol = ($assigned_witness_name !== '') ? resolve_witness_col($mysqli, $table) : null;

        if ($kagCol !== null) {
            $setParts[]            = "`$kagCol` = ?";
            $dynamicStringTypes   .= "s";
            $dynamicStringValues[] = &$assigned_kag_name;
        }
        if ($witCol !== null) {
            $setParts[]            = "`$witCol` = ?";
            $dynamicStringTypes   .= "s";
            $dynamicStringValues[] = &$assigned_witness_name;
        }
    }

    // Finalize Bind Arrays: [employee_id], [dynamic names...], [res_id], [selected_date]
    $bindTypes  .= $dynamicStringTypes;
    $bindValues  = array_merge($bindValues, $dynamicStringValues);

    // Add fixed parameters for WHERE clause (res_id and selected_date)
    $bindTypes  .= "is"; // 'i' for res_id, 's' for selected_date
    $bindValues[] = &$res_id;
    $bindValues[] = &$selected_date;

    $setClause = implode(", ", $setParts);

    // 3.2. Prepare and Execute SQL
    $sql = "
        UPDATE `$table`
           SET $setClause
         WHERE res_id = ?
           AND `$dateCol` = ?
           AND `$statusCol` IN ('Approved','ApprovedCaptain')
    ";

    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        error_log("SQL prepare failed for $table: " . $mysqli->error);
        continue;
    }

    // Sanity check (non-fatal log only)
    if (count($bindValues) !== strlen($bindTypes)) {
        error_log("BIND MISMATCH (Check Logic): Table {$table} - Types: {$bindTypes}, Values: " . count($bindValues));
    }

    // Pass $bindTypes string first, then all values in $bindValues array
    call_user_func_array([$stmt, 'bind_param'], array_merge([$bindTypes], $bindValues));

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    // 3.3. Logging and Counting
    if ($affected > 0) {
        $detail = "Bulk on $selected_date by employee_id {$employee_id}";
        if ($assigned_kag_name !== '')     $detail .= " | Kagawad: {$assigned_kag_name}";
        if ($assigned_witness_name !== '') $detail .= " | Witness: {$assigned_witness_name}";

        try {
            $trigger->isStatusUpdate($t['log_file'], $res_id, 'ApprovedCaptain', $detail);
        } catch (Exception $e) {
            error_log("Logging failed: " . $e->getMessage());
        }
        $totalUpdated += $affected;
    }
}

// === 4. Notification ===
if ($totalUpdated > 0) {
    $info_query = "
        SELECT email, CONCAT(first_name, ' ', middle_name, ' ', last_name) AS full_name
          FROM residents WHERE id = ? LIMIT 1
    ";
    $stmt_info = $mysqli->prepare($info_query);
    $stmt_info->bind_param("i", $res_id);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();

    if ($row = $result_info->fetch_assoc()) {
        $email         = $row['email'];
        $resident_name = $row['full_name'];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host          = 'mail.bugoportal.site';
            $mail->SMTPAuth      = true;
            $mail->Username      = 'admin@bugoportal.site';
            $mail->Password      = 'Jayacop@100';
            $mail->Port          = 465;
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_SMTPS; // SSL
            $mail->SMTPAutoTLS   = true;
            $mail->SMTPKeepAlive = false;
            $mail->Timeout       = 12;
            $mail->SMTPOptions   = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];

            $mail->setFrom('admin@bugoportal.site', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->addReplyTo('admin@bugoportal.site', 'Barangay Office');
            $mail->Sender   = 'admin@bugoportal.site';
            $mail->Hostname = 'bugoportal.site';
            $mail->CharSet  = 'UTF-8';

            // Build dynamic text for email body
            $assignment_info_html = '';
            $assignment_info_alt  = '';
            if ($assigned_kag_name !== '') {
                $assignment_info_html .= "<p>Assigned Kagawad: <strong>{$assigned_kag_name}</strong></p>";
                $assignment_info_alt  .= "Assigned Kagawad: {$assigned_kag_name}\n";
            }
            if ($assigned_witness_name !== '') {
                $assignment_info_html .= "<p>Witness/Secretary: <strong>{$assigned_witness_name}</strong></p>";
                $assignment_info_alt  .= "Witness/Secretary: {$assigned_witness_name}\n";
            }

            $mail->isHTML(true);
            $mail->Subject = 'Appointment Approved by Barangay Captain';
            $mail->Body = "<p>Dear {$resident_name},</p>
                <p>Your appointment(s) on <strong>{$selected_date}</strong> has/have been approved by the Barangay Captain.</p>"
                . $assignment_info_html
                . "<br><p>Thank you,<br>Barangay Office</p>";

            $mail->AltBody = "Dear {$resident_name},\n\nYour appointment(s) on {$selected_date} has/have been approved by the Barangay Captain.\n\n"
                . $assignment_info_alt
                . "\nThank you.\nBarangay Office";

            $mail->send();
        } catch (Exception $e) {
            error_log("❌ Email failed to send: " . $mail->ErrorInfo);
        }
    }

    echo json_encode([
        'success' => true,
        'updated' => $totalUpdated,
        'message' => "Approved {$totalUpdated} appointment(s). Email sent."
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No appointments found for this resident on that date.'
    ]);
}
exit;
