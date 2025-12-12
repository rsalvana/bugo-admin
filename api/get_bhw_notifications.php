<?php
// File: api/get_bhw_notifications.php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

session_start();

// Security: Only allow if logged in
if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['count' => 0, 'data' => []]);
    exit;
}

// 1. Count Pending Requests (For the Red Badge)
// We check delete_status = 0 to ensure we don't count deleted items
$countSql = "SELECT COUNT(*) as total FROM medicine_requests WHERE status = 'Pending' AND delete_status = 0";
$countRes = $mysqli->query($countSql);
$count = $countRes ? $countRes->fetch_assoc()['total'] : 0;

// 2. Get the Details (For the Dropdown List)
// We join with 'residents' table to get the name of who is requesting
$listSql = "
    SELECT mr.id, mr.request_date, r.first_name, r.last_name 
    FROM medicine_requests mr
    JOIN residents r ON mr.res_id = r.id
    WHERE mr.status = 'Pending' AND mr.delete_status = 0
    ORDER BY mr.request_date DESC 
    LIMIT 5
";
$listRes = $mysqli->query($listSql);

$data = [];
if ($listRes) {
    while ($row = $listRes->fetch_assoc()) {
        // Format date (e.g., "Oct 25, 10:30 AM")
        $phpDate = strtotime($row['request_date']);
        $formattedDate = date('M d, h:i A', $phpDate);
        
        $data[] = [
            'id' => $row['id'],
            'resident_name' => $row['first_name'] . ' ' . $row['last_name'],
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