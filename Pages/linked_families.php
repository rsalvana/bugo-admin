<?php
// Pages/linked_families.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../security/500.html';
        exit();
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once './include/redirects.php';
require_once './class/session_timeout.php';
require_once __DIR__ . '/../include/connection.php';

$mysqli = db_connection();

// -------------------------
// Fetch linked relationships
// -------------------------
$sql = "
SELECT
    child.id AS related_resident_id,
    CONCAT(child.first_name, ' ', child.middle_name, ' ', child.last_name) AS child_name,
    parent.id AS resident_id,
    CONCAT(parent.first_name, ' ', parent.middle_name, ' ', parent.last_name) AS parent_name,
    r.relationship_type,
    r.status,
    r.id_birthcertificate
FROM resident_relationships r
JOIN residents parent ON r.resident_id = parent.id
JOIN residents child  ON r.related_resident_id = child.id
WHERE parent.resident_delete_status = 0
  AND child.resident_delete_status  = 0
ORDER BY child_name ASC
";

$result = $mysqli->query($sql);

// Organize results by child
$children = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $cid = (int)$row['related_resident_id'];
        if (!isset($children[$cid])) {
            $children[$cid] = [
                'name' => $row['child_name'],
                'relationships' => []
            ];
        }
        $children[$cid]['relationships'][] = [
            'resident_id'            => (int)$row['resident_id'],
            'parent_name'            => $row['parent_name'],
            'relationship_type'      => ucfirst($row['relationship_type']),
            'relationship_type_raw'  => $row['relationship_type'],
            'status'                 => ucfirst($row['status']),
            'related_resident_id'    => $cid,
        ];
    }
}

// -------------------------
// Role-based routes
// -------------------------
$user_role = strtolower($_SESSION['Role_Name'] ?? '');

if ($user_role === 'admin') {
    $unlinkAction = enc_admin('unlink_relationship');
    $linkbaseUrl  = enc_admin('resident_info');
} elseif ($user_role === 'encoder') {
    $unlinkAction = enc_encoder('unlink_relationship');
    $linkbaseUrl  = enc_encoder('resident_info');
} elseif ($user_role === 'barangay secretary') {
    $unlinkAction = enc_brgysec('unlink_relationship');
    $linkbaseUrl  = enc_brgysec('resident_info');
} else {
    $unlinkAction = '#';
    $linkbaseUrl  = '#';
}

$csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;
?>

<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-link me-2"></i>Linked Parent-Child Relationships</h4>
    <a href="<?= htmlspecialchars($linkbaseUrl) ?>" class="btn btn-secondary">← Back to Residents</a>
  </div>

  <div class="table-responsive w-100" style="height: 300px; overflow-y: auto;">
    <table class="table table-bordered w-100 mb-0">
      <thead class="table-dark">
        <tr>
          <th>Child Name</th>
          <th>Parent Name</th>
          <th>Relationship Type</th>
          <th>Status</th>
          <th style="width:210px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($children)): ?>
          <?php foreach ($children as $child_id => $data): ?>
            <?php
              $collapseId = "child-rel-" . (int)$child_id;
              $first = true;
              foreach ($data['relationships'] as $rel):
                $status = $rel['status'];
                $badgeClass = match (strtolower($status)) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    'pending'  => 'warning',
                    default    => 'secondary'
                };
            ?>
              <tr<?= $first ? " class='parent-row'" : " class='collapse' id='$collapseId'" ?>>
                <td<?= $first ? " data-bs-toggle='collapse' data-bs-target='#$collapseId' style='cursor:pointer;'" : "" ?>>
                  <?= $first ? "<strong>" . htmlspecialchars($data['name']) . "</strong>" : "" ?>
                </td>
                <td><?= htmlspecialchars($rel['parent_name']) ?></td>
                <td><?= htmlspecialchars($rel['relationship_type']) ?></td>
                <td><span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span></td>
                <td class="d-flex flex-wrap gap-1">

                  <!-- Unlink -->
                  <form method="POST"
                        action="<?= htmlspecialchars($unlinkAction) ?>"
                        class="unlink-form d-inline-block">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="related_resident_id" value="<?= (int)$rel['related_resident_id'] ?>">
                    <input type="hidden" name="relationship_type" value="<?= htmlspecialchars(strtolower($rel['relationship_type_raw'])) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">
                      <i class="fas fa-unlink me-1"></i>Unlink
                    </button>
                  </form>

                  <!-- View Certificate -->
                  <button type="button"
                          class="btn btn-sm btn-info text-white"
                          data-bs-toggle="modal"
                          data-bs-target="#viewCertificateModal"
                          data-child-id="<?= (int)$rel['related_resident_id'] ?>"
                          data-parent-id="<?= (int)$rel['resident_id'] ?>"
                          data-relationship="<?= htmlspecialchars($rel['relationship_type_raw']) ?>">
                    <i class="fas fa-file-alt me-1"></i>View
                  </button>

                  <!-- Edit Status -->
                  <button type="button"
                          class="btn btn-sm btn-outline-primary"
                          data-bs-toggle="modal"
                          data-bs-target="#editStatusModal"
                          data-child-id="<?= (int)$rel['related_resident_id'] ?>"
                          data-parent-id="<?= (int)$rel['resident_id'] ?>"
                          data-relationship="<?= htmlspecialchars($rel['relationship_type_raw']) ?>"
                          data-current-status="<?= htmlspecialchars(strtolower($status)) ?>">
                    <i class="fas fa-edit"></i>
                  </button>

                </td>
              </tr>
              <?php $first = false; ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="text-center">No linked relationships found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- View Certificate Modal -->
