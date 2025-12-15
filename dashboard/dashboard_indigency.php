<?php
// FILE: dashboard/dashboard_indigency.php
header('Content-Type: application/json');

// 1. DATABASE CONNECTION
require_once __DIR__ . '/../include/connection.php'; 
$mysqli = db_connection();

// 2. DEFAULT RESPONSE
$response = [
    'total' => 0,
    'urgent' => 0, // We use this key for "Today's Requests"
    'pending' => 0,
    'genderData' => ['Male' => 0, 'Female' => 0],
    'recentActivities' => []
];

// 3. FILTER: LOOK FOR "INDIGENCY" CERTIFICATES
$certFilter = "certificate LIKE '%Indigency%'";

// --- QUERY 1: TOTAL INDIGENCY APPLICATIONS ---
// Counts active (non-deleted) apps from both tables
$sqlTotal = "
    SELECT COUNT(*) as c FROM schedules 
    WHERE $certFilter AND appointment_delete_status = 0
    UNION ALL
    SELECT COUNT(*) as c FROM urgent_request 
    WHERE $certFilter AND urgent_delete_status = 0
";
$result = $mysqli->query($sqlTotal);
if ($result) {
    while ($row = $result->fetch_assoc()) { 
        $response['total'] += $row['c']; 
    }
}

// --- QUERY 2: TODAY'S REQUESTS ---
// Counts apps where 'selected_date' is today
$today = date('Y-m-d');
$sqlToday = "
    SELECT COUNT(*) as c FROM schedules 
    WHERE $certFilter AND selected_date = '$today' AND appointment_delete_status = 0
    UNION ALL
    SELECT COUNT(*) as c FROM urgent_request 
    WHERE $certFilter AND selected_date = '$today' AND urgent_delete_status = 0
";
$result = $mysqli->query($sqlToday);
if ($result) {
    while ($row = $result->fetch_assoc()) { 
        $response['urgent'] += $row['c']; 
    }
}

// --- QUERY 3: PENDING APPLICATIONS ---
$sqlPending = "
    SELECT COUNT(*) as c FROM schedules 
    WHERE $certFilter AND status = 'Pending' AND appointment_delete_status = 0
    UNION ALL
    SELECT COUNT(*) as c FROM urgent_request 
    WHERE $certFilter AND status = 'Pending' AND urgent_delete_status = 0
";
$result = $mysqli->query($sqlPending);
if ($result) {
    while ($row = $result->fetch_assoc()) { 
        $response['pending'] += $row['c']; 
    }
}

// --- QUERY 4: GENDER DISTRIBUTION ---
// Joins with residents table to count Male vs Female
$sqlGender = "
    SELECT r.gender, COUNT(*) as c 
    FROM schedules s 
    JOIN residents r ON s.res_id = r.id 
    WHERE s.certificate LIKE '%Indigency%' AND s.appointment_delete_status = 0
    GROUP BY r.gender
    UNION ALL
    SELECT r.gender, COUNT(*) as c 
    FROM urgent_request u 
    JOIN residents r ON u.res_id = r.id 
    WHERE u.certificate LIKE '%Indigency%' AND u.urgent_delete_status = 0
    GROUP BY r.gender
";
$result = $mysqli->query($sqlGender);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $g = ucfirst(strtolower($row['gender'])); // Normalize 'Male', 'male', 'MALE'
        if (isset($response['genderData'][$g])) {
            $response['genderData'][$g] += $row['c'];
        }
    }
}

// --- QUERY 5: RECENT ACTIVITY (Last 5) ---
$sqlRecent = "
    SELECT 'Appointment' as module, status, created_at FROM schedules 
    WHERE $certFilter AND appointment_delete_status = 0
    UNION ALL
    SELECT 'Urgent Request' as module, status, created_at FROM urgent_request 
    WHERE $certFilter AND urgent_delete_status = 0
    ORDER BY created_at DESC LIMIT 5
";
$result = $mysqli->query($sqlRecent);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $response['recentActivities'][] = [
            'date_human' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'action' => $row['status'],
            'action_by' => 'System',
            'module' => $row['module']
        ];
    }
}

// Output Data as JSON
echo json_encode($response);
$mysqli->close();
?>