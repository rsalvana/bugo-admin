<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
// include './include/encryption.php';
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
date_default_timezone_set('Asia/Manila');

/* ====== CASES GUARD HELPERS ====== */
function bb_is_respondent_open(mysqli $db, string $first, string $middle, string $last, string $suffix=''): bool {
    $sql = "
        SELECT 1
          FROM cases
         WHERE UPPER(TRIM(Resp_First_Name)) = UPPER(TRIM(?))
           AND UPPER(TRIM(Resp_Last_Name))  = UPPER(TRIM(?))
           AND (
                Resp_Middle_Name IS NULL OR Resp_Middle_Name = '' OR
                UPPER(TRIM(Resp_Middle_Name)) = UPPER(TRIM(?))
           )
           AND action_taken IN ('Ongoing','Pending')
         LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param('sss', $first, $last, $middle);
    $st->execute();
    $hit = $st->get_result()->num_rows > 0;
    $st->close();
    return $hit;
}

/* resolve resident name from tracking (schedules/urgent_request only) */
function bb_fetch_resident_name_by_tracking(mysqli $db, string $tracking): ?array {
    $sql = "
        (SELECT r.first_name, COALESCE(r.middle_name,'' ) AS m, r.last_name, COALESCE(r.suffix_name,'') AS s
           FROM schedules s JOIN residents r ON r.id = s.res_id
          WHERE s.tracking_number = ?)
        UNION ALL
        (SELECT r.first_name, COALESCE(r.middle_name,'' ), r.last_name, COALESCE(r.suffix_name,'')
           FROM urgent_request u JOIN residents r ON r.id = u.res_id
          WHERE u.tracking_number = ?)
        LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param('ss', $tracking, $tracking);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs->fetch_assoc();
    $st->close();
    return $row ?: null; // ['first_name'=>..., 'm'=>..., 'last_name'=>..., 's'=>...]
}


// Pagination settings
$results_per_page = 200;
$page = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($GLOBALS['_GET']['pagenum']) : 1;
$search_term = isset($GLOBALS['_GET']['search']) ? trim($GLOBALS['_GET']['search']) : '';
$offset = ($page - 1) * $results_per_page;

// Handle delete (Mark appointment as archived)
if (isset($GLOBALS['_POST']['delete_appointment'], $GLOBALS['_POST']['tracking_number'], $GLOBALS['_POST']['certificate'])) {
    $tracking_number = $GLOBALS['_POST']['tracking_number'];
    $certificate = $GLOBALS['_POST']['certificate'];

    if (strtolower($certificate) === 'cedula') {
        // Delete Cedula Appointment
        $update_query = "UPDATE cedula SET cedula_delete_status = 1 WHERE tracking_number = ?";
    } else {
        // Delete Schedule Appointment
        $update_query = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";
    }

    $stmt_update = $mysqli->prepare($update_query);
    $stmt_update->bind_param("s", $tracking_number);
    $stmt_update->execute();

    echo "<script>
        Swal.fire({icon:'success',title:'Archived',text:'Appointment archived.'})
        .then(()=>{ window.location = '" . enc_captain('view_appointments') . "'; });
    </script>";
    exit; // Added exit
}


