<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Default to JSON
header('Content-Type: application/json');

// ----- Fatal/Warning handlers -> JSON -----
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'EXCEPTION: ' . $e->getMessage(), 'line' => $e->getLine()]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "ERROR: [$errno] $errstr at $errfile:$errline"]);
    exit;
});

// ----- Bootstrap / deps -----
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// Ensure MySQL session is in PH time
$mysqli->query("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../logs/logs_trig.php';
$trigger = new Trigger();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ----- Session / AuthZ -----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role         = $_SESSION['Role_Name']     ?? '';
$employee_id  = (int)($_SESSION['employee_id'] ?? 0); // ✅ who performs the update

if ($role !== 'Revenue Staff' && $role !== 'Admin' && $role !== 'indigency') {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// ----- Inputs -----
$tracking_number = $_POST['tracking_number'] ?? '';
$new_status      = $_POST['new_status'] ?? '';
$reason          = $_POST['rejection_reason'] ?? '';
$cedula_number   = $_POST['cedula_number'] ?? '';
$apply_all       = isset($_POST['apply_all_same_day']) && $_POST['apply_all_same_day'] === '1';

// NEW: Kagawad assignment by ID (from the dropdown)
$assigned_kagawad_id = (int)($_POST['assigned_kagawad_id'] ?? 0);

// NEW: Witness assignment by Name (from the dropdown)
$assigned_witness_name = trim($_POST['assigned_witness_name'] ?? '');

if ($tracking_number === '' || $new_status === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// ----- Locate base record (res_id + date) from any table that holds the tracking number -----
$query = "
    SELECT res_id, selected_date AS appt_date FROM schedules WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date FROM cedula WHERE tracking_number = ?
    UNION
    SELECT res_id, selected_date AS appt_date FROM urgent_request WHERE tracking_number = ?
    UNION
    SELECT res_id, appointment_date AS appt_date FROM urgent_cedula_request WHERE tracking_number = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
$stmt->execute();
$result = $stmt->get_result();

if (!($row = $result->fetch_assoc())) {
    echo json_encode(['success' => false, 'message' => 'Failed to find appointment info.']);
    exit;
}
$res_id = (int)$row['res_id'];
$date   = $row['appt_date'] ?? null;

/* ------------------------------------------------------------------
   NEW: Check for Non-Appearance Respondent Logic
   Prevents status update if resident is a Respondent with Non-Appearance
-------------------------------------------------------------------*/
$hasNonAppearanceCase = function (int $resId) use ($mysqli): bool {
    if ($resId <= 0) return false;

    // Get resident name
    $st = $mysqli->prepare("SELECT first_name, last_name, IFNULL(middle_name, ''), IFNULL(suffix_name, '') FROM residents WHERE id = ? LIMIT 1");
    $st->bind_param("i", $resId);
    $st->execute();
    $st->bind_result($f, $l, $m, $s);
    if (!$st->fetch()) { $st->close(); return false; }
    $st->close();

    $f = trim((string)$f);
    $l = trim((string)$l);
    
    // Check case_participants for Respondent + Non-Appearance
    $sql = "
        SELECT COUNT(*) as cnt 
        FROM case_participants 
        WHERE LOWER(TRIM(first_name)) = LOWER(?) 
          AND LOWER(TRIM(last_name))  = LOWER(?) 
          AND role = 'Respondent' 
          AND action_taken = 'Non-Appearance'
    ";
    
    $st2 = $mysqli->prepare($sql);
    $st2->bind_param("ss", $f, $l);
    $st2->execute();
    $res2 = $st2->get_result();
    $cnt = (int)($res2->fetch_assoc()['cnt'] ?? 0);
    $st2->close();

    return $cnt > 0;
};

// Execute the check immediately after finding the resident ID
if ($hasNonAppearanceCase($res_id)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Cannot update status: This resident is marked as a Respondent with "Non-Appearance" in a pending case.'
    ]);
    exit;
}

/**
 * Helper to build BESO skip condition (revenue staff cannot touch "BESO Application")
 */
$buildSkipBesoCondition = function (string $table, string $role): string {
    if ($role === 'Revenue Staff' && in_array($table, ['schedules', 'urgent_request'], true)) {
        return " AND (certificate IS NULL OR LOWER(TRIM(certificate)) != 'beso application')";
    }
    return '';
};

/**
 * Resolve a Kagawad by resident ID -> return full name if ACTIVE & position LIKE '%Kagawad%'
 */
$getValidKagawadName = function (int $resId) use ($mysqli): ?string {
    if ($resId <= 0) return null;
    $sql = "
        SELECT CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name
        FROM barangay_information bi
        JOIN residents r ON r.id = bi.official_id
        WHERE bi.status='active'
          AND bi.position LIKE '%Kagawad%'
          AND bi.official_id = ?
        LIMIT 1
    ";
    $st = $mysqli->prepare($sql);
    $st->bind_param("i", $resId);
    $st->execute();
    $rs = $st->get_result();
    $name = $rs && $rs->num_rows ? (string)$rs->fetch_assoc()['full_name'] : null;
    $st->close();
    return $name;
};

/* --- NEW: Respondent-case guard (matches by resident name to cases.* Resp_* columns) --- */
$respondentHasOngoingCase = function (int $resId) use ($mysqli): bool {
    if ($resId <= 0) return false;

    // Get resident name
    $st = $mysqli->prepare("SELECT first_name, IFNULL(middle_name,''), last_name, IFNULL(suffix_name,'') FROM residents WHERE id = ? LIMIT 1");
    $st->bind_param("i", $resId);
    $st->execute();
    $st->bind_result($f,$m,$l,$s);
    if (!$st->fetch()) { $st->close(); return false; }
    $st->close();

    // Normalize
    $f = mb_strtolower(trim((string)$f));
    $m = mb_strtolower(trim((string)$m));
    $l = mb_strtolower(trim((string)$l));
    $s = mb_strtolower(trim((string)$s));

    // Ongoing/Pending (add more unresolved labels if needed)
    $sql = "
        SELECT COUNT(*) AS cnt
          FROM cases
         WHERE LOWER(TRIM(COALESCE(Resp_First_Name,'')))  = ?
           AND LOWER(TRIM(COALESCE(Resp_Middle_Name,''))) = ?
           AND LOWER(TRIM(COALESCE(Resp_Last_Name,'')))   = ?
           AND LOWER(TRIM(COALESCE(Resp_Suffix_Name,''))) = ?
           AND LOWER(TRIM(COALESCE(action_taken,''))) IN ('ongoing','pending')
        LIMIT 1
    ";
    $st2 = $mysqli->prepare($sql);
    $st2->bind_param("ssss", $f,$m,$l,$s);
    $st2->execute();
    $rs2 = $st2->get_result();
    $cnt = (int)($rs2->fetch_assoc()['cnt'] ?? 0);
    $st2->close();

    return $cnt > 0;
};

/* ------------------------------------------------------------------
   NEW (GENERALIZED): If attempting to set status to Approved,
   ApprovedCaptain, or Released for any Barangay Clearance row
   (single or apply_all), block when respondent has ongoing/pending case.
-------------------------------------------------------------------*/
$statusesToBlockOnCase = ['Approved','ApprovedCaptain','Released'];
if (in_array($new_status, $statusesToBlockOnCase, true)) {
    $affectsClearance = false;

    // Single: check if the clicked record is a Clearance (schedules/urgent_request only)
    $stCert = $mysqli->prepare("
        (SELECT certificate FROM schedules WHERE tracking_number = ?)
        UNION ALL
        (SELECT certificate FROM urgent_request WHERE tracking_number = ?)
        LIMIT 1
    ");
    $stCert->bind_param("ss", $tracking_number, $tracking_number);
    $stCert->execute();
    $certRes = $stCert->get_result();
    if ($certRes && $certRes->num_rows) {
        $currCert = strtolower(trim((string)$certRes->fetch_assoc()['certificate']));
        if ($currCert === 'barangay clearance') $affectsClearance = true;
    }
    $stCert->close();

    // Apply-all: if not already flagged, check for any same-day clearance rows
    if (!$affectsClearance && $apply_all && $date) {
        $q = $mysqli->prepare("
            SELECT (
                (SELECT COUNT(*) FROM schedules      WHERE res_id = ? AND selected_date = ? AND LOWER(TRIM(certificate)) = 'barangay clearance')
              + (SELECT COUNT(*) FROM urgent_request WHERE res_id = ? AND selected_date = ? AND LOWER(TRIM(certificate)) = 'barangay clearance')
            ) AS cnt
        ");
        $q->bind_param('isis', $res_id, $date, $res_id, $date);
        $q->execute();
        $cnt = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
        $q->close();
        if ($cnt > 0) $affectsClearance = true;
    }

    if ($affectsClearance && $respondentHasOngoingCase($res_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot set status to '.$new_status.' for Barangay Clearance: resident is a RESPONDENT in an Ongoing/Pending case.'
        ]);
        exit;
    }
}

// ============================= Guards for "Released" =============================
if ($new_status === 'Released') {
    if ($apply_all && $date) {
        // Check all target rows across the 4 tables for this resident on this date
        $tablesToCheck = [
            // table, statusCol, dateCol
            ['schedules',             'status',        'selected_date'],
            ['cedula',                'cedula_status', 'appointment_date'],
            ['urgent_request',        'status',        'selected_date'],
            ['urgent_cedula_request', 'cedula_status', 'appointment_date'],
        ];

        $violations = 0;
        foreach ($tablesToCheck as [$table, $statusCol, $dateCol]) {
            $skipBeso = $buildSkipBesoCondition($table, $role);

            // Count rows that would be affected AND are NOT in ApprovedCaptain
            $sqlChk = "SELECT COUNT(*) AS bad
                        FROM {$table}
                        WHERE res_id = ? AND {$dateCol} = ? {$skipBeso}
                          AND COALESCE({$statusCol}, '') <> 'ApprovedCaptain'";
            $st = $mysqli->prepare($sqlChk);
            $st->bind_param("is", $res_id, $date);
            $st->execute();
            $rs = $st->get_result();
            $bad = (int)($rs->fetch_assoc()['bad'] ?? 0);
            $st->close();

            $violations += $bad;
            if ($violations > 0) break;
        }

        if ($violations > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'You can only set status to "Released" after all affected records are "ApprovedCaptain".'
            ]);
            exit;
        }
    } else {
        // Single record: inspect current status from whichever table holds this tracking number
        $sqlCurrent = "
            SELECT st FROM (
                SELECT cedula_status AS st FROM cedula                WHERE tracking_number = ?
                UNION ALL
                SELECT cedula_status AS st FROM urgent_cedula_request WHERE tracking_number = ?
                UNION ALL
                SELECT status        AS st FROM schedules               WHERE tracking_number = ?
                UNION ALL
                SELECT status        AS st FROM urgent_request          WHERE tracking_number = ?
            ) x LIMIT 1
        ";
        $stc = $mysqli->prepare($sqlCurrent);
        $stc->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
        $stc->execute();
        $rsc = $stc->get_result();
        $currentStatus = $rsc && $rsc->num_rows ? (string)$rsc->fetch_assoc()['st'] : '';
        $stc->close();

        if ($currentStatus !== 'ApprovedCaptain') {
            echo json_encode([
                'success' => false,
                'message' => 'You can only set status to "Released" after it is "ApprovedCaptain".'
            ]);
            exit;
        }
    }
}

