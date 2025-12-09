<?php
// med_request.php (BHW SIDE)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// --- PAGINATION SETUP ---
$records_per_page = 6;
$page_no = isset($_GET['page_no']) && is_numeric($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
if ($page_no < 1) $page_no = 1;
$offset = ($page_no - 1) * $records_per_page;

// 1. Count Total Records (BHW Rule: Just delete_status = 0)
$count_sql = "SELECT COUNT(*) as total_records FROM medicine_requests WHERE delete_status = 0";
$count_result = $mysqli->query($count_sql);
$total_records = $count_result->fetch_assoc()['total_records'];
$total_pages = ceil($total_records / $records_per_page);

// 2. Fetch Data (With LIMIT)
$sql = "
    SELECT mr.*, 
           r.first_name, r.last_name, r.age, r.contact_number, 
           r.res_street_address, r.res_zone
    FROM medicine_requests mr
    JOIN residents r ON mr.res_id = r.id
    WHERE mr.delete_status = 0
    ORDER BY FIELD(mr.status, 'Pending', 'Approved', 'Picked Up', 'On Delivery', 'Delivered', 'Rejected') ASC, mr.request_date DESC
    LIMIT ?, ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $offset, $records_per_page);
$stmt->execute();
$result = $stmt->get_result();

// Fetch Inventory for Dropdown
$invResult = $mysqli->query("SELECT * FROM medicine_inventory WHERE status = 'Available' AND stock_quantity > 0");
$inventory = [];
while($row = $invResult->fetch_assoc()) { $inventory[] = $row; }
?>

