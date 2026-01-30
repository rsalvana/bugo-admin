<?php
// api/urgent_request.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';

$redirects = $redirects ?? [
    'case_list'    => 'case_list.php',
    'appointments' => 'view_appointments.php',
];

$user_role = $_SESSION['Role_Name'] ?? '';

/* --- 1. Fetch Residents --- */
$residents = [];
$q = "SELECT id,
             TRIM(CONCAT(first_name,' ',IFNULL(middle_name,''),' ',last_name,' ',IFNULL(suffix_name,''))) AS full_name
      FROM residents
      WHERE resident_delete_status = 0
      ORDER BY last_name, first_name";
$r = $mysqli->query($q);
while ($row = $r->fetch_assoc()) { $residents[] = $row; }

/* --- 2. Fetch Certificates --- */
$certificates = [];
$certQ = "SELECT Certificates_Name FROM certificates WHERE status='Active' ORDER BY Certificates_Name";
$certR = $mysqli->query($certQ);
while ($row = $certR->fetch_assoc()) {
    $name = $row['Certificates_Name'];
    // Hide BESO for revenue staff
    if (stripos($user_role, 'revenue') !== false && $name === 'BESO Application') continue;
    $certificates[] = $name; 
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Urgent Appointment - Multi-Request</title>

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
        <i class="bi bi-collection-fill text-danger"></i>
        Multi-Certificate Request
      </h1>
      <p class="text-muted mb-0 small">Create multiple urgent requests for a single resident.</p>
    </header>

    <section aria-labelledby="resident-label" class="mb-4">
      <label id="resident-label" for="residentSelect" class="form-label fw-semibold">Select Resident</label>
      <select id="residentSelect" class="form-select form-select-lg shadow-sm rounded-3">
        <option value="">-- Choose Resident --</option>
        <?php foreach ($residents as $resident): ?>
          <option value="<?= (int)$resident['id'] ?>"><?= htmlspecialchars($resident['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Changing the resident will clear the current request list.</div>
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
                    <span class="text-muted me-1">Cedula Status:</span>
                    <span id="cedulaStatusBadge" class="badge"></span>
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
                        <i class="bi bi-plus-circle me-1 text-primary"></i> Configure Request
                    </div>
                    <div class="card-body">
                        <form id="stagingForm">
                            <div class="mb-3">
                                <label for="CertificateSelect" class="form-label fw-semibold">1. Select Certificate</label>
                                <select id="CertificateSelect" class="form-select">
                                    <option value="">-- Choose Certificate --</option>
                                    <?php foreach ($certificates as $certName): ?>
                                    <option value="<?= htmlspecialchars($certName) ?>"><?= htmlspecialchars($certName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div id="dynamicFields" class="border-top pt-3 mt-3">
                                
                                <div id="cedulaActionContainer" class="mb-3 d-none config-field">
                                    <label class="form-label small fw-bold">Cedula Action</label>
                                    <select id="cedulaActionSelect" class="form-select mb-2">
                                        <option value="">-- Choose Action --</option>
                                        <option value="upload">Upload Existing Cedula</option>
                                        <option value="request">Request New Cedula</option>
                                    </select>
                                </div>

                                <div id="incomeContainer" class="mb-3 d-none config-field">
                                    <label class="form-label small fw-bold">Declared Income</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" id="incomeInput" class="form-control" placeholder="0.00">
                                    </div>
                                </div>

                                <div id="uploadCedulaContainer" class="mb-3 p-3 bg-light rounded border d-none config-field">
                                    <h6 class="small fw-bold mb-2">Upload Details</h6>
                                    <input type="text" class="form-control mb-2 form-control-sm" id="cedulaNumber" placeholder="Cedula No." autocomplete="off">
                                    <input type="date" class="form-control mb-2 form-control-sm" id="dateIssued">
                                    <input type="text" class="form-control mb-2 form-control-sm" id="issuedAt" placeholder="Issued At (Place)">
                                    <input type="number" class="form-control mb-2 form-control-sm" id="incomeUpload" placeholder="Income Amount">
                                    <label class="form-label small text-muted">File (PDF/Image)</label>
                                    <input type="file" class="form-control form-control-sm" id="cedulaFile" accept=".pdf,.jpg,.jpeg,.png">
                                </div>

                                <div id="besoFields" class="mb-3 d-none config-field">
                                    <label class="form-label small fw-bold">Education</label>
                                    <input type="text" id="educationAttainment" class="form-control mb-2" placeholder="Highest Educational Attainment">
                                    <input type="text" id="course" class="form-control" placeholder="Course / Degree">
                                </div>

                                <div id="purposeContainer" class="mb-3 d-none config-field">
                                    <label class="form-label small fw-bold">Purpose</label>
                                    <select id="purposeSelect" class="form-select mb-2">
                                        <option value="">-- Choose Purpose --</option>
                                    </select>
                                </div>

                            </div>
                            
                            <button type="button" id="btnAddToList" class="btn btn-primary w-100 mt-2">
                                <i class="bi bi-arrow-right-short"></i> Add to Request List
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
                                        List is empty. Configure a request on the left.
                                    </td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="card-footer sticky-submit-footer p-3">
                         <button type="button" id="btnSubmitAll" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm" disabled>
                            <i class="bi bi-send-fill me-2"></i> Submit All Requests
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
  // --- DOM Elements ---
  const $resident = $('#residentSelect');
  const $mainInterface = $('#mainInterface');
  const $cert = $('#CertificateSelect');
  
  const $cedulaActionWrap = $('#cedulaActionContainer');
  const $cedulaAction = $('#cedulaActionSelect');
  const $incomeWrap = $('#incomeContainer');
  const $uploadCedulaWrap = $('#uploadCedulaContainer');
  const $besoWrap = $('#besoFields');
  const $purposeWrap = $('#purposeContainer');
  const $purpose = $('#purposeSelect');
  
  const $queueTableBody = $('#queueTable tbody');
  const $btnSubmit = $('#btnSubmitAll');
  const $queueCount = $('#queueCount');

  let residentDetails = null;
  let requestQueue = []; 

  const toast = (icon, title) => Swal.fire({toast:true, position:'top-end', showConfirmButton:false, timer:2500, icon, title});

  $resident.select2({ placeholder: 'Search resident...', width: '100%' });

  // -------------------------------------------------------------
  // 1. Resident Selection
  // -------------------------------------------------------------
  $resident.on('change', function () {
    const id = this.value;
    
    // Reset State
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
        
        $('#residentName').text(data.full_name);
        
        // Dynamic Badge Logic for All Statuses
        const status = data.cedula_status; 
        let cedulaTxt = 'None / Not Released';
        let badgeClass = 'badge bg-warning text-dark border border-dark';

        if (status === 'released') {
            cedulaTxt = 'Released / Active';
            badgeClass = 'badge bg-success';
        } else if (status === 'pending') {
            cedulaTxt = 'Pending Approval';
            badgeClass = 'badge bg-info text-dark';
        } else if (status === 'approved' || status === 'approvedcaptain') {
            cedulaTxt = 'Approved / For Release';
            badgeClass = 'badge bg-primary';
        }
        
        $('#cedulaStatusBadge').attr('class', badgeClass).text(cedulaTxt);

        const age = parseInt(data.age || '0', 10);
        $cert.find('option').each(function() {
            const val = $(this).val();
            if(age < 18 && (val === 'Barangay Clearance' || val === 'BESO Application')) {
                $(this).prop('disabled', true).text(val + ' (18+ only)');
            } else {
                $(this).prop('disabled', false).text(val);
            }
        });

        $mainInterface.removeClass('d-none');
        $('#residentDetails').removeClass('d-none');
      })
      .catch(() => Swal.fire('Error', 'Connection failed', 'error'));
  });

  // -------------------------------------------------------------
  // 2. Certificate Logic
  // -------------------------------------------------------------
  $cert.on('change', function() {
      const selected = this.value;
      resetStagingInputs(); 

      if (!selected) return;

      const isCedula = selected === 'Cedula';
      const isBeso = selected === 'BESO Application';

      if (isCedula) {
          $cedulaActionWrap.removeClass('d-none');
      } else if (isBeso) {
          $besoWrap.removeClass('d-none');
          $purposeWrap.removeClass('d-none');
          loadPurposes(selected);
      } else {
          $purposeWrap.removeClass('d-none');
          loadPurposes(selected);
      }
  });

  $cedulaAction.on('change', function() {
      const act = this.value;
      $incomeWrap.toggleClass('d-none', act !== 'request');
      $uploadCedulaWrap.toggleClass('d-none', act !== 'upload');
  });

  function resetStagingArea() {
      $cert.val('').trigger('change');
      resetStagingInputs();
  }

  function resetStagingInputs() {
      $('.config-field').addClass('d-none'); 
      $('#stagingForm').find('input, select').not('#CertificateSelect').val('');
      $purpose.empty(); 
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

  // -------------------------------------------------------------
  // 3. ADD TO QUEUE (Updated Validation for Blocking)
  // -------------------------------------------------------------
  $('#btnAddToList').on('click', function() {
      const certName = $cert.val();
      if(!certName) { toast('warning', 'Please select a certificate.'); return; }

      // ✅ BLOCK IF DUPLICATE IN DB (Global Check)
      if (residentDetails.pending_certificates.includes(certName)) {
           Swal.fire({
                icon: 'warning',
                title: 'Existing Request Found',
                html: `Resident already has an active request for <b>${certName}</b> in the system.`,
                confirmButtonColor: '#0d6efd'
           });
           return;
      }

      // ✅ BLOCK IF CEDULA HAS BLOCKING STATUS (Pending, Approved, etc.)
      if (certName === 'Cedula' && residentDetails.has_pending_cedula) {
          Swal.fire({
                icon: 'warning',
                title: 'Cedula in Progress',
                html: `This resident already has a <b>${residentDetails.cedula_status.toUpperCase()}</b> request.<br>Please complete it before adding a new one.`,
                confirmButtonColor: '#0d6efd'
          });
          return;
      }

      // Duplicate Check in local session list
      if(requestQueue.some(item => item.certificate === certName)) {
          Swal.fire('Duplicate', `${certName} is already in your request list.`, 'info');
          return;
      }

      // Prerequisite Check
      if (certName !== 'Cedula') {
          const hasDbCedula = residentDetails.has_approved_cedula;
          const hasQueueCedula = requestQueue.some(item => item.certificate === 'Cedula');

          if (!hasDbCedula && !hasQueueCedula) {
              Swal.fire({
                  icon: 'warning',
                  title: 'Cedula Required',
                  html: `Resident does not have a released Cedula.<br><br>Please <b>add a Cedula request</b> to the list first.`,
                  confirmButtonText: 'Understood'
              });
              return;
          }
      }

      // BESO Logic
      if (certName === 'BESO Application') {
          if (residentDetails.has_existing_beso) {
               Swal.fire('Restricted', 'Resident already has an existing BESO record.', 'error');
               return;
          }
      }

      // Payload Construction
      let payload = { userId: $resident.val(), certificate: certName, urgent: true };
      let displayDetails = "";

      if (certName === 'Cedula') {
          const action = $cedulaAction.val();
          if(!action) { toast('warning', 'Select Cedula Action'); return; }
          
          if(action === 'request') {
              const inc = parseFloat($('#incomeInput').val());
              if(!inc && inc !== 0) { toast('warning', 'Enter valid Income'); return; }
              payload.action = 'request';
              payload.income = inc;
              payload.purpose = 'Cedula Application';
              displayDetails = `Request New (Inc: ₱${inc.toLocaleString()})`;
          } else {
              const cNo = $('#cedulaNumber').val();
              const dIss = $('#dateIssued').val();
              const issAt = $('#issuedAt').val();
              const cFile = $('#cedulaFile')[0].files[0];
              if(!cNo || !dIss || !issAt || !cFile) { toast('warning', 'Fill all upload fields & file'); return; }
              payload.action = 'upload';
              payload.cedulaNumber = cNo;
              payload.dateIssued = dIss;
              payload.issuedAt = issAt;
              payload.income = parseFloat($('#incomeUpload').val()) || 0;
              payload.file = cFile; 
              displayDetails = `Upload Existing (No: ${cNo})`;
          }
      } 
      else {
          const pSel = $purpose.val();
          if(!pSel) { toast('warning', 'Select a purpose'); return; }
          payload.purpose = pSel;
          displayDetails = `Purpose: ${pSel}`;

          if(certName === 'BESO Application') {
              const educ = $('#educationAttainment').val();
              const cour = $('#course').val();
              if(!educ || !cour) { toast('warning', 'Education details required for BESO'); return; }
              payload.education_attainment = educ;
              payload.course = cour;
          }
      }

      const uniqueId = Date.now() + Math.random(); 
      requestQueue.push({
          id: uniqueId,
          certificate: certName,
          payload: payload,
          details: displayDetails
      });

      updateQueueTable();
      resetStagingArea();
      toast('success', 'Added to list');
  });

  function updateQueueTable() {
      $queueTableBody.empty();
      if(requestQueue.length === 0) {
          $queueTableBody.html(`
            <tr><td colspan="3" class="text-center text-muted py-5">
                <i class="bi bi-basket3 display-6 d-block mb-2 text-secondary opacity-25"></i>
                List is empty. Configure a request on the left.
            </td></tr>`);
          $btnSubmit.prop('disabled', true);
          $queueCount.text('0');
          return;
      }
      requestQueue.forEach(item => {
         $queueTableBody.append(`
            <tr>
                <td><span class="fw-bold text-primary">${item.certificate}</span></td>
                <td><small class="text-muted">${item.details}</small></td>
                <td class="text-end">
                    <i class="bi bi-trash3-fill queue-item-remove fs-5" 
                       onclick="removeItem(${item.id})" title="Remove"></i>
                </td>
            </tr>`);
      });
      $btnSubmit.prop('disabled', false);
      $queueCount.text(requestQueue.length);
  }

  window.removeItem = function(id) {
      const itemToRemove = requestQueue.find(i => i.id === id);
      if(!itemToRemove) return;

      if(itemToRemove.certificate === 'Cedula') {
           const othersExist = requestQueue.some(i => i.certificate !== 'Cedula');
           if(!residentDetails.has_approved_cedula && othersExist) {
               Swal.fire({
                   title: 'Cannot Remove Cedula',
                   text: 'Other items in your list depend on this Cedula request.',
                   icon: 'warning'
               });
               return; 
           }
      }
      requestQueue = requestQueue.filter(item => item.id !== id);
      updateQueueTable();
  };

  $btnSubmit.on('click', async function() {
      if(requestQueue.length === 0) return;
      const confirmed = await Swal.fire({
          title: 'Submit All Requests?',
          text: `You are about to submit ${requestQueue.length} request(s).`,
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Yes, Submit All'
      });
      if (!confirmed.isConfirmed) return;

      Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

      let successCount = 0;
      let failCount = 0;

      // Sort: Cedula -> Residency -> Others
      requestQueue.sort((a, b) => {
          if (a.certificate === 'Cedula') return -1;
          if (b.certificate === 'Cedula') return 1;
          if (a.certificate === 'Barangay Residency') return -1;
          return 1;
      });

      for (const item of requestQueue) {
          try {
              let result;
              if (item.certificate === 'Cedula' && item.payload.action === 'upload') {
                  const formData = new FormData();
                  for (const key in item.payload) { formData.append(key, item.payload[key]); }
                  const resp = await fetch('class/save_urgent_cedula.php', { method: 'POST', body: formData });
                  result = await resp.json();
              } else {
                  const resp = await fetch('class/save_schedule.php', { 
                      method: 'POST', 
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(item.payload)
                  });
                  result = await resp.json();
              }
              if (result.success) successCount++; else failCount++;
          } catch (err) { failCount++; }
      }

      Swal.close();
      Swal.fire({
          icon: failCount === 0 ? 'success' : 'warning',
          title: failCount === 0 ? 'All Done!' : 'Partial Completion',
          text: `Submitted: ${successCount} \nFailed: ${failCount}.`
      }).then(() => window.location.reload());
  });

})();
</script>
</body>
</html>