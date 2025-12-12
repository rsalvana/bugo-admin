<?php
// med_request.php (LIASON SIDE)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// --- FILTERS ---
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- BUILD QUERY (Rule: Liason CANNOT see Pending) ---
// We start with "status != Pending" AND "delete_status = 0"
$whereClause = "WHERE mr.delete_status = 0 AND mr.status != 'Pending'";
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

// Fetch Data
$sql = "SELECT mr.*, r.first_name, r.last_name, r.age, r.contact_number, r.res_street_address, r.res_zone
        FROM medicine_requests mr JOIN residents r ON mr.res_id = r.id
        $whereClause
        ORDER BY FIELD(mr.status, 'Approved', 'Picked Up', 'On Delivery', 'Delivered', 'Rejected') ASC, mr.request_date DESC
        LIMIT ?, ?";
$params[] = $offset; $params[] = $records_per_page;
$types .= "ii";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<style>
    /* Card Glass Effect */
    .card-glass {
        background: linear-gradient(145deg, #ffffff 0%, #f8f9fc 100%);
        border: 1px solid rgba(255,255,255,0.8);
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    }
    
    /* Filter Card */
    .filter-card {
        background: #fff;
        border-left: 5px solid #1cc88a; /* Green accent for Liason */
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }
    .filter-card:hover { transform: translateY(-2px); }

    /* Inputs */
    .form-floating > .form-control {
        border-radius: 10px;
        background-color: #fcfcfc;
        border: 1px solid #e3e6f0;
    }
    .form-floating > .form-control:focus {
        border-color: #1cc88a; /* Green focus */
        box-shadow: 0 0 0 4px rgba(28, 200, 138, 0.1);
        background-color: #fff;
    }

    /* Gradient Button (Green Theme) */
    .btn-gradient {
        background: linear-gradient(45deg, #1cc88a, #13855c);
        border: none;
        color: white;
        transition: all 0.3s;
    }
    .btn-gradient:hover {
        background: linear-gradient(45deg, #13855c, #0e6345);
        box-shadow: 0 4px 12px rgba(28, 200, 138, 0.4);
        transform: translateY(-1px);
    }

    /* Table Modernization */
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

    /* Avatar */
    .avatar-modern {
        width: 42px; height: 42px;
        background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); /* Green Avatar */
        color: white;
        border-radius: 12px;
        font-weight: bold;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 3px 6px rgba(0,0,0,0.1);
    }

    /* Badges */
    .badge-soft { padding: 6px 12px; border-radius: 30px; font-weight: 600; font-size: 0.75rem; }
    .badge-approved { background: #d1ecf1; color: #0c5460; }
    .badge-pickedup { background: #cce5ff; color: #004085; }
    .badge-delivery { background: #e2e3f5; color: #383d41; }
    .badge-delivered { background: #d4edda; color: #155724; }
    .badge-rejected { background: #f8d7da; color: #721c24; }
</style>

<div class="container-fluid px-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-gray-800 fw-bold mb-1">
                <i class="bi bi-truck text-success me-2"></i>Delivery Management
            </h2>
            <p class="text-muted small mb-0">Track medicine deliveries and update status.</p>
        </div>
        <button class="btn btn-white shadow-sm rounded-pill px-4 fw-bold text-success" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <div class="filter-card p-4 mb-4">
        <form method="GET" action="index_Liason.php" class="row g-3 align-items-center">
            <input type="hidden" name="page" value="<?= encrypt('med_request') ?>">
            
            <div class="col-12 mb-1">
                <small class="text-uppercase fw-bold text-success tracking-wide"><i class="bi bi-sliders me-1"></i> Delivery Filters</small>
            </div>

            <div class="col-md-4">
                <div class="form-floating">
                    <input type="text" class="form-control" id="searchInp" name="search" placeholder="Search" value="<?= htmlspecialchars($search) ?>">
                    <label for="searchInp"><i class="bi bi-search me-2 text-muted"></i>Search Resident</label>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="statusSel" name="status">
                        <option value="">All Active</option>
                        <option value="Approved" <?= $filter_status == 'Approved' ? 'selected' : '' ?>>Approved (Ready)</option>
                        <option value="Picked Up" <?= $filter_status == 'Picked Up' ? 'selected' : '' ?>>Picked Up</option>
                        <option value="On Delivery" <?= $filter_status == 'On Delivery' ? 'selected' : '' ?>>On Delivery</option>
                        <option value="Delivered" <?= $filter_status == 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                    </select>
                    <label for="statusSel"><i class="bi bi-tag-fill me-2 text-muted"></i>Status</label>
                </div>
            </div>

            <div class="col-md-3 d-flex gap-1">
                <input type="date" name="start_date" class="form-control" style="height: 58px; border-radius: 10px;" value="<?= htmlspecialchars($start_date) ?>">
                <input type="date" name="end_date" class="form-control" style="height: 58px; border-radius: 10px;" value="<?= htmlspecialchars($end_date) ?>">
            </div>

            <div class="col-md-2 d-flex flex-column gap-2">
                <button type="submit" class="btn btn-gradient rounded-3 fw-bold py-2 shadow-sm w-100">
                    Apply Filter
                </button>
                <a href="index_Liason.php?page=<?= urlencode(encrypt('med_request')) ?>" class="btn btn-sm btn-light text-muted w-100">
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <div class="card card-glass border-0 mb-5">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-dark"><i class="bi bi-box-seam me-2 text-success"></i>Delivery Tasks</h6>
            <span class="badge bg-dark text-white rounded-pill px-3">Total: <?= $total_records ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive rounded-3">
                <table class="table table-modern align-middle mb-0" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th class="ps-4">Resident Info</th> 
                            <th>Date Requested</th>
                            <th>Status</th>
                            <th>Proof</th>
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
                                                <?= strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                                    <i class="bi bi-copy text-muted small" style="cursor:pointer;" onclick="copyText('<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')"></i>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-phone me-1"></i><?= htmlspecialchars($row['contact_number']) ?>
                                                </div>
                                                <div class="small text-truncate text-muted" style="max-width: 200px;">
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
                                            'Approved' => 'badge-approved',
                                            'Picked Up' => 'badge-pickedup',
                                            'On Delivery' => 'badge-delivery',
                                            'Delivered' => 'badge-delivered',
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
                                        <?php if($s === 'Approved'): ?>
                                            <button class="btn btn-primary btn-sm rounded-3 shadow-sm px-3 update-status" 
                                                    data-id="<?= $row['id'] ?>" data-status="Picked Up">
                                                <i class="bi bi-box-arrow-up me-1"></i> Pick Up
                                            </button>

                                        <?php elseif($s === 'Picked Up'): ?>
                                            <button class="btn btn-info text-white btn-sm rounded-3 shadow-sm px-3 update-status" 
                                                    data-id="<?= $row['id'] ?>" data-status="On Delivery">
                                                <i class="bi bi-truck me-1"></i> Start Delivery
                                            </button>

                                        <?php elseif($s === 'On Delivery'): ?>
                                            <button class="btn btn-success btn-sm rounded-3 shadow-sm px-3 confirm-delivery" 
                                                    data-id="<?= $row['id'] ?>">
                                                <i class="bi bi-check-lg me-1"></i> Confirm
                                            </button>

                                        <?php elseif($s === 'Delivered' && $row['delivery_proof']): ?>
                                            <button class="btn btn-link btn-sm text-success text-decoration-none fw-bold view-img" 
                                                    data-title="Proof of Delivery"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['delivery_proof']) ?>">
                                                View Proof
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted fw-bold">No active deliveries found.</td></tr>
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
                            <a class="page-link rounded-circle border-0 <?= ($i == $page_no) ? 'bg-success text-white shadow' : 'bg-light text-dark' ?>" 
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

<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow-lg" id="proofForm">
            <div class="modal-body p-4 text-center">
                <div class="mb-3 text-success"><i class="bi bi-camera-fill display-4"></i></div>
                <h5 class="fw-bold mb-2">Confirm Delivery</h5>
                <p class="text-muted small mb-4">Upload a photo proof that the resident received the items.</p>
                <input type="hidden" name="action" value="confirm_delivery">
                <input type="hidden" name="request_id" id="proof_req_id">
                <input type="file" name="proof_img" class="form-control mb-4" accept="image/*" required>
                <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold shadow-sm">Complete Transaction</button>
            </div>
        </form>
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
    // View Image Handler
    document.querySelectorAll('.view-img').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('img_title').innerText = this.dataset.title;
            document.getElementById('view_img_src').src = this.dataset.img;
            new bootstrap.Modal(document.getElementById('imgModal')).show();
        });
    });

    // Update Status Handler
    document.querySelectorAll('.update-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id, status = this.dataset.status;
            Swal.fire({ 
                title: `Mark as ${status}?`, 
                icon: 'question',
                showCancelButton: true, 
                confirmButtonText: 'Yes, Update' 
            }).then((res) => {
                if(res.isConfirmed) {
                    const fd = new FormData(); 
                    fd.append('action', 'update_status'); 
                    fd.append('request_id', id); 
                    fd.append('new_status', status);
                    
                    fetch('api/bhw_request_action.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(d => { if(d.success) location.reload(); });
                }
            });
        });
    });

    // Confirm Delivery Handler
    document.querySelectorAll('.confirm-delivery').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('proof_req_id').value = this.dataset.id;
            new bootstrap.Modal(document.getElementById('proofModal')).show();
        });
    });

    document.getElementById('proofForm').addEventListener('submit', function(e) {
        e.preventDefault();
        Swal.fire({ title: 'Uploading...', didOpen: () => Swal.showLoading() });
        fetch('api/bhw_request_action.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json()).then(d => { 
            d.success ? Swal.fire('Success', d.message, 'success').then(() => location.reload()) : Swal.fire('Error', d.message, 'error'); 
        });
    });
});
</script>