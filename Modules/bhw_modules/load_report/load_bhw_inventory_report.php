<?php
require_once __DIR__ . '/../../../include/connection.php'; 
$mysqli = db_connection();

// --- IMAGE FIXER (Ensures logos print) ---
function getBase64Image($path) {
    if (file_exists($path)) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    return '';
}

$logo1Path = __DIR__ . '/../../../assets/img/logo_1.png'; 
$logo2Path = __DIR__ . '/../../../assets/img/logo_2.png'; 

$logo1Src = getBase64Image($logo1Path);
$logo2Src = getBase64Image($logo2Path);
// -----------------------------------------

// Fetch Inventory Data
$query = "SELECT * FROM medicine_inventory WHERE delete_status = 0 ORDER BY medicine_name ASC";
$result = $mysqli->query($query);
?>

<div class="d-flex align-items-center justify-content-between mb-4 mt-2 w-100">
    <div class="d-flex align-items-center gap-2">
        <?php if($logo1Src): ?>
            <img src="<?php echo $logo1Src; ?>" alt="Logo 1" style="height: 60px; width: auto;">
        <?php endif; ?>

        <?php if($logo2Src): ?>
            <img src="<?php echo $logo2Src; ?>" alt="Logo 2" style="height: 60px; width: auto;">
        <?php endif; ?>
    </div>
    
    <div class="text-center flex-grow-1 px-2">
        <h4 class="fw-bold m-0 text-dark" style="font-family: Arial, sans-serif; text-transform: uppercase;">LGU Barangay Bugo - Inventory Report</h4>
        <small class="text-muted">Medicine Stock Management Record</small>
    </div>
    
    <div class="text-end" style="min-width: 80px;">
        <span class="fw-bold text-dark">Year: <?php echo date('Y'); ?></span>
    </div>
</div>

<div class="table-wrapper" style="border: 1px solid #dee2e6; padding: 1px; margin-bottom: 20px;">
    <?php if ($result && $result->num_rows > 0): ?>
        <table class="table report-table w-100 mb-0">
            <thead>
                <tr>
                    <th style="width: 30%;">Medicine Name</th>
                    <th style="width: 20%;">Category</th>
                    <th style="width: 15%;">Stock Qty</th>
                    <th style="width: 15%;">Unit</th>
                    <th style="width: 20%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <?php 
                    $stock = (int)$row['stock_quantity'];
                    // Row styling for low stock (optional, mostly for screen)
                    $rowClass = ($stock == 0) ? 'bg-light-danger' : '';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td class="fw-bold text-dark text-start ps-3"><?php echo htmlspecialchars($row['medicine_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    
                    <td class="fw-bold">
                        <?php if($stock == 0): ?>
                            <span class="text-danger"><?php echo $stock; ?></span>
                        <?php elseif($stock <= 20): ?>
                            <span class="text-warning text-dark-print"><?php echo $stock; ?></span>
                        <?php else: ?>
                            <span class="text-success text-dark-print"><?php echo $stock; ?></span>
                        <?php endif; ?>
                    </td>

                    <td><?php echo htmlspecialchars($row['unit']); ?></td>
                    
                    <td>
                        <?php if ($stock == 0): ?>
                            <span class="fw-bold text-danger">OUT OF STOCK</span>
                        <?php elseif ($stock <= 20): ?>
                            <span class="fw-bold text-dark">LOW STOCK</span>
                        <?php else: ?>
                            <span class="fw-bold text-success">Available</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="p-4 text-center text-muted">No inventory records found.</div>
    <?php endif; ?>
</div>

<div class="row mt-5 text-center w-100 mx-0" style="page-break-inside: avoid;">
    <div class="col-4">
        <p class="small fw-bold mb-4">Prepared by:</p>
        <p class="fw-bold mb-0 text-uppercase">Merlito Galacio</p>
        <p class="small text-muted">BHW Secretary</p>
    </div>
    <div class="col-4">
        <p class="small fw-bold mb-4">Noted by:</p>
        <p class="fw-bold mb-0 text-uppercase">Emilor J. Cabanos</p>
        <p class="small text-muted">Barangay Secretary</p>
    </div>
    <div class="col-4">
        <p class="small fw-bold mb-4">Attested by:</p>
        <p class="fw-bold mb-0 text-uppercase">Spencer L. Cailing</p>
        <p class="small text-muted">Punong Barangay</p>
    </div>
</div>