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

// 1. Count Active Liaison Tasks (Approved OR Picked Up)
// 'Approved' = Ready for Liaison to Pick Up
// 'Picked Up' = Ready for Liaison to Start Delivery
$countSql = "SELECT COUNT(*) as total 
             FROM medicine_requests 
             WHERE status IN ('Approved', 'Picked Up') 
             AND delete_status = 0";

$countRes = $mysqli->query($countSql);
$count = $countRes ? $countRes->fetch_assoc()['total'] : 0;

// 2. Get the Details (For the Dropdown List)
$listSql = "
    SELECT mr.id, mr.request_date, mr.status, r.first_name, r.last_name, r.res_street_address
    FROM medicine_requests mr
    JOIN residents r ON mr.res_id = r.id
    WHERE mr.status IN ('Approved', 'Picked Up') 
    AND mr.delete_status = 0
    ORDER BY mr.request_date DESC 
    LIMIT 5
";
$listRes = $mysqli->query($listSql);

$data = [];
if ($listRes) {
    while ($row = $listRes->fetch_assoc()) {
        $phpDate = strtotime($row['request_date']);
        $formattedDate = date('M d, h:i A', $phpDate);
        
        // Custom message based on status
        $statusMsg = ($row['status'] === 'Approved') ? 'Ready for Pickup' : 'Ready for Delivery';
        
        $data[] = [
            'id' => $row['id'],
            'resident_name' => $row['first_name'] . ' ' . $row['last_name'],
            'address' => $row['res_street_address'], 
            'date' => $formattedDate,
            'status_msg' => $statusMsg // Optional: passing specific status text
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'count' => $count,
    'data' => $data
]);
?>