// Handle status update (REFACTORED WITH DYNAMIC QUERY AND VALIDATION)
if (isset($GLOBALS['_POST']['update_status'], $GLOBALS['_POST']['tracking_number'], $GLOBALS['_POST']['new_status'], $GLOBALS['_POST']['certificate'])) {
    $tracking_number = $GLOBALS['_POST']['tracking_number'];
    $new_status = $GLOBALS['_POST']['new_status'];
    $certificate = $GLOBALS['_POST']['certificate'];
    $cedula_number = trim($GLOBALS['_POST']['cedula_number'] ?? '');
    $rejection_reason = trim($GLOBALS['_POST']['rejection_reason'] ?? '');
    $assignedKagName = trim($GLOBALS['_POST']['assigned_kag_name'] ?? '');         // NEW
    $assignedWitnessName = trim($GLOBALS['_POST']['assigned_witness_name'] ?? ''); // NEW
    $employee_id = $_SESSION['employee_id']; // Get this early

    $isBeso = (strtolower($certificate) === 'beso application');
    
    /* --- BLOCK Clearance if resident is a RESPONDENT with Ongoing/Pending case --- */
$blockFor = ['approved','approvedcaptain','released'];
if (strtolower($certificate) === 'barangay clearance' && in_array(strtolower($new_status), $blockFor, true)) {
    $name = bb_fetch_resident_name_by_tracking($mysqli, $tracking_number);
    if ($name && bb_is_respondent_open($mysqli, $name['first_name'], $name['m'], $name['last_name'], $name['s'])) {
        echo "<script>
            Swal.fire({
              icon:'error',
              title:'Blocked',
              text:'Cannot set to Approved/ApprovedCaptain/Released for Barangay Clearance: resident is a RESPONDENT in an Ongoing/Pending case.'
            }).then(()=>history.back());
        </script>";
        exit;
    }
}


    // Require Kagawad for Captain Approval
    if ($new_status === 'ApprovedCaptain' && $assignedKagName === '') {
        echo "<script>
            Swal.fire({icon:'warning',title:'Assign Kagawad',text:'Please select a Kagawad before approving.'})
            .then(()=>history.back());
        </script>";
        exit;
    }
    // Witness required ONLY for Beso Application when approving as Captain
    if ($new_status === 'ApprovedCaptain' && $isBeso && $assignedWitnessName === '') {
        echo "<script>
            Swal.fire({icon:'warning',title:'Assign Witness',text:'Please select a Witness (Secretary) for Beso Application before approving.'})
            .then(()=>history.back());
        </script>";
        exit;
    }

    // Uniqueness check for Cedula number
    if (strtolower($certificate) === 'cedula' && $new_status === 'Approved' && !empty($cedula_number)) {
        $user_id = $_SESSION['user_id'] ?? 0; // Use resident ID if available, else 0
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
            echo "<script>
                Swal.fire({icon:'error',title:'Duplicate Cedula',text:'Cedula number already exists for another resident.'})
                .then(()=>history.back());
            </script>";
            exit;
        }
    }

    // Determine which table and columns to update
    $target_table = '';
    $status_col = 'status';
    $cedula_table = false;
    $is_clearance_or_beso = false; // Flag for tables that support assignments

    if (strtolower($certificate) === 'cedula') {
        // Check if it's urgent cedula
        $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
        $checkUrgentCedula->bind_param("s", $tracking_number);
        $checkUrgentCedula->execute();
        $checkUrgentCedula->bind_result($isUrgentCedula);
        $checkUrgentCedula->fetch();
        $checkUrgentCedula->close();

        if ($isUrgentCedula > 0) {
            $target_table = 'urgent_cedula_request';
            $status_col = 'cedula_status';
        } else {
            $target_table = 'cedula';
            $status_col = 'cedula_status';
        }
        $cedula_table = true;
    } else {
        // Check if it's urgent schedule
        $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgent->bind_param("s", $tracking_number);
        $checkUrgent->execute();
        $checkUrgent->bind_result($isUrgent);
        $checkUrgent->fetch();
        $checkUrgent->close();

        if ($isUrgent > 0) {
            $target_table = 'urgent_request';
            $status_col = 'status';
        } else {
            $target_table = 'schedules';
            $status_col = 'status';
        }
        $is_clearance_or_beso = true;
    }

    if (empty($target_table)) {
        echo "<script>
            Swal.fire({icon:'error',title:'Error',text:'Could not find the appointment record.'})
            .then(()=>history.back());
        </script>";
        exit;
    }

    // --- Perform update (Dynamic Query Build) ---
    $setParts = ["$status_col=?", "is_read=0", "notif_sent=1", "employee_id=?"];
    $types = "si"; // status, employee_id
    $params = [$new_status, $employee_id];

    if ($new_status === 'Rejected') {
        $setParts[] = "rejection_reason=?";
        $types .= "s";
        $params[] = $rejection_reason;
    } else {
        $setParts[] = "rejection_reason=NULL";
    }

    // Handle cedula-specific fields
    if ($cedula_table && $new_status !== 'Rejected') {
        $setParts[] = "cedula_number=?";
        $types .= "s";
        $params[] = $cedula_number;
    }
    
    // Assignments for schedules/urgent_request
    if ($is_clearance_or_beso) {
        if ($assignedKagName !== '') {
            $setParts[] = "assignedKagName=?";
            $types .= "s";
            $params[] = $assignedKagName;
        }

        // Witness only set when provided (Beso flow)
        if ($assignedWitnessName !== '') {
            $setParts[] = "assigned_witness_name=?";
            $types .= "s";
            $params[] = $assignedWitnessName;
        }
    }

    $params[] = $tracking_number; // Add tracking number at the end
    $types .= "s";
    $setSql = implode(", ", $setParts);

    $query = "UPDATE $target_table SET $setSql WHERE tracking_number = ?";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    // --- End Dynamic Query ---


    // Determine the appointment source (unchanged)
    $isUrgentCedula = false;
    $isUrgentSchedule = false;

    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($urgentCedulaCount);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();

    if ($urgentCedulaCount > 0) {
        $isUrgentCedula = true;
    }

    if (!$isUrgentCedula) {
        $checkUrgentSchedule = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
        $checkUrgentSchedule->bind_param("s", $tracking_number);
        $checkUrgentSchedule->execute();
        $checkUrgentSchedule->bind_result($urgentScheduleCount);
        $checkUrgentSchedule->fetch();
        $checkUrgentSchedule->close();

        if ($urgentScheduleCount > 0) {
            $isUrgentSchedule = true;
        }
    }

    // Build the correct email query (unchanged)
    if ($isUrgentCedula) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM urgent_cedula_request u
                        JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } elseif ($certificate === 'Cedula') {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM cedula c
                        JOIN residents r ON c.res_id = r.id
                        WHERE c.tracking_number = ?";
    } elseif ($isUrgentSchedule) {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM urgent_request u
                        JOIN residents r ON u.res_id = r.id
                        WHERE u.tracking_number = ?";
    } else {
        $email_query = "SELECT r.email, r.contact_number, CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) AS full_name
                        FROM schedules s
                        JOIN residents r ON s.res_id = r.id
                        WHERE s.tracking_number = ?";
    }

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email->num_rows > 0) {
        $row = $result_email->fetch_assoc();
        $email = $row['email'];
        $resident_name = $row['full_name'];
        $contact_number = $row['contact_number'];

        // Email via PHPMailer (unchanged)
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

        // SMS via Semaphore (unchanged)
        $apiKey = 'your_semaphore_api_key';
        $sender = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query([
            'apikey' => $apiKey,
            'number' => $contact_number,
            'message' => $sms_message,
            'sendername' => $sender
        ]);

        $sms_options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $sms_data,
            ],
        ];

        $sms_context = stream_context_create($sms_options);
        $sms_result = @file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);

        if ($sms_result === FALSE) {
            error_log("❌ SMS failed to send to $contact_number");
        } else {
            $sms_response = json_decode($sms_result, true);
            error_log("✅ SMS sent response: " . print_r($sms_response, true));

            // Log to DB (unchanged)
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_query = "INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?, ?, ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
        }
    }

    echo "<script>
        Swal.fire({icon:'success',title:'Status Updated',text:'Status updated to $new_status'})
        .then(()=>{ window.location = '" . enc_captain('view_appointments') . "'; });
    </script>";
    exit; // Added exit
}


