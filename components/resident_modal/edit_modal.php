<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}
include 'class/session_timeout.php';

$role = strtolower($_SESSION['Role_Name'] ?? '');
$canEditPassword = ($role === 'admin'); // ← only admins can edit password
?>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="editModalLabel">
          <i class="fa-solid fa-user-pen me-2"></i>Edit Resident Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form id="editForm" method="POST" action="class/update_resident.php" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="id" id="editId">

          <!-- names -->
          <div class="row gy-3">
            <div class="col-md-3">
              <label for="editFirstName" class="form-label">First Name</label>
              <input type="text" class="form-control" id="editFirstName" name="first_name" required>
            </div>
            <div class="col-md-3">
              <label for="editMiddleName" class="form-label">Middle Name</label>
              <input type="text" class="form-control" id="editMiddleName" name="middle_name">
            </div>
            <div class="col-md-3">
              <label for="editLastName" class="form-label">Last Name</label>
              <input type="text" class="form-control" id="editLastName" name="last_name" required>
            </div>
            <div class="col-md-3">
              <label for="editSuffixName" class="form-label">Suffix</label>
              <input type="text" class="form-control" id="editSuffixName" name="suffix_name" placeholder="Jr., Sr., III">
            </div>
          </div>

          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editGender" class="form-label">Gender</label>
              <select class="form-select" id="editGender" name="gender" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="editZone" class="form-label">Zone</label>
              <input type="text" class="form-control" id="editZone" name="zone" required>
            </div>
          </div>

          <div class="row gy-3 mt-1">
            <div class="col-md-4">
              <label for="editContactNumber" class="form-label">Contact Number</label>
              <input type="text" class="form-control" id="editContactNumber" name="contact_number" inputmode="numeric" pattern="[0-9]{10,12}" required>
            </div>
            <div class="col-md-4">
              <label for="editEmail" class="form-label">Email</label>
              <input type="email" class="form-control" id="editEmail" name="email">
              <div class="form-text">Optional. Leave blank to remove.</div>
            </div>
            <div class="col-md-4">
              <label for="editUsername" class="form-label">Username</label>
              <input type="text" class="form-control" id="editUsername" name="username" readonly>
              <small class="form-text text-muted">Auto-generated from First and Last Name.</small>
            </div>
          </div>

          <?php if ($canEditPassword): ?>
          <!-- Password section visible ONLY to admin -->
          <div class="row gy-3 mt-1">
            <div class="col-md-4">
              <label for="editPassword" class="form-label">New Password</label>
              <div class="input-group">
                <input type="password" class="form-control" id="editPassword" name="new_password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('editPassword', this)" tabindex="-1" aria-label="Show/Hide password">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
              <small class="form-text text-muted">Minimum 8 characters. Leave blank to keep current password.</small>
            </div>
            <div class="col-md-4">
              <label for="editPasswordConfirm" class="form-label">Confirm Password</label>
              <div class="input-group">
                <!-- IMPORTANT: name attribute included so PHP receives it -->
                <input type="password" class="form-control" id="editPasswordConfirm" name="confirm_password" autocomplete="new-password" placeholder="Repeat new password">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('editPasswordConfirm', this)" tabindex="-1" aria-label="Show/Hide password">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
              <div class="invalid-feedback" id="cpwFeedback"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label d-block">Tools</label>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary" onclick="genPw()">Generate</button>
                <span id="pwStrength" class="align-self-center small text-muted"></span>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editCivilStatus" class="form-label">Civil Status</label>
              <select class="form-select" id="editCivilStatus" name="civilStatus" required>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Divorced">Divorced</option>
                <option value="Widowed">Widowed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label for="editBirthDate" class="form-label">Birth Date</label>
              <input type="date" class="form-control" id="editBirthDate" name="birth_date" required>
            </div>
          </div>

          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editBirthPlace" class="form-label">Birth Place</label>
              <input type="text" class="form-control" id="editBirthPlace" name="birth_place" required>
            </div>
            <div class="col-md-6">
              <label for="editResidencyStart" class="form-label">Residency Start</label>
              <input type="date" class="form-control" id="editResidencyStart" name="residency_start" required>
            </div>
          </div>

          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editStreetAddress" class="form-label">Street Address</label>
              <input type="text" class="form-control" id="editStreetAddress" name="street_address" required>
            </div>
            <div class="col-md-6">
              <label for="editCitizenship" class="form-label">Citizenship</label>
              <input type="text" class="form-control" id="editCitizenship" name="citizenship" required>
            </div>
          </div>

          <div class="row gy-3 mt-1">
            <div class="col-md-6">
              <label for="editReligion" class="form-label">Religion</label>
              <input type="text" class="form-control" id="editReligion" name="religion" required>
            </div>
            <div class="col-md-6">
              <label for="editOccupation" class="form-label">Occupation</label>
              <input type="text" class="form-control" id="editOccupation" name="occupation" required>
            </div>
          </div>

          <input type="hidden" name="zone_leader" id="editZoneLeader">
          <input type="hidden" name="province" id="editProvince">
          <input type="hidden" name="city_municipality" id="editCityMunicipality">
          <input type="hidden" name="barangay" id="editBarangay">

          <!-- Child section -->
          <div class="card-surface mb-4 mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Child (Optional)</h6>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="editAddFamilyMembers" onchange="toggleEditFamilySection()">
                <label class="form-check-label" for="editAddFamilyMembers">Add Child</label>
              </div>
            </div>
            <div class="card-body" id="editFamilyMembersSection" style="display:none;">
              <div class="mb-3 d-flex align-items-center">
                <button type="button" class="btn btn-success btn-sm" onclick="addEditFamilyMember()">
                  <i class="fas fa-plus"></i> Add Child
                </button>
                <small class="text-muted ms-2">You can add multiple children who will share the same address.</small>
              </div>
              <div id="editFamilyMembersContainer"></div>
            </div>
          </div>

          <div class="text-end">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-1"></i>Save Changes
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Populate Edit Modal
$('#editModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget);

  $('#editId').val(button.data('id'));
  $('#editFirstName').val(button.data('fname'));
  $('#editMiddleName').val(button.data('mname'));
  $('#editLastName').val(button.data('lname'));
  $('#editSuffixName').val(button.data('sname'));

  $('#editGender').val(button.data('gender'));
  $('#editContactNumber').val(button.data('contact'));
  $('#editEmail').val(button.data('email'));
  $('#editUsername').val(button.data('username'));
  updateEditUsername();

  $('#editCivilStatus').val(button.data('civilstatus'));
  $('#editBirthDate').val(button.data('birthdate'));
  $('#editResidencyStart').val(button.data('residencystart'));
  $('#editBirthPlace').val(button.data('birthplace'));
  $('#editZone').val(button.data('zone'));
  $('#editStreetAddress').val(button.data('streetaddress'));
  $('#editCitizenship').val(button.data('citizenship'));
  $('#editReligion').val(button.data('religion'));
  $('#editOccupation').val(button.data('occupation'));

  $('#editZoneLeader').val(button.data('zoneleader') || '');
  $('#editProvince').val(button.data('province') || '');
  $('#editCityMunicipality').val(button.data('city') || '');
  $('#editBarangay').val(button.data('barangay') || '');

  // Clear password fields each open (if present)
  const p = document.getElementById('editPassword');
  const c = document.getElementById('editPasswordConfirm');
  if (p) p.value = '';
  if (c) c.value = '';
});

