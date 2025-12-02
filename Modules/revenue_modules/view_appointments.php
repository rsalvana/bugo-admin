<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');

/* =========================
   CASE GUARDS (Respondent-only)
   ========================= */

/** Resolve res_id and current status by tracking (schedules/urgent_request) */
function bb_get_resident_id_by_tracking(mysqli $db, string $tracking): array {
    // schedules
    if ($stmt = $db->prepare("SELECT res_id, status FROM schedules WHERE tracking_number = ? LIMIT 1")) {
        $stmt->bind_param("s", $tracking);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($row = $r->fetch_assoc()) { $stmt->close(); return [(int)$row['res_id'], (string)$row['status'], 'schedules']; }
        $stmt->close();
    }
    // urgent_request
    if ($stmt = $db->prepare("SELECT res_id, status FROM urgent_request WHERE tracking_number = ? LIMIT 1")) {
        $stmt->bind_param("s", $tracking);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($row = $r->fetch_assoc()) { $stmt->close(); return [(int)$row['res_id'], (string)$row['status'], 'urgent_request']; }
        $stmt->close();
    }
    return [0, '', ''];
}

/** Get resident's canonical name (lowercased/trimmed here in PHP) */
function bb_get_resident_name(mysqli $db, int $resId): array {
    if ($resId <= 0) return ['','','',''];
    $stmt = $db->prepare("SELECT first_name, middle_name, last_name, suffix_name FROM residents WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $r = $stmt->get_result();
    $row = $r->fetch_assoc() ?: ['first_name'=>'','middle_name'=>'','last_name'=>'','suffix_name'=>''];
    $stmt->close();
    // normalize
    $n = fn($s) => mb_strtolower(trim((string)$s));
    return [$n($row['first_name'] ?? ''), $n($row['middle_name'] ?? ''), $n($row['last_name'] ?? ''), $n($row['suffix_name'] ?? '')];
}

/**
 * TRUE if there is at least one Ongoing case where the RESIDENT is the RESPONDENT.
 * Matches by name fields (case-insensitive, trims empties).
 * Table columns per your screenshot: Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name, action_taken
 */
function bb_cases_respondent_has_ongoing(mysqli $db, string $f, string $m, string $l, string $s): bool {
    // We compare normalized lower(trim()) on both sides
    $sql = "
        SELECT COUNT(*) AS cnt
          FROM cases
         WHERE LOWER(TRIM(COALESCE(Resp_First_Name,'')))  = LOWER(TRIM(COALESCE(?,'')))
           AND LOWER(TRIM(COALESCE(Resp_Middle_Name,''))) = LOWER(TRIM(COALESCE(?,'')))
           AND LOWER(TRIM(COALESCE(Resp_Last_Name,'')))   = LOWER(TRIM(COALESCE(?,'')))
           AND LOWER(TRIM(COALESCE(Resp_Suffix_Name,''))) = LOWER(TRIM(COALESCE(?,'')))
           AND LOWER(TRIM(COALESCE(action_taken,'')))     = 'ongoing'
        LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssss", $f, $m, $l, $s);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return ((int)$cnt) > 0;
}

/** One-call guard you can reuse in other endpoints too */
function bb_block_release_if_respondent_has_ongoing(mysqli $db, string $tracking, string $certificate, string $newStatus): void {
    $isClearance = (stripos($certificate, 'clearance') !== false);
    if (!$isClearance || strcasecmp($newStatus, 'Released') !== 0) return;

    // Get resident + current status
    [$resId, $currentStatus] = bb_get_resident_id_by_tracking($db, $tracking);
    if ($resId <= 0) {
        echo "<script>alert('❌ Cannot release: resident not found for this tracking number.'); history.back();</script>";
        exit;
    }

    // Enforce step-lock: must be Approved by Captain first
    if (strcasecmp($currentStatus, 'ApprovedCaptain') !== 0) {
        echo "<script>alert('❌ You can only release after “Approved by Captain”.'); history.back();</script>";
        exit;
    }

    // Name-match against RESPONDENT with action_taken = Ongoing
    [$f,$m,$l,$s] = bb_get_resident_name($db, $resId);
    if (bb_cases_respondent_has_ongoing($db, $f,$m,$l,$s)) {
        echo "<script>alert('❌ Cannot release: resident is a RESPONDENT in an Ongoing case.'); history.back();</script>";
        exit;
    }
}


$user_role   = $_SESSION['Role_Name'] ?? '';
$user_id     = $_SESSION['user_id'] ?? 0;        // used for duplicate checks
$employee_id = $_SESSION['employee_id'] ?? null; // used when updating status
$BASE = OFFICE_BASE_URL;                     // no trailing slash (from your constant)

// current employee (delegate)
$empId   = (int)($_SESSION['employee_id'] ?? 0);
$empName = trim(($_SESSION['employee_fullname'] ?? '')
            ?: (($_SESSION['employee_fname'] ?? '').' '.($_SESSION['employee_mname'] ?? '').' '.($_SESSION['employee_lname'] ?? '').' '.($_SESSION['employee_sname'] ?? '')));
$empRole = $_SESSION['Role_Name'] ?? 'Revenue Staff';

/* ---------------- Pagination + Filters (request) ---------------- */
$results_per_page = 100;
$page  = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? max(1, (int)$_GET['pagenum']) : 1;

$date_filter   = $_GET['date_filter']   ?? 'today'; // today|this_week|next_week|this_month|this_year
$status_filter = $_GET['status_filter'] ?? '';      // Pending|Approved|Rejected|Released|ApprovedCaptain
$search_term   = trim($_GET['search'] ?? '');       // name or tracking

/* ---------------- Delete = soft-archive by flag ---------------- */
if (isset($_POST['delete_appointment'], $_POST['tracking_number'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $certificate     = $_POST['certificate'];

    if (strtolower($certificate) === 'cedula') {
        $update_query = "UPDATE cedula SET cedula_delete_status = 1 WHERE tracking_number = ?";
    } else {
        $update_query = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";
    }

    $stmt_update = $mysqli->prepare($update_query);
    $stmt_update->bind_param("s", $tracking_number);
    $stmt_update->execute();
    $stmt_update->close();

    echo "<script>
        alert('Appointment archived.');
        window.location = '" . enc_page('view_appointments') . "';
    </script>";
    exit;
}

/* ---------------- Status update (with duplicate cedula check) ---------------- */
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number  = $_POST['tracking_number'];
    $new_status       = $_POST['new_status'];
    $certificate      = $_POST['certificate'];
    $cedula_number    = trim($_POST['cedula_number'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    // Is this an urgent cedula?
    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($isUrgentCedula);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();
    bb_block_release_if_respondent_has_ongoing($mysqli, $tracking_number, $certificate, $new_status);


    // Uniqueness check for cedula number when approving
    if (($isUrgentCedula > 0 || $certificate === 'Cedula') && $new_status === 'Approved' && !empty($cedula_number)) {
        $checkDup = $mysqli->prepare("
            SELECT COUNT(*) FROM (
                SELECT cedula_number FROM urgent_cedula_request WHERE cedula_number = ? AND res_id != ?
                UNION ALL
                SELECT cedula_number FROM cedula WHERE cedula_number = ? AND res_id != ?
            ) AS all_cedulas
        ");
        $checkDup->bind_param("sisi", $cedula_number, $user_id, $cedula_number, $user_id);
        $checkDup->execute();
        $checkDup->bind_result($dupCount);
        $checkDup->fetch();
        $checkDup->close();

        if ($dupCount > 0) {
            echo "<script>alert('❌ Cedula number already exists for another resident. Please enter a unique Cedula number.'); history.back();</script>";
            exit;
        }
    }

    // Update the correct source table
    if ($isUrgentCedula > 0) {
        if ($new_status === 'Rejected') {
            $query = "UPDATE urgent_cedula_request 
                      SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE urgent_cedula_request 
                      SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }
    } elseif ($certificate === 'Cedula') {
        if ($new_status === 'Rejected') {
            $query = "UPDATE cedula 
                      SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            $query = "UPDATE cedula 
                      SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                      WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
        }
    } else {
        // urgent non-cedula?
        $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgent->bind_param("s", $tracking_number);
        $checkUrgent->execute();
        $checkUrgent->bind_result($isUrgent);
        $checkUrgent->fetch();
        $checkUrgent->close();

        if ($isUrgent > 0) {
            if ($new_status === 'Rejected') {
                $query = "UPDATE urgent_request 
                          SET status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE urgent_request 
                          SET status = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }
        } else {
            if ($new_status === 'Rejected') {
                $query = "UPDATE schedules 
                          SET status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE schedules 
                          SET status = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                          WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("sis", $new_status, $employee_id, $tracking_number);
            }
        }
    }

    $stmt->execute();
    $stmt->close();

    /* --------- Fetch resident contact (source-aware) --------- */
    $isUrgentCedula = false;
    $isUrgentSchedule = false;

    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($urgentCedulaCount);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();
    if ($urgentCedulaCount > 0) { $isUrgentCedula = true; }

    if (!$isUrgentCedula) {
        $checkUrgentSchedule = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgentSchedule->bind_param("s", $tracking_number);
        $checkUrgentSchedule->execute();
        $checkUrgentSchedule->bind_result($urgentScheduleCount);
        $checkUrgentSchedule->fetch();
        $checkUrgentSchedule->close();
        if ($urgentScheduleCount > 0) { $isUrgentSchedule = true; }
    }

    if ($isUrgentCedula) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM urgent_cedula_request u JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } elseif ($certificate === 'Cedula') {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM cedula c JOIN residents r ON c.res_id = r.id
                        WHERE c.tracking_number = ?";
    } elseif ($isUrgentSchedule) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM urgent_request u JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } else {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
                        FROM schedules s JOIN residents r ON s.res_id = r.id
                        WHERE s.tracking_number = ?";
    }

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email && $result_email->num_rows > 0) {
        $rowe = $result_email->fetch_assoc();
        $email          = $rowe['email'];
        $resident_name  = $rowe['full_name'];
        $contact_number = $rowe['contact_number'];

        // Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'jayacop9@gmail.com';
            $mail->Password = 'nyiq ulrn sbhz chcd';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('jayacop9@gmail.com', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->Subject = 'Appointment Status Update';
            $mail->Body = "Dear $resident_name,\n\nYour appointment for \"$certificate\" has been updated to \"$new_status\".\n\nThank you.\nBarangay Office";
            $mail->send();
        } catch (Exception $e) {
            error_log("Email failed: " . $mail->ErrorInfo);
        }

        // SMS via Semaphore
        $apiKey = 'your_semaphore_api_key';
        $sender = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query([
            'apikey' => $apiKey,
            'number' => $contact_number,
            'message' => $sms_message,
            'sendername' => $sender
        ]);
        $sms_options = ['http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => $sms_data,
        ]];
        $sms_context = stream_context_create($sms_options);
        $sms_result  = @file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);

        if ($sms_result !== FALSE) {
            $sms_response = json_decode($sms_result, true);
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_query = "INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?, ?, ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            error_log("❌ SMS failed to send to $contact_number");
        }
    }

    echo "<script>
        alert('Status updated to $new_status');
        window.location = '" . enc_revenue('view_appointments') . "';
    </script>";
    exit;
}

