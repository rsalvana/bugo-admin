<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Graceful 500 page for fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

include 'class/session_timeout.php';
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'");

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$user_role = $_SESSION['Role_Name'] ?? '';
date_default_timezone_set('Asia/Manila');

/* ============== Pagination + Filters (read) ============== */
$results_per_page = 100;
$page  = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum'])) ? max(1, (int)$_GET['pagenum']) : 1;
$offset = ($page - 1) * $results_per_page;

$date_filter   = $_GET['date_filter']  ?? 'today'; // today|this_week|next_week|this_month|this_year
$status_filter = $_GET['status_filter'] ?? '';      // Pending|Approved|Rejected|Released|ApprovedCaptain
$search_term   = trim($_GET['search'] ?? '');

/* ============== Delete (BESO only) ============== */
if (isset($_POST['delete_appointment'], $_POST['tracking_number'], $_POST['certificate'])) {
    $tracking_number = $_POST['tracking_number'];
    $certificate     = $_POST['certificate'];

    if (strtolower($certificate) === 'beso application') {
        $update_query_urgent = "UPDATE urgent_request SET appointment_delete_status = 1 WHERE tracking_number = ?";
        $update_query_sched  = "UPDATE schedules SET appointment_delete_status = 1 WHERE tracking_number = ?";

        $stmt = $mysqli->prepare($update_query_urgent);
        $stmt->bind_param("s", $tracking_number);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt = $mysqli->prepare($update_query_sched);
            $stmt->bind_param("s", $tracking_number);
            $stmt->execute();
        }

        echo "<script>
            Swal.fire({icon:'success',title:'Deleted',text:'The appointment was archived.'})
              .then(()=>{ window.location.href='" . enc_beso('view_appointments') . "'; });
        </script>";
        exit;
    }
}

/* ============== Status update (BESO) ============== */
if (isset($_POST['update_status'], $_POST['tracking_number'], $_POST['new_status'], $_POST['certificate'])) {
    $tracking_number     = $_POST['tracking_number'];
    $new_status          = $_POST['new_status'];
    $certificate         = $_POST['certificate'];
    $rejection_reason    = trim($_POST['rejection_reason'] ?? '');
    $assignedKagName     = trim($_POST['assigned_kag_name'] ?? '');
    $assignedWitnessName = trim($_POST['assigned_witness_name'] ?? ''); // NEW

    if (strtolower($certificate) !== 'beso application') {
        echo "<script>
            Swal.fire({icon:'info',title:'Notice',text:'This endpoint only updates BESO Applications.'})
              .then(()=>history.back());
        </script>";
        exit;
    }

    // Is it from urgent_request?
    $checkUrgent = $mysqli->prepare("SELECT COUNT(*) FROM urgent_request WHERE tracking_number = ?");
    $checkUrgent->bind_param("s", $tracking_number);
    $checkUrgent->execute();
    $checkUrgent->bind_result($isUrgent);
    $checkUrgent->fetch();
    $checkUrgent->close();

    // Gate: Released only after ApprovedCaptain
    $curSql  = ($isUrgent > 0) ? "SELECT status FROM urgent_request WHERE tracking_number = ?" : "SELECT status FROM schedules WHERE tracking_number = ?";
    $curStmt = $mysqli->prepare($curSql);
    $curStmt->bind_param("s", $tracking_number);
    $curStmt->execute();
    $curStmt->bind_result($current_status);
    $curStmt->fetch();
    $curStmt->close();

    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    // NEW: when moving to ApprovedCaptain, require Kagawad
    if ($new_status === 'ApprovedCaptain' && $assignedKagName === '') {
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Please select a Kagawad before approving by Captain.']);
        } else {
            echo "<script>
                Swal.fire({icon:'warning',title:'Assign Kagawad',text:'Please select a Kagawad before approving.'})
                .then(()=>history.back());
            </script>";
        }
        exit;
    }

    // NEW: when moving to ApprovedCaptain, require Witness
    if ($new_status === 'ApprovedCaptain' && $assignedWitnessName === '') {
        if ($isAjax) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Please select a Witness (Secretary) before approving.']);
        } else {
            echo "<script>
                Swal.fire({icon:'warning',title:'Assign Witness',text:'Please select a Witness (Secretary) before approving.'})
                .then(()=>history.back());
            </script>";
        }
        exit;
    }

    if ($new_status === 'Released' && $current_status !== 'ApprovedCaptain') {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'You can only set "Released" after Captain approval.']);
        } else {
            echo "<script>
                Swal.fire({icon:'warning',title:'Not allowed',text:'Approve by Captain first.'})
                  .then(()=>history.back());
            </script>";
        }
        exit;
    }

    // --- Perform update (Dynamic Query Build) ---
    $setParts = ["status=?", "is_read=0", "notif_sent=1"];
    $types = "s";
    $params = [$new_status];

    if ($new_status === 'Rejected') {
        $setParts[] = "rejection_reason=?";
        $types .= "s";
        $params[] = $rejection_reason;
    } else {
        $setParts[] = "rejection_reason=NULL";
    }

    if ($assignedKagName !== '') {
        $setParts[] = "assignedKagName=?";
        $types .= "s";
        $params[] = $assignedKagName;
    }

    if ($assignedWitnessName !== '') {
        $setParts[] = "assigned_witness_name=?";
        $types .= "s";
        $params[] = $assignedWitnessName;
    }

    $params[] = $tracking_number; // Add tracking number at the end
    $types .= "s";
    $setSql = implode(", ", $setParts);

    $query = ($isUrgent > 0)
      ? "UPDATE urgent_request SET $setSql WHERE tracking_number = ?"
      : "UPDATE schedules SET $setSql WHERE tracking_number = ?";

    $stmt = $mysqli->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    // --- End Dynamic Query ---

    // Log
    require_once './logs/logs_trig.php';
    $trigger  = new Trigger();
    $filename = ($isUrgent > 0) ? 9 : 3;

    $fetchResIDStmt = $mysqli->prepare(($isUrgent > 0)
        ? "SELECT res_id FROM urgent_request WHERE tracking_number = ?"
        : "SELECT res_id FROM schedules WHERE tracking_number = ?");
    $fetchResIDStmt->bind_param("s", $tracking_number);
    $fetchResIDStmt->execute();
    $fetchResIDStmt->bind_result($resident_id);
    $fetchResIDStmt->fetch();
    $fetchResIDStmt->close();

    try { $trigger->isStatusUpdate($filename, $resident_id, $new_status, $tracking_number); } catch (Exception $e) { error_log($e->getMessage()); }

    // Notify (email + SMS) — unchanged (kept concise)
    $email_query = ($isUrgent > 0)
        ? "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
         FROM urgent_request u JOIN residents r ON u.res_id = r.id WHERE u.tracking_number=?"
        : "SELECT r.email, r.contact_number, CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) AS full_name
         FROM schedules s JOIN residents r ON s.res_id = r.id WHERE s.tracking_number=?";

    $stmt_email = $mysqli->prepare($email_query);
    $stmt_email->bind_param("s", $tracking_number);
    $stmt_email->execute();
    $result_email = $stmt_email->get_result();

    if ($result_email->num_rows > 0) {
        $row            = $result_email->fetch_assoc();
        $email          = $row['email'];
        $resident_name  = $row['full_name'];
        $contact_number = $row['contact_number'];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.bugoportal.site';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'admin@bugoportal.site';
            $mail->Password   = 'Jayacop@100';
            $mail->Port       = 465;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout     = 12;
            $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];

            $mail->setFrom('admin@bugoportal.site', 'Barangay Office');
            $mail->addAddress($email, $resident_name);
            $mail->addReplyTo('admin@bugoportal.site', 'Barangay Office');
            $mail->Sender   = 'admin@bugoportal.site';
            $mail->Hostname = 'bugoportal.site';
            $mail->CharSet  = 'UTF-8';

            $safeName   = htmlspecialchars($resident_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeCert   = htmlspecialchars($certificate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeStatus = htmlspecialchars($new_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $mail->isHTML(true);
            $mail->Subject = 'Appointment Status Update';
            $mail->Body    = "<p>Dear {$safeName},</p><p>Your appointment for <strong>{$safeCert}</strong> is now <strong>{$safeStatus}</strong>.</p><p>Thank you,<br>Barangay Bugo</p>";
            $mail->AltBody = "Dear {$resident_name},\n\nYour {$certificate} appointment is now {$new_status}.\n\nThank you.\nBarangay Bugo";
            $mail->send();
        } catch (Exception $e) { error_log('Email failed: ' . $mail->ErrorInfo); }

        // SMS (Semaphore) — shortened
        $apiKey      = 'your_semaphore_api_key';
        $sender      = 'BRGY-BUGO';
        $sms_message = "Hello $resident_name, your $certificate appointment is now $new_status. - Barangay Bugo";

        $sms_data = http_build_query(['apikey'=>$apiKey,'number'=>$contact_number,'message'=>$sms_message,'sendername'=>$sender]);
        $sms_options = ['http'=>['header'=>"Content-type: application/x-www-form-urlencoded\r\n",'method'=>'POST','content'=>$sms_data]];
        $sms_context = stream_context_create($sms_options);
        @file_get_contents("https://api.semaphore.co/api/v4/messages", false, $sms_context);
    }

    if ($isAjax) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => 'Status updated successfully.']);
    } else {
        echo "<script>
            Swal.fire({icon:'success',title:'Status Updated',text:'BESO appointment updated.'})
              .then(()=>{ window.location.href='" . enc_beso('view_appointments') . "'; });
        </script>";
    }
    exit;
}

