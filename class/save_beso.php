<?php
declare(strict_types=1);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

session_start();

$respond = function (int $code, array $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
};

function clean($v): string {
    return htmlspecialchars(strip_tags(trim((string)$v)), ENT_QUOTES, 'UTF-8');
}

/* ---- Auth ---- */
if (empty($_SESSION['employee_id'])) {
    $respond(403, ['success' => false, 'message' => 'Forbidden: not logged in.']);
}
$employee_id = (int)$_SESSION['employee_id'];

/* ---- Input ---- */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$res_id  = isset($data['userId']) ? (int)$data['userId'] : 0;
$attain  = clean($data['education_attainment'] ?? '');
$course  = clean($data['course'] ?? '');
$purpose = clean($data['purpose'] ?? '');

if ($res_id <= 0 || $attain === '' || $course === '') {
    $respond(400, ['success' => false, 'message' => 'Missing required fields (userId, education_attainment, course).']);
}

try {
    $mysqli->begin_transaction();

    // Fetch resident name parts
    $stmt = $mysqli->prepare("
        SELECT 
            first_name,
            COALESCE(NULLIF(middle_name,''), '') AS middle_name,
            last_name,
            COALESCE(NULLIF(suffix_name,''), '') AS suffix_name
        FROM residents
        WHERE id = ? AND resident_delete_status = 0
        LIMIT 1
    ");
    $stmt->bind_param('i', $res_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        $mysqli->rollback();
        $respond(404, ['success' => false, 'message' => 'Resident not found.']);
    }
    $r = $res->fetch_assoc();
    $stmt->close();

    $first  = $r['first_name'];
    $middle = $r['middle_name'];
    $last   = $r['last_name'];
    $suffix = $r['suffix_name'];

    // Duplicate check by full name
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) 
        FROM beso 
        WHERE firstName = ? 
          AND COALESCE(middleName,'') = ? 
          AND lastName = ? 
          AND COALESCE(suffixName,'') = ?
          AND beso_delete_status = 0
    ");
    $stmt->bind_param('ssss', $first, $middle, $last, $suffix);
    $stmt->execute();
    $stmt->bind_result($existing);
    $stmt->fetch();
    $stmt->close();

    if ($existing > 0) {
        $mysqli->rollback();
        $respond(200, ['success' => false, 'message' => 'This resident already has a BESO record.']);
    }

    // ----- Generate series number (YYY-NNN) e.g., 025-010 for year 2025 #10
    $yearCode = substr(date('Y'), 1);            // '2025' -> '025'
    $like     = $yearCode . '-%';

    $stmt = $mysqli->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(seriesNum, '-', -1) AS UNSIGNED)), 0) AS max_seq
        FROM beso
        WHERE seriesNum LIKE ?
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $stmt->bind_result($maxSeq);
    $stmt->fetch();
    $stmt->close();

    $nextSeq   = (int)$maxSeq + 1;
    $seriesNum = sprintf('%s-%03d', $yearCode, $nextSeq); // e.g., 025-001

    // Insert BESO record with seriesNum
    $stmt = $mysqli->prepare("
        INSERT INTO beso (
            seriesNum,
            firstName, middleName, lastName, suffixName,
            education_attainment, course, purpose,
            employee_id, beso_delete_status, created_at
        ) VALUES (?,?,?,?,?,?,?, ?, ?, 0, NOW())
    ");
    $stmt->bind_param(
        'ssssssssi',
        $seriesNum,
        $first, $middle, $last, $suffix,
        $attain, $course, $purpose,
        $employee_id
    );
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();

    $respond(200, ['success' => true, 'seriesNum' => $seriesNum]);

} catch (mysqli_sql_exception $e) {
    // Optional: if you add UNIQUE(seriesNum), handle race by retrying on duplicate-key
    if ($mysqli->errno === 1062) {
        $mysqli->rollback();
        $respond(409, ['success' => false, 'message' => 'Series number collision. Please retry.']);
    }
    $mysqli->rollback();
    $respond(500, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    $mysqli->rollback();
    $respond(500, ['success' => false, 'message' => $e->getMessage()]);
}