/* ---------------- Auto-archive housekeeping (unchanged) ---------------- */
$mysqli->query("INSERT INTO archived_schedules SELECT * FROM schedules WHERE status='Released' AND selected_date<CURDATE()");
$mysqli->query("DELETE FROM schedules WHERE status='Released' AND selected_date<CURDATE()");

$mysqli->query("INSERT INTO archived_cedula SELECT * FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");
$mysqli->query("DELETE FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_cedula_request SELECT * FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");
$mysqli->query("DELETE FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on)<YEAR(CURDATE())");

$mysqli->query("INSERT INTO archived_urgent_request SELECT * FROM urgent_request WHERE status='Released' AND selected_date<CURDATE()");
$mysqli->query("DELETE FROM urgent_request WHERE status='Released' AND selected_date<CURDATE()");

$mysqli->query("UPDATE schedules SET appointment_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");

/* ---------------- UNION (ALL) + shared WHERE for list & count ---------------- */
$unionSql = "
  /* 1) Urgent Cedula */
  SELECT 
    1 AS src_priority,
    ucr.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    'Cedula' AS certificate,
    ucr.cedula_status AS status,
    ucr.appointment_time AS selected_time,
    ucr.appointment_date AS selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    'Cedula Application (Urgent)' AS purpose,
    ucr.issued_on, ucr.cedula_number, ucr.issued_at,
    ucr.income AS cedula_income,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name,'Barangay Staff') AS signatory_position,
    NULL AS assigned_kagawad_id,
    NULL AS assigned_kag_name
  FROM urgent_cedula_request ucr
  JOIN residents r ON ucr.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = ucr.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id     = el.Role_id
  WHERE ucr.cedula_delete_status = 0
    AND ucr.appointment_date >= CURDATE()

  UNION ALL

  /* 2) Regular Schedules */
  SELECT 
    2 AS src_priority,
    s.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    s.certificate, s.status, s.selected_time, s.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    s.purpose,
    c.issued_on, c.cedula_number, c.issued_at,
    c.income AS cedula_income,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name,'Barangay Staff') AS signatory_position,
    s.assignedKagId   AS assigned_kagawad_id,
    s.assignedKagName AS assigned_kag_name
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  LEFT JOIN cedula         c  ON c.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = s.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id     = el.Role_id
  WHERE s.appointment_delete_status = 0
    AND s.selected_date >= CURDATE()
    /* If you want to exclude BESO here instead of at render-time, keep this: */
    AND s.certificate != 'BESO Application'

  UNION ALL

  /* 3) Cedula (regular) */
  SELECT 
    3 AS src_priority,
    c.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    'Cedula' AS certificate,
    c.cedula_status AS status,
    c.appointment_time AS selected_time,
    c.appointment_date AS selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    'Cedula Application' AS purpose,
    c.issued_on, c.cedula_number, c.issued_at,
    c.income AS cedula_income,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name,'Barangay Staff') AS signatory_position,
    NULL AS assigned_kagawad_id,
    NULL AS assigned_kag_name
  FROM cedula c
  JOIN residents r ON c.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = c.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id     = el.Role_id
  WHERE c.cedula_delete_status = 0
    AND c.appointment_date >= CURDATE()

  UNION ALL

  /* 4) Urgent (non-cedula) */
  SELECT 
    4 AS src_priority,
    u.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    u.certificate, u.status, u.selected_time, u.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    u.purpose,
    COALESCE(c.issued_on, uc.issued_on) AS issued_on,
    COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
    COALESCE(c.issued_at, uc.issued_at) AS issued_at,
    COALESCE(c.income, uc.income) AS cedula_income,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name,'Barangay Staff') AS signatory_position,
    u.assignedKagId   AS assigned_kagawad_id,
    u.assignedKagName AS assigned_kag_name
  FROM urgent_request u
  JOIN residents r ON u.res_id = r.id
  LEFT JOIN cedula                c  ON c.res_id  = r.id AND c.cedula_status  = 'Approved'
  LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
  LEFT JOIN employee_list         el ON el.employee_id = u.employee_id
  LEFT JOIN employee_roles        er ON er.Role_Id     = el.Role_id
  WHERE u.urgent_delete_status = 0
    AND u.selected_date >= CURDATE()
    AND u.certificate != 'BESO Application'
";


/* Build WHERE for both count & list */
$whereParts = [];
$types = '';
$vals  = [];

switch ($date_filter) {
  case 'today':
    $whereParts[] = "selected_date = CURDATE()";
    break;
  case 'this_week':
    $whereParts[] = "YEARWEEK(selected_date,1) = YEARWEEK(CURDATE(),1)";
    break;
  case 'next_week':
    $whereParts[] = "YEARWEEK(selected_date,1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK),1)";
    break;
  case 'this_month':
    $whereParts[] = "YEAR(selected_date)=YEAR(CURDATE()) AND MONTH(selected_date)=MONTH(CURDATE())";
    break;
  case 'this_year':
    $whereParts[] = "YEAR(selected_date)=YEAR(CURDATE())";
    break;
}

if ($status_filter !== '') {
  $whereParts[] = "status = ?";
  $types .= 's';
  $vals[]  = $status_filter;
}

if ($search_term !== '') {
  $whereParts[] = "(tracking_number LIKE ? OR fullname LIKE ?)";
  $types .= 'ss';
  $like = "%$search_term%";
  $vals[] = $like;
  $vals[] = $like;
}
$whereParts[] = "status <> 'Rejected'";
$whereSql = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

/* ---------------- Count (dedup by tracking_number) ---------------- */
$countSql = "
  SELECT COUNT(*) AS total
  FROM (
    SELECT tracking_number
    FROM ( $unionSql ) base
    $whereSql
    GROUP BY tracking_number
  ) t
";
$stmt = $mysqli->prepare($countSql);
if ($types !== '') { $stmt->bind_param($types, ...$vals); }
$stmt->execute();
$total_results = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($page - 1) * $results_per_page;

/* ---------------- List page (same filters; dedup; order; limit) ---------------- */
$listSql = "
  SELECT *
  FROM (
    SELECT *
    FROM ( $unionSql ) base
    $whereSql
    GROUP BY tracking_number
  ) all_appointments
  ORDER BY
    (status='Pending' AND selected_time='URGENT' AND selected_date=CURDATE()) DESC,
    (status='Pending' AND selected_date=CURDATE()) DESC,
    selected_date ASC, selected_time ASC,
    FIELD(status,'Pending','Approved','Rejected')
  LIMIT ? OFFSET ?
";
$stmt = $mysqli->prepare($listSql);
$bindTypes = $types . 'ii';
$bindVals  = array_merge($vals, [ $results_per_page, $offset ]);
$stmt->bind_param($bindTypes, ...$bindVals);
$stmt->execute();
$result = $stmt->get_result();

$filtered_appointments = [];
while ($row = $result->fetch_assoc()) {
    $filtered_appointments[] = $row;
}
$stmt->close();