/* ============== Auto-archive housekeeping (same as reference) ============== */
$mysqli->query("INSERT INTO archived_schedules SELECT * FROM schedules WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("DELETE FROM schedules WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("INSERT INTO archived_cedula SELECT * FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("DELETE FROM cedula WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("INSERT INTO archived_urgent_cedula_request SELECT * FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("DELETE FROM urgent_cedula_request WHERE cedula_status='Released' AND YEAR(issued_on) < YEAR(CURDATE())");
$mysqli->query("INSERT INTO archived_urgent_request SELECT * FROM urgent_request WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("DELETE FROM urgent_request WHERE status='Released' AND selected_date < CURDATE()");
$mysqli->query("UPDATE schedules SET appointment_delete_status=1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE cedula SET cedula_delete_status=1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_request SET urgent_delete_status=1 WHERE selected_date < CURDATE() AND status IN ('Released','Rejected')");
$mysqli->query("UPDATE urgent_cedula_request SET cedula_delete_status=1 WHERE appointment_date < CURDATE() AND cedula_status IN ('Released','Rejected')");

/* ============== Data for header/logos/officials (from reference) ============== */
$off = "SELECT b.position, r.first_name, r.middle_name, r.last_name
        FROM barangay_information b
        INNER JOIN residents r ON b.official_id = r.id
        WHERE b.status='active'
          AND b.position NOT LIKE '%Lupon%'
          AND b.position NOT LIKE '%Barangay Tanod%'
          AND b.position NOT LIKE '%Barangay Police%'
        ORDER BY FIELD(b.position,'Punong Barangay','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','Kagawad','SK Chairman','Secretary','Treasurer')";
$offresult = $mysqli->query($off);
$officials = [];
if ($offresult && $offresult->num_rows > 0) {
    while ($row = $offresult->fetch_assoc()) {
        $officials[] = ['position'=>$row['position'], 'name'=>$row['first_name'].' '.$row['middle_name'].' '.$row['last_name']];
    }
}

$logo_sql    = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status='active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo        = ($logo_result && $logo_result->num_rows > 0) ? $logo_result->fetch_assoc() : null;

$citySql     = "SELECT * FROM logos WHERE (logo_name LIKE '%City%' OR logo_name LIKE '%Municipality%') AND status='active' LIMIT 1";
$cityResult  = $mysqli->query($citySql);
$cityLogo    = ($cityResult && $cityResult->num_rows > 0) ? $cityResult->fetch_assoc() : null;

$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name
                      FROM barangay_info bi
                      LEFT JOIN city_municipality bm ON bi.city_municipality_id=bm.city_municipality_id
                      LEFT JOIN barangay b ON bi.barangay_id=b.barangay_id
                      WHERE bi.id=1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);
if ($barangayInfoResult && $barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $cityMunicipalityName = $barangayInfo['city_municipality_name'];
    if (stripos($cityMunicipalityName, "City of") === false) { $cityMunicipalityName = "MUNICIPALITY OF " . strtoupper($cityMunicipalityName); }
    else { $cityMunicipalityName = strtoupper($cityMunicipalityName); }
    $barangayName = strtoupper(preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']));
    if (stripos($barangayName, "Barangay") !== false) { $barangayName = strtoupper($barangayName); }
    elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) { $barangayName = "POBLACION " . strtoupper($barangayName); }
    elseif (stripos($barangayName, "Poblacion") !== false) { $barangayName = strtoupper($barangayName); }
    else { $barangayName = "BARANGAY " . strtoupper($barangayName); }
} else { $cityMunicipalityName = "NO CITY/MUNICIPALITY FOUND"; $barangayName = "NO BARANGAY FOUND"; }

$councilTermResult = $mysqli->query("SELECT council_term FROM barangay_info WHERE id=1");
$councilTerm = ($councilTermResult && $councilTermResult->num_rows > 0) ? ($councilTermResult->fetch_assoc()['council_term'] ?? '#') : '#';

$lupon_sql = "SELECT r.first_name, r.middle_name, r.last_name, b.position
                  FROM barangay_information b
                  INNER JOIN residents r ON b.official_id = r.id
                  WHERE b.status='active' AND (b.position LIKE '%Lupon%' OR b.position LIKE '%Barangay Tanod%' OR b.position LIKE '%Barangay Police%')";
$lupon_result = $mysqli->query($lupon_sql);
$lupon_official = null;
$barangay_tanod_official = null;
if ($lupon_result && $lupon_result->num_rows > 0) {
    while ($lr = $lupon_result->fetch_assoc()) {
        if (stripos($lr['position'], 'Lupon') !== false) { $lupon_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name']; }
        if (stripos($lr['position'], 'Barangay Tanod') !== false || stripos($lr['position'], 'Barangay Police') !== false) {
            $barangay_tanod_official = $lr['first_name'].' '.$lr['middle_name'].' '.$lr['last_name'];
        }
    }
}

$barangayContactResult = $mysqli->query("SELECT telephone_number, mobile_number FROM barangay_info WHERE id=1");
if ($barangayContactResult && $barangayContactResult->num_rows > 0) {
    $contactInfo     = $barangayContactResult->fetch_assoc();
    $telephoneNumber = $contactInfo['telephone_number'];
    $mobileNumber    = $contactInfo['mobile_number'];
} else { $telephoneNumber = "No telephone number found"; $mobileNumber="No mobile number found"; }

/* ============== Kagawad list (active) ============== */
$kagawads = [];
$kagSql = "
  SELECT 
    r.id AS res_id,
    TRIM(CONCAT(r.first_name,' ',
               IFNULL(r.middle_name,''),' ',
               r.last_name,' ',
               IFNULL(r.suffix_name,''))) AS kag_name
  FROM barangay_information b
  JOIN residents r ON r.id = b.official_id
  WHERE b.status='active' AND b.position LIKE '%Kagawad%'
  ORDER BY b.position, kag_name
";
if ($kr = $mysqli->query($kagSql)) {
  while ($k = $kr->fetch_assoc()) {
    $kagawads[] = $k['kag_name'];
  }
}

/* ============== Witnesses (Sec / Exec Sec) ============== */
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


/* ============== Main Listing (BESO-focused) with server filters ============== */
$unionSql = "
  /* 1) Regular Schedules */
  SELECT 
    1 AS src_priority,
    s.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    s.certificate,
    s.status,
    s.selected_time,
    s.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    s.purpose,
    c.issued_on, c.cedula_number, c.issued_at,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name, 'Barangay Staff') AS signatory_position,
    s.assignedKagName AS assigned_kag_name,
    s.assigned_witness_name
  FROM schedules s
  JOIN residents r ON s.res_id = r.id
  LEFT JOIN cedula         c  ON c.res_id = r.id
  LEFT JOIN employee_list  el ON el.employee_id = s.employee_id
  LEFT JOIN employee_roles er ON er.Role_Id     = el.Role_id
  WHERE s.appointment_delete_status = 0
    AND s.selected_date >= CURDATE()
    AND ( '$user_role' <> 'BESO' OR s.certificate = 'BESO Application' )

  UNION ALL

  /* 2) Urgent Requests (non-cedula) */
  SELECT 
    2 AS src_priority,
    u.tracking_number,
    CONCAT(r.first_name,' ',IFNULL(r.middle_name,''),' ',r.last_name,' ',IFNULL(r.suffix_name,'')) AS fullname,
    u.certificate,
    u.status,
    u.selected_time,
    u.selected_date,
    r.id AS res_id, r.birth_date, r.birth_place, r.res_zone, r.civil_status, r.residency_start, r.res_street_address,
    u.purpose,
    COALESCE(c.issued_on, uc.issued_on)      AS issued_on,
    COALESCE(c.cedula_number, uc.cedula_number) AS cedula_number,
    COALESCE(c.issued_at, uc.issued_at)      AS issued_at,
    el.employee_id AS signatory_employee_id,
    TRIM(CONCAT(el.employee_fname,' ',IFNULL(el.employee_mname,''),' ',el.employee_lname)) AS signatory_name,
    COALESCE(er.Role_Name, 'Barangay Staff') AS signatory_position,
    u.assignedKagName AS assigned_kag_name,
    u.assigned_witness_name
  FROM urgent_request u
  JOIN residents r ON u.res_id = r.id
  LEFT JOIN cedula                  c  ON c.res_id  = r.id AND c.cedula_status  = 'Approved'
  LEFT JOIN urgent_cedula_request uc ON uc.res_id = r.id AND uc.cedula_status = 'Approved'
  LEFT JOIN employee_list         el ON el.employee_id = u.employee_id
  LEFT JOIN employee_roles        er ON er.Role_Id     = el.Role_id
  WHERE u.urgent_delete_status = 0
    AND u.selected_date >= CURDATE()
    AND ( '$user_role' <> 'BESO' OR u.certificate = 'BESO Application' )
";

$whereParts = [];
$types = '';
$params = [];

/* Date filter */
switch ($date_filter) {
  case 'today':      $whereParts[] = "selected_date = CURDATE()"; break;
  case 'this_week':  $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(CURDATE(), 1)"; break;
  case 'next_week':  $whereParts[] = "YEARWEEK(selected_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1)"; break;
  case 'this_month': $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE()) AND MONTH(selected_date) = MONTH(CURDATE())"; break;
  case 'this_year':  $whereParts[] = "YEAR(selected_date) = YEAR(CURDATE())"; break;
}

/* Status filter */
if ($status_filter !== '') {
  $whereParts[] = "status = ?";
  $types       .= 's';
  $params[]     = $status_filter;
}

/* Search */
if ($search_term !== '') {
  $whereParts[] = "(tracking_number LIKE ? OR fullname LIKE ?)";
  $like   = "%{$search_term}%";
  $types .= 'ss';
  $params[] = $like; 
  $params[] = $like;
}

$whereSql = $whereParts ? ('WHERE '.implode(' AND ', $whereParts)) : '';

/* Count */
$countSql = "SELECT COUNT(*) AS total FROM ( $unionSql ) AS all_appointments $whereSql";
$stmt = $mysqli->prepare($countSql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total_results = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_results / $results_per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $results_per_page; }

/* Page data */
$listSql = "
SELECT *
FROM ( $unionSql ) AS all_appointments
$whereSql
GROUP BY tracking_number
ORDER BY
  (status='Pending' AND selected_time='URGENT' AND selected_date=CURDATE()) DESC,
  (status='Pending' AND selected_date=CURDATE()) DESC,
  selected_date ASC, selected_time ASC,
  FIELD(status,'Pending','Approved','Rejected','ApprovedCaptain')
LIMIT ? OFFSET ?";

$typesList  = $types . 'ii';
$paramsList = array_merge($params, [$results_per_page, $offset]);

$stmt = $mysqli->prepare($listSql);
$stmt->bind_param($typesList, ...$paramsList);
$stmt->execute();
$result = $stmt->get_result();

/* ============== Render ============== */
?>
<?php
// Captain display name
$punong_barangay = null;
foreach ($officials as $o) {
    if (strcasecmp($o['position'], 'Punong Barangay') === 0) {
        $punong_barangay = trim($o['name']);
        break;
    }
}

$captainEmployeeId = 0;
if ($stmtCap = $mysqli->prepare("
    SELECT COALESCE(NULLIF(er.Employee_Id, 0), e.employee_id) AS captain_id
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

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <link rel="stylesheet" href="css/styles.css" />
  <link rel="stylesheet" href="css/ViewApp/ViewApp.css" />
  <style>
    #sameDayAppointments .list-group-item.active { background-color: var(--bs-primary)!important; color:#fff!important;}
    #sameDayAppointments .list-group-item.active .text-muted { color: rgba(255,255,255,.85)!important;}
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
        <form method="GET" action="<?= enc_beso('view_appointments') ?>" class="row g-2 align-items-end">
          <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'view_appointments' ?>" />
          <input type="hidden" name="pagenum" value="1">

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Date</label>
            <select name="date_filter" class="form-select form-select-sm">
              <option value="today"     <?= (($_GET['date_filter'] ?? '')==='today')?'selected':'' ?>>Today</option>
              <option value="this_week"  <?= (($_GET['date_filter'] ?? '')==='this_week')?'selected':'' ?>>This Week</option>
              <option value="next_week"  <?= (($_GET['date_filter'] ?? '')==='next_week')?'selected':'' ?>>Next Week</option>
              <option value="this_month" <?= (($_GET['date_filter'] ?? '')==='this_month')?'selected':'' ?>>This Month</option>
              <option value="this_year"  <?= (($_GET['date_filter'] ?? '')==='this_year')?'selected':'' ?>>This Year</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label mb-1 fw-semibold">Status</label>
            <select name="status_filter" class="form-select form-select-sm">
              <option value="">All</option>
              <option value="Pending"         <?= (($_GET['status_filter'] ?? '')==='Pending')?'selected':'' ?>>Pending</option>
              <option value="Approved"        <?= (($_GET['status_filter'] ?? '')==='Approved')?'selected':'' ?>>Approved</option>
              <option value="Rejected"        <?= (($_GET['status_filter'] ?? '')==='Rejected')?'selected':'' ?>>Rejected</option>
              <option value="Released"        <?= (($_GET['status_filter'] ?? '')==='Released')?'selected':'' ?>>Released</option>
              <option value="ApprovedCaptain" <?= (($_GET['status_filter'] ?? '')==='ApprovedCaptain')?'selected':'' ?>>Approved by Captain</option>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label mb-1 fw-semibold">Search</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" id="searchInput" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" class="form-control" placeholder="Search name or tracking number..." />
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
                  <th style="width: 220px;">Full Name</th>
                  <th style="width: 140px;">Certificate</th>
                  <th style="width: 200px;">Tracking Number</th>
                  <th style="width: 160px;">Date</th>
                  <th style="width: 140px;">Time Slot</th>
                  <th style="width: 140px;">Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="appointmentTableBody">
                <?php if ($result && $result->num_rows > 0): ?>
                  <?php while ($row = $result->fetch_assoc()): ?>
                    <?php include 'Modules/beso_modules/appointment_row.php'; ?>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No appointments found</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <?php
      $pageBase = 'index_beso_staff.php';
      $params   = $_GET ?? [];
      unset($params['pagenum']);
      $params['page'] = 'view_appointments';
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
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i></span></li>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i></a></li>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i></a></li>
        <?php endif; ?>

        <?php if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
          <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
            <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
          </li>
        <?php endfor; ?>
        <?php if ($end < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>

        <?php if ($page >= $total_pages): ?>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i></span></li>
          <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i></a></li>
          <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i></a></li>
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
              <i class="bi bi-calendar-check-fill"></i>
              Appointment Details
            </h5>
            <div class="small text-dark-50" id="viewMetaLine" aria-live="polite"></div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-0">
          <div class="p-4 content-grid">
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
                <small class="text-muted">Email/SMS will notify the resident</small>
              </div>
              <div class="card-body">
                <form id="statusUpdateFormInline" method="POST" action="">
                  <input type="hidden" name="update_status" value="1">
                  <input type="hidden" id="inlineTrackingNumber" name="tracking_number">
                  <input type="hidden" id="inlineCertificate" name="certificate" value="BESO Application">

                  <div class="row g-3">
                    <div class="col-12 col-md-6">
                      <label class="form-label">New Status</label>
                      <select name="new_status" id="inlineNewStatus" class="form-select" data-current-status="">
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                        <option value="ApprovedCaptain">Approved by Captain</option>
                        <option value="Released">Released</option>
                      </select>
                    </div>

                    <div class="col-12 d-none" id="inlineRejectionGroup">
                      <label class="form-label">Reason for Rejection</label>
                      <textarea name="rejection_reason" id="inlineRejectionReason" class="form-control" rows="2" placeholder="Type reason..."></textarea>
                    </div>

                    <div class="col-12 col-md-6 d-none" id="inlineKagawadGroup">
                      <label class="form-label">Assign Kagawad <small class="text-muted">(required)</small></label>
                      <select name="assigned_kag_name" id="inlineAssignedKag" class="form-select">
                        <option value="">— Select Kagawad —</option>
                        <?php foreach ($kagawads as $kn): ?>
                          <option value="<?= htmlspecialchars($kn) ?>"><?= htmlspecialchars($kn) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    
                    <div class="col-12 col-md-6 d-none" id="inlineWitnessGroup">
                      <label for="inlineWitnessSelect" class="form-label">Assign Witness <small class="text-muted">(required)</small></label>
                      <select class="form-select" name="assigned_witness_name" id="inlineWitnessSelect">
                        <option value="">— Select Witness —</option>
                        <?php foreach ($witnesses as $w): ?>
                          <option value="<?= htmlspecialchars($w['full_name']) ?>">
                            <?= htmlspecialchars($w['position'].' — '.$w['full_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="sticky-action mt-3">
                    <button type="submit" class="btn btn-success w-100" id="inlineSaveStatusBtn">
                      <i class="bi bi-check2-circle me-1"></i> Save Status
                    </button>
                  </div>
                </form>
              </div>
            </section>
          </div>
        </div>

        <div class="modal-footer bg-transparent d-flex justify-content-between">
          <small class="text-muted">Tip: “Released” is only allowed after “Approved by Captain”.</small>
          <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
  const CAPTAIN_EMPLOYEE_ID = <?= (int)$captainEmployeeId ?>;
  const WITNESS_DATA = <?php echo json_encode($witnesses); ?>;</script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
function renderSignatorySection(isCaptain, assignedKagName){
  const pbNm = `<?php echo htmlspecialchars($punong_barangay ?? ''); ?>`;
  const kag  = (assignedKagName || '').trim();

  return `
    <section class="signatory-wrap">
      <h5 class="pb-name"><u><strong>${pbNm}</strong></u></h5>
      <p class="auth-title">PUNONG BARANGAY</p>

      ${isCaptain
        ? ''
        : `
          <div class="authority-note"><strong>By the authority of the Punong Barangay</strong></div>
          <div class="auth">
            <h6 class="auth-name"><u><strong>${escapeHtml(kag || 'Authorized Kagawad')}</strong></u></h6>
            <p class="auth-title">BRGY.KAGAWAD</p>
          </div>
        `
      }
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
  residency_start = "", age= "", residentId = "", assignedKagName = "",  
  signatoryEmployeeId = 0, seriesNum = "", assignedWitnessName = ""
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

  // Check the certificate type and set the corresponding content
  if (certificate === "Barangay Indigency") {
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
                <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
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
            <br><br><br><br><br>
            <div style="display: flex; justify-content: space-between; margin-bottom: 18px;">
              <section style="width: 48%; line-height: 1.8;">
                <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                <p><strong>Issued at:</strong> ${issued_at}</p>
              </section>
              <section style="width: -155%; text-align: center; font-size: 25px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  } else if (certificate === "Barangay Residency") {
    const formattedBirthDate = birth_date ? new Date(birth_date).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";
    const formattedResidencyStart = residency_start ? new Date(residency_start).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";
    const formattedIssuedOn = issued_on ? new Date(issued_on).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }) : "N/A";

    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/form.css">
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
              <section style="width: 48%; text-align: center; font-size: 25px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  } else if (certificate.toLowerCase() === "beso application") {
 const witnessName = (assignedWitnessName || 'ENGR. BELEN B. BASADRE').trim();
    
    // --- NEW: Dynamic Witness Title Logic ---
    let witnessTitle = 'Barangay Executive Secretary'; // Default/fallback title
    
    // Check if the global WITNESS_DATA array exists and is an array
    if (typeof WITNESS_DATA !== 'undefined' && Array.isArray(WITNESS_DATA) && assignedWitnessName) {
        // Find the witness in the global data array by their full name
        const witnessObj = WITNESS_DATA.find(w => w.full_name === assignedWitnessName);
        
        // If found, use their position
        if (witnessObj && witnessObj.position) {
            witnessTitle = witnessObj.position;
        }
    }
  
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
              <h4 style="text-align: center; font-size: 40px;margin-top: -10px;"><strong>BARANGAY CERTIFICATION</strong></h4>
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

              <p>This certification is issued upon request of the above-named person for <strong>${escapeHtml(purpose)}</strong> purposes and is valid only until
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
                    <div class="sig-name">${escapeHtml(witnessName)}</div>
                    <div class="sig-sub">${escapeHtml(witnessTitle)}</div>
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
  } else if (certificate === "Barangay Clearance") {
    printAreaContent = `
      <html>
        <head>
          <link rel="stylesheet" href="css/clearance.css" alt="Barangay Logo" class="logo">
        </head>
        <body>
          <div class="container" id="printArea">
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
                  <img src="data:image/jpeg;base64,<?php echo base64_encode($cityLogo['logo_image']); ?>" alt="City Logo" class="logo">
                <?php else: ?>
                  <p>No active City/Municipality logo found.</p>
                <?php endif; ?>
              </div>
              <section style="text-align: center; margin-top: 10px;">
                <hr class="header-line" style="border: 1px solid black; margin-top: 10px;">
                <h2 style="font-size: 30px;"><strong>BARANGAY CLEARANCE</strong></h2><br>
              </section>
              <section style="display: flex; justify-content: space-between; margin-top: 10px;">
                <div style="flex: 1;"></div>
                <div style="text-align: right; flex: 1;">
                  <p><strong>Control No.</strong> _________________ <br>Series of ${year}</p>
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
                        echo '<span>' . htmlspecialchars($official['position']) . '</span>';
                        echo '<strong><u>' . htmlspecialchars($official['name']) . '</u></strong>';
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
                <p>Given this <strong>${dayWithSuffix}</strong> day of <strong>${month}</strong>, <strong>${year}</strong>, at Barangay Bugo, Cagayan de Oro City.</p><br>
                <div style="text-align: center; font-size: 15px;">
                  <u><strong>${fullname}</strong></u>
                  <p>AFFIANT SIGNATURE</p>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 70px;">
                  <section style="width: 48%;">
                    <?php if ($lupon_official): ?>
                      <p><strong>As per records (LUPON TAGAPAMAYAPA):</strong></p>
                      <p>Brgy. Case #: ___________________________</p>
                      <p>Certified by: <U><strong><?php echo htmlspecialchars($lupon_official); ?></strong></U></p>
                      <p>Date: <?php echo date('F j, Y'); ?></p>
                    <?php endif; ?>
                  </section>
                  <section style="width: 48%;">
                    <?php if ($barangay_tanod_official): ?>
                      <p><strong>As per records (BARANGAY TANOD):</strong></p>
                      <p>Brgy. Tanod Remarks: _____________________</p>
                      <p>Certified by: <U><strong><?php echo htmlspecialchars($barangay_tanod_official); ?></strong></U></p>
                      <p>Date: <?php echo date('F j, Y'); ?></p>
                    <?php endif; ?>
                  </section>
                </div>
              </div>
            </div>

            <section style="margin-top: 20px; text-align: center;">
              <div style="display: flex; justify-content: left; gap: 20px;">
                <div style="text-align: center; font-size:6px;">
                  <p><strong>Left Thumb:</strong></p>
                  <div style="border: 1px solid black; width: 60px; height: 60px;"></div>
                </div>
                <div style="text-align: center; font-size:6px;">
                  <p><strong>Right Thumb:</strong></p>
                  <div style="border: 1px solid black; width: 60px; height: 60px;"></div>
                </div>
              </div>
            </section>

            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
              <section style="width: 48%; line-height: 1.8%;">
                <p><strong>Community Tax No.:</strong> ${cedula_number}</p>
                <p><strong>Issued on:</strong> ${new Date(issued_on).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                <p><strong>Issued at:</strong> ${issued_at}</p>
              </section>
              <section style="width: 48%; text-align: center; font-size: 18px;">
                <?php
                  $punong_barangay = null;
                  foreach ($officials as $official) {
                    if ($official['position'] == 'Punong Barangay') { $punong_barangay = $official['name']; break; }
                  }
                ?>
                <h5><u><strong><?php echo htmlspecialchars($punong_barangay); ?></strong></u></h5>
                <p>Punong Barangay</p>
              </section>
            </div>
          </div>
        </body>
      </html>
    `;
  }

  // Open a new print window with the content
  const printWindow = window.open("", "_blank");
  printWindow.document.write(printAreaContent);
  printWindow.document.close();

  // Wait for the document to load, then print
  printWindow.onload = function () {
    printWindow.print();
  };
}

/* =========================
   Helpers + strict option locking
   ========================= */
const normStatus = s => (s || '').toLowerCase().replace(/[^a-z]/g,'');
function lockStatusOptions(selectEl, currentStatusNorm){
  const q = v => selectEl.querySelector(`option[value="${v}"]`);
  const pendingOpt  = q('Pending');
  const approvedOpt = q('Approved');
  const rejectedOpt = q('Rejected');
  const releasedOpt = q('Released');
  const captainOpt  = q('ApprovedCaptain');

  // reset first
  [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt].forEach(o => o && (o.disabled = false));

  if (currentStatusNorm === 'pending') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = false;
    if (rejectedOpt) rejectedOpt.disabled = false;
    if (captainOpt)  captainOpt.disabled  = true;
    if (releasedOpt) releasedOpt.disabled = true;
    return;
  }
  if (currentStatusNorm === 'approved') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = true;
    if (rejectedOpt) rejectedOpt.disabled = true;
    if (captainOpt)  captainOpt.disabled  = false;
    if (releasedOpt) releasedOpt.disabled = true;
    return;
  }
  if (currentStatusNorm === 'approvedcaptain') {
    if (pendingOpt)  pendingOpt.disabled  = true;
    if (approvedOpt) approvedOpt.disabled = true;
    if (rejectedOpt) rejectedOpt.disabled = true;
    if (captainOpt)  captainOpt.disabled  = true;
    if (releasedOpt) releasedOpt.disabled = false;
    return;
  }
  if (currentStatusNorm === 'released' || currentStatusNorm === 'rejected') {
    [pendingOpt, approvedOpt, rejectedOpt, releasedOpt, captainOpt].forEach(o => o && (o.disabled = true));
  }
}

/* =========================
   View Modal population
   ========================= */
document.querySelectorAll('[data-bs-target="#viewModal"]').forEach((button) => {
  button.addEventListener("click", () => {
    document.getElementById("modal-fullname").textContent       = button.dataset.fullname || "";
    document.getElementById("modal-certificate").textContent    = button.dataset.certificate || "";
    document.getElementById("modal-tracking-number").textContent= button.dataset.trackingNumber || "";
    document.getElementById("modal-selected-date").textContent  = button.dataset.selectedDate || "";
    document.getElementById("modal-selected-time").textContent  = button.dataset.selectedTime || "";
    document.getElementById("modal-status").textContent         = button.dataset.status || "";
  });
});

/* =========================
   Status Modal (idempotent)
   ========================= */
(function attachStatusModalHandlers() {
  const openers = document.querySelectorAll('[data-bs-target="#statusModal"]');
  const form = document.getElementById("statusUpdateForm");
  const statusSelect = document.getElementById("newStatus");

  openers.forEach((button) => {
    button.addEventListener("click", () => {
      const certificate    = button.getAttribute("data-certificate") || "";
      const trackingNumber = button.getAttribute("data-tracking-number") || "";
      const cedulaNumber   = button.getAttribute("data-cedula-number") || "";
      const currentStatus  = button.getAttribute("data-current-status") || "";
      const currentNorm    = normStatus(currentStatus);

      document.getElementById("modalTrackingNumber").value = trackingNumber;
      document.getElementById("modalCertificate").value    = certificate;

      const cedulaInput = document.getElementById("cedulaNumber");
      if (cedulaInput) cedulaInput.value = cedulaNumber;

      const cedulaContainer    = document.getElementById("cedulaNumberContainer");
      const rejectionContainer = document.getElementById("rejectionReasonContainer");

      // 🔒 strict step-locking
      lockStatusOptions(statusSelect, currentNorm);

      const checkStatus = () => {
        // Cedula number visible only if type=cedula AND selecting Released
        if (cedulaContainer) {
          if (certificate.toLowerCase() === "cedula" && statusSelect.value === "Released") {
            cedulaContainer.style.display = "block";
          } else {
            cedulaContainer.style.display = "none";
          }
        }
        // Rejection reason visibility
        if (rejectionContainer) {
          rejectionContainer.style.display = (statusSelect.value === "Rejected") ? "block" : "none";
        }
      };

      statusSelect.removeEventListener("change", checkStatus);
      statusSelect.addEventListener("change", checkStatus);
      checkStatus();
    });
  });

  // Submit (Ajax)
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      const formData = new FormData(form);
      formData.append("update_status", "1");

      Swal.fire({ title:"Updating...", html:"Please wait while we update the appointment.", allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

      fetch("", { method:"POST", body: formData, headers:{ "X-Requested-With":"XMLHttpRequest" } })
        .then(async (res) => { const t = await res.text(); try { return JSON.parse(t); } catch { return { ok:true, _html:t }; } })
        .then(() => Swal.fire({ icon:"success", title:"Status Updated", text:"Status updated and email sent!" })
          .then(()=>location.reload()))
        .catch((err) => {
          console.error("Update error:", err);
          Swal.fire({ icon:"error", title:"Error", text:"Something went wrong while updating the status." });
        });
    });
  }
})();

/* =========================
   Styling: soft badges
   ========================= */
(function applyStatusBadges() {
  const map = {
    pending: "badge-soft-warning",
    approved: "badge-soft-info",
    approvedcaptain: "badge-soft-primary",
    rejected: "badge-soft-danger",
    released: "badge-soft-success",
  };
  document.querySelectorAll("#appointmentTableBody tr").forEach((tr) => {
    const td = tr.children[5];
    if (!td) return;
    const raw = (td.textContent || "").trim();
    const key = raw.toLowerCase().replace(/\s+/g, "");
    if (raw && !td.querySelector(".badge")) {
      const b = document.createElement("span");
      b.className = "badge " + (map[key] || "badge-soft-secondary");
      b.textContent = raw;
      td.textContent = "";
      td.appendChild(b);
    }
  });
})();

/* --- tiny helpers --- */
function normalizeDate(s) {
  if (!s) return '';
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  const d = new Date(s);
  if (!isNaN(d)) {
    const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), day=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  }
  return String(s).slice(0,10);
}
function pick(ds, keys, fb=''){ for (const k of keys) if (ds[k]) return ds[k]; return fb; }

/* ====  A. VIEW MODAL: populate + same-day list + inline form seeds  ==== */
document.addEventListener('click', function (e) {
  const btn = e.target.closest('[data-bs-target="#viewModal"], [data-action="view"]');
  if (!btn) return;

  const d            = btn.dataset;
  const rawDate      = pick(d, ['selectedDate','date','selected_date'], '');
  const selectedDate = normalizeDate(rawDate);
  const tracking     = pick(d, ['trackingNumber','tracking','tn'], '');
  const certificate  = pick(d, ['certificate'], 'BESO Application');
  const status       = pick(d, ['status'], 'Pending');
  const assignedKag  = pick(d, ['assignedKagName','assigned_kag_name','assignedkagname'], '');
  const assignedWitness = pick(d, ['assignedWitnessName', 'assigned_witness_name'], '');

  // Seed inline form
  const tnEl  = document.getElementById('inlineTrackingNumber');  if (tnEl) tnEl.value = tracking;
  const certEl= document.getElementById('inlineCertificate');     if (certEl) certEl.value = certificate;
  const sel   = document.getElementById('inlineNewStatus');
  const kagEl = document.getElementById('inlineAssignedKag');
  const witEl = document.getElementById('inlineWitnessSelect');

  if (sel) {
    sel.value = status || 'Pending';
    // 🔒 strict locking on inline selector too
    lockStatusOptions(sel, normStatus(status));
  }
  if (kagEl) kagEl.value = assignedKag || '';
  if (witEl) witEl.value = assignedWitness || '';

  // Build the same-day list
  const ul = document.getElementById('sameDayAppointments');
  if (ul) {
    ul.innerHTML = '';
    let count = 0;
    document.querySelectorAll('[data-bs-target="#viewModal"], [data-action="view"]').forEach(b => {
      const bd        = b.dataset;
      const bDateNorm = normalizeDate(pick(bd, ['selectedDate','date','selected_date'], ''));
      if (bDateNorm !== selectedDate) return;

      const name   = pick(bd, ['fullname','name'], '—');
      const time   = pick(bd, ['selectedTime','time'], '');
      const stat   = pick(bd, ['status'], '');
      const track  = pick(bd, ['trackingNumber','tracking','tn'], '');
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex justify-content-between align-items-center' + (track === tracking ? ' active' : '');
      li.innerHTML = `<span>${name}</span><span class="text-muted">${time}${(time && stat)?' • ':''}${stat}</span>`;
      ul.appendChild(li);
      count++;
    });
    if (!count) ul.innerHTML = `<li class="list-group-item text-muted">No other appointments for this day.</li>`;
  }

  // Show/hide Kagawad + Rejection UI
  toggleInlineUI();
});

/* ====  B. Inline modal UI toggles (Rejected/Kagawad/Witness)  ==== */
function toggleInlineUI(){
  const statusSel = document.getElementById('inlineNewStatus');
  const rejGroup  = document.getElementById('inlineRejectionGroup');
  const kagGroup  = document.getElementById('inlineKagawadGroup');
  const kagSel    = document.getElementById('inlineAssignedKag');
  const witGroup  = document.getElementById('inlineWitnessGroup');
  const witSel    = document.getElementById('inlineWitnessSelect');
  if (!statusSel) return;

  if (rejGroup) rejGroup.classList.toggle('d-none', statusSel.value !== 'Rejected');

  const needsKag = (statusSel.value === 'ApprovedCaptain');
  if (kagGroup) kagGroup.classList.toggle('d-none', !needsKag);
  if (kagSel) kagSel.required = needsKag;

  const needsWit = (statusSel.value === 'ApprovedCaptain');
  if (witGroup) witGroup.classList.toggle('d-none', !needsWit);
  if (witSel) witSel.required = needsWit;
}
document.getElementById('inlineNewStatus')?.addEventListener('change', toggleInlineUI);

/* ====  C. Inline form submit (AJAX)  ==== */
(function attachInlineForm(){
  const form = document.getElementById('statusUpdateFormInline');
  if (!form) return;

  form.addEventListener('submit', function(e){
    e.preventDefault();

    const statusSel = document.getElementById('inlineNewStatus');
    const kagSel    = document.getElementById('inlineAssignedKag');
    const witSel    = document.getElementById('inlineWitnessSelect');

    if (statusSel && statusSel.value === 'ApprovedCaptain' && kagSel && !kagSel.value) {
      Swal.fire({ icon:'warning', title:'Assign Kagawad', text:'Please select a Kagawad before approving by Captain.' });
      return;
    }
    if (statusSel && statusSel.value === 'ApprovedCaptain' && witSel && !witSel.value) {
      Swal.fire({ icon:'warning', title:'Assign Witness', text:'Please select a Witness (Secretary) before approving.' });
      return;
    }

    const fd = new FormData(form);
    fd.append('update_status', '1');

    Swal.fire({ title:'Updating...', html:'Please wait while we update the appointment.', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    fetch('', { method:'POST', body:fd, headers:{ 'X-Requested-With':'XMLHttpRequest' } })
      .then(async (res) => { const t = await res.text(); try { return JSON.parse(t); } catch { return { ok:true, _html:t }; } })
      .then((resp) => {
        if (resp && resp.ok === false) throw new Error(resp.message || 'Update failed.');
        Swal.fire({ icon:'success', title:'Status Updated', text:'Status updated and notifications sent!' })
          .then(()=>location.reload());
      })
      .catch((err) => {
        console.error(err);
        Swal.fire({ icon:'error', title:'Error', text: err?.message || 'Something went wrong while updating the status.' });
      });
  });
})();

/* ====  D. Cosmetic status badges in the table  ==== */
(function badges(){
  const map = { pending:'badge-soft-warning', approved:'badge-soft-info', approvedcaptain:'badge-soft-primary', rejected:'badge-soft-danger', released:'badge-soft-success' };
  document.querySelectorAll('#appointmentTableBody tr').forEach(tr=>{
    const td = tr.children[5]; if (!td) return;
    const raw = (td.textContent||'').trim(); const key = raw.toLowerCase().replace(/\s+/g,'');
    if (raw && !td.querySelector('.badge')) {
      const b = document.createElement('span');
      b.className = 'badge ' + (map[key] || 'badge-soft-secondary');
      b.textContent = raw;
      td.textContent = '';
      td.appendChild(b);
    }
  });
})();
</script>
    
  </body>
</html>