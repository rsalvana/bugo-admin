<?php
// med_inventory.php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php'; // Adjust path if needed
$mysqli = db_connection();

// Fetch Inventory (Exclude archived items)
$sql = "SELECT * FROM medicine_inventory WHERE delete_status = 0 ORDER BY medicine_name ASC";
$result = $mysqli->query($sql);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-primary fw-bold"><i class="bi bi-box-seam-fill me-2"></i>Inventory Management</h2>
            <p class="text-muted small mb-0">Manage stock levels, add new items, or archive old ones.</p>
        </div>
        <button class="btn btn-primary rounded-pill shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#addMedModal">
            <i class="bi bi-plus-lg me-1"></i> Add Medicine
        </button>
    </div>

    <div class="card shadow border-0 rounded-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-ul me-2 text-primary"></i>Stock List</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="inventoryTable">
                    <thead class="bg-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-4">Medicine Name</th>
                            <th>Category</th>
                            <th>Stock Level</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php while ($row = $result->fetch_assoc()): 
                            // Get stock as integer for safer comparison
                            $stock = (int)$row['stock_quantity'];
                        ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($row['medicine_name']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['category']) ?></span></td>
                                <td>
                                    <!-- Stock Number Coloring -->
                                    <?php if($stock == 0): ?>
                                        <span class="text-danger fw-bold" style="font-size: 1.1em;">0</span>
                                    <?php elseif($stock <= 20): ?>
                                        <span class="text-warning fw-bold" style="font-size: 1.1em;"><?= $stock ?></span>
                                    <?php else: ?>
                                        <span class="text-success fw-bold" style="font-size: 1.1em;"><?= $stock ?></span>
                                    <?php endif; ?>
                                    
                                    <span class="text-muted small ms-1"><?= htmlspecialchars($row['unit']) ?></span>
                                </td>
                                <td>
                                    <!-- Status Badge Logic -->
                                    <?php if ($stock == 0): ?>
                                        <span class="badge rounded-pill bg-danger px-3">OUT OF STOCK</span>
                                    <?php elseif ($stock <= 20): ?>
                                        <span class="badge rounded-pill bg-warning text-dark px-3">LOW STOCK</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-success px-3">Available</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-outline-primary btn-sm rounded-pill px-3 me-1 edit-btn" 
                                            data-id="<?= $row['id'] ?>"
                                            data-name="<?= htmlspecialchars($row['medicine_name']) ?>"
                                            data-cat="<?= htmlspecialchars($row['category']) ?>"
                                            data-stock="<?= $row['stock_quantity'] ?>"
                                            data-unit="<?= htmlspecialchars($row['unit']) ?>"
                                            data-bs-toggle="modal" data-bs-target="#editMedModal">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm rounded-pill px-3 archive-btn" 
                                            data-id="<?= $row['id'] ?>">
                                        <i class="bi bi-archive"></i> Archive
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ADD MEDICINE MODAL -->
<div class="modal fade" id="addMedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow" id="addMedForm">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Medicine</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="add">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">MEDICINE NAME</label>
                    <input type="text" name="medicine_name" class="form-control form-control-lg" required placeholder="e.g. Biogesic">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">CATEGORY</label>
                    <select name="category" class="form-select">
                        <option value="Analgesic">Analgesic</option>
                        <option value="Antibiotic">Antibiotic</option>
                        <option value="Vitamins">Vitamins</option>
                        <option value="Cough Remedy">Cough Remedy</option>
                        <option value="First Aid">First Aid</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold small text-muted">INITIAL STOCK</label>
                        <input type="number" name="stock_quantity" class="form-control" required min="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold small text-muted">UNIT</label>
                        <select name="unit" class="form-select">
                            <option value="pcs">Pieces</option>
                            <option value="bottles">Bottles</option>
                            <option value="banig">Banig/Strip</option>
                            <option value="box">Box</option>
                            <option value="kit">Kit</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-between p-4 pt-0">
                <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 rounded-pill shadow-sm">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MEDICINE MODAL -->
<div class="modal fade" id="editMedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow" id="editMedForm">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Medicine</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="med_id" id="edit_id">
                
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">MEDICINE NAME</label>
                    <input type="text" name="medicine_name" id="edit_name" class="form-control form-control-lg" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">CATEGORY</label>
                    <select name="category" id="edit_cat" class="form-select">
                        <option value="Analgesic">Analgesic</option>
                        <option value="Antibiotic">Antibiotic</option>
                        <option value="Vitamins">Vitamins</option>
                        <option value="Cough Remedy">Cough Remedy</option>
                        <option value="First Aid">First Aid</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold small text-muted">CURRENT STOCK</label>
                        <input type="number" name="stock_quantity" id="edit_stock" class="form-control" required min="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold small text-muted">UNIT</label>
                        <select name="unit" id="edit_unit" class="form-select">
                            <option value="pcs">Pieces</option>
                            <option value="bottles">Bottles</option>
                            <option value="banig">Banig/Strip</option>
                            <option value="box">Box</option>
                            <option value="kit">Kit</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 justify-content-between p-4 pt-0">
                <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark px-4 rounded-pill shadow-sm">Update Details</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Fill Edit Modal
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value = this.dataset.id;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_cat').value = this.dataset.cat;
            document.getElementById('edit_stock').value = this.dataset.stock;
            document.getElementById('edit_unit').value = this.dataset.unit;
        });
    });

    // Handle Archive
    document.querySelectorAll('.archive-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            Swal.fire({
                title: 'Archive Medicine?',
                text: "This item will be hidden from the inventory list.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, Archive it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'archive');
                    fd.append('med_id', id);
                    submitData(fd);
                }
            });
        });
    });

    // Handle Forms
    document.getElementById('addMedForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitData(new FormData(this));
    });

    document.getElementById('editMedForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitData(new FormData(this));
    });

    function submitData(formData) {
        fetch('api/bhw_inventory_action.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                Swal.fire('Success', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Request failed', 'error'));
    }
});
</script>