/* -------------- The rest of your officials/logos/info queries (unchanged) -------------- */
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name, b.status
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status = 'active' 
          AND b.position NOT LIKE '%Lupon%'
          AND b.position NOT LIKE '%Barangay Tanod%'
          AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position,'Punong Barangay','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad',
                       'Kagawad','SK Chairman','Secretary','Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult && $offresult->num_rows > 0) {
    while ($row = $offresult->fetch_assoc()) {
        $officials[] = [
            'position' => $row['position'],
            'name'     => $row['first_name'].' '.$row['middle_name'].' '.$row['last_name']
        ];
    }
}

$kagawads = [];
$kagawadSql = "
  SELECT bi.official_id AS res_id,
         CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
         bi.position
  FROM barangay_information bi
  JOIN residents r ON r.id = bi.official_id
  WHERE bi.status='active'
    AND (bi.position LIKE '%Kagawad%')
  ORDER BY
    FIELD(bi.position,'1st Kagawad','2nd Kagawad','3rd Kagawad',
                    '4th Kagawad','5th Kagawad','6th Kagawad','7th Kagawad'),
    bi.position ASC, r.last_name ASC
";
if ($kr = $mysqli->query($kagawadSql)) {
  while ($row = $kr->fetch_assoc()) $kagawads[] = $row;
  $kr->close();
}

$logo_sql   = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status='active' LIMIT 1";
$logo       = ($lr = $mysqli->query($logo_sql)) && $lr->num_rows > 0 ? $lr->fetch_assoc() : null;

$citySql    = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status='active' LIMIT 1";
$cityLogo   = ($cr = $mysqli->query($citySql)) && $cr->num_rows > 0 ? $cr->fetch_assoc() : null;

$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);
if ($barangayInfoResult && $barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) {
        $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName);
    } else { $cityMunicipalityName = strtoupper($cityMunicipalityName); }

    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']));
    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = strtoupper($barangayName);
    } elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "POBLACION " . strtoupper($barangayName);
    } elseif (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = strtoupper($barangayName);
    } else {
        $barangayName = "BARANGAY " . strtoupper($barangayName);
    }
} else {
    $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND";
    $barangayName = "NO BARANGAY FOUND";
}

$councilTermSql = "SELECT council_term FROM barangay_info WHERE id = 1";
$councilTermResult = $mysqli->query($councilTermSql);
$councilTerm = ($councilTermResult && $councilTermResult->num_rows > 0)
    ? ($councilTermResult->fetch_assoc()['council_term'] ?? '#') : '#';

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
              FROM barangay_information b
              INNER JOIN residents r ON b.official_id = r.id
              WHERE b.status='active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);
$lupon_official = null; $barangay_tanod_official = null;
if ($lupon_result && $lupon_result->num_rows > 0) {
    while ($lr = $lupon_result->fetch_assoc()) {
        if (stripos($lr['position'], 'Lupon') !== false) $lupon_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        if (stripos($lr['position'], 'Barangay Tanod') !== false || stripos($lr['position'], 'Barangay Police') !== false)
            $barangay_tanod_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
    }
}

$barangayContactSql = "SELECT telephone_number, mobile_number FROM barangay_info WHERE id = 1";
$barangayContactResult = $mysqli->query($barangayContactSql);
if ($barangayContactResult && $barangayContactResult->num_rows > 0) {
    $contactInfo     = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber    = $contactInfo['mobile_number'];
} else {
    $telephoneNumber = "No telephone number found";
    $mobileNumber    = "No mobile number found";
}

/* -------- $filtered_appointments now holds the rows to render.
           $total_pages is consistent; hide pagination if $total_pages == 1 -------- */

?>
<?php
// Captain display name (from $officials you already built)
$punong_barangay = null;
foreach ($officials as $o) {
    if (strcasecmp($o['position'], 'Punong Barangay') === 0) {
        $punong_barangay = trim($o['name']);
        break;
    }
}

$captainEmployeeId = 0;

