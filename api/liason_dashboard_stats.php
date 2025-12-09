<?php
// api/liason_dashboard_stats.php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json');

// Security Check (Ensure user is logged in)
if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Date Filters
$startDate = $_GET['start_date'] ?? '2000-01-01';
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// --- 1. CARD COUNTS ---

// Total Requests (Active - Filtered by Date)
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM medicine_requests WHERE delete_status = 0 AND DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$totalRequests = $stmt->get_result()->fetch_row()[0];
$stmt->close();

// Pending Requests (Snapshot - Current Tasks)
$pendingRequests = $mysqli->query("SELECT COUNT(*) FROM medicine_requests WHERE status = 'Pending' AND delete_status = 0")->fetch_row()[0];

// Delivered (Filtered by Date - Completed Work)
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM medicine_requests WHERE status = 'Delivered' AND delete_status = 0 AND DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$delivered = $stmt->get_result()->fetch_row()[0];
$stmt->close();

// On Delivery (Snapshot - Current Tasks)
$onDelivery = $mysqli->query("SELECT COUNT(*) FROM medicine_requests WHERE status = 'On Delivery' AND delete_status = 0")->fetch_row()[0];


// --- 2. PIE CHART: Status Overview ---
$stmt = $mysqli->prepare("SELECT status, COUNT(*) as count FROM medicine_requests WHERE delete_status = 0 AND DATE(request_date) BETWEEN ? AND ? GROUP BY status");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
$statusLabels = [];
$statusValues = [];
while($row = $res->fetch_assoc()) {
    $statusLabels[] = $row['status'];
    $statusValues[] = $row['count'];
}
$stmt->close();


// --- 3. BAR CHART: Top 5 Requested Medicines ---
$stmt = $mysqli->prepare("
    SELECT mi.medicine_name, SUM(mri.quantity_requested) as total_qty
    FROM medicine_request_items mri
    JOIN medicine_requests mr ON mri.request_id = mr.id
    JOIN medicine_inventory mi ON mri.medicine_id = mi.id
    WHERE mr.delete_status = 0 AND DATE(mr.request_date) BETWEEN ? AND ?
    GROUP BY mri.medicine_id
    ORDER BY total_qty DESC
    LIMIT 5
");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$res = $stmt->get_result();
$topMedsLabels = [];
$topMedsValues = [];
while($row = $res->fetch_assoc()) {
    $topMedsLabels[] = $row['medicine_name'];
    $topMedsValues[] = $row['total_qty'];
}
$stmt->close();

// --- 4. RECENT ACTIVITY TABLE (Last 5) ---
$recentRes = $mysqli->query("
    SELECT mr.request_date, mr.status, r.first_name, r.last_name 
    FROM medicine_requests mr 
    JOIN residents r ON mr.res_id = r.id 
    WHERE mr.delete_status = 0 
    ORDER BY mr.request_date DESC LIMIT 5
");
$recentActivity = [];
while($row = $recentRes->fetch_assoc()) {
    $recentActivity[] = [
        'resident'   => $row['first_name'] . ' ' . $row['last_name'],
        'date_human' => date('M d, Y', strtotime($row['request_date'])),
        'status'     => $row['status']
    ];
}

// Return JSON Data
echo json_encode([
    'totalRequests'   => $totalRequests ?? 0,
    'pendingRequests' => $pendingRequests ?? 0,
    'delivered'       => $delivered ?? 0,
    'onDelivery'      => $onDelivery ?? 0,
    'statusLabels'    => $statusLabels,
    'statusValues'    => $statusValues,
    'topMedsLabels'   => $topMedsLabels,
    'topMedsValues'   => $topMedsValues,
    'recentActivity'  => $recentActivity
]);
?>