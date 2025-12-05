<?php
// med_request.php (LIAISON SIDE)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403); exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// Fetch Requests
// KEY CHANGE: Added "AND mr.status != 'Pending'" to hide pending requests
$sql = "
    SELECT mr.*, r.first_name, r.last_name, r.age, r.contact_number, r.res_street_address
    FROM medicine_requests mr
    JOIN residents r ON mr.res_id = r.id
    WHERE mr.delete_status = 0 
      AND mr.status != 'Pending' 
    ORDER BY FIELD(mr.status, 'Approved', 'Picked Up', 'On Delivery', 'Delivered', 'Rejected') ASC, mr.request_date DESC
";
$result = $mysqli->query($sql);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h2 class="text-primary fw-bold"><i class="bi bi-truck me-2"></i>Delivery Management</h2>
            <p class="text-muted small mb-0">Manage medicine pickup and delivery to residents.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh List
        </button>
    </div>

    <div class="card shadow border-0 rounded-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-task me-2 text-primary"></i>Delivery Queue</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="min-width: 900px;">
                    <thead class="bg-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-4">Resident & Address</th>
                            <th>Date Requested</th>
                            <th>Status</th>
                            <th>Proof</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="py-3">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-initials bg-light text-primary rounded-circle me-3 fw-bold d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
                                            <?= strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                            <div class="small text-muted"><i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($row['res_street_address'] ?? 'No Address') ?></div>
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
                                        'Approved'    => 'bg-info text-dark',
                                        'Picked Up'   => 'bg-primary',
                                        'On Delivery' => 'bg-indigo text-white',
                                        'Delivered'   => 'bg-success',
                                        'Rejected'    => 'bg-danger',
                                        default       => 'bg-secondary'
                                    };
                                    $icon = match($s) {
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
                                    <?php if($row['delivery_proof']): ?>
                                        <button class="btn btn-light btn-sm text-success border shadow-sm view-img" 
                                                data-title="Proof of Delivery"
                                                data-img="data:image/jpeg;base64,<?= base64_encode($row['delivery_proof']) ?>">
                                            <i class="bi bi-image"></i> Proof
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($s === 'Approved'): ?>
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

                                    <?php elseif($s === 'Delivered'): ?>
                                        <span class="text-success small"><i class="bi bi-check-all"></i> Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- VIEW IMAGE ---
    document.querySelectorAll('.view-img').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('img_title').innerText = this.dataset.title;
            document.getElementById('view_img_src').src = this.dataset.img;
            new bootstrap.Modal(document.getElementById('imgModal')).show();
        });
    });

    // --- SIMPLE STATUS UPDATE (Picked Up / On Delivery) ---
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
                    
                    // Uses the SAME backend action file as BHW
                    fetch('api/bhw_request_action.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(d => {
                        if(d.success) location.reload();
                    });
                }
            });
        });
    });

    // --- CONFIRM DELIVERY (Upload Proof) ---
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