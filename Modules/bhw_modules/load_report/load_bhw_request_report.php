<?php
require_once __DIR__ . '/../../../include/connection.php'; 
$mysqli = db_connection();

$month = $_GET['month'] ?? '';
$year  = $_GET['year'] ?? '';

// --- IMAGE CONVERTER (To fix missing logos in Print) ---
function getBase64Image($path) {
    if (file_exists($path)) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    return '';
}

// Adjust these paths if needed
$logo1Path = __DIR__ . '/../../../assets/img/logo_1.png'; 
$logo2Path = __DIR__ . '/../../../assets/img/logo_2.png'; 

$logo1Src = getBase64Image($logo1Path);
$logo2Src = getBase64Image($logo2Path);
// -------------------------------------------------------

// Build Query
$whereClause = "WHERE r.delete_status = 0";
if ($month) {
    $whereClause .= " AND MONTH(r.request_date) = '" . $mysqli->real_escape_string($month) . "'";
}
if ($year) {
    $whereClause .= " AND YEAR(r.request_date) = '" . $mysqli->real_escape_string($year) . "'";
}

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

<div class="d-flex align-items-center justify-content-between mb-3 mt-2" style="width: 100%;">
    <div class="d-flex align-items-center gap-2">
        <?php if($logo1Src): ?>
            <img src="<?php echo $logo1Src; ?>" alt="Logo 1" style="height: 60px; width: auto;">
        <?php endif; ?>

        <?php if($logo2Src): ?>
            <img src="<?php echo $logo2Src; ?>" alt="Logo 2" style="height: 60px; width: auto;">
        <?php endif; ?>
    </div>
    
    <div class="text-center flex-grow-1">
        <h4 class="fw-bold m-0 text-dark" style="font-family: Arial, sans-serif;">LGU Barangay Bugo - Medical Report</h4>
    </div>
    
    <div class="text-end" style="min-width: 100px;">
        <span class="text-muted">Year: <?php echo $year ? $year : date('Y'); ?></span>
    </div>
</div>
<style>
    .report-box {
        width: 100%;        /* Stretches to fill the entire available space */
        max-width: 100%;    /* Ensures it doesn't get restricted */
        padding: 10px;      /* Adds a little breathing room inside */
        box-sizing: border-box; /* Ensures padding doesn't break the width */
    }
    
</style>
<div class="report-box">
    <?php if ($result && $result->num_rows > 0): ?>
        <table class="table table-custom w-100 mb-0">
            <thead>
                <tr>
                    <th style="width: 50%;">Resident ID</th>
                    <th style="width: 50%;">Medicines (Qty)</th>
                    <th style="width: 50%;">Status</th>
                    <th style="width: 50%;">Date</th>
                    <th style="width: 50%;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td class="fw-bold text-start"><?php echo htmlspecialchars($row['res_id']); ?></td>
                    <td class="text-start"><?php echo $row['medicines_list']; ?></td>
                    <td>
                        <?php 
                            $statusColor = match($row['status']) {
                                'Delivered' => 'text-success',
                                'On Delivery' => 'text-primary',
                                'Pending' => 'text-dark',
                                'Rejected' => 'text-danger',
                                default => 'text-dark'
                            };
                        ?>
                        <span class="fw-bold <?php echo $statusColor; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                    </td>
                    <td><?php echo date('Y-m-d', strtotime($row['request_date'])); ?></td>
                    <td><?php echo date('H:i:s', strtotime($row['request_date'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="p-4 text-center text-muted">No medicine requests found for the selected criteria.</div>
    <?php endif; ?>
</div>

<div class="row mt-5 text-center" style="page-break-inside: avoid;">
    <div class="col-4">
        <p class="small fw-bold mb-5">Prepared by:</p>
        <p class="fw-bold mb-0 text-uppercase">Merlito Galacio</p>
        <p class="small text-muted" style="font-size: 11px;">Tanod, Barangay Executive Secretary, bhw, liason</p>
    </div>
    <div class="col-4">
        <p class="small fw-bold mb-5">Noted by:</p>
        <p class="fw-bold mb-0 text-uppercase">Emilor J. Cabanos</p>
        <p class="small text-muted" style="font-size: 11px;">Barangay Secretary</p>
    </div>
    <div class="col-4">
        <p class="small fw-bold mb-5">Attested by:</p>
        <p class="fw-bold mb-0 text-uppercase">Spencer L. Cailing</p>
        <p class="small text-muted" style="font-size: 11px;">Punong Barangay</p>
    </div>
</div>