<style>
    .scroll-box::-webkit-scrollbar { width: 8px; }
    .scroll-box::-webkit-scrollbar-track { background: #f1f1f1; }
    .scroll-box::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
    .scroll-box::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
</style>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-primary fw-bold"><i class="bi bi-heart-pulse-fill me-2"></i>Medicine Management</h2>
            <p class="text-muted small mb-0">Manage resident requests and track delivery status.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh List
        </button>
    </div>

    <div class="card shadow border-0 rounded-4 mb-5">
        <div class="card-header bg-white border-0 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-task me-2 text-primary"></i>Request Queue</h5>
                <span class="badge bg-light text-dark border">
                    Total Requests: <?= $total_records ?>
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="min-width: 900px;">
                    <thead class="bg-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-4">Resident Details</th> 
                            <th>Date Requested</th>
                            <th>Status</th>
                            <th>Prescription</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="py-3">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-start py-2">
                                            <div class="avatar-initials bg-light text-primary rounded-circle me-3 fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width:45px; height:45px; font-size: 1.1rem;">
                                                <?= strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)) ?>
                                            </div>
                                            
                                            <div style="min-width: 200px;">
                                                <button class="btn btn-link text-dark fw-bold text-decoration-none p-0 mb-1 text-start" 
                                                        onclick="copyText('<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>')" 
                                                        title="Copy Name">
                                                    <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                                </button>
                                                
                                                <div class="d-flex align-items-center mb-1">
                                                    <a href="tel:<?= htmlspecialchars($row['contact_number']) ?>" 
                                                       class="btn btn-success btn-sm py-0 px-2 rounded-pill me-2" 
                                                       title="Call Resident">
                                                        <i class="bi bi-telephone-fill small"></i> <?= htmlspecialchars($row['contact_number']) ?>
                                                    </a>
                                                    <span class="text-secondary small">| <strong>Age:</strong> <?= $row['age'] ?></span>
                                                </div>

                                                <div class="small text-muted text-truncate" style="max-width: 280px;">
                                                    <i class="bi bi-geo-alt-fill me-1 text-danger"></i>
                                                    <?= htmlspecialchars($row['res_street_address'] . ', ' . $row['res_zone']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark"><?= date('M d, Y', strtotime($row['request_date'])) ?></div>
                                        <div class="small text-muted"><?= date('h:i A', strtotime($row['request_date'])) ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                        $s = $row['status'];
                                        $badgeClass = match($s) {
                                            'Pending'     => 'bg-warning text-dark',
                                            'Approved'    => 'bg-info text-dark',
                                            'Picked Up'   => 'bg-primary',
                                            'On Delivery' => 'bg-indigo text-white',
                                            'Delivered'   => 'bg-success',
                                            'Rejected'    => 'bg-danger',
                                            default       => 'bg-secondary'
                                        };
                                        $icon = match($s) {
                                            'Pending'     => 'bi-hourglass-split',
                                            'Approved'    => 'bi-check-circle',
                                            'Picked Up'   => 'bi-box-seam',
                                            'On Delivery' => 'bi-truck',
                                            'Delivered'   => 'bi-house-check-fill',
                                            'Rejected'    => 'bi-x-circle',
                                            default       => 'bi-circle'
                                        };
                                        ?>
                                        <span class="badge rounded-pill <?= $badgeClass ?> px-3 py-2 fw-normal">
                                            <i class="bi <?= $icon ?> me-1"></i> <?= $s ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($row['prescription_img']): ?>
                                            <button class="btn btn-light btn-sm text-primary border shadow-sm view-img" 
                                                    data-title="Prescription"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['prescription_img']) ?>">
                                                <i class="bi bi-file-earmark-image"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($s === 'Pending'): ?>
                                            <button class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm process-btn" 
                                                    data-id="<?= $row['id'] ?>"
                                                    data-name="<?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['prescription_img']) ?>">
                                                Review & Dispense
                                            </button>

                                        <?php elseif($s === 'Approved'): ?>
                                            <button class="btn btn-outline-primary btn-sm rounded-pill px-3 update-status" 
                                                    data-id="<?= $row['id'] ?>" data-status="Picked Up">
                                                <i class="bi bi-box-arrow-up me-1"></i> Mark Picked Up
                                            </button>

                                        <?php elseif($s === 'Picked Up'): ?>
                                            <button class="btn btn-outline-info btn-sm rounded-pill px-3 update-status" 
                                                    data-id="<?= $row['id'] ?>" data-status="On Delivery">
                                                <i class="bi bi-truck me-1"></i> Start Delivery
                                            </button>

                                        <?php elseif($s === 'On Delivery'): ?>
                                            <button class="btn btn-success btn-sm rounded-pill px-3 confirm-delivery" 
                                                    data-id="<?= $row['id'] ?>">
                                                <i class="bi bi-camera me-1"></i> Confirm & Upload Proof
                                            </button>

                                        <?php elseif($s === 'Delivered' && $row['delivery_proof']): ?>
                                            <button class="btn btn-outline-success btn-sm rounded-pill px-3 view-img" 
                                                    data-title="Proof of Delivery"
                                                    data-img="data:image/jpeg;base64,<?= base64_encode($row['delivery_proof']) ?>">
                                                <i class="bi bi-check-all me-1"></i> See Proof
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white py-3 border-0 rounded-bottom-4">
            <?php
            $window = 5; 
            $half   = (int)floor($window/2);
            $start  = max(1, $page_no - $half);
            $end    = min($total_pages, $start + $window - 1);
            if (($end - $start + 1) < $window) {
                $start = max(1, $end - $window + 1);
            }
            ?>

            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mb-0">

                    <li class="page-item <?= ($page_no <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page_no' => 1])) ?>" aria-label="First">
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                    </li>

                    <li class="page-item <?= ($page_no <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page_no' => $page_no - 1])) ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>

                    <?php if ($start > 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $page_no) ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page_no' => $i])) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($end < $total_pages): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>

                    <li class="page-item <?= ($page_no >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page_no' => $page_no + 1])) ?>" aria-label="Next">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>

                    <li class="page-item <?= ($page_no >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page_no' => $total_pages])) ?>" aria-label="Last">
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
                    </li>

                </ul>
            </nav>
            <div class="text-center text-muted small mt-2">
                Page <?= $page_no ?> of <?= $total_pages == 0 ? 1 : $total_pages ?>
            </div>
        </div>
        </div>