/* ------------------------------------------------------------------
   Cedula-first rule when releasing with "Apply to same day"
   If there are any non-cedula rows being released and there exists
   a same-day cedula/urgent-cedula that is NOT yet Released -> block
-------------------------------------------------------------------*/
if ($new_status === 'Released' && $apply_all && $date) {
    $skipSched = $buildSkipBesoCondition('schedules', $role);
    $skipUrg   = $buildSkipBesoCondition('urgent_request', $role);

    // Count non-cedula targets
    $sqlNonCedula = "
        SELECT (
            (SELECT COUNT(*) FROM schedules      WHERE res_id = ? AND selected_date = ? {$skipSched})
          + (SELECT COUNT(*) FROM urgent_request WHERE res_id = ? AND selected_date = ? {$skipUrg})
        ) AS cnt
    ";
    $stNC = $mysqli->prepare($sqlNonCedula);
    $stNC->bind_param('isis', $res_id, $date, $res_id, $date);
    $stNC->execute();
    $nonCedulaCnt = (int)($stNC->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stNC->close();

    // Any same-day Cedula/urgent-cedula NOT yet Released?
    $sqlCedulaPending = "
        SELECT (
            (SELECT COUNT(*) FROM cedula                WHERE res_id = ? AND appointment_date = ? AND COALESCE(cedula_status,'') <> 'Released')
          + (SELECT COUNT(*) FROM urgent_cedula_request WHERE res_id = ? AND appointment_date = ? AND COALESCE(cedula_status,'') <> 'Released')
        ) AS pending_cnt
    ";
    $stCP = $mysqli->prepare($sqlCedulaPending);
    $stCP->bind_param('isis', $res_id, $date, $res_id, $date);
    $stCP->execute();
    $cedulaPending = (int)($stCP->get_result()->fetch_assoc()['pending_cnt'] ?? 0);
    $stCP->close();

    if ($nonCedulaCnt > 0 && $cedulaPending > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'This resident has a Cedula appointment on the same day. Please release the Cedula first before releasing other appointments.'
        ]);
        exit;
    }
}