// ---------- Archiving (UNCHANGED) ----------
$archiveSchedules = "
    INSERT INTO archived_schedules
    SELECT * FROM schedules
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$deleteSchedules = "
    DELETE FROM schedules
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$mysqli->query($archiveSchedules);
$mysqli->query($deleteSchedules);

$archiveCedula = "
    INSERT INTO archived_cedula
    SELECT * FROM cedula
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteCedula = "
    DELETE FROM cedula
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$mysqli->query($archiveCedula);
$mysqli->query($deleteCedula);

$archiveUrgentCedula = "
    INSERT INTO archived_urgent_cedula_request
    SELECT * FROM urgent_cedula_request
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteUrgentCedula = "
    DELETE FROM urgent_cedula_request
    WHERE cedula_status = 'Released' 
      AND YEAR(issued_on) < YEAR(CURDATE())
";
$mysqli->query($archiveUrgentCedula);
$mysqli->query($deleteUrgentCedula);

$archiveUrgentRequest = "
    INSERT INTO archived_urgent_request
    SELECT * FROM urgent_request
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$deleteUrgentRequest = "
    DELETE FROM urgent_request
    WHERE status = 'Released' AND selected_date < CURDATE()
";
$mysqli->query($archiveUrgentRequest);
$mysqli->query($deleteUrgentRequest);

$mysqli->query("UPDATE schedules SET appointment_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released', 'Rejected')");

// ---------- DATA SOURCE (INCLUDES ASSIGNMENT FIELDS FROM schedules & urgent_request) ----------
$sql = "
SELECT * FROM (
    -- Urgent Cedula 
    SELECT 
        ucr.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname,
        'Cedula' AS certificate,
        ucr.cedula_status AS status,
        ucr.appointment_time AS selected_time,
        ucr.appointment_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application (Urgent)' AS purpose,
        ucr.issued_on,
        ucr.cedula_number,
        ucr.issued_at,
        NULL AS assigned_kag_id,
        NULL AS assigned_kag_name,
        NULL AS assigned_witness_id,
        NULL AS assigned_witness_name
    FROM urgent_cedula_request ucr
    JOIN residents r ON ucr.res_id = r.id
    WHERE ucr.cedula_delete_status = 0 
      AND ucr.appointment_date >= CURDATE()

    UNION

    -- Regular Schedules
    SELECT 
        s.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname, 
        s.certificate, 
        s.status,
        s.selected_time,
        s.selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        s.purpose,
        c.issued_on,
        c.cedula_number,
        c.issued_at,
        s.assignedKagId         AS assigned_kag_id,
        s.assignedKagName       AS assigned_kag_name,
        NULL                    AS assigned_witness_id,      -- schedules has no witness ID
        s.assigned_witness_name AS assigned_witness_name
    FROM schedules s
    JOIN residents r ON s.res_id = r.id
    LEFT JOIN cedula c ON c.res_id = r.id
    WHERE s.appointment_delete_status = 0 
      AND s.selected_date >= CURDATE()

    UNION

    -- Cedula Appointments
    SELECT 
        c.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname, 
        'Cedula' AS certificate,
        c.cedula_status AS status,
        c.appointment_time AS selected_time,
        c.appointment_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        'Cedula Application' AS purpose,
        c.issued_on,
        c.cedula_number,
        c.issued_at,
        NULL AS assigned_kag_id,
        NULL AS assigned_kag_name,
        NULL AS assigned_witness_id,
        NULL AS assigned_witness_name
    FROM cedula c
    JOIN residents r ON c.res_id = r.id
    WHERE c.cedula_delete_status = 0 
      AND c.appointment_date >= CURDATE()

    UNION

    -- Urgent Requests 
    SELECT 
        u.tracking_number,
        CONCAT(r.first_name, ' ', IFNULL(r.middle_name, ''), ' ', r.last_name, ' ',IFNULL(r.suffix_name, '')) AS fullname,
        u.certificate,
        u.status,
        u.selected_time,
        u.selected_date AS selected_date,
        r.id AS res_id,
        r.birth_date,
        r.birth_place,
        r.res_zone,
        r.civil_status,
        r.residency_start,
        r.res_street_address,
        u.purpose,
        COALESCE(c.issued_on, uc.issued_on) AS issued_on,
        COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
        COALESCE(c.issued_at, uc.issued_at) AS issued_at,
        u.assignedKagId         AS assigned_kag_id,
        u.assignedKagName       AS assigned_kag_name,
        NULL                    AS assigned_witness_id,      -- urgent_request has no witness ID
        u.assigned_witness_name AS assigned_witness_name
    FROM urgent_request u
    JOIN residents r ON u.res_id = r.id
    LEFT JOIN cedula c ON c.res_id = r.id AND c.cedula_status = 'Approved'
    LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
    WHERE u.urgent_delete_status = 0 
      AND u.selected_date >= CURDATE()
) AS all_appointments
 WHERE status IN ('Approved', 'ApprovedCaptain')
