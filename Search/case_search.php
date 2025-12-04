<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
session_start();

$user_role = strtolower($_SESSION['Role_Name'] ?? '');
if (!in_array($user_role, ['lupon','punong barangay','barangay secretary','admin'], true)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json; charset=UTF-8');

function sanitize_input($s) { return htmlspecialchars(strip_tags(trim((string)$s)), ENT_QUOTES, 'UTF-8'); }

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$limit  = 100;
$page   = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) && (int)$_GET['pagenum'] > 0) ? (int)$_GET['pagenum'] : 1;
$offset = ($page - 1) * $limit;

$searchTerm = '%' . $search . '%';

// Helper to format names
$participantNameExpr = "TRIM(CONCAT_WS(' ', cp.first_name, NULLIF(cp.middle_name,''), cp.last_name, NULLIF(cp.suffix_name,'')))";

// ---------------------------------------------------------
// 1. COUNT TOTAL
// ---------------------------------------------------------
// logic: join tables, but use TRIM() on case_number to avoid mismatch due to spaces
$count_sql = "
    SELECT COUNT(DISTINCT c.case_number) AS total
    FROM cases c
    LEFT JOIN case_participants cp ON TRIM(c.case_number) = TRIM(cp.case_number)
    WHERE c.case_number LIKE ? 
       OR $participantNameExpr LIKE ?
";

if (!$stmt = $mysqli->prepare($count_sql)) {
    echo json_encode(['error' => 'Prepare failed (count): '.$mysqli->error]); exit;
}
$stmt->bind_param('ss', $searchTerm, $searchTerm);
$stmt->execute();
$res = $stmt->get_result();
$total_rows  = (int)($res->fetch_assoc()['total'] ?? 0);
$total_pages = (int)ceil($total_rows / $limit);
$stmt->close();

// ---------------------------------------------------------
// 2. FETCH DATA
// ---------------------------------------------------------
// We use TRIM() in the subqueries to ensure we match the participants even if there are accidental spaces.
$data_sql = "
    SELECT 
        c.*,
        /* --- ADDED THIS LINE TO FIX 'PENDING' BADGES --- */
        (SELECT action_taken FROM case_participants WHERE case_number = c.case_number AND action_taken IN ('Appearance', 'Non-Appearance') LIMIT 1) as attendance_status,
        /* ----------------------------------------------- */
        (
            SELECT GROUP_CONCAT(
                TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name, NULLIF(suffix_name,'')))
                SEPARATOR '<br>'
            )
            FROM case_participants cp 
            WHERE TRIM(cp.case_number) = TRIM(c.case_number) AND cp.role = 'Complainant'
        ) AS complainant_list,
        (
            SELECT GROUP_CONCAT(
                TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name, NULLIF(suffix_name,'')))
                SEPARATOR '<br>'
            )
            FROM case_participants cp 
            WHERE TRIM(cp.case_number) = TRIM(c.case_number) AND cp.role = 'Respondent'
        ) AS respondent_list
    FROM cases c
    WHERE c.case_number LIKE ?
       OR EXISTS (
           SELECT 1 FROM case_participants cp 
           WHERE TRIM(cp.case_number) = TRIM(c.case_number)
           AND TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name, NULLIF(suffix_name,''))) LIKE ?
       )
    ORDER BY c.date_filed DESC, c.case_number DESC
    LIMIT ? OFFSET ?
";

if (!$stmt = $mysqli->prepare($data_sql)) {
    echo json_encode(['error' => 'Prepare failed (data): '.$mysqli->error]); exit;
}
$stmt->bind_param('ssii', $searchTerm, $searchTerm, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    // If the list is empty (NULL), fill it with the old single column data as a fallback
    if (empty($row['complainant_list'])) {
        $row['complainant_list'] = trim(($row['Comp_First_Name'] ?? '') . ' ' . ($row['Comp_Last_Name'] ?? ''));
    }
    if (empty($row['respondent_list'])) {
        $row['respondent_list'] = trim(($row['Resp_First_Name'] ?? '') . ' ' . ($row['Resp_Last_Name'] ?? ''));
    }
    $rows[] = $row;
}
$stmt->close();

echo json_encode([
    'cases'        => $rows,
    'total_pages'  => $total_pages,
    'current_page' => $page,
    'total_rows'   => $total_rows
]);
?>