<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

include 'class/session_timeout.php';
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");


require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');

/* ============== Pagination + Filters (read) ============== */
$results_per_page = 100;
$page  = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? max(1, (int)$_GET['pagenum']) : 1;
$offset = ($page - 1) * $results_per_page;

$date_filter    = $_GET['date_filter']   ?? 'today'; // today|this_week|next_week|this_month|this_year
$status_filter = $_GET['status_filter'] ?? '';     // Pending|Approved|Rejected|Released|ApprovedCaptain
$search_term    = trim($_GET['search'] ?? '');     // name or tracking

/* ====================== Delete (archive flag) ====================== */
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

    echo "<script>
        alert('Appointment archived.');
        window.location = '" . enc_page('view_appointments') . "';
    </script>";
    exit;
}

/* ====================== Status update ====================== */
/* ====================== Status update ====================== */
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $new_status      = $_POST['new_status'];
    $certificate     = $_POST['certificate'];
    $cedula_number   = trim($_POST['cedula_number'] ?? '');
    $rejection_reason= trim($_POST['rejection_reason'] ?? '');
    $employee_id     = $_SESSION['employee_id'] ?? null;

    /* -----------------------------------------------------------
       1. NEW BLOCKING LOGIC: CHECK FOR NON-APPEARANCE
       ----------------------------------------------------------- */
    // A. Identify the resident ID first
    $resIdQuery = "
        SELECT res_id FROM schedules WHERE tracking_number = ?
        UNION SELECT res_id FROM cedula WHERE tracking_number = ?
        UNION SELECT res_id FROM urgent_request WHERE tracking_number = ?
        UNION SELECT res_id FROM urgent_cedula_request WHERE tracking_number = ?
        LIMIT 1
    ";
    $stmtRes = $mysqli->prepare($resIdQuery);
    $stmtRes->bind_param("ssss", $tracking_number, $tracking_number, $tracking_number, $tracking_number);
    $stmtRes->execute();
    $resResult = $stmtRes->get_result();
    $resRow = $resResult->fetch_assoc();
    $stmtRes->close();

    if ($resRow) {
        $resident_id = $resRow['res_id'];

        // B. Get Resident Name
        $nameQ = $mysqli->prepare("SELECT first_name, last_name, middle_name, suffix_name FROM residents WHERE id = ?");
        $nameQ->bind_param("i", $resident_id);
        $nameQ->execute();
        $resInfo = $nameQ->get_result()->fetch_assoc();
        $nameQ->close();

        if ($resInfo) {
            $fname = trim($resInfo['first_name']);
            $lname = trim($resInfo['last_name']);
            
            // C. Check case_participants for 'Respondent' AND 'Non-Appearance'
            $blockSql = "
                SELECT COUNT(*) as count 
                FROM case_participants 
                WHERE LOWER(first_name) = LOWER(?) 
                  AND LOWER(last_name) = LOWER(?) 
                  AND role = 'Respondent' 
                  AND action_taken = 'Non-Appearance'
            ";
            
            $blockStmt = $mysqli->prepare($blockSql);
            $blockStmt->bind_param("ss", $fname, $lname);
            $blockStmt->execute();
            $blockRes = $blockStmt->get_result()->fetch_assoc();
            $blockStmt->close();

            if ($blockRes['count'] > 0) {
                echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Action Blocked',
                        text: 'Cannot update status. This resident is marked as a Respondent with Non-Appearance in a pending case.',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location = '" . enc_page('view_appointments') . "';
                    });
                </script>";
                exit; // STOP EXECUTION HERE
            }
        }
    }
    /* ---------------- END BLOCKING LOGIC ---------------- */

    // Check if urgent cedula
    $checkUrgentCedula = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE tracking_number = ?");
    $checkUrgentCedula->bind_param("s", $tracking_number);
    $checkUrgentCedula->execute();
    $checkUrgentCedula->bind_result($isUrgentCedula);
    $checkUrgentCedula->fetch();
    $checkUrgentCedula->close();

    // (Optional) duplicate cedula number check when approving
    if (($isUrgentCedula > 0 || $certificate === 'Cedula') && $new_status === 'Approved' && !empty($cedula_number)) {
        $user_id = $_SESSION['user_id'] ?? 0; // ensure defined
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

    // Perform the status update based on type/urgency
    if ($isUrgentCedula > 0) {
        if ($new_status === 'Rejected') {
            $query = "UPDATE urgent_cedula_request 
                        SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                        WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            // If releasing, make sure issuance fields are set
            if ($new_status === 'Released') {
                $issued_on       = date('Y-m-d');
                $issued_at_place = 'Barangay Bugo, Cagayan de Oro City';
                $query = "UPDATE urgent_cedula_request 
                            SET cedula_status = ?, cedula_number = ?,
                                issued_on = CASE WHEN issued_on IS NULL OR issued_on='0000-00-00' THEN ? ELSE issued_on END,
                                issued_at = CASE WHEN issued_at IS NULL OR issued_at='' THEN ? ELSE issued_at END,
                                rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                            WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssssis", $new_status, $cedula_number, $issued_on, $issued_at_place, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE urgent_cedula_request 
                            SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                            WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
            }
        }
    } elseif ($certificate === 'Cedula') {
        if ($new_status === 'Rejected') {
            $query = "UPDATE cedula 
                        SET cedula_status = ?, rejection_reason = ?, is_read = 0, notif_sent = 1, employee_id = ?
                        WHERE tracking_number = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("ssis", $new_status, $rejection_reason, $employee_id, $tracking_number);
        } else {
            if ($new_status === 'Released') {
                $issued_on       = date('Y-m-d');
                $issued_at_place = 'Barangay Bugo, Cagayan de Oro City';
                $query = "UPDATE cedula 
                            SET cedula_status = ?, cedula_number = ?,
                                issued_on = CASE WHEN issued_on IS NULL OR issued_on='0000-00-00' THEN ? ELSE issued_on END,
                                issued_at = CASE WHEN issued_at IS NULL OR issued_at='' THEN ? ELSE issued_at END,
                                rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                            WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssssis", $new_status, $cedula_number, $issued_on, $issued_at_place, $employee_id, $tracking_number);
            } else {
                $query = "UPDATE cedula 
                            SET cedula_status = ?, cedula_number = ?, rejection_reason = NULL, is_read = 0, notif_sent = 1, employee_id = ?
                            WHERE tracking_number = ?";
                $stmt = $mysqli->prepare($query);
                $stmt->bind_param("ssis", $new_status, $cedula_number, $employee_id, $tracking_number);
            }
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

    // Determine appointment source for notifications
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
        $rowe = $result_email->fetch_assoc();
        $email          = $rowe['email'];
        $resident_name  = $rowe['full_name'];
        $contact_number = $rowe['contact_number'];

        // Email (PHPMailer)
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
        $sms_options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $sms_data,
            ],
        ];
        $sms_context = stream_context_create($sms_options);
        $sms_result  = file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);

        if ($sms_result !== FALSE) {
            $sms_response = json_decode($sms_result, true);
            $status = $sms_response[0]['status'] ?? 'unknown';
            $log_query = "INSERT INTO sms_logs (recipient_name, contact_number, message, status) VALUES (?, ?, ?, ?)";
            $log_stmt = $mysqli->prepare($log_query);
            $log_stmt->bind_param("ssss", $resident_name, $contact_number, $sms_message, $status);
            $log_stmt->execute();
        } else {
            error_log("❌ SMS failed to send to $contact_number");
        }
    }

    echo "<script>
        alert('Status updated to $new_status');
        window.location = '" . enc_page('view_appointments') . "';
    </script>";
    exit;
}