function updateEditUsername() {
  const firstName = document.getElementById('editFirstName').value;
  const lastName  = document.getElementById('editLastName').value;
  const usernameInput = document.getElementById('editUsername');
  const first = (firstName || '').toLowerCase().replace(/\s+/g, '');
  const last  = (lastName  || '').toLowerCase().replace(/\s+/g, '');
  usernameInput.value = first + last;
}

// ===== Helpers & validators =====
function todayYMD(){ return new Date().toISOString().slice(0,10); }
function setInvalid(el,msg){
  el.classList.add('is-invalid');
  let fb=el.nextElementSibling;
  if(!fb || !fb.classList.contains('invalid-feedback')){
    fb=document.createElement('div'); fb.className='invalid-feedback';
    el.insertAdjacentElement('afterend',fb);
  }
  fb.textContent=msg; fb.style.display='block';
  el.setCustomValidity(msg);
}
function clearInvalid(el){
  el.classList.remove('is-invalid');
  const fb=el.nextElementSibling;
  if(fb?.classList.contains('invalid-feedback')){ fb.textContent=''; fb.style.display='none'; }
  el.setCustomValidity('');
}

function validateEditBirthDate(el){
  if(!el.value){ clearInvalid(el); return true; }
  if(el.value > todayYMD()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}
function validateEditResidencyStart(el){
  if(!el.value){ clearInvalid(el); return true; }
  if(el.value > todayYMD()){ setInvalid(el,'Residency start cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}

function togglePw(id, btn){
  const i = document.getElementById(id);
  if(!i) return;
  i.type = (i.type === 'password') ? 'text' : 'password';
  const icon = btn.querySelector('i');
  if(icon) icon.className = (i.type === 'password') ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
}
function genPw(){
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
  let s=''; for(let i=0;i<12;i++){ s += chars[Math.floor(Math.random()*chars.length)]; }
  const p = document.getElementById('editPassword');
  const c = document.getElementById('editPasswordConfirm');
  if(p){ p.value=s; p.dispatchEvent(new Event('input')); }
  if(c){ c.value=s; c.dispatchEvent(new Event('input')); }
}
function strengthLabel(pw){
  let score=0;
  if(pw.length>=8) score++;
  if(/[A-Z]/.test(pw)) score++;
  if(/[a-z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;         // ← fixed tiny typo
  if(/[^A-Za-z0-9]/.test(pw)) score++;
  if(score<=2) return 'Weak';
  if(score===3) return 'Fair';
  if(score===4) return 'Good';
  return 'Strong';
}
function validatePasswords(){
  const p = document.getElementById('editPassword');
  const c = document.getElementById('editPasswordConfirm');
  const meter = document.getElementById('pwStrength');

  if(!p || !c) return true; // non-admin => no fields

  const pv = (p.value||'').trim();
  const cv = (c.value||'').trim();

  if(pv==='' && cv===''){ clearInvalid(p); clearInvalid(c); if(meter) meter.textContent=''; return true; }
  if(pv!=='' && cv===''){ setInvalid(c,'Please confirm the new password.'); return false; }
  if(pv==='' && cv!==''){ setInvalid(p,'Please enter the new password.'); return false; }
  if(pv.length < 8){ setInvalid(p,'Password must be at least 8 characters.'); return false; } else { clearInvalid(p); }
  if(pv !== cv){ setInvalid(c,'Passwords do not match.'); return false; } else { clearInvalid(c); }
  if(!(/[A-Z]/.test(pv) && /[a-z]/.test(pv) && /[0-9]/.test(pv))){
    setInvalid(p,'Use upper, lower, and a number for a stronger password.');
    return false;
  }
  if(meter){ meter.textContent = 'Strength: ' + strengthLabel(pv); }
  return true;
}

document.addEventListener('DOMContentLoaded', ()=>{
  const bd=document.getElementById('editBirthDate');
  const rs=document.getElementById('editResidencyStart');
  if(bd){
    bd.setAttribute('max', todayYMD());
    ['input','change','blur'].forEach(ev=>bd.addEventListener(ev,()=>validateEditBirthDate(bd)));
  }
  if(rs){
    rs.setAttribute('max', todayYMD());
    ['input','change','blur'].forEach(ev=>rs.addEventListener(ev,()=>validateEditResidencyStart(rs)));
  }

  const p = document.getElementById('editPassword');
  const c = document.getElementById('editPasswordConfirm');
  if(p){ ['input','blur','change'].forEach(e=>p.addEventListener(e, validatePasswords)); }
  if(c){ ['input','blur','change'].forEach(e=>c.addEventListener(e, validatePasswords)); }

  const form=document.getElementById('editForm');
  if(form){
    form.addEventListener('submit',(e)=>{
      let ok=true;
      if(bd) ok=validateEditBirthDate(bd) && ok;
      if(rs) ok=validateEditResidencyStart(rs) && ok;
      if(!validatePasswords()) ok=false;

      if(!ok || !form.checkValidity()){
        e.preventDefault();
        (form.querySelector(':invalid')||form.querySelector('.is-invalid'))?.focus();
      }
    });
  }
});

$('#editModal').on('shown.bs.modal', function(){
  const bd=document.getElementById('editBirthDate');
  const rs=document.getElementById('editResidencyStart');
  if(bd) validateEditBirthDate(bd);
  if(rs) validateEditResidencyStart(rs);
  updateEditUsername();
});
</script>
