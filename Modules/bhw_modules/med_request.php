<?php
// med_request.php (BHW SIDE - WITH PROFILE PICTURE)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// --- FILTERS & SEARCH PARAMETERS ---
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- BUILD WHERE CLAUSE ---
$whereClause = "WHERE mr.delete_status = 0";
$params = [];
$types = "";

// 1. Search
if (!empty($search)) {
    $whereClause .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR mr.id LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    $types .= "sss";
}

// 2. Status Filter
if (!empty($filter_status)) {
    $whereClause .= " AND mr.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

// 3. Date Range
if (!empty($start_date) && !empty($end_date)) {
    $whereClause .= " AND DATE(mr.request_date) BETWEEN ? AND ?";
    $params[] = $start_date; $params[] = $end_date;
    $types .= "ss";
}

// --- PAGINATION ---
$records_per_page = 6;
$page_no = isset($_GET['page_no']) && is_numeric($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
if ($page_no < 1) $page_no = 1;
$offset = ($page_no - 1) * $records_per_page;

// Count
$count_sql = "SELECT COUNT(*) as total_records FROM medicine_requests mr JOIN residents r ON mr.res_id = r.id $whereClause";
$stmtCount = $mysqli->prepare($count_sql);
if (!empty($params)) { $stmtCount->bind_param($types, ...$params); }
$stmtCount->execute();
$total_records = $stmtCount->get_result()->fetch_assoc()['total_records'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch - ADDED 'r.profile_picture' HERE
$sql = "SELECT mr.*, r.first_name, r.last_name, r.age, r.contact_number, r.res_street_address, r.res_zone, r.profile_picture
        FROM medicine_requests mr JOIN residents r ON mr.res_id = r.id
        $whereClause
        ORDER BY FIELD(mr.status, 'Pending', 'Approved', 'Picked Up', 'On Delivery', 'Delivered', 'Rejected') ASC, mr.request_date DESC
        LIMIT ?, ?";
$params[] = $offset; $params[] = $records_per_page;
$types .= "ii";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Inventory for Modal
$invResult = $mysqli->query("SELECT * FROM medicine_inventory WHERE status = 'Available' AND stock_quantity > 0");
$inventory = [];
while($row = $invResult->fetch_assoc()) { $inventory[] = $row; }
?>

<style>
    /* Card & General */
    .card-glass {
        background: linear-gradient(145deg, #ffffff 0%, #f3f5f9 100%);
        border: 1px solid rgba(255,255,255,0.8);
        border-radius: 20px;
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.07);
    }
    
    .filter-card {
        background: #ffffff;
        border-left: 5px solid #4e73df;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .form-floating > .form-control {
        border-radius: 10px;
        border: 1px solid #e0e0e0;
        background-color: #fcfcfc;
    }
    .form-floating > .form-control:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1);
        background-color: #fff;
    }
    
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

    .table-modern thead th {
        background-color: #f1f3f9;
        color: #5a5c69;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        border: none;
        padding: 1rem;
    }
    .table-modern tbody td { padding: 1rem; border-bottom: 1px solid #f0f0f0; }
    .table-modern tbody tr:hover { background-color: #f8f9fc; }
    
    .avatar-modern {
        width: 42px; height: 42px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        font-weight: bold;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        overflow: hidden; /* Added to keep image inside border radius */
    }
    .avatar-modern img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* Status Badges */
    .badge-soft { padding: 6px 12px; border-radius: 30px; font-weight: 600; font-size: 0.75rem; }
    .badge-pending { background: #fff3cd; color: #856404; }
    .badge-approved { background: #d4edda; color: #155724; }
    .badge-pickedup { background: #cce5ff; color: #004085; }
    .badge-delivery { background: #e2e3f5; color: #383d41; }
    .badge-rejected { background: #f8d7da; color: #721c24; }
</style>

<div class="container-fluid px-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-gray-800 fw-bold mb-1">
                <i class="bi bi-capsule-pill text-primary me-2"></i>Medicine Requests
            </h2>
            <p class="text-muted small mb-0">Manage resident health requests efficiently.</p>
        </div>
        <button class="btn btn-light bg-white shadow-sm rounded-pill px-4 fw-bold text-primary" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh Data
        </button>
    </div>

    <div class="filter-card p-4 mb-4">
        <form method="GET" action="index_bhw.php" class="row g-3 align-items-center">
            <input type="hidden" name="page" value="<?= encrypt('med_request') ?>">
            
            <div class="col-12 mb-1">
                <small class="text-uppercase fw-bold text-primary tracking-wide"><i class="bi bi-sliders me-1"></i> Smart Filters</small>
            </div>

            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="searchInp" name="search" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                    <label for="searchInp"><i class="bi bi-search me-2 text-muted"></i>Search Name or ID</label>
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="statusSel" name="status">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $filter_status == 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $filter_status == 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Picked Up" <?= $filter_status == 'Picked Up' ? 'selected' : '' ?>>Picked Up</option>
                        <option value="On Delivery" <?= $filter_status == 'On Delivery' ? 'selected' : '' ?>>On Delivery</option>
                        <option value="Delivered" <?= $filter_status == 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="Rejected" <?= $filter_status == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                    <label for="statusSel"><i class="bi bi-tag-fill me-2 text-muted"></i>Status</label>
                </div>
            </div>

            <div class="col-md-3">
                <div class="input-group h-100">
                    <div class="form-floating flex-grow-1">
                        <input type="date" class="form-control rounded-end-0" id="startD" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                        <label for="startD">Start Date</label>
                    </div>
                    <div class="form-floating flex-grow-1">
                        <input type="date" class="form-control rounded-start-0 border-start-0" id="endD" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                        <label for="endD">End Date</label>
                    </div>
                </div>
            </div>

            <div class="col-md-2 d-flex flex-column gap-2">
                <button type="submit" class="btn btn-gradient rounded-3 fw-bold py-2 shadow-sm w-100">
                    Apply Filter
                </button>
                <a href="index_bhw.php?page=<?= urlencode(encrypt('med_request')) ?>" class="btn btn-sm btn-light text-muted w-100">
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <div class="card card-glass border-0 mb-5">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-dark"><i class="bi bi-list-stars me-2 text-warning"></i>Results List</h6>
            <span class="badge bg-dark text-white rounded-pill px-3">Count: <?= $total_records ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive rounded-3">
                <table class="table table-modern align-middle mb-0" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th class="ps-4">Resident</th> 
                            <th>Request Date</th>
                            <th>Status</th>
                            <th>Prescription</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-modern me-3">
                                                <?php if (!empty($row['profile_picture'])): ?>
                                                    <img src="data:image/jpeg;base64,<?= base64_encode($row['profile_picture']) ?>" alt="Pic">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                                    <i class="bi bi-copy text-muted small" style="cursor:pointer;" onclick="copyText('<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')"></i>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-phone me-1"></i><?= htmlspecialchars($row['contact_number']) ?>
                                                </div>
                                                <div class="small text-truncate text-muted" style="max-width: 180px;">
                                                    <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($row['res_street_address']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?= date('M d, Y', strtotime($row['request_date'])) ?></div>
                                        <div class="small text-muted"><?= date('h:i A', strtotime($row['request_date'])) ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                        $s = $row['status'];
                                        $cls = match($s) {
                                            'Pending' => 'badge-pending',
                                            'Approved' => 'badge-approved',
                                            'Picked Up' => 'badge-pickedup',
                                            'On Delivery' => 'badge-delivery',
                                            'Delivered' => 'badge-approved',
                                            'Rejected' => 'badge-rejected',
                                            default => 'bg-secondary text-white'
                                        };
                                        ?>
                                        <span class="badge-soft <?= $cls ?>">
                                            <?= $s ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($row['prescription_img']): ?>
                                            <button class="btn btn-white border shadow-sm btn-sm rounded-pill px-3 view-img" 
                                                    data-title="Prescription"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['prescription_img']) ?>">
                                                <i class="bi bi-eye-fill text-primary"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small ms-2">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if($s === 'Pending'): ?>
                                            <button class="btn btn-primary btn-sm rounded-3 shadow-sm process-btn px-3 fw-bold" 
                                                    data-id="<?= $row['id'] ?>"
                                                    data-name="<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>"
                                                    data-mode="edit" 
                                                    data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['prescription_img']) ?>">
                                                <i class="bi bi-pencil-square me-1"></i> Review
                                            </button>

                                        <?php elseif($s === 'Approved'): ?>
                                            <button class="btn btn-outline-primary btn-sm rounded-3 px-3 update-status" 
                                                    data-id="<?= $row['id'] ?>" data-status="Picked Up">
                                                Mark Picked Up
                                            </button>

                                        <?php elseif($s === 'Picked Up' || $s === 'On Delivery'): ?>
                                            <button class="btn btn-light border btn-sm rounded-3 px-3 process-btn text-muted" 
                                                    data-id="<?= $row['id'] ?>"
                                                    data-name="<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>"
                                                    data-mode="view" 
                                                    data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['prescription_img']) ?>">
                                                <i class="bi bi-eye"></i> View Details
                                            </button>

                                        <?php elseif($s === 'Delivered' && $row['delivery_proof']): ?>
                                            <button class="btn btn-link btn-sm text-success text-decoration-none fw-bold view-img" 
                                                    data-title="Proof of Delivery"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['delivery_proof']) ?>">
                                                <i class="bi bi-check-circle-fill me-1"></i> View Proof
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted fw-bold">No requests found matching your filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-transparent border-0 py-3 d-flex justify-content-center">
            <?php
            $window = 3; 
            $start  = max(1, $page_no - 1);
            $end    = min($total_pages, $page_no + 1);
            $queryParams = $_GET;
            ?>
            <nav>
                <ul class="pagination pagination-sm mb-0 gap-1">
                    <li class="page-item <?= ($page_no <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-circle border-0 bg-light text-dark" style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;" href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $page_no - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $page_no) ? 'active' : '' ?>">
                            <a class="page-link rounded-circle border-0 <?= ($i == $page_no) ? 'bg-primary text-white shadow' : 'bg-light text-dark' ?>" 
                               style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;"
                               href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page_no >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-circle border-0 bg-light text-dark" style="width:30px; height:30px; display:flex; align-items:center; justify-content:center;" href="?<?= http_build_query(array_merge($queryParams, ['page_no' => $page_no + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="processModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-primary" id="modalTitle">Dispense Medicine</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card h-100 border-0 bg-light rounded-4 overflow-hidden">
                            <div class="card-body d-flex align-items-center justify-content-center p-0">
                                <img id="p_presc_img" src="" class="img-fluid" style="object-fit: contain; max-height: 400px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <h6 class="fw-bold mb-3">Request Details: <span id="p_res_name" class="text-primary"></span></h6>
                        
                        <div id="inventorySection" class="bg-light p-3 rounded-4 mb-3">
                            <div class="input-group">
                                <select id="sel_med" class="form-select border-0 bg-white shadow-sm rounded-start-pill ps-3">
                                    <option value="">Select Medicine...</option>
                                    <?php foreach($inventory as $item): ?>
                                        <option value="<?= $item['id'] ?>" 
                                                data-name="<?= htmlspecialchars($item['medicine_name']) ?>"
                                                data-stock="<?= $item['stock_quantity'] ?>"
                                                data-unit="<?= $item['unit'] ?>">
                                            <?= htmlspecialchars($item['medicine_name']) ?> (Stock: <?= $item['stock_quantity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" id="sel_qty" class="form-control border-0 bg-white shadow-sm" placeholder="Qty" value="1" style="max-width: 80px;">
                                <button class="btn btn-primary rounded-end-pill px-3 shadow-sm" id="add_btn"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>

                        <div class="table-responsive border rounded-4 mb-3 p-2" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody id="dispense_list"></tbody>
                            </table>
                            <div id="empty_msg" class="text-center text-muted py-4 small">Add medicines from the inventory above.</div>
                        </div>
                        
                        <textarea id="p_remarks" class="form-control bg-light border-0 rounded-4 mb-3" rows="2" placeholder="Admin remarks (optional)..."></textarea>
                        
                        <div id="actionButtons" class="d-flex gap-2">
                            <button class="btn btn-light text-danger flex-grow-1 rounded-pill fw-bold" id="btn_reject">Reject</button>
                            <button class="btn btn-primary flex-grow-1 rounded-pill fw-bold shadow-sm" id="btn_approve">Approve & Dispense</button>
                        </div>
                        
                        <div id="viewButtons" class="d-none">
                            <button class="btn btn-secondary w-100 rounded-pill fw-bold" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="imgModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-header border-0 p-0 mb-2">
                <h5 class="modal-title text-white" id="img_title">Image</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center">
                <img id="view_img_src" src="" class="img-fluid rounded-4 shadow-lg" style="max-height: 85vh;">
            </div>
        </div>
    </div>
</div>

<script>
function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
        Toast.fire({ icon: 'success', title: 'Copied!' });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    let currentReqId = null;
    let items = [];

    // View Image Modal
    document.querySelectorAll('.view-img').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('img_title').innerText = this.dataset.title;
            document.getElementById('view_img_src').src = this.dataset.img;
            new bootstrap.Modal(document.getElementById('imgModal')).show();
        });
    });

    // Handle "Review" (Edit) and "View Info" (Read-only) buttons
    document.querySelectorAll('.process-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentReqId = this.dataset.id;
            const mode = this.dataset.mode; // 'edit' or 'view'

            document.getElementById('p_res_name').innerText = this.dataset.name;
            document.getElementById('p_presc_img').src = this.dataset.img;
            
            // --- LOAD REMARKS FROM DATA ATTRIBUTE ---
            document.getElementById('p_remarks').value = this.dataset.remarks || '';

            items = []; renderItems();

            // Toggle UI elements based on mode
            if (mode === 'view') {
                document.getElementById('modalTitle').innerText = 'Request Details';
                document.getElementById('inventorySection').classList.add('d-none');
                document.getElementById('actionButtons').classList.add('d-none');
                document.getElementById('viewButtons').classList.remove('d-none');
                document.getElementById('p_remarks').setAttribute('readonly', true);
            } else {
                document.getElementById('modalTitle').innerText = 'Dispense Medicine';
                document.getElementById('inventorySection').classList.remove('d-none');
                document.getElementById('actionButtons').classList.remove('d-none');
                document.getElementById('viewButtons').classList.add('d-none');
                document.getElementById('p_remarks').removeAttribute('readonly');
            }

            new bootstrap.Modal(document.getElementById('processModal')).show();
        });
    });

    document.getElementById('add_btn').addEventListener('click', function() {
        const sel = document.getElementById('sel_med');
        const opt = sel.options[sel.selectedIndex];
        const qty = parseInt(document.getElementById('sel_qty').value);
        if(!sel.value || qty <= 0) return Swal.fire('Error', 'Invalid selection.', 'warning');
        
        items.push({ id: sel.value, name: opt.dataset.name, qty: qty, unit: opt.dataset.unit });
        renderItems();
        sel.value = ""; document.getElementById('sel_qty').value = 1;
    });

    window.removeItem = function(index) { 
        // Prevent removing items in View Mode (simple check)
        if(document.getElementById('inventorySection').classList.contains('d-none')) return;
        items.splice(index, 1); renderItems(); 
    }

    function renderItems() {
        const tb = document.getElementById('dispense_list');
        const emptyMsg = document.getElementById('empty_msg');
        if (items.length === 0) {
            tb.innerHTML = ''; emptyMsg.style.display = 'block';
        } else {
            emptyMsg.style.display = 'none';
            // Only show delete button if NOT in view mode
            const isViewMode = document.getElementById('inventorySection').classList.contains('d-none');
            const delBtn = isViewMode ? '' : `<button class="btn btn-sm text-danger" onclick="removeItem(${items.length})"><i class="bi bi-x-circle-fill"></i></button>`;

            tb.innerHTML = items.map((i, idx) => `
                <tr class="border-bottom">
                    <td><span class="fw-bold text-dark">${i.name}</span> <small class="text-muted">(${i.unit})</small></td>
                    <td class="text-end"><span class="badge bg-primary rounded-pill">${i.qty}</span></td>
                    <td class="text-end" style="width:30px;">
                        ${!isViewMode ? `<button class="btn btn-sm text-danger" onclick="removeItem(${idx})"><i class="bi bi-x-circle-fill"></i></button>` : ''}
                    </td>
                </tr>
            `).join('');
        }
    }

    document.getElementById('btn_approve').addEventListener('click', () => submitProcess('Approved'));
    document.getElementById('btn_reject').addEventListener('click', () => {
        Swal.fire({ title: 'Reject?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes, Reject' })
        .then((r) => { if(r.isConfirmed) submitProcess('Rejected'); });
    });

    function submitProcess(status) {
        if(status === 'Approved' && items.length === 0) return Swal.fire('Error', 'Add medicines first.', 'warning');
        const fd = new FormData();
        fd.append('action', 'process'); fd.append('request_id', currentReqId);
        fd.append('status', status); fd.append('remarks', document.getElementById('p_remarks').value);
        fd.append('items', JSON.stringify(items));
        fetch('api/bhw_request_action.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => { 
            d.success ? Swal.fire('Success', d.message, 'success').then(() => location.reload()) : Swal.fire('Error', d.message, 'error'); 
        });
    }

    document.querySelectorAll('.update-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id, status = this.dataset.status;
            Swal.fire({ title: `Mark as ${status}?`, showCancelButton: true, confirmButtonText: 'Yes' }).then((res) => {
                if(res.isConfirmed) {
                    const fd = new FormData(); fd.append('action', 'update_status'); fd.append('request_id', id); fd.append('new_status', status);
                    fetch('api/bhw_request_action.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if(d.success) location.reload(); });
                }
            });
        });
    });
});
</script>