/* ====================== Auto-archive housekeeping ====================== */
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
    WHERE cedula_status = 'Released' AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteCedula = "
    DELETE FROM cedula
    WHERE cedula_status = 'Released' AND YEAR(issued_on) < YEAR(CURDATE())
";
$mysqli->query($archiveCedula);
$mysqli->query($deleteCedula);

$archiveUrgentCedula = "
    INSERT INTO archived_urgent_cedula_request
    SELECT * FROM urgent_cedula_request
    WHERE cedula_status = 'Released' AND YEAR(issued_on) < YEAR(CURDATE())
";
$deleteUrgentCedula = "
    DELETE FROM urgent_cedula_request
    WHERE cedula_status = 'Released' AND YEAR(issued_on) < YEAR(CURDATE())
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
$mysqli->query("UPDATE cedula SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Pending','Approved', 'ApprovedCaptain','Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status = 1 WHERE selected_date < CURDATE() AND status IN ('Released', 'Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status = 1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Pending','Approved', 'ApprovedCaptain','Rejected')");

/* ====================== Officials / Logos / Barangay Info ====================== */
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name, b.status
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status = 'active' 
          AND b.position NOT LIKE '%Lupon%'
          AND b.position NOT LIKE '%Barangay Tanod%'
          AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position, 'Punong Barangay','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','SK Chairman','Secretary','Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult && $offresult->num_rows > 0) {
    while ($row = $offresult->fetch_assoc()) {
        $officials[] = [
            'position' => $row['position'],
            'name'     => $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']
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

/* ====================== Witnesses (Sec / Exec Sec) ====================== */
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

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo = $logo_result && $logo_result->num_rows > 0 ? $logo_result->fetch_assoc() : null;

$citySql = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status = 'active' LIMIT 1";
$cityResult = $mysqli->query($citySql);
$cityLogo = $cityResult && $cityResult->num_rows > 0 ? $cityResult->fetch_assoc() : null;

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
    } else {
        $cityMunicipalityName = strtoupper($cityMunicipalityName);
    }
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName));
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
    ? ($councilTermResult->fetch_assoc()['council_term'] ?? '#')
    : '#';

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
                  FROM barangay_information b
                  INNER JOIN residents r ON b.official_id = r.id
                  WHERE b.status = 'active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);
$lupon_official = null;
$barangay_tanod_official = null;
if ($lupon_result && $lupon_result->num_rows > 0) {
    while ($lr = $lupon_result->fetch_assoc()) {
        if (stripos($lr['position'], 'Lupon') !== false) {
            $lupon_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        }
        if (stripos($lr['position'], 'Barangay Tanod') !== false || stripos($lr['position'], 'Barangay Police') !== false) {
            $barangay_tanod_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        }
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

/* ====================== FILTERED QUERY + COUNT (SQL) ====================== */
$unionSql = "
  -- 1) Urgent Cedula
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
    CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname) AS signatory_name,
    er.Role_Name AS signatory_position,
    /* no Kagawad for urgent_cedula rows */
    NULL AS assigned_kag_name,
    NULL AS assigned_witness_name,
    NULL AS oneness_fullname
  FROM urgent_cedula_request ucr
  JOIN residents r ON ucr.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = ucr.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id       = el.Role_id
  WHERE ucr.cedula_delete_status = 0 AND ucr.appointment_date >= CURDATE()

  UNION ALL

  -- 2) Regular Schedules
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
    CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname) AS signatory_name,
    er.Role_Name AS signatory_position,
    s.assignedKagName AS assigned_kag_name,
    s.assigned_witness_name,
    s.oneness_fullname AS oneness_fullname
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  LEFT JOIN cedula           c  ON c.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = s.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id       = el.Role_id
  WHERE s.appointment_delete_status = 0 AND s.selected_date >= CURDATE()

  UNION ALL

  -- 3) Cedula Appointments
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
    CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname) AS signatory_name,
    er.Role_Name AS signatory_position,
    /* no Kagawad for regular cedula rows */
    NULL AS assigned_kag_name,
    NULL AS assigned_witness_name,
    NULL AS oneness_fullname
  FROM cedula c
  JOIN residents r ON c.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = c.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id       = el.Role_id
  WHERE c.cedula_delete_status = 0 AND c.appointment_date >= CURDATE()

  UNION ALL

  -- 4) Urgent Requests
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
    CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname) AS signatory_name,
    er.Role_Name AS signatory_position,
    u.assignedKagName AS assigned_kag_name,
    u.assigned_witness_name,
    u.oneness_fullname AS oneness_fullname
  FROM urgent_request u
  JOIN residents r ON u.res_id = r.id
  LEFT JOIN cedula                c  ON c.res_id = r.id AND c.cedula_status = 'Approved'
  LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
  LEFT JOIN employee_list         el ON el.employee_id = u.employee_id
  LEFT JOIN employee_roles        er ON er.Role_Id       = el.Role_id
  WHERE u.urgent_delete_status = 0 AND u.selected_date >= CURDATE()
";




$whereParts = [];
$types = '';
$vals  = [];