<div class="modal fade" id="viewCertificateModal" tabindex="-1" aria-labelledby="viewCertificateModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i> Birth Certificate Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-3">
        <div id="certificate-loader" class="my-4">
          <div class="spinner-border text-primary" role="status"></div>
          <p class="mt-2 text-muted">Loading certificate...</p>
        </div>
        <img id="certificate-image" class="img-fluid d-none border shadow" style="max-height: 90vh;" alt="Birth Certificate Image">
        <iframe id="certificate-pdf" class="w-100 d-none" style="height:90vh; border:1px solid #ccc;" frameborder="0"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- Status Edit Modal -->
<div class="modal fade" id="editStatusModal" tabindex="-1" aria-labelledby="editStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="class/update_relationship_status.php" class="modal-content" id="updateStatusForm">
      <div class="modal-header">
        <h5 class="modal-title">Update Relationship Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="related_resident_id" id="modal-child-id">
        <input type="hidden" name="resident_id"        id="modal-parent-id">
        <input type="hidden" name="relationship_type"  id="modal-relationship-type">

        <div class="mb-3">
          <label class="form-label">New Status</label>
          <select class="form-select" name="status" id="modal-status" required>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>

        <!-- Optional: if you want to show an embedded certificate here too, add this iframe and it will auto-load -->
        <!-- <div class="mb-3">
          <label class="form-label">Uploaded Birth Certificate</label>
          <iframe id="birth-certificate-frame" src="" style="width:100%; height:400px; border:1px solid #ccc;" frameborder="0"></iframe>
        </div> -->
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Update Status</button>
      </div>
    </form>
  </div>
</div>

<!-- Dependencies (SweetAlert2 + Bootstrap Bundle) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// -----------------------------
// Edit Status Modal: populate
// -----------------------------
document.addEventListener('DOMContentLoaded', () => {
  const editModal = document.getElementById('editStatusModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;

      document.getElementById('modal-child-id').value        = btn.getAttribute('data-child-id') || '';
      document.getElementById('modal-parent-id').value       = btn.getAttribute('data-parent-id') || '';
      document.getElementById('modal-relationship-type').value = btn.getAttribute('data-relationship') || '';
      document.getElementById('modal-status').value          = btn.getAttribute('data-current-status') || 'pending';

      // If you uncomment the iframe in the modal, this will load it:
      const iframe = document.getElementById('birth-certificate-frame');
      if (iframe) {
        const url = `ajax/fetch_birth_certificate.php?related_resident_id=${encodeURIComponent(btn.getAttribute('data-child-id'))}&resident_id=${encodeURIComponent(btn.getAttribute('data-parent-id'))}&relationship_type=${encodeURIComponent(btn.getAttribute('data-relationship'))}`;
        iframe.src = url;
      }
    });
  }

  // --------------------------------
  // View Certificate Modal: preview
  // --------------------------------
  const viewModal = document.getElementById('viewCertificateModal');
  const img       = document.getElementById('certificate-image');
  const iframe    = document.getElementById('certificate-pdf');
  const loader    = document.getElementById('certificate-loader');

  if (viewModal && img && iframe && loader) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      if (!btn) return;

      const childId = btn.getAttribute('data-child-id');
      const parentId = btn.getAttribute('data-parent-id');
      const rel = btn.getAttribute('data-relationship');
      const url = `ajax/fetch_birth_certificate.php?related_resident_id=${encodeURIComponent(childId)}&resident_id=${encodeURIComponent(parentId)}&relationship_type=${encodeURIComponent(rel)}`;

      // Reset
      img.classList.add('d-none');
      iframe.classList.add('d-none');
      loader.classList.remove('d-none');

      // Try image first
      img.onload = () => {
        loader.classList.add('d-none');
        img.classList.remove('d-none');
      };
      img.onerror = () => {
        // fallback to PDF
        iframe.src = url;
        iframe.onload = () => {
          loader.classList.add('d-none');
          iframe.classList.remove('d-none');
        };
      };

      img.src = url;
    });

    viewModal.addEventListener('hidden.bs.modal', function () {
      img.src = '';
      iframe.src = '';
      img.classList.add('d-none');
      iframe.classList.add('d-none');
      loader.classList.remove('d-none');
    });
  }

  // -----------------------------
  // Unlink confirmation (Swal)
  // -----------------------------
  document.querySelectorAll('.unlink-form').forEach(form => {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      Swal.fire({
        title: 'Are you sure?',
        text: 'This will unlink the parent-child relationship.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, unlink it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33'
      }).then(result => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
    });
  });

  // -----------------------------
  // Update status confirmation
  // -----------------------------
  const updateForm = document.getElementById('updateStatusForm');
  if (updateForm) {
    updateForm.addEventListener('submit', function (e) {
      e.preventDefault();
      Swal.fire({
        title: 'Confirm Update',
        text: 'Are you sure you want to update the status?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, update it!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa'
      }).then((result) => {
        if (result.isConfirmed) {
          updateForm.submit();
        }
      });
    });
  }
});
</script>