</div>

<div class="modal fade" id="processModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-capsule-pill me-2"></i>Dispense Medicine</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-white fw-bold">Prescription Reference</div>
                            <div class="card-body text-center d-flex align-items-center justify-content-center bg-dark rounded-bottom p-0 overflow-hidden">
                                <img id="p_presc_img" src="" class="img-fluid" style="max-height: 450px; width: auto;">
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white fw-bold">
                                Patient: <span id="p_res_name" class="text-primary"></span>
                            </div>
                            <div class="card-body">
                                <div class="bg-light p-3 rounded-3 mb-3 border">
                                    <label class="form-label small text-muted text-uppercase fw-bold">Add Medicine to List</label>
                                    <div class="input-group">
                                        <select id="sel_med" class="form-select border-0 shadow-sm">
                                            <option value="">Select Medicine from Inventory...</option>
                                            <?php foreach($inventory as $item): ?>
                                                <option value="<?= $item['id'] ?>" 
                                                        data-name="<?= htmlspecialchars($item['medicine_name']) ?>"
                                                        data-stock="<?= $item['stock_quantity'] ?>"
                                                        data-unit="<?= $item['unit'] ?>">
                                                    <?= htmlspecialchars($item['medicine_name']) ?> (Stock: <?= $item['stock_quantity'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" id="sel_qty" class="form-control border-0 shadow-sm" placeholder="Qty" min="1" value="1" style="max-width: 80px;">
                                        <button class="btn btn-success shadow-sm" id="add_btn"><i class="bi bi-plus-lg"></i></button>
                                    </div>
                                </div>

                                <div class="table-responsive border rounded-3 mb-3" style="max-height: 200px; overflow-y: auto;">
                                    <table class="table table-sm table-striped mb-0">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>Medicine Name</th>
                                                <th class="text-center">Qty</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="dispense_list">
                                        </tbody>
                                    </table>
                                    <div id="empty_msg" class="text-center text-muted py-4 small">
                                        No medicines added yet.
                                    </div>
                                </div>
                                
                                <label class="form-label small text-muted">Additional Remarks</label>
                                <textarea id="p_remarks" class="form-control mb-3" rows="2" placeholder="Instructions for the patient..."></textarea>
                                
                                <div class="d-flex justify-content-between pt-2">
                                    <button class="btn btn-outline-danger px-4" id="btn_reject">
                                        <i class="bi bi-x-circle me-1"></i> Reject Request
                                    </button>
                                    <button class="btn btn-primary px-4 shadow" id="btn_approve">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approve & Deduct Stock
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4 shadow" id="proofForm">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-camera-fill me-2"></i>Confirm Delivery</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <input type="hidden" name="action" value="confirm_delivery">
                <input type="hidden" name="request_id" id="proof_req_id">
                
                <div class="upload-icon mb-3 text-success">
                    <i class="bi bi-cloud-arrow-up display-1"></i>
                </div>
                <p class="text-muted">Please upload a clear photo as proof that the medicine was received by the resident.</p>
                
                <input type="file" name="proof_img" class="form-control form-control-lg mt-3" accept="image/*" required>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button type="submit" class="btn btn-success px-5 rounded-pill shadow">Upload & Complete</button>
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
                <img id="view_img_src" src="" class="img-fluid rounded shadow-lg" style="max-height: 85vh;">
            </div>
        </div>
    </div>
</div>

<style>
    .bg-indigo { background-color: #6610f2; color: white; }
    .table-hover tbody tr:hover { background-color: rgba(13, 110, 253, 0.03); transition: 0.2s; }
    .btn { transition: all 0.2s ease-in-out; }
    .btn:active { transform: scale(0.98); }
</style>

<script>
// --- Copy Function ---
function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        Toast.fire({
            icon: 'success',
            title: 'Name Copied!'
        });
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    let currentReqId = null;
    let items = [];

    // --- VIEW IMAGE ---
    document.querySelectorAll('.view-img').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('img_title').innerText = this.dataset.title;
            document.getElementById('view_img_src').src = this.dataset.img;
            new bootstrap.Modal(document.getElementById('imgModal')).show();
        });
    });

    // --- OPEN DISPENSE MODAL ---
    document.querySelectorAll('.process-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentReqId = this.dataset.id;
            document.getElementById('p_res_name').innerText = this.dataset.name;
            document.getElementById('p_presc_img').src = this.dataset.img;
            items = []; renderItems();
            new bootstrap.Modal(document.getElementById('processModal')).show();
        });
    });

    // --- ADD MEDICINE TO LIST ---
    document.getElementById('add_btn').addEventListener('click', function() {
        const sel = document.getElementById('sel_med');
        const opt = sel.options[sel.selectedIndex];
        const qty = parseInt(document.getElementById('sel_qty').value);
        if(!sel.value || qty <= 0) return Swal.fire('Error', 'Invalid selection.', 'warning');
        
        items.push({ id: sel.value, name: opt.dataset.name, qty: qty, unit: opt.dataset.unit });
        renderItems();
        
        // Reset inputs
        sel.value = "";
        document.getElementById('sel_qty').value = 1;
    });

    // Remove Item Helper
    window.removeItem = function(index) {
        items.splice(index, 1);
        renderItems();
    }

    function renderItems() {
        const tb = document.getElementById('dispense_list');
        const emptyMsg = document.getElementById('empty_msg');
        
        if (items.length === 0) {
            tb.innerHTML = '';
            emptyMsg.style.display = 'block';
        } else {
            emptyMsg.style.display = 'none';
            tb.innerHTML = items.map((i, idx) => `
                <tr>
                    <td class="align-middle">${i.name} <small class="text-muted">(${i.unit})</small></td>
                    <td class="text-center align-middle fw-bold text-primary">${i.qty}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-light text-danger border-0" onclick="removeItem(${idx})"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `).join('');
        }
    }

    // --- SUBMIT APPROVE ---
    document.getElementById('btn_approve').addEventListener('click', () => submitProcess('Approved'));
    
    // --- SUBMIT REJECT ---
    document.getElementById('btn_reject').addEventListener('click', () => {
        Swal.fire({
            title: 'Reject Request?',
            text: "This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, Reject'
        }).then((result) => {
            if (result.isConfirmed) submitProcess('Rejected');
        })
    });

    function submitProcess(status) {
        if(status === 'Approved' && items.length === 0) return Swal.fire('Error', 'Please add medicines to dispense first.', 'warning');
        
        const fd = new FormData();
        fd.append('action', 'process');
        fd.append('request_id', currentReqId);
        fd.append('status', status);
        fd.append('remarks', document.getElementById('p_remarks').value);
        fd.append('items', JSON.stringify(items));

        fetch('api/bhw_request_action.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if(d.success) {
                Swal.fire('Success', d.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        });
    }

    // --- SIMPLE STATUS UPDATE ---
    document.querySelectorAll('.update-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const status = this.dataset.status;
            const icon = status === 'Picked Up' ? 'success' : 'info';
            
            Swal.fire({
                title: `Mark as ${status}?`,
                text: `Update status to ${status}?`,
                icon: icon,
                showCancelButton: true,
                confirmButtonText: 'Yes, Update'
            }).then((res) => {
                if(res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'update_status');
                    fd.append('request_id', id);
                    fd.append('new_status', status);
                    
                    fetch('api/bhw_request_action.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(d => {
                        if(d.success) location.reload();
                    });
                }
            });
        });
    });

    // --- CONFIRM DELIVERY ---
    document.querySelectorAll('.confirm-delivery').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('proof_req_id').value = this.dataset.id;
            new bootstrap.Modal(document.getElementById('proofModal')).show();
        });
    });

    document.getElementById('proofForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Uploading Proof...',
            text: 'Please wait.',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('api/bhw_request_action.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json()).then(d => {
            if(d.success) {
                Swal.fire('Completed!', d.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        });
    });
});
</script>