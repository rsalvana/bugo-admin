<?php
// ajax/fetch_resident_details.php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
session_start();

$role = $_SESSION['Role_Name'] ?? '';

// Access control based on user role
if ($role !== 'Revenue Staff' && $role !== 'Admin' && $role !== 'BESO' && $role !== 'indigency') {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

$id = $_GET['id'] ?? 0;

// Fetch basic resident profile
$stmt = $mysqli->prepare("
    SELECT 
        first_name, middle_name, last_name, suffix_name, 
        birth_date, birth_place, res_zone, res_street_address 
    FROM residents 
    WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // ✅ Calculate age for clearance restrictions
    $birthDate = new DateTime($row['birth_date']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;

    // ✅ Approved Cedula Check: Exactly 'Released' (ignore soft-deleted)
    $stmt1 = $mysqli->prepare("SELECT COUNT(*) FROM cedula WHERE res_id = ? AND cedula_status = 'Released' AND cedula_delete_status = 0");
    $stmt1->bind_param("i", $id);
    $stmt1->execute();
    $stmt1->bind_result($count1);
    $stmt1->fetch();
    $stmt1->close();

    $stmt2 = $mysqli->prepare("SELECT COUNT(*) FROM urgent_cedula_request WHERE res_id = ? AND cedula_status = 'Released' AND cedula_delete_status = 0");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->bind_result($count2);
    $stmt2->fetch();
    $stmt2->close();

    $hasApprovedCedula = ($count1 + $count2) > 0;

    // ✅ Blocking Cedula Check: Includes Pending, Approved, and ApprovedCaptain
    // This prevents new urgent requests if a record is anywhere in the approval pipeline.
    $stmt3 = $mysqli->prepare("
        SELECT COUNT(*) FROM cedula 
        WHERE res_id = ? 
        AND cedula_status IN ('Pending', 'Approved', 'ApprovedCaptain') 
        AND cedula_delete_status = 0
    ");
    $stmt3->bind_param("i", $id);
    $stmt3->execute();
    $stmt3->bind_result($count3);
    $stmt3->fetch();
    $stmt3->close();

    $stmt4 = $mysqli->prepare("
        SELECT COUNT(*) FROM urgent_cedula_request 
        WHERE res_id = ? 
        AND cedula_status IN ('Pending', 'Approved', 'ApprovedCaptain') 
        AND cedula_delete_status = 0
    ");
    $stmt4->bind_param("i", $id);
    $stmt4->execute();
    $stmt4->bind_result($count4);
    $stmt4->fetch();
    $stmt4->close();

    $hasPendingCedula = ($count3 + $count4) > 0;

    // ✅ Ongoing Case Check
    $stmt5 = $mysqli->prepare("
        SELECT COUNT(*) 
        FROM cases 
        WHERE employee_id = ? 
          AND action_taken IN ('Ongoing','Pending')
    ");
    $stmt5->bind_param("i", $id);
    $stmt5->execute();
    $stmt5->bind_result($caseCount);
    $stmt5->fetch();
    $stmt5->close();

    $hasOngoingCase = $caseCount > 0;

    // ✅ Global Pending/Active Certificate Check
    // This looks for ANY status that isn't finished/released to block duplicates
    $pendingCertificates = [];

    $stmt11 = $mysqli->prepare("SELECT certificate FROM schedules WHERE res_id = ? AND status IN ('Pending', 'Approved', 'ApprovedCaptain') AND appointment_delete_status = 0");
    $stmt11->bind_param("i", $id);
    $stmt11->execute();
    $stmt11->bind_result($cert1);
    while ($stmt11->fetch()) {
        $pendingCertificates[] = $cert1;
    }
    $stmt11->close();

    $stmt12 = $mysqli->prepare("SELECT certificate FROM urgent_request WHERE res_id = ? AND status IN ('Pending', 'Approved', 'ApprovedCaptain') AND urgent_delete_status = 0");
    $stmt12->bind_param("i", $id);
    $stmt12->execute();
    $stmt12->bind_result($cert2);
    while ($stmt12->fetch()) {
        $pendingCertificates[] = $cert2;
    }
    $stmt12->close();

    // ✅ Existing BESO Check: Match by full name
    $fullName = trim($row['first_name'].' '.($row['middle_name'] ?? '').' '.$row['last_name'].' '.($row['suffix_name'] ?? ''));
    $fullName = preg_replace('/\s+/', ' ', $fullName);

    $stmt8 = $mysqli->prepare("
      SELECT COUNT(*)
      FROM beso
      WHERE beso_delete_status = 0
        AND LOWER(
          CONCAT_WS(' ',
            TRIM(firstName),
            NULLIF(TRIM(middleName), ''),
            TRIM(lastName),
            NULLIF(TRIM(suffixName), '')
          )
        ) = LOWER(?)
    ");
    $stmt8->bind_param("s", $fullName);
    $stmt8->execute();
    $stmt8->bind_result($besoCount);
    $stmt8->fetch();
    $stmt8->close();

    $hasExistingBeso = $besoCount > 0;

    // ✅ Used Residency for BESO Check
    $stmt9 = $mysqli->prepare("
        SELECT COUNT(*) FROM (
            SELECT res_id FROM schedules
             WHERE res_id = ? AND certificate = 'Barangay Residency'
               AND purpose = 'First Time Jobseeker' AND barangay_residency_used_for_beso = 1
            UNION ALL
            SELECT res_id FROM urgent_request
             WHERE res_id = ? AND certificate = 'Barangay Residency'
               AND purpose = 'First Time Jobseeker' AND barangay_residency_used_for_beso = 1
            UNION ALL
            SELECT res_id FROM archived_schedules
             WHERE res_id = ? AND certificate = 'Barangay Residency'
               AND purpose = 'First Time Jobseeker' AND barangay_residency_used_for_beso = 1
            UNION ALL
            SELECT res_id FROM archived_urgent_request
             WHERE res_id = ? AND certificate = 'Barangay Residency'
               AND purpose = 'First Time Jobseeker' AND barangay_residency_used_for_beso = 1
        ) AS combined
    ");
    $stmt9->bind_param("iiii", $id, $id, $id, $id);
    $stmt9->execute();
    $stmt9->bind_result($residencyUsedCount);
    $stmt9->fetch();
    $stmt9->close();

    $hasResidencyUsed = $residencyUsedCount > 0;

    // ✅ Check if Resident has ANY Residency (FTJ)
    $stmt10 = $mysqli->prepare("
        SELECT COUNT(*) FROM (
            SELECT res_id FROM schedules WHERE res_id = ? AND certificate = 'Barangay Residency' AND purpose = 'First Time Jobseeker'
            UNION ALL
            SELECT res_id FROM urgent_request WHERE res_id = ? AND certificate = 'Barangay Residency' AND purpose = 'First Time Jobseeker'
            UNION ALL
            SELECT res_id FROM archived_schedules WHERE res_id = ? AND certificate = 'Barangay Residency' AND purpose = 'First Time Jobseeker'
            UNION ALL
            SELECT res_id FROM archived_urgent_request WHERE res_id = ? AND certificate = 'Barangay Residency' AND purpose = 'First Time Jobseeker'
        ) AS combined
    ");
    $stmt10->bind_param("iiii", $id, $id, $id, $id);
    $stmt10->execute();
    $stmt10->bind_result($residencyTotal);
    $stmt10->fetch();
    $stmt10->close();

    $hasResidency = $residencyTotal > 0;

    // ✅ Used for Clearance or Indigency Check
    $stmt13 = $mysqli->prepare("
        SELECT 
            MAX(used_for_clearance) AS clearance_used, 
            MAX(used_for_indigency) AS indigency_used
        FROM (
            SELECT used_for_clearance, used_for_indigency FROM schedules WHERE res_id = ? AND certificate = 'BESO Application'
            UNION ALL
            SELECT used_for_clearance, used_for_indigency FROM urgent_request WHERE res_id = ? AND certificate = 'BESO Application'
            UNION ALL
            SELECT used_for_clearance, used_for_indigency FROM archived_schedules WHERE res_id = ? AND certificate = 'BESO Application'
            UNION ALL
            SELECT used_for_clearance, used_for_indigency FROM archived_urgent_request WHERE res_id = ? AND certificate = 'BESO Application'
        ) AS combined
    ");
    $stmt13->bind_param("iiii", $id, $id, $id, $id);
    $stmt13->execute();
    $stmt13->bind_result($clearanceUsed, $indigencyUsed);
    $stmt13->fetch();
    $stmt13->close();

    $hasClearanceUsed = intval($clearanceUsed) > 0;
    $hasIndigencyUsed = intval($indigencyUsed) > 0;

    // ✅ Soft-deleted Cedula Check
    $stmtSoft = $mysqli->prepare("
      SELECT SUM(cnt) FROM (
        SELECT COUNT(*) AS cnt FROM cedula WHERE res_id = ? AND cedula_delete_status = 1
        UNION ALL
        SELECT COUNT(*) AS cnt FROM urgent_cedula_request WHERE res_id = ? AND cedula_delete_status = 1
      ) s
    ");
    $stmtSoft->bind_param("ii", $id, $id);
    $stmtSoft->execute();
    $stmtSoft->bind_result($softCnt);
    $stmtSoft->fetch();
    $stmtSoft->close();

    $hasSoftDeletedCedula = (int)$softCnt > 0;

    // ✅ Fetch Latest Cedula Status for UI Badge
    $cedula_status = null;
    $cedulaQuery = $mysqli->prepare("
        SELECT cedula_status 
        FROM (
            SELECT cedula_status, issued_at FROM cedula WHERE res_id = ? AND cedula_delete_status = 0
            UNION ALL
            SELECT cedula_status, issued_at FROM urgent_cedula_request WHERE res_id = ? AND cedula_delete_status = 0
        ) AS all_cedula
        ORDER BY issued_at DESC LIMIT 1
    ");
    $cedulaQuery->bind_param("ii", $id, $id);
    $cedulaQuery->execute();
    $cedulaQuery->bind_result($status);
    if ($cedulaQuery->fetch()) {
        $cedula_status = strtolower($status);
    }
    $cedulaQuery->close();

    // ✅ JSON Output
    echo json_encode([
        'success' => true,
        'full_name' => trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'] . ' ' . $row['suffix_name']),
        'birth_date' => $row['birth_date'],
        'birth_place' => $row['birth_place'],
        'res_zone' => $row['res_zone'],
        'res_street_address' => $row['res_street_address'],
        'has_approved_cedula' => $hasApprovedCedula,
        'has_pending_cedula' => $hasPendingCedula,
        'has_ongoing_case' => $hasOngoingCase,
        'has_existing_beso' => $hasExistingBeso,
        'has_residency_used' => $hasResidencyUsed,
        'has_residency' => $hasResidency,
        'cedula_status' => $cedula_status,
        'has_clearance_used' => $hasClearanceUsed,
        'has_indigency_used' => $hasIndigencyUsed,
        'has_soft_deleted_cedula' => $hasSoftDeletedCedula,
        'age' => $age,
        'pending_certificates' => $pendingCertificates
    ]);
} else {
    echo json_encode(['success' => false]);
}
?>