<?php 
session_start();

// get user role
$role = $_SESSION['Role_Name'] ?? '';
$restricted = in_array($role, ['Punong Barangay','Barangay Secretary'], true);

// who can edit/add
$canEdit = !$restricted;
$canAdd  = !$restricted;

// --- server-side guard ---
if (($_POST['update_case_details'] ?? '') === '1' && !$canEdit) {
  http_response_code(403); exit('Forbidden: your role cannot edit cases.');
}
if (isset($_POST['add_case']) && !$canAdd) {
  http_response_code(403); exit('Forbidden: your role cannot add cases.');
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php'; 
?>

<div class="modal fade" id="viewCaseModal" tabindex="-1" aria-labelledby="viewCaseModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="POST" id="editCaseForm" novalidate>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewCaseModalLabel">Case Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="update_case_details" value="1">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-bold" for="modal_case_number">Case Number</label>
              <input type="text" class="form-control fw-bold text-primary" name="case_number" id="modal_case_number" readonly>
            </div>

            <div class="col-12">
                <div class="p-3 border rounded bg-light">
                    <h6 class="text-primary"><i class="bi bi-people-fill"></i> Involved Parties (Read-Only List)</h6>
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <small class="text-muted fw-bold text-uppercase">Complainants:</small>
                            <div id="modal_complainant_list" class="mt-1 ps-2" style="font-size: 0.95rem;"></div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted fw-bold text-uppercase">Respondents:</small>
                            <div id="modal_respondent_list" class="mt-1 ps-2" style="font-size: 0.95rem;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12"><hr></div>

            <div class="col-12"><h6 class="mb-0 fw-bold">Edit Primary Details & Add New Participants</h6></div>

            <div class="col-12">
               <label class="form-label text-muted">Primary Complainant:</label>
               <div class="row g-2 mb-2">
                 <div class="col-md-3"><input type="text" class="form-control" name="Comp_First_Name" id="Comp_First_Name" placeholder="First Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Comp_Middle_Name" id="Comp_Middle_Name" placeholder="Middle Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Comp_Last_Name" id="Comp_Last_Name" placeholder="Last Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Comp_Suffix_Name" id="Comp_Suffix_Name" placeholder="Suffix"></div>
               </div>
               
               <div id="editComplainantsContainer"></div>
               <div id="editComplainantTemplate" class="row g-2 mb-2 border p-2 rounded bg-light" style="display: none;">
                 <div class="col-md-3"><input type="text" class="form-control" name="Complainant[first_name][]" placeholder="New First Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Complainant[middle_name][]" placeholder="Middle Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Complainant[last_name][]" placeholder="Last Name"></div>
                 <div class="col-md-2"><input type="text" class="form-control" name="Complainant[suffix_name][]" placeholder="Suffix"></div>
                 <div class="col-md-1"><button type="button" class="btn btn-danger btn-remove-participant w-100"><i class="bi bi-x"></i></button></div>
               </div>
               <button type="button" class="btn btn-sm btn-link text-decoration-none" id="addEditComplainantBtn">+ Add Another Complainant</button>
            </div>

            <div class="col-12 mt-3">
               <label class="form-label text-muted">Primary Respondent:</label>
               <div class="row g-2 mb-2">
                 <div class="col-md-3"><input type="text" class="form-control" name="Resp_First_Name" id="Resp_First_Name" placeholder="First Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Resp_Middle_Name" id="Resp_Middle_Name" placeholder="Middle Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Resp_Last_Name" id="Resp_Last_Name" placeholder="Last Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Resp_Suffix_Name" id="Resp_Suffix_Name" placeholder="Suffix"></div>
               </div>

               <div id="editRespondentsContainer"></div>
               <div id="editRespondentTemplate" class="row g-2 mb-2 border p-2 rounded bg-light" style="display: none;">
                 <div class="col-md-3"><input type="text" class="form-control" name="Respondent[first_name][]" placeholder="New First Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Respondent[middle_name][]" placeholder="Middle Name"></div>
                 <div class="col-md-3"><input type="text" class="form-control" name="Respondent[last_name][]" placeholder="Last Name"></div>
                 <div class="col-md-2"><input type="text" class="form-control" name="Respondent[suffix_name][]" placeholder="Suffix"></div>
                 <div class="col-md-1"><button type="button" class="btn btn-danger btn-remove-participant w-100"><i class="bi bi-x"></i></button></div>
               </div>
               <button type="button" class="btn btn-sm btn-link text-decoration-none" id="addEditRespondentBtn">+ Add Another Respondent</button>
            </div>

            <div class="col-md-6 mt-4">
              <label class="form-label" for="modal_nature_offense">Nature of Offense</label>
              <input type="text" class="form-control" name="nature_offense" id="modal_nature_offense" required>
            </div>
            <div class="col-md-6 mt-4">
              <label class="form-label" for="modal_date_hearing">Date of Hearing</label>
              <input type="date" class="form-control" name="date_hearing" id="modal_date_hearing" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_date_filed">Date Filed</label>
              <input type="date" class="form-control" name="date_filed" id="modal_date_filed" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="modal_time_filed">Time Filed</label>
              <input type="time" class="form-control" name="time_filed" id="modal_time_filed" required>
            </div>
          </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-success" id="saveChangesBtn"
              <?= $canEdit ? '' : 'disabled' ?>>
              Save Changes
            </button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog">
    <form method="POST" novalidate>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="statusModalLabel">Update Case Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="update_action_only" value="1">
          <input type="hidden" name="case_number" id="status_case_number">
          <div class="mb-3">
            <label class="form-label" for="status_action_taken">Action Taken</label>
            <select class="form-select" name="action_taken" id="status_action_taken" required>
              <option value="">Select</option>
              <option value="Conciliated">Conciliated</option>
              <option value="Mediated">Mediated</option>
              <option value="Dismissed">Dismissed</option>
              <option value="Withdrawn">Withdrawn</option>
              <option value="Ongoing">Ongoing</option>
              <option value="Arbitration">Arbitration</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update Status</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($canAdd): ?>
<div class="modal fade" id="addCaseModal" tabindex="-1" aria-labelledby="addCaseModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="addCaseForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="addCaseModalLabel">Add Case (Multiple Participants)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="add_case" value="1">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="case_number_add">Case Number <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="case_number" id="case_number_add" placeholder="2025-001" required pattern="[A-Za-z0-9\-]+" title="Letters, numbers, and dashes only.">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="nature_offense_add">Nature of Offense <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="nature_offense_add" name="nature_offense" required>
            </div>

            <div class="col-12"><hr class="my-2"><h6 class="mb-0 text-primary">Complainants</h6></div>
            <div id="complainantsContainer" class="col-12">
              
              <div id="complainantTemplate" class="row g-2 mb-2 border p-2 rounded bg-light" style="display: none;">
                <div class="col-md-3"><input type="text" class="form-control" name="Complainant[first_name][]" placeholder="First Name"></div>
                <div class="col-md-3"><input type="text" class="form-control" name="Complainant[middle_name][]" placeholder="Middle Name"></div>
                <div class="col-md-3"><input type="text" class="form-control" name="Complainant[last_name][]" placeholder="Last Name"></div>
                <div class="col-md-2"><input type="text" class="form-control" name="Complainant[suffix_name][]" placeholder="Suffix"></div>
                <div class="col-md-1"><button type="button" class="btn btn-danger btn-remove-participant w-100"><i class="bi bi-x"></i></button></div>
              </div>

              <div class="row g-2 mb-2 border p-2 rounded" data-participant="complainant">
                <div class="col-md-3"><input type="text" class="form-control" name="Comp_First_Name_P" id="Comp_First_Name_add" placeholder="First Name *" required autocomplete="given-name"></div>
                <div class="col-md-3"><input type="text" class="form-control" name="Comp_Middle_Name_P" id="Comp_Middle_Name_add" placeholder="Middle Name"></div>
                <div class="col-md-3"><input type="text" class="form-control" name="Comp_Last_Name_P" id="Comp_Last_Name_add" placeholder="Last Name *" required autocomplete="family-name"></div>
                <div class="col-md-2"><input type="text" class="form-control" name="Comp_Suffix_Name_P" id="Comp_Suffix_Name_add" placeholder="Suffix"></div>
                <div class="col-md-1"><button type="button" class="btn btn-secondary w-100" disabled><i class="bi bi-person"></i></button></div>
              </div>
            </div>
            <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary" id="addComplainantBtn">➕ Add Another Complainant</button></div>

            <div class="col-12"><hr class="my-2"><h6 class="mb-0 text-primary">Respondents (Search Resident)</h6></div>
            <div id="respondentsContainer" class="col-12">
              
              <div id="respondentTemplate" class="row g-2 mb-2 border p-2 rounded bg-light" style="display: none;">
                <div class="col-11 position-relative">
                  <input type="text" class="form-control participant-lookup" placeholder="Search Resident for Respondent..." autocomplete="off">
                  <div class="dropdown-menu w-100 shadow d-none resident-suggestions" role="listbox" style="max-height:200px;overflow:auto;position:absolute;z-index: 1060;"></div>
                  
                  <input type="hidden" class="participant-first" name="Respondent[first_name][]">
                  <input type="hidden" class="participant-middle" name="Respondent[middle_name][]">
                  <input type="hidden" class="participant-last" name="Respondent[last_name][]">
                  <input type="hidden" class="participant-suffix" name="Respondent[suffix_name][]">
                </div>
                <div class="col-1">
                  <button type="button" class="btn btn-danger btn-remove-participant w-100"><i class="bi bi-x"></i></button>
                </div>
              </div>

              <div class="row g-2 mb-2 border p-2 rounded" data-participant="respondent">
                <div class="col-11 position-relative">
                  <input type="text" class="form-control participant-lookup" placeholder="Search Resident for Respondent... *" autocomplete="off" required>
                  <div class="dropdown-menu w-100 shadow d-none resident-suggestions" role="listbox" style="max-height:200px;overflow:auto;position:absolute;z-index: 1060;"></div>
                  
                  <input type="hidden" class="participant-first" name="Resp_First_Name_P" id="Resp_First_Name_add" required>
                  <input type="hidden" class="participant-middle" name="Resp_Middle_Name_P" id="Resp_Middle_Name_add">
                  <input type="hidden" class="participant-last" name="Resp_Last_Name_P" id="Resp_Last_Name_add" required>
                  <input type="hidden" class="participant-suffix" name="Resp_Suffix_Name_P" id="Resp_Suffix_Name_add">
                </div>
                <div class="col-1">
                  <button type="button" class="btn btn-secondary w-100" disabled><i class="bi bi-person"></i></button>
                </div>
              </div>
            </div>
            <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary" id="addRespondentBtn">➕ Add Another Respondent</button></div>

            <div class="col-md-4 mt-4">
              <label class="form-label" for="date_filed">Date Filed <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_filed" name="date_filed" required>
              <small id="filedDateError" class="text-danger d-none">❌ Cannot be in the future.</small>
            </div>

            <div class="col-md-4 mt-4">
              <label class="form-label" for="time_filed">Time Filed <span class="text-danger">*</span></label>
              <input type="time" class="form-control" id="time_filed" name="time_filed" required>
            </div>

            <div class="col-md-4 mt-4">
              <label class="form-label" for="date_hearing">Date of Hearing <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_hearing" name="date_hearing" required>
              <small id="hearingError" class="text-danger d-none">❌ Cannot be in the past.</small>
            </div>
            
            <div class="col-md-12">
              <label class="form-label" for="action_taken_add">Action Taken <span class="text-danger">*</span></label>
              <select class="form-select" id="action_taken_add" name="action_taken" required>
                <option value="">Select</option>
                <option value="Conciliated">Conciliated</option>
                <option value="Mediated">Mediated</option>
                <option value="Dismissed">Dismissed</option>
                <option value="Withdrawn">Withdrawn</option>
                <option value="Ongoing">Ongoing</option>
                <option value="Arbitration">Arbitration</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_case" class="btn btn-primary">Add Case</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const role = <?= json_encode($role) ?>;
    const restricted = role === "Punong Barangay" || role === "Barangay Secretary";

    // ----------------------------------------
    // 1. Participant Row Management
    // ----------------------------------------
    const addParticipantRow = (containerId, templateId, isRespondent) => {
        const container = document.getElementById(containerId);
        const template = document.getElementById(templateId);
        
        if (!container || !template) return;

        const clone = template.cloneNode(true);
        clone.style.display = 'flex';
        clone.removeAttribute('id');
        
        // Reset inputs
        clone.querySelectorAll('input').forEach(input => input.value = '');
        
        // Remove button
        const removeBtn = clone.querySelector('.btn-remove-participant');
        if (removeBtn) {
            removeBtn.onclick = (e) => e.target.closest('.row').remove();
        }

        container.appendChild(clone);
        
        // If Respondent, enable Search Lookup
        if (isRespondent) {
            initParticipantLookup(clone);
        }
    };
    
    // ----------------------------------------
    // 2. Resident Search Logic (AJAX)
    // ----------------------------------------
    const initParticipantLookup = (context) => {
        const lookup = context.querySelector('.participant-lookup');
        const menu = context.querySelector('.resident-suggestions');
        
        if (!lookup || !menu) return;

        // Fields to populate
        const first = context.querySelector('.participant-first');
        const middle = context.querySelector('.participant-middle');
        const last = context.querySelector('.participant-last');
        const suffix = context.querySelector('.participant-suffix');

        let timer = null, lastQ = '';
        const debounce = (fn, ms) => (...args) => { clearTimeout(timer); timer = setTimeout(()=>fn(...args), ms); };

        const hideMenu = () => { menu.classList.add('d-none'); menu.classList.remove('show'); };
        const showMenu = () => { menu.classList.remove('d-none'); menu.classList.add('show'); };

        function render(items){
            if (!items || !items.length){ hideMenu(); return; }
            menu.innerHTML = '';
            items.forEach(row=>{
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-item text-wrap border-bottom';
                btn.dataset.f  = row.first_name  || '';
                btn.dataset.m  = row.middle_name || '';
                btn.dataset.l  = row.last_name   || '';
                btn.dataset.s  = row.suffix_name || '';
                
                const full = [btn.dataset.f, btn.dataset.m, btn.dataset.l, btn.dataset.s].filter(Boolean).join(' ');
                const addr = row.res_street_address || '';
                
                btn.innerHTML = `<div class="fw-bold">${full}</div><div class="small text-muted">${addr}</div>`;

                btn.onclick = () => {
                    // Fill hidden fields in THIS row
                    if(first) first.value = btn.dataset.f;
                    if(middle) middle.value = btn.dataset.m;
                    if(last) last.value = btn.dataset.l;
                    if(suffix) suffix.value = btn.dataset.s;
                    
                    lookup.value = full; 
                    hideMenu();
                    
                    // IF this is the PRIMARY required row, update the specific IDs for validation
                    // (This ensures the specific IDs used in form validation get populated)
                    if (context.closest('#respondentsContainer')?.querySelector('.participant-lookup') === lookup) {
                         const pFirst = document.getElementById('Resp_First_Name_add');
                         const pLast  = document.getElementById('Resp_Last_Name_add');
                         if(pFirst) pFirst.value = btn.dataset.f;
                         if(pLast)  pLast.value = btn.dataset.l;
                    }
                };
                menu.appendChild(btn);
            });
            showMenu();
        }

        const search = debounce(async (q)=>{
            q = q.trim();
            if (q.length < 2 || q === lastQ){ hideMenu(); return; }
            lastQ = q;
            try{
                // Ensure this path is correct relative to where this file is included
                const res = await fetch('ajax/case_resident.php?q=' + encodeURIComponent(q), {
                    headers: {'X-Requested-With':'XMLHttpRequest'}
                });
                const data = await res.json();
                render(data);
            }catch(e){
                console.error("Search error", e);
                hideMenu();
            }
        }, 300);
        
        lookup.addEventListener('input', e=>{
            // Clear hidden values on new input
            if(first) first.value = '';
            if(last) last.value = '';
            
            // Clear primary IDs if applicable
            if (context.closest('#respondentsContainer')?.querySelector('.participant-lookup') === lookup) {
                 const pFirst = document.getElementById('Resp_First_Name_add');
                 const pLast = document.getElementById('Resp_Last_Name_add');
                 if(pFirst) pFirst.value = '';
                 if(pLast) pLast.value = '';
            }
            search(e.target.value);
        });

        document.addEventListener('click', e=>{
            if (e.target !== lookup && !menu.contains(e.target)) hideMenu();
        });
    };
    
    // ----------------------------------------
    // 3. Initialization
    // ----------------------------------------
    if (!restricted) {
        // Init Add Buttons
        const addCompBtn = document.getElementById('addComplainantBtn');
        const addRespBtn = document.getElementById('addRespondentBtn');
        const addEditCompBtn = document.getElementById('addEditComplainantBtn');
        const addEditRespBtn = document.getElementById('addEditRespondentBtn');

        if(addCompBtn) addCompBtn.onclick = () => addParticipantRow('complainantsContainer', 'complainantTemplate', false);
        if(addRespBtn) addRespBtn.onclick = () => addParticipantRow('respondentsContainer', 'respondentTemplate', true);
        
        // Init Add Buttons inside Edit Modal
        if(addEditCompBtn) addEditCompBtn.onclick = () => addParticipantRow('editComplainantsContainer', 'editComplainantTemplate', false);
        if(addEditRespBtn) addEditRespBtn.onclick = () => addParticipantRow('editRespondentsContainer', 'editRespondentTemplate', false);

        // Init Search for Primary Respondent (Add Modal)
        document.querySelectorAll('#respondentsContainer > .row[data-participant="respondent"]').forEach(initParticipantLookup);

        // Auto-fill time
        const addCaseModal = document.getElementById('addCaseModal');
        if (addCaseModal) {
            addCaseModal.addEventListener('show.bs.modal', function () {
                const timeInput = document.getElementById('time_filed');
                if (timeInput) {
                    const now = new Date();
                    timeInput.value = now.toTimeString().substring(0,5);
                }
            });
        }
        
        // ----------------------------------------
        // 4. Form Validation
        // ----------------------------------------
        const addCaseForm = document.getElementById('addCaseForm');
        addCaseForm?.addEventListener('submit', function(e) {
            let errorMessages = [];
            
            // Primary Complainant
            const compFirst = document.getElementById('Comp_First_Name_add')?.value.trim();
            const compLast = document.getElementById('Comp_Last_Name_add')?.value.trim();
            if (!compFirst || !compLast) {
                errorMessages.push('Primary Complainant Name is required.');
            }

            // Primary Respondent (Must be selected via lookup)
            const respFirst = document.getElementById('Resp_First_Name_add')?.value.trim();
            const respLast = document.getElementById('Resp_Last_Name_add')?.value.trim();
            const respInput = document.querySelector('#respondentsContainer [data-participant="respondent"] .participant-lookup')?.value.trim();

            if (!respFirst || !respLast) {
                if (respInput && !respFirst) {
                    errorMessages.push('Please select the Respondent from the suggestion list (do not just type the name).');
                } else {
                    errorMessages.push('Primary Respondent is required.');
                }
            }

            // Dates
            const filed = document.getElementById('date_filed')?.value;
            const hearing = document.getElementById('date_hearing')?.value;
            const todayStr = new Date().toISOString().split('T')[0];

            if (filed && filed > todayStr) {
                errorMessages.push('Date Filed cannot be in the future.');
                document.getElementById('filedDateError')?.classList.remove('d-none');
            } else {
                document.getElementById('filedDateError')?.classList.add('d-none');
            }
            
            if (hearing && hearing < todayStr) {
                 // Warning only, or strict error depending on preference. usually hearing CAN be today.
            }

            if (errorMessages.length > 0) {
                e.preventDefault();
                // If using SweetAlert
                if(typeof Swal !== 'undefined'){
                    Swal.fire({
                        icon: 'warning',
                        title: 'Validation Error',
                        html: '<ul class="text-start"><li>' + errorMessages.join('</li><li>') + '</li></ul>',
                        confirmButtonColor: '#d33'
                    });
                } else {
                    alert('Errors:\n- ' + errorMessages.join('\n- '));
                }
            }
        });
    }
});
</script>