GROUP BY tracking_number
ORDER BY 
    (status = 'Pending' AND selected_time = 'URGENT' AND selected_date = CURDATE()) DESC,
    (status = 'Pending' AND selected_date = CURDATE()) DESC,
    selected_date ASC,
    selected_time ASC,
    FIELD(status, 'Pending', 'Approved', 'Rejected')
LIMIT ?, ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $offset, $results_per_page);
$stmt->execute();
$result = $stmt->get_result();
$filtered_appointments = [];
$currentDate = date('Y-m-d');

// Default date_filter to 'today' if not provided
$date_filter = isset($GLOBALS['_GET']['date_filter']) ? $GLOBALS['_GET']['date_filter'] : 'today';

while ($row = $result->fetch_assoc()) {
    $include = true;

    // Date filter (UNCHANGED)
    if (!empty($date_filter)) {
        $appt_date = $row['selected_date'];
        $week = date('W', strtotime($appt_date));
        $this_week = date('W');
        $next_week = date('W', strtotime("+1 week"));

        switch ($date_filter) {
            case 'today':
                $include = $appt_date === $currentDate;
                break;
            case 'this_week':
                $include = $week === $this_week && date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'next_week':
                $include = $week === $next_week && date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'this_month':
                $include = date('m', strtotime($appt_date)) === date('m') &&
                           date('Y', strtotime($appt_date)) === date('Y');
                break;
            case 'this_year':
                $include = date('Y', strtotime($appt_date)) === date('Y');
                break;
        }
    }

    // Status filter
    if ($include && !empty($GLOBALS['_GET']['status_filter'])) {
        $include = $row['status'] === $GLOBALS['_GET']['status_filter'];
    }

    if ($include) {
        $filtered_appointments[] = $row;
    }
}

$total_results_sql = "
    SELECT COUNT(*) AS total FROM (
        SELECT tracking_number FROM schedules WHERE appointment_delete_status = 0
        UNION
        SELECT tracking_number FROM cedula WHERE cedula_status IS NOT NULL AND cedula_delete_status = 0
        UNION
        SELECT tracking_number FROM urgent_request WHERE urgent_delete_status = 0
        UNION
        SELECT tracking_number FROM urgent_cedula_request WHERE cedula_delete_status = 0
    ) AS combined
";
$total_results_result = $mysqli->query($total_results_sql);
$total_results = $total_results_result->fetch_row()[0];
$total_pages = ceil($total_results / $results_per_page);

// Officials (UNCHANGED)
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name, b.status
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status = 'active' 
        AND b.position NOT LIKE '%Lupon%'
        AND b.position NOT LIKE '%Barangay Tanod%'
        AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position, 'Punong Barangay', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 'Kagawad', 
        'Kagawad', 'SK Chairman', 'Secretary', 'Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult->num_rows > 0) {
    while($row = $offresult->fetch_assoc()) {
        $officials[] = [
            'position' => $row['position'],
            'name' => $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']
        ];
    }
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo = $logo_result->num_rows > 0 ? $logo_result->fetch_assoc() : null;

$citySql = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status = 'active' LIMIT 1";
$cityResult = $mysqli->query($citySql);
$cityLogo = $cityResult->num_rows > 0 ? $cityResult->fetch_assoc() : null;

// Barangay/City labels (UNCHANGED)
$barangayInfoSql = "SELECT 
                        bm.city_municipality_name, 
                        b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) {
        $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName);
    } else {
        $cityMunicipalityName = strtoupper($cityMunicipalityName);
    }
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName));

    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = strtoupper($barangayName);
    } else if (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "POBLACION " . strtoupper($barangayName);
    } else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = strtoupper($barangayName);
    } else {
        $barangayName = "BARANGAY " . strtoupper($barangayName);
    }
} else {
    $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND";
    $barangayName = "NO BARANGAY FOUND";
}

// Council term + contacts (UNCHANGED)
$councilTermSql = "SELECT council_term FROM barangay_info WHERE id = 1";
$councilTermResult = $mysqli->query($councilTermSql);
$councilTerm = ($councilTermResult->num_rows > 0) ? $councilTermResult->fetch_assoc()['council_term'] : "#";

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
            FROM barangay_information b
            INNER JOIN residents r ON b.official_id = r.id
            WHERE b.status = 'active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);

$lupon_official = null;
$barangay_tanod_official = null;
if ($lupon_result->num_rows > 0) {
    while ($row = $lupon_result->fetch_assoc()) {
        if (stripos($row['position'], 'Lupon') !== false) {
            $lupon_official = $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'];
        }
        if (stripos($row['position'], 'Barangay Tanod') !== false || stripos($row['position'], 'Barangay Police') !== false) {
            $barangay_tanod_official = $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'];
        }
    }
}

$barangayContactSql = "SELECT telephone_number, mobile_number FROM barangay_info WHERE id = 1";
$barangayContactResult = $mysqli->query($barangayContactSql);
if ($barangayContactResult->num_rows > 0) {
    $contactInfo = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber = $contactInfo['mobile_number'];
} else {
    $telephoneNumber = "No telephone number found";
    $mobileNumber = "No mobile number found";
}

