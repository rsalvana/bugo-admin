<?php
// api/urgent_beso_request.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

require_once __DIR__ . '/../../include/encryption.php'; 
require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';

$user_role = $_SESSION['Role_Name'] ?? '';
$residents = [];

/* --- Strict Resident Filter: Must have Released Residency FTJ & NOT used for BESO --- */
$sql = "
    SELECT DISTINCT
        r.id,
        TRIM(CONCAT(
            r.first_name, ' ',
            COALESCE(NULLIF(r.middle_name, ''), ''), ' ',
            r.last_name,
            CASE WHEN COALESCE(r.suffix_name,'') <> '' THEN CONCAT(' ', r.suffix_name) ELSE '' END
        )) AS full_name
    FROM residents r
    WHERE r.resident_delete_status = 0
      AND (
        EXISTS (
          SELECT 1
          FROM schedules s
          WHERE s.res_id = r.id
            AND s.certificate = 'Barangay Residency'
            AND s.purpose = 'First Time Jobseeker'
            AND s.status = 'Released'
            AND COALESCE(s.barangay_residency_used_for_beso, 0) = 0
            AND COALESCE(s.appointment_delete_status, 0) = 0
        )
        OR
        EXISTS (
          SELECT 1
          FROM urgent_request u
          WHERE u.res_id = r.id
            AND u.certificate = 'Barangay Residency'
            AND u.purpose = 'First Time Jobseeker'
            AND u.status = 'Released'
            AND COALESCE(u.barangay_residency_used_for_beso, 0) = 0
            AND COALESCE(u.urgent_delete_status, 0) = 0
        )
      )
    ORDER BY full_name ASC
";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $residents[] = $row;
    }
    $result->free();
}

