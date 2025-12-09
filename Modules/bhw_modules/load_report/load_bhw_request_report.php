<?php
// CHANGE THIS LINE: add an extra "../"
require_once __DIR__ . '/../../../include/connection.php'; 

$mysqli = db_connection();
// ... rest of the code ...

$month = $_GET['month'] ?? '';
$year  = $_GET['year'] ?? '';

// Build Query
$whereClause = "WHERE r.delete_status = 0";
if ($month) {
    $whereClause .= " AND MONTH(r.request_date) = '" . $mysqli->real_escape_string($month) . "'";
}
if ($year) {
    $whereClause .= " AND YEAR(r.request_date) = '" . $mysqli->real_escape_string($year) . "'";
}

// Join requests with items and medicine details
// Note: Assuming 'residents' table exists for res_id. If not, remove the JOIN and resident_name.
$query = "
    SELECT 
        r.id, 
        r.request_date, 
        r.res_id,
        r.status, 
        r.remarks,
        GROUP_CONCAT(CONCAT(m.medicine_name, ' (', ri.quantity_requested, ')') SEPARATOR '<br>') as medicines_list
    FROM medicine_requests r
    LEFT JOIN medicine_request_items ri ON r.id = ri.request_id
    LEFT JOIN medicine_inventory m ON ri.medicine_id = m.id
    $whereClause
    GROUP BY r.id
    ORDER BY r.request_date DESC
";

$result = $mysqli->query($query);
?>

<?php if ($result && $result->num_rows > 0): ?>
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Request ID</th>
                <th>Date</th>
                <th>Resident ID</th>
                <th>Medicines (Qty)</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td>#<?php echo htmlspecialchars($row['id']); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($row['request_date'])); ?></td>
                <td><?php echo htmlspecialchars($row['res_id']); ?></td>
                <td class="text-start"><?php echo $row['medicines_list']; ?></td>
                <td>
                    <?php 
                        $statusColor = match($row['status']) {
                            'Approved', 'Delivered', 'Picked Up' => 'text-success',
                            'Pending' => 'text-warning',
                            'Rejected' => 'text-danger',
                            default => 'text-dark'
                        };
                    ?>
                    <span class="fw-bold <?php echo $statusColor; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                </td>
                <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info text-center">No medicine requests found for the selected criteria.</div>
<?php endif; ?>