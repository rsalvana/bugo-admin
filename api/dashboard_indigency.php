<?php
// FILE: dashboard/dashboard_indigency.php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/connection.php'; 
$mysqli = db_connection();

$response = [
    'total' => 0,
    'urgent' => 0,
    'pending' => 0,
    'genderData' => ['Male' => 0, 'Female' => 0],
    'recentActivities' => []
];

// FILTER: We only look for 'Indigency' certificates
$filter = "certificate LIKE '%Indigency%'";

// 1. Total Indigency Applications
$q1 = "SELECT COUNT(*) as c FROM schedules WHERE $filter AND appointment_delete_status=0
       UNION ALL
       SELECT COUNT(*) as c FROM urgent_request WHERE $filter AND urgent_delete_status=0";
$r1 = $mysqli->query($q1);
while ($row = $r1->fetch_assoc()) { $response['total'] += $row['c']; }

// 2. Today's Requests
$today = date('Y-m-d');
$q2 = "SELECT COUNT(*) as c FROM schedules WHERE $filter AND selected_date = '$today' AND appointment_delete_status=0
       UNION ALL
       SELECT COUNT(*) as c FROM urgent_request WHERE $filter AND selected_date = '$today' AND urgent_delete_status=0";
$r2 = $mysqli->query($q2);
while ($row = $r2->fetch_assoc()) { $response['urgent'] += $row['c']; }

// 3. Pending
$q3 = "SELECT COUNT(*) as c FROM schedules WHERE $filter AND status = 'Pending' AND appointment_delete_status=0
       UNION ALL
       SELECT COUNT(*) as c FROM urgent_request WHERE $filter AND status = 'Pending' AND urgent_delete_status=0";
$r3 = $mysqli->query($q3);
while ($row = $r3->fetch_assoc()) { $response['pending'] += $row['c']; }

// 4. Recent Activity (Last 5)
$q4 = "SELECT 'Appointment' as module, status, created_at FROM schedules 
       WHERE $filter ORDER BY created_at DESC LIMIT 5";
$r4 = $mysqli->query($q4);
while ($row = $r4->fetch_assoc()) {
    $response['recentActivities'][] = [
        'date_human' => date('M d, Y h:i A', strtotime($row['created_at'])),
        'action' => $row['status'],
        'action_by' => 'System' // Or join with employee table if you track who updated it
    ];
}

echo json_encode($response);
$mysqli->close();
?>