// Date filter
switch ($date_filter) {
  case 'today':
    $whereParts[] = "selected_date = CURDATE()";
    break;
  case 'this_week':
    $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(CURDATE(), 1)";
    break;
  case 'next_week':
    $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1)";
    break;
  case 'this_month':
    $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE()) AND MONTH(selected_date) = MONTH(CURDATE())";
    break;
  case 'this_year':
    $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE())";
    break;
  default: /* none */ break;
}

// Status filter
if ($status_filter !== '') {
  $whereParts[] = "status = ?";
  $types .= 's';
  $vals[]  = $status_filter;
}

// Search term
if ($search_term !== '') {
  $whereParts[] = "(tracking_number LIKE ? OR fullname LIKE ?)";
  $types .= 'ss';
  $like = "%$search_term%";
  $vals[] = $like;
  $vals[] = $like;
}
$whereParts[] = "status <> 'Rejected'";
$whereSql = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

// Count
$countSql = "SELECT COUNT(*) AS total FROM ($unionSql) AS all_appointments $whereSql";
$stmt = $mysqli->prepare($countSql);
if ($types !== '') { $stmt->bind_param($types, ...$vals); }
$stmt->execute();
$total_results = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $results_per_page; }

// Page data
$listSql = "
WITH all_appointments AS (
  $unionSql
),
ranked AS (
  SELECT a.*,
         ROW_NUMBER() OVER (PARTITION BY tracking_number ORDER BY src_priority) AS rn
  FROM all_appointments a
)
SELECT *
FROM ranked
$whereSql
AND rn = 1
ORDER BY
  (status='Pending' AND selected_time='URGENT' AND selected_date=CURDATE()) DESC,
  (status='Pending' AND selected_date=CURDATE()) DESC,
  selected_date ASC, selected_time ASC,
  FIELD(status,'Pending','Approved','Rejected')
LIMIT ? OFFSET ?";


$stmt = $mysqli->prepare($listSql);
$typesList = $types . 'ii';
$valsList  = array_merge($vals, [ $results_per_page, $offset ]);
$stmt->bind_param($typesList, ...$valsList);
$stmt->execute();
$result = $stmt->get_result();

$filtered_appointments = [];
while ($row = $result->fetch_assoc()) {
    $filtered_appointments[] = $row;
}
$stmt->close();

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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Appointment List</title>

  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <link rel="stylesheet" href="css/styles.css" />
  <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />

  <style>
.signatory-wrap{width:48%;text-align:center;position:relative;}
.pb-name,.pb-title{position:relative;z-index:1;margin:0;}
.pb-name{font-size:30px;}
.pb-title{font-size:20px;margin-bottom:6px;}
/* PB signature sits ON TOP of the name */
.pb-signature{
  position:absolute; top:-14px; left:50%; transform:translateX(-50%);
  width:150px; height:auto; z-index:3; pointer-events:none;
}

