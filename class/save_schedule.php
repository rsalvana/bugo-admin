<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json'); // ✅ Required for clean JSON
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();

include_once '../logs/logs_trig.php';
$trigs = new Trigger();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['userId'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../security/403.html';
    exit;
}

/* ---------------- Helpers ---------------- */
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}
function columnExists(mysqli $db, string $table, string $column): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safeTable === '') return false;
    $safeCol = $db->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'";
    if (!$res = $db->query($sql)) return false;
    $ok = $res->num_rows > 0;
    $res->free();
    return $ok;
}

/**
 * Generate a two-digit running number for Barangay Clearance
 * by counting existing Barangay Clearance records across:
 * schedules, urgent_request, archived_schedules, archived_urgent_request.
 * Returns "01", "02", ...
 */
function generate_clearance_series_num(mysqli $db): string {
    $tables = [
        ['name' => 'schedules',               'where' => 'appointment_delete_status = 0'],
        ['name' => 'urgent_request',          'where' => 'urgent_delete_status = 0'],
        ['name' => 'archived_schedules',      'where' => '1'],
        ['name' => 'archived_urgent_request', 'where' => '1'],
    ];
    $total = 0;
    foreach ($tables as $t) {
        $sql = "SELECT COUNT(*) AS c FROM `{$t['name']}` WHERE certificate = 'Barangay Clearance' AND {$t['where']}";
        if ($res = $db->query($sql)) {
            $row = $res->fetch_assoc();
            $total += (int)($row['c'] ?? 0);
            $res->free();
        }
    }
    return str_pad((string)($total + 1), 2, '0', STR_PAD_LEFT); // "01", "02", ...
}

/* ---------------- Extract inputs ---------------- */
$user_id                = intval($data['userId']);
$certificate            = sanitize_input($data['certificate'] ?? '');
$isUrgent               = isset($data['urgent']) && $data['urgent'] === true;
$trackingNumber         = 'BUGO-' . date('YmdHis') . rand(1000, 9999);
$status                 = 'Pending';
$appointmentDeleteStatus = 0;
$isClearance            = (strcasecmp($certificate, 'Barangay Clearance') === 0); // ✅ case-insensitive
$seriesNumClearance     = $isClearance ? generate_clearance_series_num($mysqli) : null;

/* ---------------- Fetch resident info ---------------- */
$stmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, suffix_name FROM residents WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
    exit;
}
$resident   = $result->fetch_assoc();
$full_name  = trim("{$resident['first_name']} {$resident['middle_name']} {$resident['last_name']} {$resident['suffix_name']}");

$purpose           = sanitize_input($data['purpose'] ?? '');
$additionalDetails = sanitize_input($data['additionalDetails'] ?? '');
$selectedDate      = $isUrgent ? date('Y-m-d') : sanitize_input($data['selectedDate'] ?? '');
$selectedTime      = $isUrgent ? 'URGENT'      : sanitize_input($data['selectedTime'] ?? '');
$employeeId        = $_SESSION['employee_id'] ?? 0;

$stmt->close(); // close the resident-fetch stmt before reuse

/* ---------------- Payment helpers ---------------- */
$certLower        = strtolower($certificate);
$isMedicalAid     = strtolower($purpose) === 'medical assistance';
$isBesoCert       = $certLower === 'beso application';

// For ALL non-cedula certificates (urgent or regular): 0 if Medical Assistance or BESO Application, else 50.
$finalPayment = ($isMedicalAid || $isBesoCert) ? 0.00 : 50.00;

/* Cedula computed fee (only when requesting urgent cedula) */
$cedulaPayment = null;
if (strcasecmp($certificate, 'Cedula') === 0 && $isUrgent && isset($data['income'])) {
    $income = (float)$data['income'];               // monthly income
    $gross  = $income * 12;                         // annual
    $base   = floor($gross / 1000) + 5;             // base per rule
    $month  = (int)date('n', strtotime($selectedDate)); // month of appointment date (1..12)
    $rate   = 0.0;
    if ($month >= 2) {
        // Feb = 4%, Mar = 6%, ..., Nov = 22%, Dec = 24%
        $rate = (4 + 2 * ($month - 2)) / 100.0; // e.g., Nov (11): (4 + 2*9)/100 = 0.22
    }

    $interest      = $base * $rate;
    $computed      = $base + $interest;
    $cedulaPayment = max(50, (float)round($computed)); // min ₱50, rounded to nearest peso
}