/* --- Kagawad list for dropdown in the View modal --- */
$kagawads = [];
$kagSql = "
    SELECT r.id AS resident_id,
           TRIM(CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name, IFNULL(CONCAT(' ',r.suffix_name),''))) AS full_name
    FROM barangay_information b
    JOIN residents r ON b.official_id = r.id
    WHERE b.status='active' AND b.position LIKE '%Kagawad%'
    ORDER BY full_name
";
if ($resK = $mysqli->query($kagSql)) {
    while ($kr = $resK->fetch_assoc()) $kagawads[] = $kr;
}

/* --- NEW: Witnesses (Sec / Exec Sec) --- */
$witnesses = [];
$witnessSql = "
    SELECT 
           CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name) AS full_name,
           b.position
    FROM barangay_information b
    JOIN residents r ON r.id = b.official_id
    WHERE b.status='active'
      AND (b.position LIKE '%Barangay Secretary%' OR b.position LIKE '%Barangay Executive Secretary%' OR b.position LIKE '%Admin%')
    ORDER BY b.position
";
if ($wr = $mysqli->query($witnessSql)) {
    while ($row = $wr->fetch_assoc()) $witnesses[] = $row;
    $wr->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Appointment List</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</head>
<body>
    <div class="container my-4 app-shell">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
            <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
            <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
        </div>

        <div class="card card-filter mb-3 shadow-sm">
            <div class="card-body py-3">
                <form method="GET" action="index_captain.php" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="<?= $GLOBALS['_GET']['page'] ?? 'view_appointments' ?>" />

                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 fw-semibold">Date</label>
                        <select name="date_filter" class="form-select form-select-sm">
                            <option value="today" <?= ($GLOBALS['_GET']['date_filter'] ?? '') == 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="this_week" <?= ($GLOBALS['_GET']['date_filter'] ?? '') == 'this_week' ? 'selected' : '' ?>>This Week</option>
                            <option value="next_week" <?= ($GLOBALS['_GET']['date_filter'] ?? '') == 'next_week' ? 'selected' : '' ?>>Next Week</option>
                            <option value="this_month" <?= ($GLOBALS['_GET']['date_filter'] ?? '') == 'this_month' ? 'selected' : '' ?>>This Month</option>
                            <option value="this_year" <?= ($GLOBALS['_GET']['date_filter'] ?? '') == 'this_year' ? 'selected' : '' ?>>This Year</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label mb-1 fw-semibold">Status</label>
                        <select name="status_filter" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Pending" <?= ($GLOBALS['_GET']['status_filter'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Approved" <?= ($GLOBALS['_GET']['status_filter'] ?? '') == 'Approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="Rejected" <?= ($GLOBALS['_GET']['status_filter'] ?? '') == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="Released" <?= ($GLOBALS['_GET']['status_filter'] ?? '') == 'Released' ? 'selected' : '' ?>>Released</option>
                            <option value="ApprovedCaptain" <?= ($GLOBALS['_GET']['status_filter'] ?? '') == 'ApprovedCaptain' ? 'selected' : '' ?>>Approved by Captain</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1 fw-semibold">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($search_term) ?>" class="form-control" placeholder="Search name or tracking number..." />
                            <button type="submit" class="btn btn-primary">Apply</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body table-shell">
                <div class="table-edge">
                    <div class="table-scroll">
                        <table class="table table-hover align-middle mb-0" id="appointmentsTable">
                            <thead class="table-head sticky-top">
                                <tr>
                                    <th style="width: 200px;">Full Name</th>
                                    <th style="width: 120px;">Certificate</th>
                                    <th style="width: 220px;">Tracking Number</th>
                                    <th style="width: 160px;">Date</th>
                                    <th style="width: 160px;">Time Slot</th>
                                    <th style="width: 160px;">Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="appointmentTableBody">
                            <?php if (count($filtered_appointments) > 0): ?>
                                <?php foreach ($filtered_appointments as $row): ?>
                                    <?php include 'Modules/captain_modules/appointment_row.php'; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No appointments found matching filters</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php
          // Windowed pagination (styled like reference)
          $pageBase = 'index_captain.php'; // Use base page
          $params = $GLOBALS['_GET']; 
          unset($params['pagenum']);
          $params['page'] = 'view_appointments'; // Ensure page param is set
          $qs = '?' . http_build_query($params);

          $window = 7;
          $half   = (int)floor($window/2);
          $start  = max(1, $page - $half);
          $end    = min($total_pages, $start + $window - 1);
          if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
        ?>

        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-end pagination-soft mb-0">
                <?php if ($page <= 1): ?>
                    <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></span></li>
                <?php else: ?>
                    <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></a></li>
                <?php endif; ?>

                <?php if ($page <= 1): ?>
                    <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
                <?php else: ?>
                    <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></a></li>
                <?php endif; ?>

                <?php if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>

                <?php for ($i=$start; $i<=$end; $i++): ?>
                    <li class="page-item <?= ($i==$page)?'active':'' ?>"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a></li>
                <?php endfor; ?>

                <?php if ($end < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>

                <?php if ($page >= $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
                <?php else: ?>
                    <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></a></li>
                <?php endif; ?>

                <?php if ($page >= $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></span></li>
                <?php else: ?>
                    <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>

    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-elev rounded-4">
                <div class="modal-header modal-accent rounded-top-4">
                    <div>
                        <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="viewModalLabel">
                            <i class="bi bi-calendar-check-fill"></i> Appointment Details
                        </h5>
                        <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-0">
                    <div class="p-4 content-grid">
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

                        <section class="card soft-card grid-col-2">
                            <div class="card-header soft-card-header">
                                <span class="section-title"><i class="bi bi-people"></i> Assign Kagawad (for Captain Approval)</span>
                            </div>
                            <div class="card-body">
                                <select class="form-select" id="viewAssignedKagName">
                                    <option value="">— Select Kagawad —</option>
                                    <?php foreach ($kagawads as $k): ?>
                                        <option value="<?= htmlspecialchars($k['full_name']) ?>"><?= htmlspecialchars($k['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Required when approving as Captain.</div>
                            </div>
                        </section>

                        <!-- Witness section: hidden by default; shown only for Beso Application -->
                        <section class="card soft-card grid-col-2 d-none" id="witnessSection">
                            <div class="card-header soft-card-header">
                                <span class="section-title"><i class="bi bi-person-badge"></i> Assign Witness/Secretary</span>
                            </div>
                            <div class="card-body">
                                <select class="form-select" id="viewAssignedWitnessName">
                                    <option value="">— Select Witness —</option>
                                    <?php foreach ($witnesses as $w): ?>
                                        <option value="<?= htmlspecialchars($w['full_name']) ?>">
                                            <?= htmlspecialchars($w['position'].' — '.$w['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Required only for Beso Application.</div>
                            </div>
                        </section>
                        
                        <section class="card soft-card grid-col-2">
                            <div class="card-header soft-card-header d-flex justify-content-between align-items-center">
                                <span class="section-title"><i class="bi bi-person-check"></i> Captain Approval</span>
                                <small class="text-muted">Approve all of resident’s appointments for the date</small>
                            </div>
                            <div class="card-body">
                                <button id="approveCaptainBtn" type="button" class="btn btn-primary w-100">
                                    <i class="bi bi-check2-circle me-1"></i> Approve All (Captain)
                                </button>
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

    <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg"> 
            <form method="POST" action="" id="statusUpdateForm"> 
                <div class="modal-content rounded-4 shadow">
                    <div class="modal-header modal-accent text-white rounded-top-4"> 
                        <h5 class="modal-title" id="statusModalLabel"><i class="bi bi-arrow-repeat"></i> Change Status</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body bg-light p-4">
                        <input type="hidden" name="tracking_number" id="modalTrackingNumber">
                        <input type="hidden" name="certificate" id="modalCertificate">

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="newStatus" class="form-label fw-semibold">New Status</label>
                                <select name="new_status" id="newStatus" class="form-select rounded-3 shadow-sm" data-current-status="">
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                    <option value="ApprovedCaptain">Approved by Captain</option>
                                    <option value="Released">Released</option>
                                </select>
                            </div>
                            
                            <div class="col-12 col-md-6" id="cedulaNumberContainer" style="display: none;">
                                <label for="cedulaNumber" class="form-label fw-semibold">Cedula Number</label>
                                <input type="text" name="cedula_number" id="cedulaNumber" class="form-control shadow-sm rounded-3" placeholder="Enter Cedula Number">
                            </div>
                            
                            <div class="col-12" id="rejectionReasonContainer" style="display: none;">
                                <label for="rejectionReason" class="form-label fw-semibold">Rejection Reason</label>
                                <textarea class="form-control shadow-sm rounded-3" name="rejection_reason" id="rejectionReason" rows="2" placeholder="State reason for rejection..."></textarea>
                            </div>

                            <div class="col-12 col-md-6" id="modalKagawadGroup" style="display: none;">
                                <label for="modalAssignedKag" class="form-label fw-semibold">Assign Kagawad <small class="text-muted">(required for Cap. Appr.)</small></label>
                                <select name="assigned_kag_name" id="modalAssignedKag" class="form-select rounded-3 shadow-sm">
                                    <option value="">— Select Kagawad —</option>
                                    <?php foreach ($kagawads as $k): ?>
                                        <option value="<?= htmlspecialchars($k['full_name']) ?>"><?= htmlspecialchars($k['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-md-6 d-none" id="modalWitnessGroup">
                                <label for="modalWitnessSelect" class="form-label fw-semibold">Assign Witness <small class="text-muted">(required for Beso)</small></label>
                                <select class="form-select rounded-3 shadow-sm" name="assigned_witness_name" id="modalWitnessSelect">
                                    <option value="">— Select Witness —</option>
                                    <?php foreach ($witnesses as $w): ?>
                                        <option value="<?= htmlspecialchars($w['full_name']) ?>">
                                            <?= htmlspecialchars($w['position'].' — '.$w['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer bg-light rounded-bottom-4">
                        <button type="submit" name="update_status" class="btn btn-success w-100 rounded-pill shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Update Status
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>-->
    <script src="util/debounce.js"></script>
    <script>
    // ---------- Helpers (UI only) ----------
    const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g,'');

    // Apply soft badge styling to Status column (like reference)
    (function(){
        const map = {
            pending: 'badge-soft-warning',
            approved: 'badge-soft-info',
            approvedcaptain: 'badge-soft-primary',
            rejected: 'badge-soft-danger',
            released: 'badge-soft-success'
        };
        document.querySelectorAll('#appointmentTableBody td:nth-child(6)').forEach(td => {
            const raw = (td.textContent || '').trim();
            if (!raw) return;
            const key = normStatus(raw);
            if (!td.querySelector('.badge')) {
                const b = document.createElement('span');
                b.className = 'badge ' + (map[key] || 'badge-soft-secondary');
                b.textContent = raw;
                td.textContent = '';
                td.appendChild(b);
            }
        });
    })();

    // ---------- View modal wiring ----------
    document.querySelectorAll('[data-bs-target="#viewModal"]').forEach(button => {
        button.addEventListener('click', () => {
            const resId        = button.getAttribute('data-res-id');
            const selectedDate = button.getAttribute('data-selected-date');
            const assignedKag  = (button.getAttribute('data-assigned-kag-name') || '').trim();
            const assignedWit  = (button.getAttribute('data-assigned-witness-name') || '').trim();
            const certRaw      = (button.getAttribute('data-certificate') || '').trim().toLowerCase();
            const needsWitness = (certRaw === 'beso application');

            // Toggle witness section visibility
            const witnessSection = document.getElementById('witnessSection');
            if (witnessSection) witnessSection.classList.toggle('d-none', !needsWitness);

            // Populate same-day appointments list (server snippet output)
            fetch(`Search/get_resident_appointments.php?res_id=${encodeURIComponent(resId)}&selected_date=${encodeURIComponent(selectedDate)}`)
                .then(res => res.text())
                .then(html => {
                    const ul = document.getElementById('sameDayAppointments');
                    ul.innerHTML = html || '<li class="list-group-item text-muted">No appointments for this resident on this day.</li>';
                }).catch(()=> {
                    document.getElementById('sameDayAppointments').innerHTML = '<li class="list-group-item text-muted">Failed to load.</li>';
                });

            // Populate case history block
            fetch(`Search/get_cases.php?res_id=${encodeURIComponent(resId)}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('caseHistoryContainer').innerHTML = html || '<p class="text-muted px-3 py-2 mb-0">No case records for this resident.</p>';
                }).catch(()=> {
                    document.getElementById('caseHistoryContainer').innerHTML = '<p class="text-muted px-3 py-2 mb-0">Failed to load case history.</p>';
                });

            // Preselect Kagawad if already assigned
            const kagSel = document.getElementById('viewAssignedKagName');
            if (kagSel) {
                const match = Array.from(kagSel.options).find(o => (o.value||'').trim() === assignedKag);
                kagSel.value = match ? match.value : '';
            }

            // Preselect Witness if already assigned
            const witSel = document.getElementById('viewAssignedWitnessName');
            if (witSel) {
                const matchWit = Array.from(witSel.options).find(o => (o.value||'').trim() === assignedWit);
                witSel.value = matchWit ? matchWit.value : '';
            }

            // Captain bulk approve button flow  ✅ FIXED
            const approveBtn = document.getElementById('approveCaptainBtn');
            if (approveBtn) {
                approveBtn.onclick = () => {
                    const pickedKag = (kagSel ? kagSel.value.trim() : '');
                    const pickedWit = (witSel ? witSel.value.trim() : '');

                    if (!pickedKag) {
                        Swal.fire({ icon: 'warning', title: 'Assign Kagawad', text: 'Please select a Kagawad before approving as Captain.' });
                        return;
                    }
                    if (needsWitness && !pickedWit) {
                        Swal.fire({ icon: 'warning', title: 'Assign Witness', text: 'Please select a Witness/Secretary before approving Beso Application.' });
                        return;
                    }

                    Swal.fire({
                        title: 'Approve All?',
                        text: 'Approve all appointments for this resident on this date?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, approve all',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#28a745'
                    }).then(result => {
                        if (!result.isConfirmed) return;

                        Swal.fire({
                            title: 'Approving...',
                            text: 'Please wait while we update the records.',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });

                        const body = new URLSearchParams({
                            res_id: resId,
                            selected_date: selectedDate,
                            assignedKagName: pickedKag
                        });
                        if (needsWitness) body.append('assignedWitnessName', pickedWit);

                        fetch('Search/approve_captain.php', {
                          method: 'POST',
                          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                          body: body.toString()
                        })
                        .then(async (res) => {
                          const raw = await res.text();
                          let data;
                          try { data = JSON.parse(raw); } catch { data = { success: res.ok, message: raw }; }
                          const ok = (data && typeof data.success === 'boolean') ? data.success : res.ok;
                          return { ok, message: data.message || (ok ? 'Approved.' : 'Blocked.') };
                        })
                        .then(({ ok, message }) => {
                          Swal.fire({
                            icon: ok ? 'success' : 'error',
                            title: ok ? 'Done' : 'Blocked',
                            text: message,
                            confirmButtonColor: ok ? '#3085d6' : '#d33'
                          }).then(() => { if (ok) location.reload(); });
                        })
                        .catch((err) => {
                          console.error(err);
                          Swal.fire({ icon:'error', title:'Error', text:'Something went wrong while approving appointments.' });
                        });
                    });
                };
            }
        });
    });

    // ---------- Status modal (UPDATED with Kagawad/Witness logic) ----------
    document.querySelectorAll('[data-bs-target="#statusModal"]').forEach(button => {
        button.addEventListener('click', () => {
            const trackingNumber = button.getAttribute('data-tracking-number') || '';
            const certificate = button.getAttribute('data-certificate') || '';
            const cedulaNumber = button.getAttribute('data-cedula-number') || '';
            const currentStatus = button.getAttribute('data-current-status') || 'Pending';
            const assignedKag = (button.getAttribute('data-assigned-kag-name') || '').trim();
            const assignedWit = (button.getAttribute('data-assigned-witness-name') || '').trim();

            const isCedula = certificate.toLowerCase() === 'cedula';
            const isBeso   = certificate.toLowerCase() === 'beso application';

            // Seed hidden inputs
            document.getElementById('modalTrackingNumber').value = trackingNumber;
            document.getElementById('modalCertificate').value = certificate;

            // Seed main inputs
            const cedulaInput = document.getElementById('cedulaNumber');
            const statusSelect = document.getElementById('newStatus');
            const kagSelect = document.getElementById('modalAssignedKag');
            const witSelect = document.getElementById('modalWitnessSelect');
            
            if (cedulaInput) cedulaInput.value = cedulaNumber;
            if (statusSelect) statusSelect.value = currentStatus;
            if (kagSelect) kagSelect.value = assignedKag;
            if (witSelect) witSelect.value = assignedWit;

            const cedulaContainer    = document.getElementById('cedulaNumberContainer');
            const rejectionContainer = document.getElementById('rejectionReasonContainer');
            const kagContainer       = document.getElementById('modalKagawadGroup');
            const witContainer       = document.getElementById('modalWitnessGroup');
            
            // UI Toggler function
            const checkStatus = () => {
                const newStatus = statusSelect.value;
                const isApprovedCap = newStatus === 'ApprovedCaptain';
                
                if (cedulaContainer) {
                    cedulaContainer.style.display = (isCedula && (newStatus === 'Released' || newStatus === 'Approved')) ? 'block' : 'none';
                }
                if (rejectionContainer) {
                    rejectionContainer.style.display = (newStatus === 'Rejected') ? 'block' : 'none';
                }
                
                // Show assignments for Captain Approval (non-cedula)
                if (kagContainer) {
                    kagContainer.style.display = (isApprovedCap && !isCedula) ? 'block' : 'none';
                    kagContainer.querySelector('select').required = (isApprovedCap && !isCedula);
                }
                if (witContainer) {
                    const showWitness = (isApprovedCap && !isCedula && isBeso);
                    witContainer.classList.toggle('d-none', !showWitness);
                    witContainer.querySelector('select').required = showWitness;
                }

                // Disable "Released" unless already ApprovedCaptain
                const releasedOpt = statusSelect.querySelector('option[value="Released"]');
                if(releasedOpt) {
                    releasedOpt.disabled = (currentStatus !== 'ApprovedCaptain');
                }
            };

            // Force checkStatus() on initial load and setup event listener
            statusSelect.removeEventListener('change', checkStatus);
            statusSelect.addEventListener('change', checkStatus);
            checkStatus(); // initial sync
        });
    });

    // NEW: Client-side validation for Status Modal
    const statusForm = document.getElementById('statusUpdateForm');
    if (statusForm) {
        statusForm.addEventListener('submit', function(e) {
            const statusSelect = document.getElementById('newStatus');
            const kagSelect = document.getElementById('modalAssignedKag');
            const witSelect = document.getElementById('modalWitnessSelect');
            const certificate = document.getElementById('modalCertificate').value.toLowerCase();

            const isCedula = certificate === 'cedula';
            const isBeso = certificate === 'beso application';

            if (statusSelect.value === 'ApprovedCaptain' && !isCedula) {
                if (!kagSelect.value) {
                    e.preventDefault();
                    Swal.fire({icon:'warning', title:'Assign Kagawad', text:'Please select a Kagawad before approving.'});
                    return;
                }
                if (isBeso && !witSelect.value) {
                    e.preventDefault();
                    Swal.fire({icon:'warning', title:'Assign Witness', text:'Please select a Witness (Secretary) before approving.'});
                    return;
                }
            }
        });
    }

    // Log view helper (unchanged, just scoped)
    function logAppointmentView(residentId) {
        fetch('./logs/logs_trig.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `filename=3&viewedID=${encodeURIComponent(residentId)}`
        }).then(res => res.text())
            .then(data => console.log("Appointment view logged:", data))
            .catch(err => console.error("Log view error:", err));
    }
    
    // Search filter JS
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function (e) {
            const term = e.target.value.toLowerCase();
            const tableBody = document.getElementById('appointmentTableBody');
            if (!tableBody) return;
            
            let hasVisibleRows = false;
            tableBody.querySelectorAll('tr').forEach(row => {
                const name = (row.dataset.fullname || '').toLowerCase();
                const tracking = (row.dataset.trackingNumber || '').toLowerCase();
                
                const isVisible = name.includes(term) || tracking.includes(term);
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) hasVisibleRows = true;
            });
            
            const noResultsRow = tableBody.querySelector('.no-results-row');
            if (!hasVisibleRows && !noResultsRow) {
                const tr = document.createElement('tr');
                tr.className = 'no-results-row';
                tr.innerHTML = `<td colspan="7" class="text-center text-muted py-4">No appointments match your search</td>`;
                tableBody.appendChild(tr);
            } else if (hasVisibleRows && noResultsRow) {
                noResultsRow.remove();
            }
        }, 300));
    }
    </script>

</body>
</html>