.authority-note{font-size:14px;margin-top:6px;}
.auth{position:relative;margin-top:10px;}
.auth-name,.auth-title{position:relative;z-index:1;margin:0;}
.auth-name{font-size:22px;}
.auth-title{font-size:16px;margin-bottom:8px;}
/* Authorized signature sits ON TOP of the delegate's name */
.auth-signature{
  position:absolute; top:-26px; left:50%; transform:translateX(-50%);
  width:140px; height:auto; z-index:3; pointer-events:none;
}
</style>
</head>
<body>
  <div class="container my-4 app-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <h2 class="page-title m-0"><i class="bi bi-card-list me-2"></i>Appointment List</h2>
      <span class="small text-muted d-none d-md-inline">Manage filters, search, and quick actions</span>
    </div>

    <div class="card card-filter mb-3 shadow-sm">
      <div class="card-body py-3">
        <form method="GET" action="index_Admin.php" class="row g-2 align-items-end">
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
    <div class="table-edge">                <div class="table-scroll">            <table class="table table-hover align-middle mb-0" id="appointmentsTable">
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
            <?php if (count($filtered_appointments) > 0): ?>
              <?php foreach ($filtered_appointments as $row): ?>
                <?php include 'components/appointment_row.php'; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">No appointments found</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
  <?php
    // Build preserved query string excluding pagenum
    $pageBase = enc_page('view_appointments');
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

      <?php if ($start > 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <?php for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
        </li>
      <?php endfor; ?>

      <?php if ($end < $total_pages): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

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
                  
                  <div class="col-12 col-md-6 d-none" id="assignWitnessGroup">
                    <label for="assignWitnessSelect" class="form-label">Assign Witness (required for BESO)</label>
                    <select class="form-select" name="assigned_witness_name" id="assignWitnessSelect">
                      <option value="">— Select Witness —</option>
                      <?php foreach ($witnesses as $w): ?>
                        <option value="<?= htmlspecialchars($w['full_name']) ?>">
                          <?= htmlspecialchars($w['position'].' — '.$w['full_name']) ?>
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
            <input type="hidden" name="update_status" value="1">

            <div class="mb-3">
              <label for="newStatus" class="form-label fw-semibold">New Status</label>
              <select name="new_status" id="newStatus" class="form-select rounded-3 shadow-sm" data-current-status="">
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="Released">Released</option>
                <option value="ApprovedCaptain">ApprovedCaptain</option>
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

      <h5 class="pb-name"><u><strong>${pbNm}</strong></u></h5>
      <p class="auth-title">PUNONG BARANGAY</p>

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
  assignedWitnessName = "", oneness_fullname = ""
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
        
        <style>
            /* 1. UPDATED CSS: Set layers properly */
            .container { 
                position: relative; 
            }
            .watermark-logo {
                position: absolute;
                top: 57%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 65%;
                opacity: 0.20;
                z-index: 0;   /* Sits above paper, behind text */
                pointer-events: none;
            }
            /* 2. IMPORTANT: Force text and images to sit ON TOP */
            header, section, .two-col, .photo-2x2, .footer {
                position: relative;
                z-index: 2;
            }
        </style>
      </head>
      <body>
        <div class="container" id="printArea">
        
          <?php if ($logo): ?>
              <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
          <?php endif; ?>

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
        <section class="col-48" style="line-height:1.8;">
          <p><strong>Community Tax No.:</strong> ${escapeHtml(cedula_number)}</p>
          <p><strong>Issued on:</strong> ${issued_on ? new Date(issued_on).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : ''}</p>
          <p><strong>Issued at:</strong> ${escapeHtml(issued_at)}</p>
        </section>

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
      .photo-2x2 {
        width: 2in; height: 2in;        /* exact 2x2 inches */
        object-fit: cover;              /* fill without distortion */
        border-radius: 0;               /* NOT circular */
        border: 1px solid #000;         /* optional passport-style border */
        display: block;
        position: relative;             /* Important for layering */
        z-index: 2;                     /* Sit on top of watermark */
      }

      /* 1. UPDATED CSS: Set layers properly */
      .container { 
          position: relative; 
      }
      .watermark-logo {
          position: absolute;
          top:57%;
          left: 50%;
          transform: translate(-50%, -50%);
          width: 60%;
          opacity: 0.20;
          z-index: 0;   /* Sits above paper, behind text */
          pointer-events: none;
      }
      /* 2. IMPORTANT: Force text and sections to sit ON TOP */
      header, section, .footer, .two-col {
          position: relative;
          z-index: 2;
      }
    </style>
  </head>
  <body>
    <div class="container" id="printArea">
    
      <?php if ($logo): ?>
          <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
      <?php endif; ?>

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
                
                <style>
                    /* 1. UPDATED CSS: Uses z-index 0 to ensure it stays visible */
                    .container { 
                        position: relative; 
                    }
                    .watermark-logo {
                        position: absolute;
                        top: 60%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        width: 57%;
                        opacity: 0.20; /* Adjust transparency here */
                        z-index: 0;   /* Changed from -1 to 0 to prevent hiding */
                        pointer-events: none;
                    }
                    /* Ensure text sits on top of the watermark */
                    header, section, .two-col, .footer {
                        position: relative;
                        z-index: 2;
                    }
                </style>
            </head>
            <body>
                <div class="container" id="printArea">
                
                    <?php if ($logo): ?>
                        <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
                    <?php endif; ?>

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
            <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo"   >
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
    // 1. Format dates properly
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';

    printAreaContent = `
        <html>
            <head>
                <link rel="stylesheet" href="css/form.css">
                <link rel="stylesheet" href="css/print/print.css">
                
                <style>
                    /* 2. ADD THIS STYLE: Ensures Watermark is visible */
                    .container { 
                        position: relative; 
                    }
                    .watermark-logo {
                        position: absolute;
                        top: 60%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        width: 57%;
                        opacity: 0.20; 
                        z-index: 0;   /* Sits above the white paper background */
                        pointer-events: none;
                    }
                    /* Ensure text sits on top of the watermark */
                    header, section, .footer, .two-col {
                        position: relative;
                        z-index: 2;
                    }
                </style>
            </head>
            <body>
                <div class="container" id="printArea">
                
                    <?php if ($logo): ?>
                        <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
                    <?php endif; ?>

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
} else if (certificate.trim().toLowerCase() === "certification of oneness") {

  // 1) Format date strings similar to other certs (optional)
  const formattedIssuedOn = issued_on
    ? new Date(issued_on).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' })
    : 'N/A';

  // 2) Age
  let ageText = "N/A";
  if (birth_date) {
    const dob = new Date(birth_date);
    if (!isNaN(dob)) {
      const diff = Date.now() - dob.getTime();
      const age = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
      ageText = `${age}`;
    }
  }

  // 3) month upper like your sample
  const monthUpper = (month || "").toUpperCase();

  // 4) Oneness other-name (SAFE fallback so it never crashes)
  // ✅ If you haven't passed it yet, this will just show blank line.
  const onenessName = (typeof oneness_fullname !== 'undefined' ? (oneness_fullname || '') : '').trim();

  // 5) Purpose
  const purposeUpper = (purpose || "IDENTIFICATION").toUpperCase();

  printAreaContent = `
    <html>
      <head>
        <link rel="stylesheet" href="css/form.css">
        <link rel="stylesheet" href="css/print/print.css">

        <style>
          /* Same watermark layering as Barangay Residency */
          .container { position: relative; }
          .watermark-logo {
            position: absolute;
            top: 60%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 57%;
            opacity: 0.20;
            z-index: 0;
            pointer-events: none;
          }
          header, section, .footer, .two-col {
            position: relative;
            z-index: 2;
          }
        </style>
      </head>

      <body>
        <div class="container" id="printArea">

          <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
          <?php endif; ?>

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
                <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                <h3><strong><?php echo $barangayName; ?></strong></h3>                
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
            <h4 style="text-align:center;font-size:50px;"><strong>CERTIFICATION of ONENESS</strong></h4>
            <p>TO WHOM IT MAY CONCERN:</p><br>

            <p>
              THIS IS TO CERTIFY that <strong>${fullname}</strong>, <strong>${ageText}</strong> years old,
              is a resident of <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>, Bugo, Cagayan de Oro City.
            </p>
            <br>

            <p>
              FURTHER CERTIFIES that the above-named person and the name
              <strong>${onenessName ? onenessName : '________________________'}</strong>
              is the same and one person.
            </p>
            <br>

            <p>
              This Certification is issued upon the request of the above-mentioned person
              for <strong>${purposeUpper}</strong> whatever legal purposes it may serve best.
            </p>
            <br>

            <p>
              Issued this <strong>${dayWithSuffix}</strong> day of <strong>${monthUpper}</strong>, <strong>${year}</strong>,
              at Barangay Bugo, Cagayan de Oro City.
            </p>
          </section>

          <br><br><br><br><br>

          <div style="display:flex; justify-content:space-between; margin-bottom:18px;">
            <section style="width:48%; line-height:1.8;">

            </section>

            <!-- ✅ Use your existing signature renderer (prevents captainName undefined) -->
            ${renderSignatorySection(isCaptainSignatory, assignedKagName)}
          </div>

        </div>
      </body>
    </html>
  `;
}

else if (certificate === "Barangay Clearance") {
    printAreaContent = `
    <html>
    <head>
        <link rel="stylesheet" href="css/clearance.css">
        <link rel="stylesheet" href="css/print/clearance.css">
        
        <style>
            .container { 
                position: relative; 
            }
            .watermark-logo {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 65%;  /* Resize as needed */
                opacity: 0.15; /* Transparency */
                z-index: 9999; /* Forces it to stay on top */
                pointer-events: none;
            }
            /* Ensure text sections are relative for proper stacking */
            header, .side-by-side, section, .footer {
                position: relative;
                z-index: 2;
            }
        </style>
    </head>
    <body>
        <div class="container" id="printArea">
        
        <?php if ($logo): ?>
            <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
        <?php endif; ?>

        <br>
        <br>
            <header>
                <div class="logo-header"> 
                    <?php if ($logo): ?>
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
                        <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
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
                            foreach ($officials as $official) {
                                if ($official['position'] == 'Punong Barangay') {
                                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                                    break;
                                }
                            }
                            for ($i = 1; $i <= 3; $i++) {
                                foreach ($officials as $official) {
                                    if ($official['position'] == $i . 'st Kagawad' || $official['position'] == $i . 'nd Kagawad' || $official['position'] == $i . 'rd Kagawad') {
                                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                                    }
                                }
                            }
                            for ($i = 4; $i <= 7; $i++) {
                                foreach ($officials as $official) {
                                    if ($official['position'] == $i . 'th Kagawad') {
                                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                                    }
                                }
                            }
                            foreach ($officials as $official) {
                                if ($official['position'] == 'SK Chairman') {
                                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                                    break;
                                }
                            }
                            foreach ($officials as $official) {
                                if ($official['position'] == 'Barangay Secretary') {
                                    echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                                    echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
                                    break;
                                }
                            }
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
                <div class="right-content">
                    <p>TO WHOM IT MAY CONCERN:</p>
                    <p>THIS IS TO CERTIFY that <strong>${fullname}</strong>, legal age, <strong>${civil_status}</strong>. 
                    Filipino citizen, is a resident of Barangay Bugo, this City, particularly in <strong>${res_zone}</strong>, <strong>${res_street_address}</strong>.</p><br>
                    <p>FURTHER CERTIFIES that the above-named person is known to be a person of good moral character and reputation as far as this office is concerned.
                    He/She has no pending case filed and blottered before this office.</p><br>
                    <p>This certification is being issued upon the request of the above-named person, in connection with his/her desire <strong>${purpose}</strong>.</p><br>

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

            <section style="margin-top: 20px; text-align: center;">
                <div style="display: flex; justify-content: left; gap: 20px;">
                    <div style="text-align: center; font-size:6px;" >
                        <p><strong>Left Thumb:</strong></p>
                        <div style="border: 1px solid black; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center;">
                        </div>
                    </div>

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
        } else if (certificate.toLowerCase() === "beso application") {
  printAreaContent = `
    <html>
      <head>
        <link rel="stylesheet" href="css/form.css">
        <link rel="stylesheet" href="css/print/print.css">
        <link rel="stylesheet" href="css/print/oath.css">

        <style>
            /* 1. WATERMARK CSS */
            .container { 
                position: relative; 
            }
            .watermark-logo {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                
                /* RESIZE HERE: Change 600px to whatever size you want */
                width: 70%; 
                
                opacity: 0.15; /* Transparency */
                z-index: 9999; /* Forces it to stay on top */
                pointer-events: none;
            }
             /* 2. FONT CHANGE: Set to Courier New */
            body, p, div, span, h2, h3, h4, strong, u {
                font-family: "Courier New", Courier, monospace !important; 
            }
                 
        </style>
      </head>

      <body>
        <div class="container" id="printArea">

          <?php if ($logo): ?>
              <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" class="watermark-logo">
          <?php endif; ?>

          <header>
            <div class="logo-header">
              <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
              <?php endif; ?>
              <div class="header-text header-center">
                <h2><strong>Republic of the Philippines</strong></h2>
                <h3><strong><?php echo $cityMunicipalityName; ?></strong></h3>
                <h3><strong><?php echo $barangayName; ?></strong></h3>
                <h2><strong>OFFICE OF THE PUNONG BARANGAY</strong></h2>
                <p>Tel No.: <?php echo htmlspecialchars($telephoneNumber); ?>; Cell: <?php echo htmlspecialchars($mobileNumber); ?></p>
              </div>
              <?php if ($cityLogo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
              <?php endif; ?>
            </div>
          </header>
          <hr class="header-line">
          <div style="
                display:flex;
                justify-content:flex-end;
                font-size:14px;
                margin: 2px 0 10px 0;">
            <div><strong>Barangay Certificate Number.:</strong> ${escapeHtml(seriesNum || '')}</div>
          </div>          

          <section class="barangay-certification">
            <h4 style="text-align: center; font-size: 50px; margin-top: -10px;"><strong>BARANGAY CERTIFICATION</strong></h4>
            <p style="text-align: center; font-size: 18px; margin-top: -20px;">
              <em>(First Time Jobseeker Assistance Act - RA 11261)</em>
            </p>

            <p>This is to certify that <strong><u>${escapeHtml(fullname)}</u></strong>, ${escapeHtml(age)} years old is a resident of
              <strong>${escapeHtml(res_zone)}</strong>, <strong>${escapeHtml(res_street_address)}</strong>, Bugo, Cagayan de Oro City for
              <strong>${
                (() => {
                  const start = new Date(residency_start);
                  const today = new Date();
                  let years = today.getFullYear() - start.getFullYear();
                  const m = today.getMonth() - start.getMonth();
                  if (m < 0 || (m === 0 && today.getDate() < start.getDate())) years--;
                  return years + (years === 1 ? " year" : " years");
                })()
              }</strong>, is <strong>qualified</strong> availee of <strong>RA 11261</strong> or the <strong>First Time Jobseeker Act of 2019</strong>.</p>

            <p>Further certifies that the holder/bearer was informed of his/her rights, including the duties and responsibilities accorded by RA 11261 through the
              <strong>Oath of Undertaking</strong> he/she has signed and executed in the presence of a Barangay Official.</p>

            <p>This certification is issued upon the request of the above-named person for <strong>${escapeHtml(purpose)}</strong> purposes and is valid only until
              <strong>${
                (() => {
                  const d = new Date();
                  const valid = new Date(d.getFullYear() + 1, d.getMonth(), d.getDate());
                  return valid.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
                })()
              }</strong>.</p>

            <p>Signed this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>,
              at Barangay Bugo, Cagayan de Oro City.</p>
          </section>


          <div class="two-col" style="margin-bottom:18px;">
            <section class="col-48" style="line-height:1.8;">
              <p><em>Not valid without seal</em></p>
            </section>
            ${renderSignatorySection(isCaptainSignatory, assignedKagName)}
          </div>

        </div> <div class="page-break"></div>

        <div class="oath-page">
          <div class="oath-inner">
          <div class="oath-top-gap"></div>
            <header>
              <div class="oath-header">
                <?php if ($logo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Logo" class="logo">
                <?php else: ?><span></span><?php endif; ?>

                <div class="header-text">
                  <h2><strong>REPUBLIC OF THE PHILIPPINES</strong></h2>
                  <h3><strong>City of Cagayan de Oro</strong></h3>
                  <h3><strong>BARANGAY BUGO</strong></h3>
                  <h4><strong>OFFICE OF THE SANGGUNIANG BARANGAY</strong></h4>
                </div>

                <?php if ($cityLogo): ?>
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                <?php else: ?><span></span><?php endif; ?>
              </div>
            </header>

            <hr class="oath-rule">

            <section class="oath-wrap">
              <div class="oath-title">OATH OF UNDERTAKING</div>

              <p class="no-indent">
                I, <strong>${escapeHtml(fullname)}</strong>, <strong>${escapeHtml(age)}</strong> years of age, resident of
                <strong>Barangay BUGO</strong>, <strong>${escapeHtml(res_zone)}</strong>, Bugo, Cagayan de Oro City, availing the benefits of
                <strong>Republic Act 11261</strong>, otherwise known as the <strong>First Time Jobseekers Act of 2019</strong>, do hereby declare,
                agree and undertake to abide and be bound by the following:
              </p>

              <p class="clause">That this is the first time that I will actively look for a job, and therefore requesting that a Barangay Certification be issued in my favor to avail the benefits of the law.</p>
              <p class="clause">That I am aware that the benefits and privileges under the said law shall be valid only for one (1) year from the date that the Barangay Certification is issued.</p>
              <p class="clause">That I can avail the benefits of the law only once.</p>
              <p class="clause">That I understand that my personal information shall be included in the Roster/List of First Time Jobseekers and will not be used for any unlawful purpose.</p>
              <p class="clause">That I will inform and/or report to the Barangay personally, through text or other means, or through my family/relatives once I get employed.</p>
              <p class="clause">That I am not a beneficiary of the JobStart Program under R.A. No. 10869 and other laws that give similar exemptions for the documents or transactions exempted under R.A. No. 11261.</p>
              <p class="clause">That if issued the requested Certification, I will not use the same in any fraud, neither falsely help and/or assist in the fabrication of the said certification.</p>
              <p class="clause">That this undertaking is made solely for the purpose of obtaining a Barangay Certification consistent with the objective of R.A. No. 11261 and not for any other purpose.</p>
              <p class="clause">That I consent to the use of my personal information pursuant to the Data Privacy Act and other applicable laws, rules, and regulations.</p>

              <br>
              <p class="no-indent">
                Signed this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong> in the City/Municipality of
                <strong>Cagayan de Oro</strong> City.
              </p>
<div class="sig-row">
  <div class="sig-box sig-left">
    <div class="sig-line"></div>
    <div class="sig-caption">Witnessed By:</div>
    ${(() => {
        // Use the witness name string passed into the function
        const name = (assignedWitnessName || '').trim();
        
        // Use fallbacks if no witness was assigned
        const displayName = name || 'ENGR. BELEN B. BASADRE'; // Default fallback name
        // Determine position based on name, or default
        const displayTitle = name ? 'Barangay Secretary' : 'Barangay Executive Secretary';
        
        return `
          <div class="sig-name">${escapeHtml(displayName.toUpperCase())}</div>
          <div class="sig-sub">${escapeHtml(displayTitle)}</div>
        `;
    })()}
  </div>

  <div class="sig-box sig-right">
    <div class="sig-line"></div>
    <div class="sig-caption">First Time Jobseekers</div>
  </div>
</div>


    </section>
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
  b.classList.add('page-long');       // ← use this for 8.5×13
  printWindow.print();
};


    }
/* ====================== Helpers ====================== */
const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g, '');
const isCedulaType = t => {
  const k = normStatus(t);
  return (k === 'cedula' || k === 'urgentcedula');
};

/* Strict step-locking for status dropdown
   - pending          → only Approved / Rejected
   - approved         → only Approved by Captain
   - approvedcaptain  → only Released
   - released/rejected→ freeze all
*/
function lockStatusOptions(selectEl, currentStatusNorm){
  const q = v => selectEl.querySelector(`option[value="${v}"]`);
  const pendingOpt  = q('Pending');
  const approvedOpt = q('Approved');
  const rejectedOpt = q('Rejected');
  const releasedOpt = q('Released');
  const captainOpt  = q('ApprovedCaptain');

  // reset
  [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt]
    .forEach(o => o && (o.disabled = false));

  // PENDING → only Approved & Rejected
  if (currentStatusNorm === 'pending') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = false;
    if (rejectedOpt) rejectedOpt.disabled = false;
    if (captainOpt)  captainOpt.disabled  = true;
    if (releasedOpt) releasedOpt.disabled = true;
    return;
  }

  // base guard: Released only after ApprovedCaptain
  if (releasedOpt) releasedOpt.disabled = (currentStatusNorm !== 'approvedcaptain');

  // APPROVED → only Approved by Captain
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

  // RELEASED or REJECTED → freeze all
  if (currentStatusNorm === 'released' || currentStatusNorm === 'rejected') {
    [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt]
      .forEach(o => o && (o.disabled = true));
  }
}

/* =================== Page Script ===================== */
let currentAppointmentType = '';
let currentCedulaNumber    = '';

document.addEventListener('DOMContentLoaded', () => {
  /* Cache DOM nodes used in the View Modal workflow */
  const statusForm          = document.getElementById('statusUpdateForm');
  const statusSelect        = document.getElementById('statusSelect');
  const rejectionGroup      = document.getElementById('viewRejectionReasonGroup');
  const cedulaGroup         = document.getElementById('viewCedulaNumberContainer');
  const cedulaInput         = document.getElementById('viewCedulaNumber');
  const assignGroup         = document.getElementById('assignKagawadGroup');
  const assignSelect        = document.getElementById('assignKagawadSelect');
  const assignWitnessGroup  = document.getElementById('assignWitnessGroup');
  const assignWitnessSelect = document.getElementById('assignWitnessSelect');

  /* ---------- VIEW MODAL: toggle fields when status changes ---------- */
  statusSelect.addEventListener('change', () => {
    const selected = statusSelect.value;

    // Rejection textarea toggle
    const showReject = (selected === 'Rejected');
    if (rejectionGroup) {
      rejectionGroup.classList.toggle('d-none', !showReject);
      const rej = document.getElementById('viewRejectionReason');
      if (rej) rej.required = showReject;
    }

    // Cedula number is required only if Released + appointment is a Cedula type
    const showCedula = (selected === 'Released' && isCedulaType(currentAppointmentType));
    if (cedulaGroup) {
      cedulaGroup.classList.toggle('d-none', !showCedula);
      if (cedulaInput) {
        cedulaInput.required = showCedula;
        if (!showCedula) cedulaInput.value = '';
      }
    }

    // Assign Kagawad only when setting ApprovedCaptain for NON-Cedula types
    const showKag = (selected === 'ApprovedCaptain' && !isCedulaType(currentAppointmentType));
    if (assignGroup) {
      assignGroup.classList.toggle('d-none', !showKag);
      if (assignSelect) {
        if (showKag) assignSelect.setAttribute('required','required');
        else { assignSelect.removeAttribute('required'); assignSelect.value = ''; }
      }
    }

    // Assign Witness only when setting ApprovedCaptain for BESO
    const showWitness = (selected === 'ApprovedCaptain' && normStatus(currentAppointmentType) === 'besoapplication');
    if (assignWitnessGroup) {
      assignWitnessGroup.classList.toggle('d-none', !showWitness);
      if (assignWitnessSelect) {
        if (showWitness) assignWitnessSelect.setAttribute('required','required');
        else { assignWitnessSelect.removeAttribute('required'); assignWitnessSelect.value = ''; }
      }
    }
  });

  /* ---------- VIEW MODAL: open & populate ---------- */
  document.querySelectorAll('[data-bs-target="#viewModal"]').forEach(button => {
    button.addEventListener('click', () => {
      const trackingNumber = button.dataset.trackingNumber || '';
      const tnEl = document.getElementById('statusTrackingNumber');
      if (tnEl) tnEl.value = trackingNumber;

      fetch('./ajax/view_case_and_status.php?tracking_number=' + encodeURIComponent(trackingNumber))
        .then(res => res.json())
        .then(data => {
          if (!data?.success) {
            alert('❌ Failed to load appointment data.');
            return;
          }

          // --- 1. Basic Setup & Status Locking ---
          statusForm.dataset.currentStatus     = data.status || '';
          statusForm.dataset.currentStatusNorm = normStatus(data.status);
          statusSelect.value                   = data.status || '';

          // 🔒 Strict locking of allowed options
          lockStatusOptions(statusSelect, statusForm.dataset.currentStatusNorm);

          // Store appointment type + existing cedula
          const selectedAppt = (data.appointments || []).find(app => app.tracking_number === trackingNumber);
          currentAppointmentType = selectedAppt?.certificate || '';
          currentCedulaNumber    = selectedAppt?.cedula_number || '';

          // Pre-fill witness name if it exists (BESO)
          if (assignWitnessSelect) assignWitnessSelect.value = selectedAppt?.assigned_witness_name || '';

          // Trigger initial toggle state (to show/hide fields based on status)
          statusSelect.dispatchEvent(new Event('change'));


          // ---------------------------------------------------------
          // 2. NEW FRONTEND LOGIC: Check for Non-Appearance and Block UI
          // ---------------------------------------------------------
          const saveBtn = document.getElementById('saveStatusBtn');
          let isBlocked = false;

          // Reset UI first (clean slate)
          if (saveBtn) {
              saveBtn.disabled = false;
              saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Status';
              saveBtn.classList.remove('btn-secondary');
              saveBtn.classList.add('btn-success');
          }
          
          // Remove any existing warning alerts from previous opens
          const existingAlert = document.getElementById('blockAlert');
          if(existingAlert) existingAlert.remove();

          // Check cases for blocking condition
          if (data.cases && data.cases.length > 0) {
            data.cases.forEach(c => {
               if(c.participants && c.participants.length > 0) {
                   c.participants.forEach(p => {
                       // Normalize strings for comparison
                       const role = (p.role || '').trim();
                       const action = (p.action_taken || '').trim();

                       if (role === 'Respondent' && action === 'Non-Appearance') {
                           isBlocked = true;
                       }
                   });
               }
            });
          }

          // Apply Blocking UI if needed
          if (isBlocked && saveBtn) {
              // Disable the button
              saveBtn.disabled = true;
              saveBtn.classList.remove('btn-success');
              saveBtn.classList.add('btn-secondary');
              saveBtn.innerHTML = '<i class="bi bi-lock-fill"></i> BLOCKED (Non-Appearance)';

              // Show a visual warning inside the modal
              const alertHTML = `
                <div id="blockAlert" class="alert alert-danger d-flex align-items-center mt-3" role="alert">
                  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2"></i>
                  <div>
                    <strong>Action Forbidden:</strong> This resident has a "Non-Appearance" record as a Respondent. Status updates are disabled.
                  </div>
                </div>
              `;
              
              // Inject above the button inside the sticky-action container
              const formContainer = document.querySelector('#statusUpdateForm .sticky-action');
              if(formContainer) {
                  formContainer.insertAdjacentHTML('beforebegin', alertHTML);
              }
          }
          // ---------------------------------------------------------


          // --- 3. Render Case History ---
          const container = document.getElementById('caseHistoryContainer');
          if (container) {
            if (data.cases && data.cases.length) {
              container.innerHTML = '<ul class="list-group list-group-flush">' + data.cases.map(cs => {
                
                // Helper to format participant name
                const formatName = (p) => {
                   return [p.first_name, p.middle_name, p.last_name, p.suffix_name]
                     .filter(Boolean)
                     .join(' ');
                };

                // Generate Participants HTML
                let participantsHtml = '';
                if (cs.participants && cs.participants.length > 0) {
                    const listItems = cs.participants.map(p => {
                        
                        // 1. Action Taken Line
                        const actionLine = p.action_taken 
                            ? `<div class="text-muted mt-1" style="font-size:0.85em;">
                                 <i class="bi bi-check2-circle me-1 text-success"></i><strong>Action:</strong> ${p.action_taken}
                               </div>` 
                            : '';

                        // 2. Remarks Line
                        const remarksLine = p.remarks 
                            ? `<div class="text-muted mt-1" style="font-size:0.85em;">
                                 <i class="bi bi-chat-left-text me-1 text-info"></i><strong>Remarks:</strong> ${p.remarks}
                               </div>` 
                            : '';
                        
                        const detailsBlock = (actionLine || remarksLine) 
                            ? `<div class="ms-1 ps-3 border-start border-2 border-light mb-2">
                                 ${actionLine}
                                 ${remarksLine}
                               </div>` 
                            : '';

                        return `
                        <li class="mb-1">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-secondary-subtle text-dark border me-2" style="font-size:0.75em; min-width:85px;">${p.role}</span> 
                                <span class="fw-bold text-dark small">${formatName(p)}</span>
                            </div>
                            ${detailsBlock}
                        </li>`;
                    }).join('');
                    
                    participantsHtml = `
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <small class="fw-bold text-secondary d-block mb-2">
                                <i class="bi bi-people-fill me-1"></i>Participants
                            </small>
                            <ul class="list-unstyled ms-1 mb-0 small">
                                ${listItems}
                            </ul>
                        </div>
                    `;
                }

                return `
                  <li class="list-group-item p-3">
                    <div class="mb-2">
                        <strong class="text-primary"><i class="bi bi-folder2-open me-1"></i>Case #${cs.case_number}</strong> 
                        <span class="ms-1 badge bg-danger-subtle text-danger border border-danger-subtle">${cs.nature_offense}</span>
                    </div>
                    
                    <div class="small text-muted bg-light p-2 rounded border border-light-subtle d-flex flex-wrap gap-3">
                        <span><strong>Filed:</strong> ${cs.date_filed}</span> 
                        <span class="border-start mx-1"></span>
                        <span><strong>Hearing:</strong> ${cs.date_hearing || 'TBD'}</span>
                        <span class="border-start mx-1"></span>
                        <span><strong>Status:</strong> ${cs.action_taken || 'Pending'}</span>
                    </div>

                    ${participantsHtml}
                  </li>
                `;
              }).join('') + '</ul>';
            } else {
              container.innerHTML = '<p class="text-muted px-3 py-2 mb-0 text-center"><i class="bi bi-clipboard-x me-1"></i>No case records for this resident.</p>';
            }
          }

          // --- 4. Render Same-day Appointments ---
          const ul   = document.getElementById('sameDayAppointments');
          const peso = v => '₱' + Number(v).toLocaleString('en-PH');
          if (ul) {
            if (data.appointments && data.appointments.length) {
              ul.innerHTML = data.appointments.map(app => {
                const incomeHtml =
                  (String(app.certificate || '').toLowerCase() === 'cedula' && app.cedula_income)
                    ? `<div class="text-muted small mt-1"><i class="bi bi-cash-coin me-1"></i>Income: ${peso(app.cedula_income)}</div>`
                    : '';
                return `
                  <li class="list-group-item">
                    <strong class="text-dark">${app.certificate}</strong><br>
                    <small class="text-muted">Tracking #: <code class="text-primary">${app.tracking_number}</code></small><br>
                    <small class="text-muted">Time: ${app.time_slot}</small>
                    ${incomeHtml}
                  </li>
                `;
              }).join('');
            } else {
              ul.innerHTML = '<li class="list-group-item text-muted small fst-italic">No appointments for this resident on this day.</li>';
            }
          }
        });
    });
  });

  /* ---------- VIEW MODAL: submit ---------- */
/* ---------- VIEW MODAL: submit ---------- */
  statusForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const newStatus     = statusSelect.value;
    const currentStatus = statusForm.dataset.currentStatusNorm || normStatus(statusForm.dataset.currentStatus || '');

    // 1. Validation: Require ApprovedCaptain before Released
    if (normStatus(newStatus) === 'released' && currentStatus !== 'approvedcaptain') {
      await Swal.fire({ icon:'warning', title:'Action Not Allowed',
        text:'You must first mark the appointment as Approved by Captain before releasing it.' });
      return;
    }

    // 2. Validation: Require Kagawad when moving to ApprovedCaptain (non-Cedula)
    if (newStatus === 'ApprovedCaptain' && !isCedulaType(currentAppointmentType) && assignSelect && !assignSelect.value) {
      await Swal.fire({ icon:'warning', title:'Assign Kagawad',
        text:'Please select a Kagawad before approving by Captain.' });
      assignSelect.focus();
      return;
    }

    // 3. Validation: Require Witness when moving to ApprovedCaptain (BESO)
    if (newStatus === 'ApprovedCaptain' &&
        normStatus(currentAppointmentType) === 'besoapplication' &&
        assignWitnessSelect && !assignWitnessSelect.value.trim()) {
      await Swal.fire({ icon:'warning', title:'Assign Witness',
        text:'Please select a Witness (Secretary) for the BESO application.' });
      assignWitnessSelect.focus();
      return;
    }

    // 4. Validation: Require Cedula # when Releasing a Cedula appointment
    if (newStatus === 'Released' && isCedulaType(currentAppointmentType) && cedulaInput && !cedulaInput.value.trim()) {
      await Swal.fire({ icon:'warning', title:'Cedula Number required',
        text:'Provide the Cedula Number before marking as Released.' });
      cedulaInput.focus();
      return;
    }

    const formData = new FormData(statusForm);

    // 🔥 UPDATED LOADING ALERT: Shows explicit "sending email" message
    Swal.fire({ 
        title: 'Processing...', 
        html: 'Updating status and <b>sending email notification</b>...<br>Please do not close this window.', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading() 
    });

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

      const modalTracking = document.getElementById('modalTrackingNumber');
      const modalCert     = document.getElementById('modalCertificate');
      const modalCedNo    = document.getElementById('statusModalCedulaNumber');
      if (modalTracking) modalTracking.value = trackingNum;
      if (modalCert)     modalCert.value     = certificate;
      if (modalCedNo)    modalCedNo.value    = cedulaNumber;

      const modalStatusSelect = document.getElementById('newStatus');
      const cedulaWrap        = document.getElementById('statusModalCedulaNumberContainer');
      const rejectWrap        = document.getElementById('statusModalRejectionReasonContainer');

      // If you kept the small modal Kagawad controls, wire them too:
      const assignKagWrap = document.getElementById('statusModalAssignKagGroup');
      const assignKagSel2 = document.getElementById('statusModalAssignKag');

      const setModalToggles = () => {
        const selectedStatus    = modalStatusSelect.value;
        const currentStatusNorm = (modalStatusSelect.getAttribute('data-current-status') || '')
          .toLowerCase().replace(/[^a-z]/g,'');

        // 🔒 Same strict locking
        lockStatusOptions(modalStatusSelect, currentStatusNorm);

        // Cedula field for Released + Cedula type
        if (cedulaWrap) cedulaWrap.style.display =
          (certificate.toLowerCase() === 'cedula' && selectedStatus === 'Released') ? 'block' : 'none';

        // Rejection textarea
        if (rejectWrap) rejectWrap.style.display = (selectedStatus === 'Rejected') ? 'block' : 'none';

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

      
    </body>
    </html>