// Strictly BESO Application
$certificates = ['BESO Application'];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>On-Site BESO Request - Multi</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4@5.0.16/bootstrap-4.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <link rel="stylesheet" href="css/urgent/urgent.css">
  <style>
      .queue-item-remove { cursor: pointer; color: #dc3545; transition: color 0.2s; }
      .queue-item-remove:hover { color: #a71d2a; transform: scale(1.1); }
      .sticky-submit-footer { position: sticky; bottom: 0; background: white; z-index: 10; border-top: 1px solid #dee2e6; }
      .staging-card { border-left: 5px solid #0d6efd; }
      .queue-card { border-top: 5px solid #dc3545; }
  </style>
</head>
<body>
<main class="container py-4">
  <div class="card app-card shadow-lg rounded-4 p-3 p-md-5 mb-5">
    
    <header class="text-center mb-4">
      <h1 class="h3 h2-md fw-bold d-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-briefcase-fill text-danger"></i>
        On-Site BESO Request
      </h1>
      <p class="text-muted mb-0 small">Process First Time Jobseeker BESO applications.</p>
    </header>

    <section aria-labelledby="resident-label" class="mb-4">
      <label id="resident-label" for="residentSelect" class="form-label fw-semibold">Select Eligible Resident</label>
      <select id="residentSelect" class="form-select form-select-lg shadow-sm rounded-3">
        <option value="">-- Choose Resident --</option>
        <?php foreach ($residents as $resident): ?>
          <option value="<?= (int)$resident['id'] ?>"><?= htmlspecialchars($resident['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text text-primary"><i class="bi bi-info-circle"></i> Only residents with a <b>Released Barangay Residency (FTJ)</b> appear here.</div>
    </section>

    <section id="residentDetails" class="d-none mb-4" aria-live="polite">
      <div class="card border-0 shadow-sm rounded-4 bg-light">
        <div class="card-body py-2">
             <div class="row align-items-center small">
                <div class="col-md-6">
                    <span class="text-muted">Resident:</span> 
                    <span id="residentName" class="fw-bold text-dark fs-6 ms-1"></span>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted me-1">Status:</span>
                    <span class="badge bg-success">Eligible for BESO</span>
                </div>
             </div>
        </div>
      </div>
    </section>

    <div id="mainInterface" class="d-none">
        <div class="row g-4">
            
            <div class="col-lg-5">
                <div class="card staging-card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">
                        <i class="bi bi-plus-circle me-1 text-primary"></i> Configure Application
                    </div>
                    <div class="card-body">
                        <form id="stagingForm">
                            <div class="mb-3">
                                <label for="CertificateSelect" class="form-label fw-semibold">Certificate Type</label>
                                <select id="CertificateSelect" class="form-select" readonly>
                                    <?php foreach ($certificates as $certName): ?>
                                    <option value="<?= htmlspecialchars($certName) ?>"><?= htmlspecialchars($certName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="dynamicFields" class="border-top pt-3 mt-3">
                                <div id="besoFields" class="mb-3">
                                    <label class="form-label small fw-bold">Education Details</label>
                                    <input type="text" id="educationAttainment" class="form-control mb-2" placeholder="Highest Educational Attainment">
                                    <input type="text" id="course" class="form-control" placeholder="Course / Degree">
                                </div>

                                <div id="purposeContainer" class="mb-3">
                                    <label class="form-label small fw-bold">Purpose</label>
                                    <select id="purposeSelect" class="form-select mb-2">
                                        <option value="">-- Choose Purpose --</option>
                                    </select>
                                    
                                    <div id="customPurposeContainer" class="d-none mt-2">
                                        <input type="text" id="customPurposeInput" class="form-control" placeholder="Specify custom purpose">
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" id="btnAddToList" class="btn btn-primary w-100 mt-2">
                                <i class="bi bi-arrow-right-short"></i> Add to Queue
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card queue-card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-1 text-danger"></i> Request Queue</span>
                        <span class="badge bg-secondary rounded-pill" id="queueCount">0</span>
                    </div>
                    
                    <div class="card-body p-0 position-relative">
                        <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0" id="queueTable">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 35%">Certificate</th>
                                        <th style="width: 45%">Details</th>
                                        <th style="width: 20%" class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="3" class="text-center text-muted py-5">
                                        <i class="bi bi-basket3 display-6 d-block mb-2 text-secondary opacity-25"></i>
                                        List is empty.
                                    </td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="card-footer sticky-submit-footer p-3">
                         <button type="button" id="btnSubmitAll" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm" disabled>
                            <i class="bi bi-send-fill me-2"></i> Submit Application
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.12.4/dist/sweetalert2.all.min.js"></script>

<script>
(() => {
  const $resident = $('#residentSelect');
  const $mainInterface = $('#mainInterface');
  const $cert = $('#CertificateSelect');
  
  const $besoWrap = $('#besoFields');
  const $purposeWrap = $('#purposeContainer');
  const $purpose = $('#purposeSelect');
  const $customPurposeWrap = $('#customPurposeContainer');
  const $customPurposeInput = $('#customPurposeInput');
  
  const $queueTableBody = $('#queueTable tbody');
  const $btnSubmit = $('#btnSubmitAll');
  const $queueCount = $('#queueCount');

  let residentDetails = null;
  let requestQueue = []; 
  const redirectUrl = "<?= enc_beso('urgent_request') ?>";

  const toast = (icon, title) => Swal.fire({toast:true, position:'top-end', showConfirmButton:false, timer:2500, icon, title});

  $resident.select2({ placeholder: 'Search resident...', width: '100%' });

  // 1. Resident Logic
  $resident.on('change', function () {
    const id = this.value;
    
    // Reset
    requestQueue = [];
    updateQueueTable();
    resetStagingArea();
    $mainInterface.addClass('d-none');
    $('#residentDetails').addClass('d-none');
    residentDetails = null;

    if (!id) return;

    Swal.showLoading();
    fetch('ajax/fetch_resident_details.php?id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        Swal.close();
        if (!data?.success) {
          Swal.fire('Error', 'Resident not found.', 'error');
          return;
        }
        residentDetails = data;

        // --- BESO Guardrails (Parity with previous logic) ---
        if (parseInt(data.age || '0', 10) <= 17) {
             Swal.fire('Not Eligible','Resident is under 18.', 'error');
             $resident.val(null).trigger('change');
             return;
        }
        if (data.has_existing_beso) {
             Swal.fire('Duplicate','BESO record already exists.', 'warning');
             $resident.val(null).trigger('change');
             return;
        }

        // Populate Info
        $('#residentName').text(data.full_name);
        
        // Load purposes
        loadPurposes('BESO Application');

        // Show Interface
        $mainInterface.removeClass('d-none');
        $('#residentDetails').removeClass('d-none');
      })
      .catch(() => Swal.fire('Error', 'Connection failed', 'error'));
  });

  // Purpose Toggle
  $purpose.on('change', function() {
      $customPurposeWrap.toggleClass('d-none', this.value === 'others');
  });

  function resetStagingArea() {
      $('#educationAttainment').val('');
      $('#course').val('');
      $purpose.empty(); 
      $customPurposeWrap.addClass('d-none');
      $customPurposeInput.val('');
  }

  function loadPurposes(certName) {
      $purpose.html('<option value="">Loading...</option>');
      fetch('ajax/fetch_purposes_by_certificate.php?cert=' + encodeURIComponent(certName))
        .then(r => r.json())
        .then(data => {
            let html = '<option value="">-- Choose Purpose --</option>';
            if(Array.isArray(data)) {
                data.forEach(p => html += `<option value="${p.purpose_name}">${p.purpose_name}</option>`);
            }
            $purpose.html(html);
        })
        .catch(() => $purpose.html('<option value="others">Others</option>'));
  }

  // 2. Add to Queue
  $('#btnAddToList').on('click', function() {
      const certName = $cert.val(); // Hardcoded 'BESO Application'
      
      // Prevent duplicates
      if(requestQueue.length > 0) {
          Swal.fire('Limit Reached', 'You can only add one BESO application per request.', 'info');
          return;
      }

      // Fields
      const attainment = $('#educationAttainment').val().trim();
      const course = $('#course').val().trim();
      const pSel = $purpose.val();
      const pCust = $customPurposeInput.val();
      const purpose = (pSel === 'others' ? pCust : pSel);

      if (!attainment || !course) { toast('warning', 'Education details required.'); return; }
      if (!purpose) { toast('warning', 'Purpose required.'); return; }

      const payload = {
         userId: $resident.val(),
         certificate: certName,
         urgent: true,
         education_attainment: attainment,
         course: course,
         purpose: purpose
      };

      const uniqueId = Date.now(); 
      requestQueue.push({
          id: uniqueId,
          certificate: certName,
          payload: payload,
          details: `Course: ${course}`
      });

      updateQueueTable();
      resetStagingArea(); // Optional: clear inputs to prevent double add confusion
      toast('success', 'Added to Queue');
  });

  // 3. Queue UI
  function updateQueueTable() {
      $queueTableBody.empty();
      if(requestQueue.length === 0) {
          $queueTableBody.html(`<tr><td colspan="3" class="text-center text-muted py-5">List is empty.</td></tr>`);
          $btnSubmit.prop('disabled', true);
          $queueCount.text('0');
          return;
      }

      requestQueue.forEach(item => {
         const row = `
            <tr>
                <td><span class="fw-bold text-primary">${item.certificate}</span></td>
                <td><small class="text-muted">${item.details}</small></td>
                <td class="text-end">
                    <i class="bi bi-trash3-fill queue-item-remove fs-5" 
                       onclick="removeItem(${item.id})" title="Remove"></i>
                </td>
            </tr>`;
         $queueTableBody.append(row);
      });
      $btnSubmit.prop('disabled', false);
      $queueCount.text(requestQueue.length);
  }

  window.removeItem = function(id) {
      requestQueue = requestQueue.filter(item => item.id !== id);
      updateQueueTable();
  };

  // 4. Submit
  $btnSubmit.on('click', async function() {
      if(requestQueue.length === 0) return;

      const confirmed = await Swal.fire({
          title: 'Submit Application?',
          text: `Finalize BESO Application.`,
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Yes, Submit',
          confirmButtonColor: '#d63384'
      });

      if (!confirmed.isConfirmed) return;

      Swal.fire({
          title: 'Processing...',
          allowOutsideClick: false,
          didOpen: () => { Swal.showLoading(); }
      });

      let failCount = 0;

      for (const item of requestQueue) {
          try {
              // 1. Save Schedule
              const resp = await fetch('class/save_schedule.php', { 
                  method: 'POST', 
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify(item.payload)
              });
              const result = await resp.json();

              if (!result.success) throw new Error(result.message || 'Save schedule failed');

              // 2. Save BESO Details
              const besoResp = await fetch('class/save_beso.php', { 
                  method: 'POST', 
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify(item.payload)
              });
              const besoResult = await besoResp.json();
              
              if (!besoResult.success) {
                   console.error('BESO Save Error', besoResult);
                   // Note: Schedule is already saved, might need rollback logic in strict systems
                   // But for now we count it as failure
                   throw new Error('BESO detail save failed');
              }

          } catch (err) {
              failCount++;
              console.error('Submission Error:', err);
          }
      }

      Swal.close();

      if (failCount === 0) {
          Swal.fire({icon: 'success', title: 'Success', text: 'Application Submitted.'})
            .then(() => window.location.reload());
      } else {
          Swal.fire({icon: 'error', title: 'Error', text: 'Failed to submit application.'});
      }
  });

})();
</script>
</body>
</html>