// Single query: prefer employee_roles.Employee_Id, else fallback via Role_Id→employee_list
if ($stmtCap = $mysqli->prepare("
    SELECT
      COALESCE(NULLIF(er.Employee_Id, 0), e.employee_id) AS captain_id
    FROM employee_roles er
    LEFT JOIN employee_list e ON e.Role_id = er.Role_Id
    WHERE LOWER(er.Role_Name) = LOWER('Punong Barangay')
    LIMIT 1
")) {
    $stmtCap->execute();
    $stmtCap->bind_result($capId);
    if ($stmtCap->fetch()) $captainEmployeeId = (int)$capId;
    $stmtCap->close();
}
?>


    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Appointment List</title>
        
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="css/styles.css">
        <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
                        <!-- SweetAlert2 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <div class="container my-4 app-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
      <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
    </div>

    <!-- Filters -->
    <div class="card card-filter mb-3 shadow-sm">
      <div class="card-body py-3">
        <form method="GET" action="index_revenue_staff.php" class="row g-2 align-items-end">
          <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'view_appointments' ?>" />

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Date</label>
            <select name="date_filter" class="form-select form-select-sm">
              <option value="today" <?= ($_GET['date_filter'] ?? '') == 'today' ? 'selected' : '' ?>>Today</option>
              <option value="this_week" <?= ($_GET['date_filter'] ?? '') == 'this_week' ? 'selected' : '' ?>>This Week</option>
              <option value="next_week" <?= ($_GET['date_filter'] ?? '') == 'next_week' ? 'selected' : '' ?>>Next Week</option>
              <option value="this_month" <?= ($_GET['date_filter'] ?? '') == 'this_month' ? 'selected' : '' ?>>This Month</option>
              <option value="this_year" <?= ($_GET['date_filter'] ?? '') == 'this_year' ? 'selected' : '' ?>>This Year</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Status</label>
            <select name="status_filter" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="Pending" <?= ($_GET['status_filter'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
              <option value="Approved" <?= ($_GET['status_filter'] ?? '') == 'Approved' ? 'selected' : '' ?>>Approved</option>
              <option value="Rejected" <?= ($_GET['status_filter'] ?? '') == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
              <option value="Released" <?= ($_GET['status_filter'] ?? '') == 'Released' ? 'selected' : '' ?>>Released</option>
              <option value="ApprovedCaptain" <?= ($_GET['status_filter'] ?? '') == 'ApprovedCaptain' ? 'selected' : '' ?>>Approved by Captain</option>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label mb-1 fw-semibold">Search</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" id="searchInput" class="form-control" placeholder="Search name or tracking number..." />
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </div>
        </form>
      </div>
    </div>

<div class="card shadow-sm">
  <div class="card-body table-shell">
    <div class="table-edge">               <!-- keeps rounded corners -->
      <div class="table-scroll">           <!-- becomes the scroller -->
        <table class="table table-hover align-middle mb-0" id="appointmentsTable">
          <thead class="table-head sticky-top">
            <tr>
              <th style="width: 200px;">Full Name</th>
              <th style="width: 100px;">Certificate</th>
              <th style="width: 200px;">Tracking Number</th>
              <th style="width: 200px;">Date</th>
              <th style="width: 200px;">Time Slot</th>
              <th style="width: 200px;">Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody id="appointmentTableBody">
<?php
if (!empty($filtered_appointments)):
    foreach ($filtered_appointments as $row):

        // ❌  Skip BESO if the user is Revenue Staff
        if (stripos($user_role, 'revenue') !== false &&
            $row['certificate'] === 'BESO Application') {
            continue;
        }

        // Re‑use your existing row template
        include 'components/appointment_row.php';

    endforeach;
else: ?>
    <tr>
        <td colspan="7" class="text-center">No appointments found</td>
    </tr>
<?php endif; ?>
</tbody>
        </table>
      </div>
    </div>
  </div>
</div>
 <!-- Windowed Pagination -->
  <?php
    // Build preserved query string excluding pagenum
    $pageBase = enc_revenue('view_appointments');
    $params = $_GET; unset($params['pagenum']);
    $qs = '';
    if (!empty($params)) {
      $pairs = [];
      foreach ($params as $k => $v) {
        if (is_array($v)) { foreach ($v as $vv) $pairs[] = urlencode($k).'='.urlencode($vv); }
        else { $pairs[] = urlencode($k).'='.urlencode($v ?? ''); }
      }
      $qs = '&'.implode('&', $pairs);
    }

    $window = 7;
    $half   = (int)floor($window/2);
    $start  = max(1, $page - $half);
    $end    = min($total_pages, $start + $window - 1);
    if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
  ?>

  <nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-end pagination-soft mb-0">

      <!-- First -->
      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>" aria-label="First">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Previous -->
      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>" aria-label="Previous">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Left ellipsis -->
      <?php if ($start > 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <!-- Windowed numbers -->
      <?php for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
        </li>
      <?php endfor; ?>

      <!-- Right ellipsis -->
      <?php if ($end < $total_pages): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <!-- Next -->
      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>" aria-label="Next">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </a>
        </li>
      <?php endif; ?>

      <!-- Last -->
      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>" aria-label="Last">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </a>
        </li>
      <?php endif; ?>

    </ul>
  </nav>
 <!-- View Appointment Modal (enhanced) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content modal-elev rounded-4">
      <div class="modal-header modal-accent rounded-top-4">
        <div>
          <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="viewModalLabel">
            <i class="bi bi-calendar-check-fill"></i>
            Appointment Details
          </h5>
          <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0">
        <div class="p-4 content-grid">
          <!-- Case history -->
          <section class="card soft-card">
            <div class="card-header soft-card-header">
              <span class="section-title"><i class="bi bi-journal-check"></i> Case History</span>
            </div>
            <div class="card-body p-0">
              <div id="caseHistoryContainer" class="timeline-wrap">
                <p class="text-muted px-3 py-2 mb-0">No case history loaded...</p>
              </div>
            </div>
          </section>

          <!-- Same day -->
          <section class="card soft-card grid-col-2">
            <div class="card-header soft-card-header">
              <span class="section-title"><i class="bi bi-calendar-week"></i> Appointments on This Day</span>
            </div>
            <div class="card-body p-0">
              <ul id="sameDayAppointments" class="list-group list-group-flush compact-list">
                <li class="list-group-item">Loading...</li>
              </ul>
            </div>
          </section>

          <!-- Update form -->
          <section class="card soft-card grid-col-2">
            <div class="card-header soft-card-header d-flex justify-content-between align-items-center">
              <span class="section-title"><i class="bi bi-arrow-repeat"></i> Update Status</span>
              <small class="text-muted">Changes notify via Email/System Notification</small>
            </div>
            <div class="card-body">
              <form id="statusUpdateForm" data-current-status="">
                <input type="hidden" id="statusTrackingNumber" name="tracking_number">

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label">New Status</label>
                    <select name="new_status" id="statusSelect" class="form-select">
                      <option value="Pending">Pending</option>
                      <option value="Approved">Approved</option>
                      <option value="Rejected">Rejected</option>
                      <option value="Released">Released</option>
                      <option value="ApprovedCaptain">Approved by Captain</option>
                    </select>
                  </div>

                  <!-- NEW: Assign Kagawad (hidden until ApprovedCaptain is selected) -->
                  <div class="col-12 col-md-6 d-none" id="assignKagawadGroup">
                    <label class="form-label">Assign Kagawad (required for Approved by Captain)</label>
                    <select class="form-select" name="assigned_kagawad_id" id="assignKagawadSelect">
                      <option value="">— Select Kagawad —</option>
                      <?php foreach ($kagawads as $k): ?>
                        <option value="<?= (int)$k['res_id'] ?>">
                          <?= htmlspecialchars($k['position'].' — '.$k['full_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-12 col-md-6 d-none" id="viewCedulaNumberContainer">
                    <label for="viewCedulaNumber" class="form-label">Cedula Number</label>
                    <input type="text" name="cedula_number" id="viewCedulaNumber" class="form-control" placeholder="Enter Cedula Number">
                  </div>

                  <div class="col-12 d-none" id="viewRejectionReasonGroup">
                    <label class="form-label">Reason for Rejection</label>
                    <textarea name="rejection_reason" id="viewRejectionReason" class="form-control" rows="2" placeholder="Type reason..."></textarea>
                  </div>

                  <div class="col-12">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" value="1" id="applyAllSameDay" name="apply_all_same_day">
                      <label class="form-check-label" for="applyAllSameDay">
                        Apply status to all appointments of this resident on the same day
                      </label>
                    </div>
                  </div>
                </div>

                <div class="sticky-action mt-3">
                  <button type="submit" class="btn btn-success w-100" id="saveStatusBtn">
                    <i class="bi bi-check2-circle me-1"></i> Save Status
                  </button>
                </div>
              </form>
            </div>
          </section>
        </div>
      </div>

      <div class="modal-footer bg-transparent d-flex justify-content-between">
        <small class="text-muted">Tip: You can only release after “Approved by Captain”.</small>
        <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>
  <!-- Status Change Modal -->
  <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <form method="POST" action="">
        <div class="modal-content rounded-4 shadow">
          <div class="modal-header text-white rounded-top-4">
            <h5 class="modal-title" id="statusModalLabel">🛠️ Change Status</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body bg-light">
            <input type="hidden" name="tracking_number" id="modalTrackingNumber">
            <input type="hidden" name="certificate" id="modalCertificate">

            <div class="mb-3">
              <label for="newStatus" class="form-label fw-semibold">New Status</label>
              <select name="new_status" id="newStatus" class="form-select rounded-3 shadow-sm" data-current-status="">
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="Released">Released</option>
                <!--<option value="ApprovedCaptain">ApprovedCaptain</option>-->
              </select>
            </div>

        <div class="mb-3" id="statusModalCedulaNumberContainer" style="display:none;">
          <label for="statusModalCedulaNumber" class="form-label fw-semibold">Cedula Number</label>
          <input type="text" name="cedula_number" id="statusModalCedulaNumber" class="form-control shadow-sm rounded-3" placeholder="Enter Cedula Number">
        </div>
        
        <div class="mb-3" id="statusModalRejectionReasonContainer" style="display:none;">
          <label for="statusModalRejectionReason" class="form-label fw-semibold">Rejection Reason</label>
          <textarea class="form-control shadow-sm rounded-3" name="rejection_reason" id="statusModalRejectionReason" rows="2" placeholder="State reason for rejection..."></textarea>
        </div>
          </div>
          <div class="modal-footer bg-light rounded-bottom-4">
            <button type="submit" name="update_status" class="btn btn-success w-100 rounded-pill shadow-sm">
              Update
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
<script>
  const CAPTAIN_EMPLOYEE_ID = <?= (int)$captainEmployeeId ?>;
</script>
        <script src="util/debounce.js"></script>


        <script>
/* ---------- helpers ---------- */
function escapeHtml(s=''){
  return String(s).replace(/[&<>"']/g, m => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
  ));
}

/* ----------
   renderSignatorySection
   Shows PB name/title always.
   If the signatory IS the PB -> show PB e-signature overlayed on PB name.
   If delegated -> show “By the authority…” block with the delegate’s name/title
   and overlay the delegate signature on their name.
---------- */
function renderSignatorySection(isCaptain /* ignored */, assignedKagName){
  const pbNm = `<?php echo htmlspecialchars($punong_barangay ?? ''); ?>`;
  const kag  = (assignedKagName || '').trim();

  return `
    <section
  class="signatory-wrap"
  style="
    display:flex;
    flex-direction:column;
    align-items:center;
    width:48%;
    text-align:center;
    position:relative;
    /* ↓ pull the whole block up and shrink reserved space */
    margin-top:0;
    min-height:1.15in;
  "
>

      <!-- PB always -->
      <h5 class="pb-name"><u><strong>${pbNm}</strong></u></h5>
      <p class="auth-title">PUNONG BARANGAY</p>

      <!-- Always show "By the authority…" block -->
      <div class="authority-note"><strong>By the authority of the Punong Barangay</strong></div>
      <div class="auth">
        <h6 class="auth-name"><u><strong>${escapeHtml(kag || 'Authorized Kagawad')}</strong></u></h6>
        <p class="auth-title">BRGY.KAGAWAD</p>
      </div>
    </section>
  `;
}


/* ----------
   printAppointment (excerpt)
   This shows the complete "Barangay Indigency With Picture" case updated
   with the overlay signature styles. Reuse the same styles/renderer for
   your other certificate blocks.
---------- */
function printAppointment(
  certificate, fullname, res_zone, birth_date = "", birth_place = "", res_street_address = "",
  purpose = "", issued_on ="", issued_at = "", cedula_number = "", civil_status = "",
  residency_start = "", age= "", residentId = "",  assignedKagName = "",   
  signatoryEmployeeId = 0, seriesNum = "",
) {
  let printAreaContent = "";

  const today  = new Date();
  const day    = today.getDate();
  const month  = today.toLocaleString('default', { month: 'long' });
  const year   = today.getFullYear();
  const residentPhotoUrl = residentId
    ? `components/employee_modal/show_profile_picture.php?res_id=${encodeURIComponent(residentId)}&t=${Date.now()}`
    : "";

  const dayWithSuffix = (d=>{
    if (d===1||d===21||d===31) return `${d}ˢᵗ`;
    if (d===2||d===22)        return `${d}ⁿᵈ`;
    if (d===3||d===23)        return `${d}ʳᵈ`;
    return `${d}ᵗʰ`;
  })(day);

  // If you echoed CAPTAIN_EMPLOYEE_ID from PHP earlier, it's available here.
  const isCaptainSignatory = Number(signatoryEmployeeId) === Number((typeof CAPTAIN_EMPLOYEE_ID!=='undefined'?CAPTAIN_EMPLOYEE_ID:0));

  /* ======================= BARANGAY INDIGENCY WITH PICTURE ======================= */
  if (certificate === "Barangay Indigency With Picture") {
    printAreaContent = `
<html>
  <head>
    <link rel="stylesheet" href="css/form.css">
    <link rel="stylesheet" href="css/print/print.css">
  </head>
  <body>
    <div class="container" id="printArea">
      <header>
        <div class="logo-header">
          <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
          <?php else: ?>
            <p>No active Barangay logo found.</p>
          <?php endif; ?>

          <div class="header-text">
            <h2><strong>Republic of the Philippines</strong></h2>
            <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
            <h3><strong><?php echo $barangayName; ?></strong></h3>
            <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
            <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
          </div>

          <!-- Resident photo -->
          <img src="${residentPhotoUrl}" alt="Resident Photo" class="photo-2x2" onerror="this.style.display='none'"/>
        </div>
      </header>

      <hr class="header-line">

      <section class="barangay-certification">
        <h4 style="text-align:center;font-size:50px;"><strong>CERTIFICATION</strong></h4>
        <br>
        <p>TO WHOM IT MAY CONCERN:</p>
        <br>
        <p>THIS IS TO CERTIFY that <strong>${escapeHtml(fullname)}</strong>, a resident of 
          <strong>${escapeHtml(res_zone)}</strong>, <strong>${escapeHtml(res_street_address)}</strong>, Bugo, Cagayan de Oro City.</p>
        <br>
        <p>This Certification is issued upon the request of the above-mentioned person 
          for <strong>${escapeHtml(purpose)}</strong> only.</p>
        <br>
        <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
      </section>

      <br><br><br><br><br>

      <div class="two-col" style="margin-bottom:18px;">
        <!-- Left column: cedula info -->
        <section class="col-48" style="line-height:1.8;">
          <p><strong>Community Tax No.:</strong> ${escapeHtml(cedula_number)}</p>
          <p><strong>Issued on:</strong> ${issued_on ? new Date(issued_on).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : ''}</p>
          <p><strong>Issued at:</strong> ${escapeHtml(issued_at)}</p>
        </section>

        <!-- Right column: dynamic signatory with overlay signature -->
        ${renderSignatorySection(isCaptainSignatory, assignedKagName)}
      </div>
    </div>
  </body>
</html>
    `;
  } 

else if (certificate === "Barangay Residency With Picture") {
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
      const residentPhotoUrl = residentId
    ? `components/employee_modal/show_profile_picture.php?res_id=${encodeURIComponent(residentId)}&t=${Date.now()}`
    : "";

    printAreaContent = `
<html>
  <head>
    <link rel="stylesheet" href="css/form.css">
    <link rel="stylesheet" href="css/print/print.css">
    <style>
      /* square 2x2in resident photo for print */
      .photo-2x2{
        width: 2in; height: 2in;           /* exact 2x2 inches */
        object-fit: cover;                  /* fill without distortion */
        border-radius: 0;                   /* NOT circular */
        border: 1px solid #000;             /* optional passport-style border */
        display: block;
      }
    </style>
  </head>
  <body>
    <div class="container" id="printArea">
      <header>
        <div class="logo-header">
          <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
          <?php else: ?>
            <p>No active Barangay logo found.</p>
          <?php endif; ?>

          <div class="header-text">
            <h2><strong>Republic of the Philippines</strong></h2>
            <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
            <h3><strong><?php echo $barangayName; ?></strong></h3>
            <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
            <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
          </div>

          <!-- Resident photo (square 2x2) -->
          <img src="${residentPhotoUrl}" alt="Resident Photo" class="photo-2x2" onerror="this.style.display='none'"/>
        </div>
      </header>
                    <hr class="header-line">

                    <section class="barangay-certification">
                        <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                        <p>TO WHOM IT MAY CONCERN:</p><br>
                        <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                        <strong>${res_zone}</strong>, <strong>${res_street_address}</strong> Bugo, Cagayan de Oro City. He/She was born on <strong>${formattedBirthDate}</strong> at <strong>${birth_place}</strong>. 
                        Stayed in Bugo, CDOC since <strong>${formattedResidencyStart}</strong> and up to present.</p>
                        <br>
                        <p>This Certification is issued upon the request of the above-mentioned person 
                            for <strong>${purpose}</strong> only.</p>
                        <br>
                        <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                    </section>

                    <br><br><br><br><br>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                        <section style="width: 48%; line-height: 1.8;">
                            <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                            <p><strong>Issued on:</strong> ${formattedIssuedOn}</p>
                            <p><strong>Issued at:</strong> ${issued_at}</p>
                        </section>
        ${renderSignatorySection(isCaptainSignatory, assignedKagName)}
                    </div>
                </div>
            </body>
        </html>
    `;
}
else if (certificate === "Barangay Indigency") {
            printAreaContent = `
                <html>
                    <head>
                        <link rel="stylesheet" href="css/form.css">
                        <link rel="stylesheet" href="css/print/print.css">
                    </head>
                    <body>
                        <div class="container" id="printArea">
                            <header>
                    <div class="logo-header"> <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
        <?php else: ?>
            <p>No active Barangay logo found.</p>
        <?php endif; ?>
                                    <div class="header-text">
                                        <h2><strong>Republic of the Philippines</strong></h2>
                                        <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                                        <h3><strong><?php echo $barangayName; ?></strong></h3>
                                        <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                        <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                                    </div>
                        <?php if ($cityLogo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
        <?php else: ?>
            <p>No active City/Municipality logo found.</p>
        <?php endif; ?>
                    </div>
                            </header>
                            <hr class="header-line">
                            <section class="barangay-certification">
                                <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                                <br>
                                <p>TO WHOM IT MAY CONCERN:</p>
                                <br>
                                <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, a resident of 
                                <strong>${res_zone}</strong>,  <strong>${res_street_address}</strong>,Bugo, Cagayan de Oro City.</p>
                                <br>
                                <p>This Certification is issued upon the request of the above-mentioned person 
                                    for <strong>${purpose}</strong> only.</p>
                                <br>
                            <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                            </section>
                            <br>
                            <br>
                            <br>
                            <br>
                            <br>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                    <section style="width: 48%; line-height: 1.8;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
        ${renderSignatorySection(isCaptainSignatory, assignedKagName)}

                            </div>
                        </div>
                    </body>
                </html>
            `;
        } else if (certificate === "Barangay Residency") {
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';

    printAreaContent = `
        <html>
            <head>
                <link rel="stylesheet" href="css/form.css">
                <link rel="stylesheet" href="css/print/print.css">
            </head>
            <body>
                <div class="container" id="printArea">
                    <header>
                        <div class="logo-header">
                            <?php if ($logo): ?>
                                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
                            <?php else: ?>
                                <p>No active Barangay logo found.</p>
                            <?php endif; ?>

                            <div class="header-text">
                                <h2><strong>Republic of the Philippines</strong></h2>
                                <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                                <h3><strong><?php echo $barangayName; ?></strong></h3>
                                <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                            </div>

                            <?php if ($cityLogo): ?>
                                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                            <?php else: ?>
                                <p>No active City/Municipality logo found.</p>
                            <?php endif; ?>
                        </div>
                    </header>
                    <hr class="header-line">

                    <section class="barangay-certification">
                        <h4 style="text-align: center; font-size: 50px;"><strong>CERTIFICATION</strong></h4>
                        <p>TO WHOM IT MAY CONCERN:</p><br>
                        <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                        <strong>${res_zone}</strong>, <strong>${res_street_address}</strong> Bugo, Cagayan de Oro City. He/She was born on <strong>${formattedBirthDate}</strong> at <strong>${birth_place}</strong>. 
                        Stayed in Bugo, CDOC since <strong>${formattedResidencyStart}</strong> and up to present.</p>
                        <br>
                        <p>This Certification is issued upon the request of the above-mentioned person 
                            for <strong>${purpose}</strong> only.</p>
                        <br>
                        <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                    </section>

                    <br><br><br><br><br>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                        <section style="width: 48%; line-height: 1.8;">
                            <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                            <p><strong>Issued on:</strong> ${formattedIssuedOn}</p>
                            <p><strong>Issued at:</strong> ${issued_at}</p>
                        </section>
${renderSignatorySection(isCaptainSignatory, assignedKagName)}
                    </div>
                </div>
            </body>
        </html>
    `;
}else if (certificate === "Residency") {
            printAreaContent = `
                <html>
                    <head>
                        <link rel="stylesheet" href="css/form.css">
                    </head>
                    <body>
                    <div class="container" id="printArea">
                <header>
                    <div class="logo-header"> <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
        <?php else: ?>
            <p>No active Barangay logo found.</p>
        <?php endif; ?>
                                    <div class="header-text">
                                        <h2><strong>Republic of the Philippines</strong></h2>
                                        <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                                        <h3><strong><?php echo $barangayName; ?></strong></h3>
                                        <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                        <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                                    </div>
                        <?php if ($cityLogo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
        <?php else: ?>
            <p>No active City/Municipality logo found.</p>
        <?php endif; ?>
                    </div>
                                <hr class="header-line">
                            </header>
                            <section class="barangay-certification">
                                <h4 style="text-align: center;font-size: 50px;"><strong>CERTIFICATION</strong></h4><br>
                                <p>TO WHOM IT MAY CONCERN:</p>
                                <br>
                                <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, is a resident of 
                                <strong>${res_zone}</strong>,<strong>${res_street_address}</strong>, Bugo, Cagayan de Oro City.</p>
                                <br>
                                <p>This certify further that according to and as reported by ___________________________________ 
                                    he/she has been at the said area since <strong>${new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</strong> up to present.</p>
                                <br>
                                <p>This certification is issued upon the request of the above-mentioned person for 
                                    <strong>${purpose}</strong>.</p>
                                <br>
                            <p>Issued this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                            </section>
                            <br>
                            <br>
                            <br>
                            <br>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                    <section style="width: 48%; line-height: 1.8;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
                    <section style="width: -155%; text-align: center; font-size: 25px;">
                        <?php
                            // Find the Punong Barangay from the $officials array
                            $punong_barangay = null;
                            foreach ($officials as $official) {
                                if ($official['position'] == 'Punong Barangay') {
                                    $punong_barangay = $official['name'];
                                    break;
                                }
                            }
                        ?>
                        <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                        <p>Punong Barangay</p>
                        <!-- e-Signature Image -->
                        <img src="components/employee_modal/show_esignature.php?t=<?=time()?>"  alt="Punong Barangay e-Signature" style="width: 150px; height: auto; margin-top: 10px;">
                    </section>
                            </div>
                        </div>
                    </body>
                </html>
            `;
        }  else if (certificate === "Barangay Clearance") {
        printAreaContent = `
        <html>
        <head>
            <link rel="stylesheet" href="css/clearance.css">
            <link rel="stylesheet" href="css/print/clearance.css">
        </head>
        <body>
            <div class="container" id="printArea">
            <br>
            <br>
                <header>
                    <div class="logo-header"> <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
        <?php else: ?>
            <p>No active Barangay logo found.</p>
        <?php endif; ?>
                        <div class="header-text" style="text-align: center;">
                            <h2><strong>Republic of the Philippines</strong></h2>
                            <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                            <h3><strong><?php echo $barangayName; ?></strong></h3>
                            <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                            <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                        </div>
                        <?php if ($cityLogo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
        <?php else: ?>
            <p>No active City/Municipality logo found.</p>
        <?php endif; ?>
                    </div>

                    <section style="text-align: center; margin-top: 10px;">
                        <hr class="header-line" style="border: 1px solid black; margin-top: 10px;">
                        <h2 style="font-size: 30px;"><strong>BARANGAY CLEARANCE</strong></h2>
                        <br>
                    </section>
                <section style="display: flex; justify-content: space-between; margin-top: 10px;">
                    <div style="flex: 1;"></div>
                    <div style="text-align: right; flex: 1;">
                        <p>
                            <strong>Control No.</strong>
                            <span style="display:inline-block; min-width:120px; border-bottom:1px solid #000; text-align:center;">
                                ${escapeHtml(seriesNum || '')}
                            </span>
                            <br>Series of ${year}
                        </p>
                    </div>
                </section>
                </header>

                <div class="side-by-side">
                    <div class="left-content">
    <div class="council-box">
        <h1><?php echo htmlspecialchars($councilTerm); ?><sup>th</sup> COUNCIL</h1><br>
        <div class="official-title">
            <?php
            // Display Punong Barangay first
            foreach ($officials as $official) {
                if ($official['position'] == 'Punong Barangay') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display 1st, 2nd, and 3rd Kagawads
            for ($i = 1; $i <= 3; $i++) {
                foreach ($officials as $official) {
                    if ($official['position'] == $i . 'st Kagawad' || $official['position'] == $i . 'nd Kagawad' || $official['position'] == $i . 'rd Kagawad') {
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    }
                }
            }

            // Display 4th to 7th Kagawads
            for ($i = 4; $i <= 7; $i++) {
                foreach ($officials as $official) {
                    if ($official['position'] == $i . 'th Kagawad') {
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    }
                }
            }

            // Display SK Chairman
            foreach ($officials as $official) {
                if ($official['position'] == 'SK Chairman') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display Barangay Secretary
            foreach ($officials as $official) {
                if ($official['position'] == 'Barangay Secretary') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }

            // Display Treasurer
            foreach ($officials as $official) {
                if ($official['position'] == 'Treasurer') {
                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                    break;
                }
            }
            ?>
        </div>
    </div>
</div>
                    <!-- Right Section: Certification Text -->
                    <div class="right-content">
                        <p>TO WHOM IT MAY CONCERN:</p>
                        <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, legal age, <strong>${civil_status}</strong>. 
                        Filipino citizen, is a resident of Barangay Bugo, this City, particularly in <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>.</p><br>
                        <p>FURTHER CERTIFIES that the above-named person is known to be a person of good moral character and reputation as far as this office is concerned.
                        He/She has no pending case filed and blottered before this office.</p><br>
                        <p>This certification is being issued upon the request of the above-named person, in connection with his/her desire <strong>${purpose}</strong>.</p><br>

                        <!-- New Section Added Below -->
                            <p>Given this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p>
                        <br>
                        <div style="text-align: center; font-size: 15px;" >
                            <u><strong>${fullname}</strong></u>
                            <p>AFFIANT SIGNATURE</p>
                        </div>

<div style="display: flex; justify-content: space-between; margin-top: 10px;">
    <section style="width: 48%; position: relative;">
        <?php if ($lupon_official): ?>
            <p><strong>As per records (LUPON TAGAPAMAYAPA):</strong></p>
            <p>Brgy. Case #: ___________________________</p>
            <p>Certified by: <U><strong><?php echo htmlspecialchars($lupon_official); ?></strong></U></p>
            <!-- e-Signature for Lupon Official positioned over the name -->
                <div style="position: absolute; top: 25px; left: 50%; transform: translateX(-25%); width: 120px; height: auto;">
                    <img src="components/employee_modal/lupon_sig.php?t=<?=time()?>" alt="Lupon Tagapamayapa e-Signature" 
                        style="width: 120px; height: auto; z-index: 1;">
                </div>
            <p>Date: <?php echo date('F j, Y'); ?></p>
        <?php endif; ?>
    </section>

    <section style="width: 48%; position: relative;">
        <?php if ($barangay_tanod_official): ?>
            <p><strong>As per records (BARANGAY TANOD):</strong></p>
            <p>Brgy. Tanod Remarks: _____________________</p>
            <p>Certified by: <U><strong><?php echo htmlspecialchars($barangay_tanod_official); ?></strong></U></p>
            <!-- e-Signature for Barangay Tanod Official positioned over the name -->
            <div style="position: absolute; top: 25px; left: 50%; transform: translateX(-25%); width: 120px; height: auto;">
                    <img src="components/employee_modal/tanod_sig.php?t=<?=time()?>" alt="Lupon Tagapamayapa e-Signature" 
                        style="width: 120px; height: auto; z-index: 1;">
                </div>
            <p>Date: <?php echo date('F j, Y'); ?></p>
        <?php endif; ?>
    </section>
</div>
                        
                    </div>
                </div>

                <!-- Thumbprint Section Below Left Content -->
                <section style="margin-top: 20px; text-align: center;">
                    <div style="display: flex; justify-content: left; gap: 20px;">
                        <!-- Left Thumb Box with Label Above -->
                        <div style="text-align: center; font-size:6px;" >
                            <p><strong>Left Thumb:</strong></p>
                            <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                            </div>
                        </div>

                        <!-- Right Thumb Box -->
                        <div style="text-align: center; font-size:6px;">
                            <p><strong>Right Thumb:</strong></p>
                            <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                            </div>
                        </div>
                    </div>
                </section>

                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <section style="width: 48%; line-height: 1.8; margin-top: 35px;">
                        <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Issued at:</strong> ${issued_at}</p>
                    </section>
${renderSignatorySection(isCaptainSignatory, assignedKagName)}
                </div>
            </div>
        </body>
    </html>


        `;
    }else if (certificate.toLowerCase() === "beso application") {
                printAreaContent = `
                    <html>
                        <head>
                            <link rel="stylesheet" href="css/form.css">
                            <link rel="stylesheet" href="css/print/print.css">
                        </head>
                        <body>
                        <div class="container" id="printArea">
                    <header>
                        <div class="logo-header"> <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
            <?php else: ?>
                <p>No active Barangay logo found.</p>
            <?php endif; ?>
                                        <div class="header-text">
                                            <h2><strong>Republic of the Philippines</strong></h2>
                                            <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                                            <h3><strong><?php echo $barangayName; ?></strong></h3>
                                            <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                                            <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
                                        </div>
                            <?php if ($cityLogo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"    >
            <?php else: ?>
                <p>No active City/Municipality logo found.</p>
            <?php endif; ?>
                        </div>
                                    <hr class="header-line">
                                </header>
                                <section class="barangay-certification">
                                    <h4 style="text-align: center; font-size: 50px;"><strong>BARANGAY CERTIFICATION</strong></h4>
                                    <p style="text-align: center; font-size: 18px; margin-top: -10px;">
                                        <em>(First Time Jobseeker Assistance Act - RA 11261)</em>
                                    </p>
                                    <br>
                                    <p>This is to certify that <strong><u>${fullname}</u></strong>, ${age} years old is a resident of 
                                        <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>, Bugo, Cagayan de Oro City for <strong>${(() => {
                                            const start = new Date(residency_start);
                                            const today = new Date();
                                            let years = today.getFullYear() - start.getFullYear();

                                            // Adjust if current date hasn't reached the anniversary month/day yet
                                            const m = today.getMonth() - start.getMonth();
                                            if (m < 0 || (m === 0 && today.getDate() < start.getDate())) {
                                                years--;
                                            }

                                            return years + (years === 1 ? " year" : " years");
                                        })()}</strong>, is <strong>qualified</strong> availee of <strong>RA 11261</strong> or the <strong>First Time Jobseeker act of 2019.</strong>
                                    </p>
                                    <p>Further certifies that the holder/bearer was informed of his/her rights, including the duties and responsibilities accorded by RA 11261 through the <strong>OATH UNDERTAKING</strong> he/she has signed and execute in the presence of our Barangay Official.</p>
                                    <p>This certification is issued upon request of the above-named person for <strong>${purpose}</strong> purposes and is valid only until <strong>${(() => {
                                        const issuedDate = new Date();
                                        const validUntil = new Date(issuedDate.setFullYear(issuedDate.getFullYear() + 1));
                                        return validUntil.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                                    })()}</strong>.</p>
                                    <br>
                                    <p>Signed this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, 
                                        at Barangay Bugo, Cagayan de Oro City.
                                    </p>
                                </section>
                                <br>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
                        <section style="width: 48%; line-height: 1.8;">
                        <br>
                        <br>    
                        <p> Not valid without seal</p>
                        </section>
${renderSignatorySection(isCaptainSignatory, assignedKagName)}
                                </div>
                            </div>
                        </body>
                    </html>
                `;
            }else if (certificate.toLowerCase() === "cedula") {
  // --- helpers ---
  const toNumericShort = d => {
    const dt = d ? new Date(d) : new Date();
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const dd = String(dt.getDate()).padStart(2, '0');
    const yy = String(dt.getFullYear()).slice(-2);
    return `${mm} ${dd} ${yy}`; // e.g., 11 05 25
  };
  const parseYMD = (s) => {
    if (!s) return null;
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/); // MySQL YYYY-MM-DD
    if (m) return new Date(Date.UTC(+m[1], +m[2]-1, +m[3])); // avoid TZ shift
    return new Date(s);
  };
  const toSlashLong = d => {
    const dt = d ? parseYMD(d) : null;
    if (!dt) return '';
    const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(dt.getUTCDate()).padStart(2, '0');
    const yyyy = dt.getUTCFullYear();
    return `${mm}/${dd}/${yyyy}`; // e.g., 01/01/1970
  };

  const yearToday = new Date().getFullYear();

  // Split fullname → LAST / FIRST / MIDDLE
  const partsFromFull = (n='') => {
    const p = n.trim().split(/\s+/);
    if (p.length === 1) return { first:p[0], middle:'', last:'' };
    if (p.length === 2) return { first:p[0], middle:'', last:p[1] };
    return { first:p[0], middle:p.slice(1,-1).join(' '), last:p[p.length-1] };
  };
  const np = partsFromFull(fullname || '');
  const LNAME = (np.last||'').toUpperCase();
  const FNAME = (np.first||'').toUpperCase();
  const MNAME = (np.middle||'').toUpperCase();

  const address    = [res_street_address, res_zone, "Bugo, Cagayan de Oro City"].filter(Boolean).join(', ');
  const dateIssued = toNumericShort(issued_on);   // MM DD YY
  const birthDate  = toSlashLong(birth_date);     // MM/DD/YYYY
  const birthPlace = birth_place || '';           // POB (right column)
  const placeIssue = issued_at   || '';           // PLACE OF ISSUE

  printAreaContent = `
  <html>
    <head>
      <style>
        /* Default: we target landscape 6x4 */
        @media print and (orientation: landscape) {
          @page { size: 6in 4in; margin: 0; }
          #sheet { width:6in; height:4in; transform:none; }
        }
        /* Fallback: some drivers lock 4x6 portrait — rotate canvas */
        @media print and (orientation: portrait) {
          @page { size: 4in 6in; margin: 0; }
          #sheet { width:6in; height:4in; transform: rotate(90deg) translate(0, -6in); transform-origin: top left; }
        }

        html, body { margin:0; padding:0; }
        #sheet { position:relative; font-family: Arial, sans-serif; }
        .txt { position:absolute; font-size:12px; line-height:1.05; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .small { font-size:11px; }

        /* Row 1 (top band) — lowered & right-aligned date issued */
        .year       { top:.80in; left:.30in;  width:.85in;  text-align:left; }   /* YEAR (e.g., 2025) */
        .placeIssue { top:.80in; left:1.25in; width:2.10in; text-align:left; }   /* PLACE OF ISSUE */
        .dateIssued { top:.80in; left:4.25in; width:2.10in; text-align:left; }

        /* Name row (LAST / FIRST / MIDDLE) */
        .lname { top:1.10in; left:.75in; width:2.10in; text-align:left; }
        .fname { top:1.10in; left:3.05in; width:1.70in; text-align:left; }
        .mname { top:1.10in; left:4.90in; width:.95in;  text-align:left; }

        /* Address line */
        .address { top:1.45in; left:.75in; width:5.20in; text-align:left; }

        /* Right column info */
.pob { top:1.70in; right:.25in; width:2.20in; text-align:right; }
.dob { top:2in; right: -.75in; width:2.20in; text-align:right; }
      </style>
    </head>
    <body>
      <div id="sheet">
        <!-- Top band -->
        <div class="txt year">${yearToday}</div>
        <div class="txt placeIssue ${placeIssue.length>22?'small':''}">${escapeHtml(placeIssue)}</div>
        <div class="txt dateIssued">${escapeHtml(dateIssued)}</div>

        <!-- Names -->
        <div class="txt lname">${escapeHtml(LNAME)}</div>
        <div class="txt fname">${escapeHtml(FNAME)}</div>
        <div class="txt mname">${escapeHtml(MNAME)}</div>

        <!-- Address -->
        <div class="txt address ${address.length>48?'small':''}">${escapeHtml(address)}</div>

        <!-- Right column -->
        <div class="txt pob ${birthPlace.length>32?'small':''}">${escapeHtml(birthPlace)}</div>
        <div class="txt dob">${escapeHtml(birthDate)}</div>
      </div>
    </body>
  </html>`;
}


        // Open a new print window with the content
        const printWindow = window.open('', '_blank');
        printWindow.document.write(printAreaContent);
        printWindow.document.close();

printWindow.onload = function () {
  const b = printWindow.document.body;
  b.classList.remove('page-a4','page-letter','page-long');
  b.classList.add('page-long');        // ← use this for 8.5×13
  printWindow.print();
};
    }
const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g, '');
const isCedulaType = t => {
  const k = normStatus(t);
  return (k === 'cedula' || k === 'urgentcedula');
};

/* --- NEW: strict step-locking for status dropdown --- */
function lockStatusOptions(selectEl, currentStatusNorm){
  const q = v => selectEl.querySelector(`option[value="${v}"]`);
  const pendingOpt  = q('Pending');
  const approvedOpt = q('Approved');
  const rejectedOpt = q('Rejected');
  const releasedOpt = q('Released');
  const captainOpt  = q('ApprovedCaptain');

  // reset
  [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt].forEach(o => o && (o.disabled = false));

  // PENDING → only Approved & Rejected are enabled
  if (currentStatusNorm === 'pending') {
    if (pendingOpt)  pendingOpt.disabled  = true;   // can't re-choose
    if (approvedOpt) approvedOpt.disabled = false;  // allowed
    if (rejectedOpt) rejectedOpt.disabled = false;  // allowed
    if (captainOpt)  captainOpt.disabled  = true;   // not yet
    if (releasedOpt) releasedOpt.disabled = true;   // not yet
    return;
  }

  // base guard: Released only after ApprovedCaptain
  if (releasedOpt) releasedOpt.disabled = (currentStatusNorm !== 'approvedcaptain');

  // APPROVED → only next step (Approved by Captain)
  if (currentStatusNorm === 'approved') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = true;
    if (rejectedOpt) rejectedOpt.disabled = true;
    if (releasedOpt) releasedOpt.disabled = true;
    if (captainOpt)  captainOpt.disabled  = false;
    return;
  }

  // APPROVEDCAPTAIN → only Released
  if (currentStatusNorm === 'approvedcaptain') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = true;
    if (rejectedOpt) rejectedOpt.disabled = true;
    if (releasedOpt) releasedOpt.disabled = false;
    if (captainOpt)  captainOpt.disabled  = true;
    return;
  }

  // RELEASED or REJECTED → freeze
  if (currentStatusNorm === 'released' || currentStatusNorm === 'rejected') {
    [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt].forEach(o => o && (o.disabled = true));
  }
}


let currentAppointmentType = '';
let currentCedulaNumber    = '';

document.addEventListener('DOMContentLoaded', () => {
  /* Cache DOM nodes used in the View Modal workflow */
  const statusForm     = document.getElementById('statusUpdateForm');
  const statusSelect   = document.getElementById('statusSelect');
  const rejectionGroup = document.getElementById('viewRejectionReasonGroup');
  const cedulaGroup    = document.getElementById('viewCedulaNumberContainer');
  const cedulaInput    = document.getElementById('viewCedulaNumber');
  const assignGroup    = document.getElementById('assignKagawadGroup');
  const assignSelect   = document.getElementById('assignKagawadSelect');

  statusSelect.addEventListener('change', () => {
    const selected = statusSelect.value;

    // Rejection textarea toggle (uses d-none)
    const showReject = (selected === 'Rejected');
    rejectionGroup.classList.toggle('d-none', !showReject);
    document.getElementById('viewRejectionReason').required = showReject;

    // Cedula number only for Released + Cedula types
    const showCedula = (selected === 'Released' && isCedulaType(currentAppointmentType));
    cedulaGroup.classList.toggle('d-none', !showCedula);
    cedulaInput.required = showCedula;
    if (!showCedula) cedulaInput.value = '';

    // Assign Kagawad only when ApprovedCaptain for NON-Cedula
    const showKag = (selected === 'ApprovedCaptain' && !isCedulaType(currentAppointmentType));
    assignGroup.classList.toggle('d-none', !showKag);
    if (showKag) assignSelect.setAttribute('required','required');
    else { assignSelect.removeAttribute('required'); assignSelect.value = ''; }
  });

  /* ---------- VIEW MODAL: open & populate ---------- */
  document.querySelectorAll('[data-bs-target="#viewModal"]').forEach(button => {
    button.addEventListener('click', () => {
      const trackingNumber = button.dataset.trackingNumber || '';
      document.getElementById('statusTrackingNumber').value = trackingNumber;

      fetch('./ajax/view_case_and_status.php?tracking_number=' + encodeURIComponent(trackingNumber))
        .then(res => res.json())
        .then(data => {
          if (!data?.success) {
            alert('❌ Failed to load appointment data.');
            return;
          }

          // Set current status
          statusForm.dataset.currentStatus     = data.status || '';
          statusForm.dataset.currentStatusNorm = normStatus(data.status);
          statusSelect.value                   = data.status || '';

          // 🔒 Strict locking of allowed options
          lockStatusOptions(statusSelect, statusForm.dataset.currentStatusNorm);

          // Store appointment type + existing cedula
          const selectedAppt = (data.appointments || []).find(app => app.tracking_number === trackingNumber);
          currentAppointmentType = selectedAppt?.certificate || '';
          currentCedulaNumber    = selectedAppt?.cedula_number || '';

          // Trigger initial toggle state
          statusSelect.dispatchEvent(new Event('change'));

          // ----- Render Case History -----
          const container = document.getElementById('caseHistoryContainer');
          if (data.cases && data.cases.length) {
            container.innerHTML = '<ul class="list-group">' + data.cases.map(cs => `
              <li class="list-group-item">
                <strong>Case #${cs.case_number}</strong> - ${cs.nature_offense}<br>
                <small>Filed: ${cs.date_filed} | Hearing: ${cs.date_hearing} | Action: ${cs.action_taken}</small>
              </li>
            `).join('') + '</ul>';
          } else {
            container.innerHTML = '<p class="text-muted px-3 py-2 mb-0">No case records for this resident.</p>';
          }

          // ----- Render Same-day Appointments (with Cedula income if any) -----
          const ul   = document.getElementById('sameDayAppointments');
          const peso = v => '₱' + Number(v).toLocaleString('en-PH');
          if (data.appointments && data.appointments.length) {
            ul.innerHTML = data.appointments.map(app => {
              const incomeHtml =
                (String(app.certificate || '').toLowerCase() === 'cedula' && app.cedula_income)
                  ? `<div class="text-muted">Income: ${peso(app.cedula_income)}</div>`
                  : '';
              return `
                <li class="list-group-item">
                  <strong>${app.certificate}</strong><br>
                  Tracking #: <code>${app.tracking_number}</code><br>
                  Time: ${app.time_slot}
                  ${incomeHtml}
                </li>
              `;
            }).join('');
          } else {
            ul.innerHTML = '<li class="list-group-item text-muted">No appointments for this resident on this day.</li>';
          }
        });
    });
  });

  /* ---------- VIEW MODAL: submit ---------- */
  statusForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const newStatus      = statusSelect.value;
    const currentStatus  = statusForm.dataset.currentStatusNorm || normStatus(statusForm.dataset.currentStatus || '');

    // Require ApprovedCaptain before Released
    if (normStatus(newStatus) === 'released' && currentStatus !== 'approvedcaptain') {
      await Swal.fire({ icon:'warning', title:'Action Not Allowed',
        text:'You must first mark the appointment as Approved by Captain before releasing it.' });
      return;
    }

    // Require Kagawad when moving to ApprovedCaptain (non-Cedula)
    if (newStatus === 'ApprovedCaptain' && !isCedulaType(currentAppointmentType) && !assignSelect.value) {
      await Swal.fire({ icon:'warning', title:'Assign Kagawad',
        text:'Please select a Kagawad before approving by Captain.' });
      assignSelect.focus();
      return;
    }

    // Require Cedula # when Releasing a Cedula appointment
    if (newStatus === 'Released' && isCedulaType(currentAppointmentType) && !cedulaInput.value.trim()) {
      await Swal.fire({ icon:'warning', title:'Cedula Number required',
        text:'Provide the Cedula Number before marking as Released.' });
      cedulaInput.focus();
      return;
    }

    const formData = new FormData(statusForm);
    Swal.fire({ title:'Saving...', text:'Please wait while we update the status.', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    try {
      const res  = await fetch('./ajax/update_status_batch.php', {
        method:'POST', body:formData, headers:{ 'X-Requested-With':'XMLHttpRequest' }
      });

      let json=null, text='';
      try { json = await res.json(); } catch { text = await res.text().catch(()=> ''); }
      if (!res.ok || !json) throw new Error(json?.message || (text ? text.slice(0,200) : `HTTP ${res.status}`));

      if (json.success) {
        await Swal.fire({ icon:'success', title:'Success', text:json.message || 'Status updated.' });
        location.reload();
      } else {
        await Swal.fire({ icon:'warning', title:'Update Failed', text:json.message || 'Please try again.' });
      }
    } catch (err) {
      console.error('Error during status update:', err);
      await Swal.fire({ icon:'error', title:'Error', text: err.message || 'Something went wrong.' });
    }
  });

  /* ======================================================
     SMALL "STATUS MODAL" (optional / if you still use it)
     - Mirrors the same Kagawad/Cedula/Rejection toggles
  ====================================================== */
  document.querySelectorAll('[data-bs-target="#statusModal"]').forEach(button => {
    button.addEventListener('click', () => {
      const certificate  = String(button.getAttribute('data-certificate') || '');
      const trackingNum  = String(button.getAttribute('data-tracking-number') || '');
      const cedulaNumber = String(button.getAttribute('data-cedula-number') || '');

      document.getElementById('modalTrackingNumber').value = trackingNum;
      document.getElementById('modalCertificate').value    = certificate;
      document.getElementById('statusModalCedulaNumber').value = cedulaNumber;

      const modalStatusSelect = document.getElementById('newStatus');
      const cedulaWrap        = document.getElementById('statusModalCedulaNumberContainer');
      const rejectWrap        = document.getElementById('statusModalRejectionReasonContainer');

      // If you kept the small modal Kagawad controls, wire them too:
      const assignKagWrap     = document.getElementById('statusModalAssignKagGroup');
      const assignKagSel2     = document.getElementById('statusModalAssignKag');

      const setModalToggles = () => {
        const selectedStatus = modalStatusSelect.value;
        const currentStatusNorm = (modalStatusSelect.getAttribute('data-current-status') || '')
          .toLowerCase().replace(/[^a-z]/g,'');

        // 🔒 Same strict locking
        lockStatusOptions(modalStatusSelect, currentStatusNorm);

        // Cedula field for Released + Cedula type
        if (certificate.toLowerCase() === 'cedula' && selectedStatus === 'Released') {
          cedulaWrap.style.display = 'block';
        } else {
          cedulaWrap.style.display = 'none';
        }

        // Rejection textarea
        rejectWrap.style.display = (selectedStatus === 'Rejected') ? 'block' : 'none';

        // Show Assign Kagawad only for ApprovedCaptain on non-Cedula
        if (assignKagWrap && assignKagSel2) {
          const showKag = (selectedStatus === 'ApprovedCaptain' && certificate.toLowerCase() !== 'cedula');
          assignKagWrap.style.display = showKag ? 'block' : 'none';
          if (showKag) assignKagSel2.setAttribute('required','required');
          else { assignKagSel2.removeAttribute('required'); assignKagSel2.value = ''; }
        }
      };

      modalStatusSelect.removeEventListener('change', setModalToggles);
      modalStatusSelect.addEventListener('change', setModalToggles);
      setModalToggles();
    });
  });

  /* ---------- optional: view log helper ---------- */
  window.logAppointmentView = function(residentId) {
    fetch('./logs/logs_trig.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
      body:`filename=3&viewedID=${encodeURIComponent(residentId)}`
    }).catch(()=>{});
  };

  /* ---------- badge coloring on the table ---------- */
  (function(){
    const map = {
      pending:'badge-soft-warning',
      approved:'badge-soft-info',
      approvedcaptain:'badge-soft-primary',
      rejected:'badge-soft-danger',
      released:'badge-soft-success'
    };
    document.querySelectorAll('#appointmentTableBody td:nth-child(6)').forEach(td => {
      const raw = (td.textContent || '').trim();
      const key = raw.toLowerCase().replace(/\s+/g,'');
      if (!td.querySelector('.badge')) {
        const b = document.createElement('span');
        b.className = 'badge ' + (map[key] || 'badge-soft-secondary');
        b.textContent = raw;
        td.textContent = '';
        td.appendChild(b);
      }
    });
  })();
});
        </script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        
    </body>
    </html>
