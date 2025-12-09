<?php
// api/bhw_dashboard_stats.php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Filters
$startDate = $_GET['start_date'] ?? '2000-01-01';
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// --- 1. CARD COUNTS ---

// Total Requests (Active)
// Source: medicine_requests table
$stmt = $mysqli->prepare("SELECT COUNT(*) FROM medicine_requests WHERE delete_status = 0 AND DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$totalRequests = $stmt->get_result()->fetch_row()[0];
$stmt->close();

// Pending Requests (Actionable)
// Source: medicine_requests table where status is 'Pending'
$pendingRequests = $mysqli->query("SELECT COUNT(*) FROM medicine_requests WHERE status = 'Pending' AND delete_status = 0")->fetch_row()[0];

// Medicines Available (Unique Categories/Types)
// Source: medicine_inventory table where status is 'Available' and stock > 0
$medsAvailable = $mysqli->query("SELECT COUNT(*) FROM medicine_inventory WHERE status = 'Available' AND stock_quantity > 0 AND delete_status = 0")->fetch_row()[0];

// Total Stock Count (Sum of all units)
// Source: medicine_inventory table, sum of stock_quantity
$totalStock = $mysqli->query("SELECT SUM(stock_quantity) FROM medicine_inventory WHERE delete_status = 0")->fetch_row()[0];


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
// Joins medicine_request_items with inventory to get names
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

// --- 4. RECENT ACTIVITY TABLE ---
// Fetches the 5 most recent requests
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
    'medsAvailable'   => $medsAvailable ?? 0,
    'totalStock'      => $totalStock ?? 0,
    'statusLabels'    => $statusLabels,
    'statusValues'    => $statusValues,
    'statusData'      => array_combine($statusLabels, $statusValues), // For pie chart
    'topMedsLabels'   => $topMedsLabels,
    'topMedsValues'   => $topMedsValues,
    'topMeds'         => array_combine($topMedsLabels, $topMedsValues), // For bar chart
    'recentActivity'  => $recentActivity
]);
?>