/* ---------------- Branches ---------------- */

// ✨ CEDULA URGENT  (writes to urgent_cedula_request with total_payment)
// Also: soft-delete ONLY prior **Released** Cedula rows (active only) before insert.
if (strcasecmp($certificate, 'Cedula') === 0 && $isUrgent) {

    // 🔐 Soft-delete any "Released" Cedula in main table (not already deleted)
    if (columnExists($mysqli, 'cedula', 'res_id') && columnExists($mysqli, 'cedula', 'cedula_delete_status') && columnExists($mysqli, 'cedula', 'cedula_status')) {
        if ($upd = $mysqli->prepare("
            UPDATE cedula
               SET cedula_delete_status = 1
             WHERE res_id = ?
               AND cedula_status = 'Released'
               AND cedula_delete_status = 0
        ")) {
            $upd->bind_param('i', $user_id);
            $upd->execute();
            $upd->close();
        }
    }

    // 🔐 Soft-delete any "Released" Cedula in urgent_cedula_request (if you store uploaded/issued records there too)
    if (columnExists($mysqli, 'urgent_cedula_request', 'res_id')
        && columnExists($mysqli, 'urgent_cedula_request', 'cedula_delete_status')
        && columnExists($mysqli, 'urgent_cedula_request', 'cedula_status')) {
        if ($upd2 = $mysqli->prepare("
            UPDATE urgent_cedula_request
               SET cedula_delete_status = 1
             WHERE res_id = ?
               AND cedula_status = 'Released'
               AND cedula_delete_status = 0
        ")) {
            $upd2->bind_param('i', $user_id);
            $upd2->execute();
            $upd2->close();
        }
    }

    $income                 = (float)($data['income'] ?? 0);
    $cedulaDeleteStatus     = 0;
    $cedulaStatus           = 'Pending';
    $cedulaNumber           = '';
    $issued_at              = "Bugo, Cagayan de Oro City";
    $issued_on              = date("Y-m-d");
    $cedulaImg              = null;
    $cedula_expiration_date = date('Y') . '-12-31';

    $stmt = $mysqli->prepare("INSERT INTO urgent_cedula_request (
        res_id, employee_id, income, appointment_date, appointment_time,
        tracking_number, cedula_status, cedula_delete_status,
        cedula_number, issued_at, issued_on, cedula_img, cedula_expiration_date,
        total_payment
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // i i d s s s s i s s s s s d  → "iidssssisssssd"
    $stmt->bind_param(
        'iidssssisssssd',
        $user_id,
        $employeeId,
        $income,
        $selectedDate,
        $selectedTime,
        $trackingNumber,
        $cedulaStatus,
        $cedulaDeleteStatus,
        $cedulaNumber,
        $issued_at,
        $issued_on,
        $cedulaImg,
        $cedula_expiration_date,
        $cedulaPayment
    );
}

// ✨ BESO URGENT (insert into urgent_request with total_payment = 0)
elseif ($isUrgent && strcasecmp($certificate, 'BESO Application') === 0) {
    $selectedDateInt    = intval(date('Ymd'));
    $urgentDeleteStatus = 0;

    $stmt = $mysqli->prepare("INSERT INTO urgent_request (
        employee_id, res_id, certificate, purpose, selected_date, selected_time,
        tracking_number, status, urgent_delete_status, total_payment
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // i i s s i s s s i d  → "iississsid"
    $stmt->bind_param(
        'iississsid',
        $employeeId,
        $user_id,
        $certificate,
        $purpose,
        $selectedDateInt,
        $selectedTime,
        $trackingNumber,
        $status,
        $urgentDeleteStatus,
        $finalPayment // 0.00 for BESO
    );
}

// ✨ OTHER URGENT (includes Barangay Clearance) — write total_payment too
elseif ($isUrgent) {
    $selectedDateInt    = intval(date('Ymd'));
    $urgentDeleteStatus = 0;

    if ($isClearance) {
        // with seriesNum + total_payment
        $stmt = $mysqli->prepare("INSERT INTO urgent_request (
            employee_id, res_id, certificate, purpose, selected_date, selected_time,
            tracking_number, status, urgent_delete_status, seriesNum, total_payment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // i i s s i s s s i s d → "iississsisd"
        $stmt->bind_param(
            'iississsisd',
            $employeeId,
            $user_id,
            $certificate,
            $purpose,
            $selectedDateInt,
            $selectedTime,
            $trackingNumber,
            $status,
            $urgentDeleteStatus,
            $seriesNumClearance,
            $finalPayment // 50.00 unless Medical Assistance
        );
    } else {
        // non-clearance urgent + total_payment
        $stmt = $mysqli->prepare("INSERT INTO urgent_request (
            employee_id, res_id, certificate, purpose, selected_date, selected_time,
            tracking_number, status, urgent_delete_status, total_payment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // i i s s i s s s i d → "iississsid"
        $stmt->bind_param(
            'iississsid',
            $employeeId,
            $user_id,
            $certificate,
            $purpose,
            $selectedDateInt,
            $selectedTime,
            $trackingNumber,
            $status,
            $urgentDeleteStatus,
            $finalPayment
        );
    }
}

// ✨ REGULAR SCHEDULE  (already saving total_payment)
else {
    if ($isClearance) {
        // Include seriesNum + total_payment for Barangay Clearance
        $stmt = $mysqli->prepare("INSERT INTO schedules (
            fullname, purpose, additional_details, selected_date, selected_time, certificate,
            tracking_number, res_id, status, appointment_delete_status, total_payment, seriesNum
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // s s s s s s s i s i d s → "sssssssisids"
        $stmt->bind_param(
            'sssssssisids',
            $full_name,
            $purpose,
            $additionalDetails,
            $selectedDate,
            $selectedTime,
            $certificate,
            $trackingNumber,
            $user_id,
            $status,
            $appointmentDeleteStatus,
            $finalPayment,
            $seriesNumClearance
        );
    } else {
        $stmt = $mysqli->prepare("INSERT INTO schedules (
            fullname, purpose, additional_details, selected_date, selected_time, certificate,
            tracking_number, res_id, status, appointment_delete_status, total_payment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // s s s s s s s i s i d → "sssssssisid"
        $stmt->bind_param(
            'sssssssisid',
            $full_name,
            $purpose,
            $additionalDetails,
            $selectedDate,
            $selectedTime,
            $certificate,
            $trackingNumber,
            $user_id,
            $status,
            $appointmentDeleteStatus,
            $finalPayment
        );
    }
}

/* ---------------- Execute & After-effects ---------------- */
if ($stmt->execute()) {
    // ✅ Trigger audit log based on certificate type
    if (strcasecmp($certificate, 'Cedula') === 0 && $isUrgent) {
        $trigs->isUrgent(10, $user_id); // Cedula request
    } elseif (strcasecmp($certificate, 'BESO Application') === 0 && $isUrgent) {
        $trigs->isUrgent(9, $user_id);  // BESO urgent request
    } elseif ($isUrgent) {
        $trigs->isUrgent(9, $user_id);  // Other urgent requests
    } else {
        $trigs->isUrgent(9, $user_id);  // Regular schedule
    }

    // ✅ BESO used-for tracking logic (unchanged)
    if (strcasecmp($certificate, 'BESO Application') === 0) {
        $residencyTables = [
            'schedules'               => 'appointment_delete_status = 0 AND status = "Released"',
            'urgent_request'          => 'urgent_delete_status = 0 AND status = "Released"',
            'archived_schedules'      => '1',
            'archived_urgent_request' => '1'
        ];

        foreach ($residencyTables as $table => $whereClause) {
            $updateQuery = "
                UPDATE $table
                SET barangay_residency_used_for_beso = 1
                WHERE res_id = ?
                  AND certificate = 'Barangay Residency'
                  AND purpose = 'First Time Jobseeker'
                  AND barangay_residency_used_for_beso = 0
                  AND $whereClause
                ORDER BY created_at DESC
                LIMIT 1
            ";
            if ($updateResidency = $mysqli->prepare($updateQuery)) {
                $updateResidency->bind_param("i", $user_id);
                $updateResidency->execute();
                $updateResidency->close();
            }
        }
    }

    echo json_encode([
        'success'        => true,
        'trackingNumber' => $trackingNumber,
        'seriesNum'      => $seriesNumClearance,                    // may be null for non-clearance
        'cedulaPayment'  => $cedulaPayment,                         // set for urgent cedula
        'totalPayment'   => ($certLower !== 'cedula') ? $finalPayment : null // set for all non-cedula certs
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$mysqli->close();
