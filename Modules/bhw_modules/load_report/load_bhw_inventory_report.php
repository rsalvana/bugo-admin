<?php
// CHANGE THIS LINE: add an extra "../"
require_once __DIR__ . '/../../../include/connection.php';

$mysqli = db_connection();
// ... rest of the code ...

$query = "SELECT * FROM medicine_inventory WHERE delete_status = 0 ORDER BY medicine_name ASC";
$result = $mysqli->query($query);
?>

<?php if ($result && $result->num_rows > 0): ?>
    <div class="text-end mb-2">
        <small>Report Generated: <?php echo date('M d, Y h:i A'); ?></small>
    </div>
    <table class="table table-bordered table-hover">
        <thead>
            <tr>
                <th>Medicine Name</th>
                <th>Category</th>
                <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <?php 
                // Highlight low stock
                $rowClass = ($row['stock_quantity'] <= 10) ? 'table-warning' : ''; 
                if ($row['stock_quantity'] == 0) $rowClass = 'table-danger';
            ?>
            <tr class="<?php echo $rowClass; ?>">
                <td class="fw-bold text-start"><?php echo htmlspecialchars($row['medicine_name']); ?></td>
                <td><?php echo htmlspecialchars($row['category']); ?></td>
                <td><?php echo number_format($row['stock_quantity']); ?></td>
                <td><?php echo htmlspecialchars($row['unit']); ?></td>
                <td>
                    <?php if ($row['stock_quantity'] > 0): ?>
                        <span class="badge bg-success">Available</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Out of Stock</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info text-center">No medicine inventory records found.</div>
<?php endif; ?>