// ====================================================================
// Cedula-number requirement: only when releasing AND a cedula row is hit
// ====================================================================

// 1) Is the clicked tracking number itself a cedula/urgent-cedula?
$isCedulaTracking = false;
$chk = $mysqli->prepare("
    (SELECT 1 AS x FROM cedula WHERE tracking_number = ?)
    UNION ALL
    (SELECT 1 FROM urgent_cedula_request WHERE tracking_number = ?)
    LIMIT 1
");
$chk->bind_param("ss", $tracking_number, $tracking_number);
$chk->execute();
$chkRes = $chk->get_result();
$isCedulaTracking = ($chkRes && $chkRes->num_rows > 0);
$chk->close();

// 2) If applying to all same-day, will we touch any cedula records that day?
$hasSameDayCedula = false;
if ($apply_all && $date) {
    $chk2 = $mysqli->prepare("
        (SELECT 1 AS x FROM cedula WHERE res_id = ? AND appointment_date = ?)
        UNION ALL
        (SELECT 1 FROM urgent_cedula_request WHERE res_id = ? AND appointment_date = ?)
        LIMIT 1
    ");
    $chk2->bind_param("isis", $res_id, $date, $res_id, $date);
    $chk2->execute();
    $r2 = $chk2->get_result();
    $hasSameDayCedula = ($r2 && $r2->num_rows > 0);
    $chk2->close();
}

// Only require cedula number if we're releasing AND a cedula row is part of this update
$requiresCedulaNumber = ($new_status === 'Released') && ($isCedulaTracking || $hasSameDayCedula);

if ($requiresCedulaNumber) {
    $cedula_number = trim($cedula_number);

    if ($cedula_number === '') {
        echo json_encode(['success' => false, 'message' => 'Cedula number is required when status is Released.']);
        exit;
    }
    // Adjust pattern to your exact format if needed
    if (!preg_match('/^[A-Z0-9\-\/]{4,32}$/i', $cedula_number)) {
        echo json_encode(['success' => false, 'message' => 'Cedula number format is invalid.']);
        exit;
    }

    // No-duplicate guarantee across BOTH cedula tables
    $dupSql = "
        SELECT src FROM (
            SELECT 'cedula' AS src, tracking_number FROM cedula WHERE cedula_number = ? AND tracking_number <> ?
            UNION ALL
            SELECT 'urgent_cedula_request' AS src, tracking_number FROM urgent_cedula_request WHERE cedula_number = ? AND tracking_number <> ?
        ) t LIMIT 1
    ";
    $stmtDup = $mysqli->prepare($dupSql);
    $stmtDup->bind_param("ssss", $cedula_number, $tracking_number, $cedula_number, $tracking_number);
    $stmtDup->execute();
    $dupRes = $stmtDup->get_result();
    if ($dupRes && $dupRes->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Cedula number already exists. Please use a unique number.']);
        exit;
    }
}

// ============================= Guard for "ApprovedCaptain" =============================
// We need the Kagawad Name for updates later, so fetch it regardless of cedula/non-cedula for now
$assigned_kagawad_name = null;
if ($new_status === 'ApprovedCaptain') {
    $assigned_kagawad_name = $getValidKagawadName($assigned_kagawad_id);

    // If this tracking belongs to cedula/urgent_cedula, we don't NEED a Kagawad
    $isCedulaOrUrgentCed = false;
    $chk3 = $mysqli->prepare("
        (SELECT 1 FROM cedula WHERE tracking_number = ?)
        UNION ALL
        (SELECT 1 FROM urgent_cedula_request WHERE tracking_number = ?)
        LIMIT 1
    ");
    $chk3->bind_param("ss", $tracking_number, $tracking_number);
    $chk3->execute();
    $isCedulaOrUrgentCed = (bool)$chk3->get_result()->num_rows;
    $chk3->close();

    // Only enforce for schedules/urgent_request (single or apply_all)
    if (!$isCedulaOrUrgentCed) {
        if ($assigned_kagawad_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a Kagawad (ID) before approving by Captain.']);
            exit;
        }
        if ($assigned_kagawad_name === null) {
            echo json_encode(['success' => false, 'message' => 'Selected Kagawad is not valid/active. Refresh and try again.']);
            exit;
        }
    }
}


// ----- UPDATE LOGIC -----
$tablesApplyAll = [
    // table, statusCol, dateCol, filename (for trigger)
    ['schedules',             'status',        'selected_date',    3],
    ['cedula',                'cedula_status', 'appointment_date', 4],
    ['urgent_request',        'status',        'selected_date',    9],
    ['urgent_cedula_request', 'cedula_status', 'appointment_date', 10],
];

$tablesSingle = [
    // table, statusCol, filename (for trigger)
    ['schedules',             'status',        3],
    ['cedula',                'cedula_status', 4],
    ['urgent_request',        'status',        9],
    ['urgent_cedula_request', 'cedula_status', 10],
];

try {
    if ($apply_all) {
        $mysqli->begin_transaction();
        $logged = false;

        foreach ($tablesApplyAll as [$table, $statusCol, $dateCol, $filename]) {
            $skipBeso = $buildSkipBesoCondition($table, $role);

            if (in_array($table, ['cedula', 'urgent_cedula_request'], true) && $requiresCedulaNumber) {
                // (A) CEDULA tables with cedula number (for Released)
                $sql = "UPDATE $table 
                        SET $statusCol = ?, rejection_reason = ?, cedula_number = ?, notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE res_id = ? AND $dateCol = ? $skipBeso";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssiis", $new_status, $reason, $cedula_number, $employee_id, $res_id, $date);

            } elseif (in_array($table, ['schedules','urgent_request'], true) && $new_status === 'ApprovedCaptain') {
                // (B) SCHED/URGENT → set assignedKagName AND assigned_witness_name when moving to ApprovedCaptain
                $kagName = $assigned_kagawad_name ?? ''; // Use fetched name
                $sql = "UPDATE $table
                        SET $statusCol = ?, assignedKagName = ?, assigned_witness_name = ?, rejection_reason = NULL,
                            notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE res_id = ? AND $dateCol = ? $skipBeso";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssiis", $new_status, $kagName, $assigned_witness_name, $employee_id, $res_id, $date);

            } else {
                // (C) Generic (no cedula number; do NOT touch assignedKag/Witness fields)
                $sql = "UPDATE $table 
                        SET $statusCol = ?, rejection_reason = ?, notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE res_id = ? AND $dateCol = ? $skipBeso";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("ssiis", $new_status, $reason, $employee_id, $res_id, $date);
            }

            if (!$stmtUpdate->execute()) {
                throw new Exception($stmtUpdate->error, (int)$stmtUpdate->errno);
            }

            if ($stmtUpdate->affected_rows > 0 && !$logged) {
                // Log once per batch (include who)
                $trigger->isStatusUpdate($filename, $res_id, $new_status, $tracking_number . " | by employee_id {$employee_id}");
                $logged = true;
            }
            $stmtUpdate->close();
        }

        $mysqli->commit();
    } else { // Single update
        $mysqli->begin_transaction();
        $updated = false;

        foreach ($tablesSingle as [$table, $statusCol, $filename]) {

            if (in_array($table, ['cedula', 'urgent_cedula_request'], true) && $requiresCedulaNumber) {
                // (A) CEDULA tables with cedula number (for Released)
                $sql = "UPDATE $table
                        SET $statusCol = ?, rejection_reason = ?, cedula_number = ?, notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE tracking_number = ?";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssis", $new_status, $reason, $cedula_number, $employee_id, $tracking_number);

            } elseif (in_array($table, ['schedules','urgent_request'], true) && $new_status === 'ApprovedCaptain') {
                // (B) SCHED/URGENT single → set assignedKagName AND assigned_witness_name
                $kagName = $assigned_kagawad_name ?? '';
                $sql = "UPDATE $table
                        SET $statusCol = ?, assignedKagName = ?, assigned_witness_name = ?, rejection_reason = NULL,
                            notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE tracking_number = ?";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("sssis", $new_status, $kagName, $assigned_witness_name, $employee_id, $tracking_number);

            } else {
                // (C) Generic (no cedula number; do NOT touch assignedKag/Witness fields)
                $sql = "UPDATE $table
                        SET $statusCol = ?, rejection_reason = ?, notif_sent = 1, is_read = 0, employee_id = ?, 
                            update_time = CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
                        WHERE tracking_number = ?";
                $stmtUpdate = $mysqli->prepare($sql);
                $stmtUpdate->bind_param("ssis", $new_status, $reason, $employee_id, $tracking_number);
            }

            if (!$stmtUpdate->execute()) {
                throw new Exception($stmtUpdate->error, (int)$stmtUpdate->errno);
            }

            if ($stmtUpdate->affected_rows > 0) {
                $trigger->isStatusUpdate($filename, $res_id, $new_status, $tracking_number . " | by employee_id {$employee_id}");
                $updated = true;
                $stmtUpdate->close();
                break; // stop after first matching table
            }
            $stmtUpdate->close();
        }

        if (!$updated) {
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => 'No records updated.']);
            exit;
        }

        $mysqli->commit();
    }
} catch (Exception $ex) {
    $mysqli->rollback();
    if ((int)$ex->getCode() === 1062) {
        echo json_encode(['success' => false, 'message' => 'Cedula number already exists. Please use a unique number.']);
        exit;
    }
    error_log("Update failed: " . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update status. Error: ' . $ex->getMessage()]);
    exit;
}

// ----- EMAIL NOTIFICATION (UPDATED: Gmail SMTP) -----
$email_query = "
    SELECT r.email, r.contact_number, 
           CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name,
           CASE 
             WHEN uc.tracking_number IS NOT NULL THEN 'Urgent Cedula'
             WHEN c.tracking_number  IS NOT NULL THEN 'Cedula'
             WHEN ur.tracking_number IS NOT NULL THEN ur.certificate
             WHEN s.tracking_number  IS NOT NULL THEN s.certificate
             ELSE 'Appointment'
           END AS certificate
    FROM residents r
    LEFT JOIN cedula c                 ON r.id = c.res_id                 AND c.tracking_number  = ?
    LEFT JOIN urgent_cedula_request uc ON r.id = uc.res_id                AND uc.tracking_number = ?
    LEFT JOIN urgent_request ur        ON r.id = ur.res_id                AND ur.tracking_number = ?
    LEFT JOIN schedules s              ON r.id = s.res_id                 AND s.tracking_number  = ?
    WHERE r.id = ?
    LIMIT 1
";
$stmt_email = $mysqli->prepare($email_query);
$stmt_email->bind_param("ssssi", $tracking_number, $tracking_number, $tracking_number, $tracking_number, $res_id);
$stmt_email->execute();
$result_email = $stmt_email->get_result();

if ($result_email && $result_email->num_rows > 0) {
    $rowE          = $result_email->fetch_assoc();
    $email         = (string)$rowE['email'];
    $resident_name = (string)$rowE['full_name'];
    $certificate   = (string)$rowE['certificate'];

    if ($email !== '') {
        $mail = new PHPMailer(true);
        try {
            // ── GMAIL SMTP CONFIGURATION (From Reference) ──────────────────────────
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'jayacop9@gmail.com';
            $mail->Password   = 'fsls ywyv irfn ctyc'; // Your App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // SSL Bypass (Required for Localhost/XAMPP)
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];

            // Email Content
            $mail->setFrom('jayacop9@gmail.com', 'Barangay Bugo Admin');
            $mail->addAddress($email, $resident_name);
            $mail->addReplyTo('jayacop9@gmail.com', 'Barangay Bugo Admin');

            $mail->isHTML(true);
            $prettyDate  = $date ? date('F j, Y', strtotime($date)) : 'your date';
            $mail->Subject = 'Appointment Status Update';
            
            $mail->Body    = "
                <p>Dear <strong>{$resident_name}</strong>,</p>
                <p>Your <strong>{$certificate}</strong> appointment on <strong>{$prettyDate}</strong> has been updated to:</p>
                <h2 style='color:#0d6efd;'>{$new_status}</h2>"
                . ($reason !== '' ? "<p><em>Reason: ".htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')."</em></p>" : "")
                . "<br><p>Thank you,<br>Barangay Bugo Portal</p>";

            $mail->AltBody = "Dear {$resident_name},\n\nYour {$certificate} appointment on {$prettyDate} has been updated to \"{$new_status}\"."
                             . ($reason !== '' ? "\nReason: {$reason}" : "")
                             . "\n\nThank you.\nBarangay Bugo Portal";

            $mail->send();
        } catch (Exception $e) {
            // Log error but don't fail the AJAX response
            error_log("Email failed to send: " . $mail->ErrorInfo);
        }
    }
}
$stmt_email->close();

// ----- Success response -----
echo json_encode([
    'success' => true,
    'message' => 'Status updated & Email sent successfully.',
    'assignedKagawadId'   => ($new_status === 'ApprovedCaptain' && isset($assigned_kagawad_id) ? $assigned_kagawad_id : null),
    'assignedKagawadName' => ($new_status === 'ApprovedCaptain' && isset($assigned_kagawad_name) ? $assigned_kagawad_name : null),
    'assignedWitnessName' => ($new_status === 'ApprovedCaptain' ? $assigned_witness_name : null)
]);
exit;
?>