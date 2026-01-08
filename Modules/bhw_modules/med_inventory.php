<?php
// med_inventory.php (BHW SIDE)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// =============================================================
// 1. HANDLE ARCHIVE LOGIC (Add this block)
// =============================================================
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    
    // Update delete_status to 1
    $stmt = $mysqli->prepare("UPDATE medicine_inventory SET delete_status = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Success! Reload the page and remove 'delete_id' from URL
        echo "<script>
            const url = new URL(window.location.href);
            url.searchParams.delete('delete_id');
            window.location.href = url.toString();
        </script>";
        exit;
    }
}

// --- FILTERS & SEARCH ---
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';

// --- PAGINATION SETUP ---
$records_per_page = 8; 
$page_no = isset($_GET['page_no']) && is_numeric($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
if ($page_no < 1) $page_no = 1;
$offset = ($page_no - 1) * $records_per_page;

// --- BUILD QUERY ---
$whereClause = "WHERE delete_status = 0";
$params = [];
$types = "";

// 1. Search
if (!empty($search)) {
    $whereClause .= " AND medicine_name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// 2. Category
if (!empty($category)) {
    $whereClause .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

// 3. Status Logic
if (!empty($status_filter)) {
    if ($status_filter === 'Out of Stock') {
        $whereClause .= " AND stock_quantity = 0";
    } elseif ($status_filter === 'Low Stock') {
        $whereClause .= " AND stock_quantity > 0 AND stock_quantity <= 20";
    } elseif ($status_filter === 'Available') {
        $whereClause .= " AND stock_quantity > 20";
    }
}

// 4. Count Total (For Pagination)
$count_sql = "SELECT COUNT(*) as total_records FROM medicine_inventory $whereClause";
$stmtCount = $mysqli->prepare($count_sql);
if(!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$total_records = $stmtCount->get_result()->fetch_assoc()['total_records'];
$total_pages = ceil($total_records / $records_per_page);

// 5. Fetch Data (With LIMIT)
$sql = "SELECT * FROM medicine_inventory $whereClause ORDER BY medicine_name ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $records_per_page;
$types .= "ii";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<style>
    /* Glass & Card Styles */
    .card-glass {
        background: linear-gradient(145deg, #ffffff 0%, #f3f5f9 100%);
        border: 1px solid rgba(255,255,255,0.8);
        border-radius: 20px;
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
    }
    
    .filter-card {
        background: #ffffff;
        border-left: 5px solid #4e73df; /* Blue Accent */
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }
    .filter-card:hover { transform: translateY(-2px); }

    /* Inputs & Buttons */
    .form-floating > .form-control, .form-floating > .form-select {
        border-radius: 10px;
        background-color: #fcfcfc;
        border: 1px solid #e0e0e0;
    }
    .form-floating > .form-control:focus, .form-floating > .form-select:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1);
        background-color: #fff;
    }

    /* Gradient Button (Blue Theme) */
    .btn-gradient {
        background: linear-gradient(45deg, #4e73df, #224abe);
        border: none;
        color: white;
        transition: all 0.3s;
    }
    .btn-gradient:hover {
        background: linear-gradient(45deg, #224abe, #1a3a9c);
        box-shadow: 0 4px 12px rgba(78, 115, 223, 0.4);
        transform: translateY(-1px);
    }

    /* Table Styles */
    .table-modern thead th {
        background-color: #f1f3f9;
        color: #5a5c69;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        border: none;
        padding: 1rem;
    }
    .table-modern tbody td { padding: 1rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    .table-modern tbody tr:hover { background-color: #f8f9fc; }

    /* Inventory Icon Badge (Blue/Purple Gradient) */
    .icon-box {
        width: 42px; height: 42px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem;
        color: white;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    /* Status Badges */
    .badge-soft { padding: 6px 12px; border-radius: 30px; font-weight: 600; font-size: 0.75rem; }
    .badge-stock-ok { background: #d4edda; color: #155724; }
    .badge-stock-low { background: #fff3cd; color: #856404; }
    .badge-stock-out { background: #f8d7da; color: #842029; }
</style>

<div class="container-fluid px-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-gray-800 fw-bold mb-1">
                <i class="bi bi-box-seam-fill text-primary me-2"></i>Inventory Management
            </h2>
            <p class="text-muted small mb-0">Track stock levels and manage medicine supplies.</p>
        </div>
        <button class="btn btn-gradient rounded-pill px-4 fw-bold shadow-sm mt-3 mt-md-0" data-bs-toggle="modal" data-bs-target="#addMedModal">
            <i class="bi bi-plus-lg me-1"></i> Add Medicine
        </button>
    </div>

    <div class="filter-card p-4 mb-4">
        <form method="GET" action="index_bhw.php" class="row g-3 align-items-center">
            <input type="hidden" name="page" value="<?= encrypt('med_inventory') ?>">

            
            
            <div class="col-12 mb-1">
                <small class="text-uppercase fw-bold text-primary tracking-wide"><i class="bi bi-funnel-fill me-1"></i> Stock Filters</small>
            </div>

            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="searchInp" name="search" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                    <label for="searchInp"><i class="bi bi-search me-2 text-muted"></i>Search Medicine</label>
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="catSel" name="category">
                        <option value="">All Categories</option>
                        <option value="Analgesic" <?= $category == 'Analgesic' ? 'selected' : '' ?>>Analgesic</option>
                        <option value="Antibiotic" <?= $category == 'Antibiotic' ? 'selected' : '' ?>>Antibiotic</option>
                        <option value="Vitamins" <?= $category == 'Vitamins' ? 'selected' : '' ?>>Vitamins</option>
                        <option value="Cough Remedy" <?= $category == 'Cough Remedy' ? 'selected' : '' ?>>Cough Remedy</option>
                        <option value="First Aid" <?= $category == 'First Aid' ? 'selected' : '' ?>>First Aid</option>
                        <option value="Maintenance" <?= $category == 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="Others" <?= $category == 'Others' ? 'selected' : '' ?>>Others</option>
                    </select>
                    <label for="catSel"><i class="bi bi-tags-fill me-2 text-muted"></i>Category</label>
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="statSel" name="status">
                        <option value="">All Stock Levels</option>
                        <option value="Available" <?= $status_filter == 'Available' ? 'selected' : '' ?>>Available (High Stock)</option>
                        <option value="Low Stock" <?= $status_filter == 'Low Stock' ? 'selected' : '' ?>>Low Stock (<= 20)</option>
                        <option value="Out of Stock" <?= $status_filter == 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                    <label for="statSel"><i class="bi bi-bar-chart-fill me-2 text-muted"></i>Stock Level</label>
                </div>
            </div>

            <div class="col-md-2 d-flex flex-column gap-2">
                <button type="submit" class="btn btn-gradient text-white rounded-3 fw-bold py-2 shadow-sm w-100">
                    Apply Filter
                </button>
                <a href="index_bhw.php?page=<?= urlencode(encrypt('med_inventory')) ?>" class="btn btn-sm btn-light text-muted w-100">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <div class="card card-glass border-0 mb-5">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-dark"><i class="bi bi-list-ul me-2 text-primary"></i>Medicine List</h6>
            <span class="badge bg-light text-dark border px-3">Total Items: <?= $total_records ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive rounded-3">
                <table class="table table-modern align-middle mb-0" id="inventoryTable">
                    <thead>
                        <tr>
                            <th class="ps-4">Medicine Name</th>
                            <th>Category</th>
                            <th>Stock Level</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): 
                                $stock = (int)$row['stock_quantity'];
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box me-3 flex-shrink-0">
                                            <i class="bi bi-capsule"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($row['medicine_name']) ?></div>
                                            <div class="small text-muted">Unit: <?= htmlspecialchars($row['unit']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border border-secondary-subtle rounded-pill fw-normal px-3">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 6px; width: 80px; background-color: #eaecf4;">
                                            <?php 
                                            // Dynamic progress bar color
                                            $width = min(100, ($stock / 200) * 100); 
                                            $color = $stock == 0 ? 'bg-danger' : ($stock <= 20 ? 'bg-warning' : 'bg-success');
                                            ?>
                                            <div class="progress-bar <?= $color ?>" role="progressbar" style="width: <?= $width ?>%"></div>
                                        </div>
                                        <span class="fw-bold <?= str_replace('bg-', 'text-', $color) ?>"><?= $stock ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    if ($stock == 0) {
                                        echo '<span class="badge-soft badge-stock-out"><i class="bi bi-x-circle me-1"></i> Out of Stock</span>';
                                    } elseif ($stock <= 20) {
                                        echo '<span class="badge-soft badge-stock-low"><i class="bi bi-exclamation-triangle me-1"></i> Low Stock</span>';
                                    } else {
                                        echo '<span class="badge-soft badge-stock-ok"><i class="bi bi-check-circle me-1"></i> Available</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-white border shadow-sm btn-sm rounded-pill px-3 edit-btn text-primary fw-bold"
                                            data-id="<?= $row['id'] ?>"
                                            data-name="<?= htmlspecialchars($row['medicine_name']) ?>"
                                            data-cat="<?= htmlspecialchars($row['category']) ?>"
                                            data-stock="<?= $row['stock_quantity'] ?>"
                                            data-unit="<?= htmlspecialchars($row['unit']) ?>"
                                            data-bs-toggle="modal" data-bs-target="#editMedModal">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <button class="btn btn-light btn-sm rounded-circle text-danger ms-1 archive-btn" 
                                            data-id="<?= $row['id'] ?>" title="Archive">
                                        <i class="bi bi-archive-fill"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted fw-bold">No medicines found matching your criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white py-3 border-0 rounded-bottom-4 d-flex justify-content-center">
            <?php
            $window = 3; 
            $start  = max(1, $page_no - 1);
            $end    = min($total_pages, $page_no + 1);
            
            // Important: Preserve existing filters in pagination links
            $queryParams = $_GET;
            ?>
            <nav>
                <ul class="pagination pagination-sm mb-0 gap-1">
                    
                    <li class="page-item <?= ($page_no <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-circle border-0 bg-light text-dark" style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;" href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $page_no - 1])) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $page_no) ? 'active' : '' ?>">
                            <a class="page-link rounded-circle border-0 <?= ($i == $page_no) ? 'bg-primary text-white shadow' : 'bg-light text-dark' ?>" 
                               style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;"
                               href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= ($page_no >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-circle border-0 bg-light text-dark" style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;" href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $page_no + 1])) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>

                </ul>
            </nav>
        </div>
        <div class="text-center pb-3 text-muted small">
            Page <?= $page_no ?> of <?= $total_pages == 0 ? 1 : $total_pages ?>
        </div>
    </div>
</div>

<div class="modal fade" id="addMedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow-lg" id="addMedForm">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2"></i>New Medicine</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="add">
                
                <div class="form-floating mb-3">
                    <input type="text" name="medicine_name" class="form-control" id="addName" placeholder="Name" required>
                    <label for="addName">Medicine Name</label>
                </div>
                
                <div class="form-floating mb-3">
                    <select name="category" class="form-select" id="addCat">
                        <option value="Analgesic">Analgesic</option>
                        <option value="Antibiotic">Antibiotic</option>
                        <option value="Vitamins">Vitamins</option>
                        <option value="Cough Remedy">Cough Remedy</option>
                        <option value="First Aid">First Aid</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Others">Others</option>
                    </select>
                    <label for="addCat">Category</label>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="number" name="stock_quantity" class="form-control" id="addStock" placeholder="0" required min="0">
                            <label for="addStock">Initial Stock</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <select name="unit" class="form-select" id="addUnit">
                                <option value="pcs">Pieces</option>
                                <option value="bottles">Bottles</option>
                                <option value="banig">Banig/Strip</option>
                                <option value="box">Box</option>
                                <option value="kit">Kit</option>
                            </select>
                            <label for="addUnit">Unit</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary text-white rounded-pill px-4 shadow-sm">Save Item</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editMedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow-lg" id="editMedForm">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="med_id" id="edit_id">
                
                <div class="form-floating mb-3">
                    <input type="text" name="medicine_name" id="edit_name" class="form-control" placeholder="Name" required>
                    <label for="edit_name">Medicine Name</label>
                </div>
                
                <div class="form-floating mb-3">
                    <select name="category" id="edit_cat" class="form-select">
                        <option value="Analgesic">Analgesic</option>
                        <option value="Antibiotic">Antibiotic</option>
                        <option value="Vitamins">Vitamins</option>
                        <option value="Cough Remedy">Cough Remedy</option>
                        <option value="First Aid">First Aid</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Others">Others</option>
                    </select>
                    <label for="edit_cat">Category</label>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="number" name="stock_quantity" id="edit_stock" class="form-control" placeholder="0" required min="0">
                            <label for="edit_stock">Current Stock</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <select name="unit" id="edit_unit" class="form-select">
                                <option value="pcs">Pieces</option>
                                <option value="bottles">Bottles</option>
                                <option value="banig">Banig/Strip</option>
                                <option value="box">Box</option>
                                <option value="kit">Kit</option>
                            </select>
                            <label for="edit_unit">Unit</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm">Save Changes</button>
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

    // Handle Archive (Updated Simple Version)
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
                    // Redirect using the CURRENT URL + delete_id
                    const url = new URL(window.location.href);
                    url.searchParams.set('delete_id', id);
                    window.location.href = url.toString();
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