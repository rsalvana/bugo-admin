<?php
// api/get_liason_notifications.php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

session_start();

// Security: Only allow if logged in
if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['count' => 0, 'data' => []]);
    exit;
}

// 1. Count APPROVED Requests (This is the Liason's trigger)
// We count items that are 'Approved' because these are waiting for the Liason to pick up/deliver.
// We also exclude deleted items.
$countSql = "SELECT COUNT(*) as total FROM medicine_requests WHERE status = 'Approved' AND delete_status = 0";
$countRes = $mysqli->query($countSql);
$count = $countRes ? $countRes->fetch_assoc()['total'] : 0;

// 2. Get the Details (For the Dropdown List)
$listSql = "
    SELECT mr.id, mr.request_date, r.first_name, r.last_name, r.res_street_address
    FROM medicine_requests mr
    JOIN residents r ON mr.res_id = r.id
    WHERE mr.status = 'Approved' AND mr.delete_status = 0
    ORDER BY mr.request_date DESC 
    LIMIT 5
";
$listRes = $mysqli->query($listSql);

$data = [];
if ($listRes) {
    while ($row = $listRes->fetch_assoc()) {
        $phpDate = strtotime($row['request_date']);
        $formattedDate = date('M d, h:i A', $phpDate);
        
        $data[] = [
            'id' => $row['id'],
            'resident_name' => $row['first_name'] . ' ' . $row['last_name'],
            'address' => $row['res_street_address'], // Helpful for Liason to see address
            'date' => $formattedDate
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'count' => $count,
    'data' => $data
]);
?>