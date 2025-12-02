<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

$formAction = 'index_Admin.php?page=' . urlencode(encrypt('resident_info')); // default
include 'class/session_timeout.php';

if (isset($_SESSION['Role_Name'])) {
    $role = strtolower($_SESSION['Role_Name']);
    if ($role === 'barangay secretary') {
        $formAction = 'index_barangay_secretary.php?page=' . urlencode(encrypt('resident_info'));
    } elseif ($role === 'encoder') {
        $formAction = 'index_barangay_staff.php?page=' . urlencode(encrypt('resident_info'));
    } elseif ($role === 'admin') {
        $formAction = 'index_Admin.php?page=' . urlencode(encrypt('resident_info'));
    }
}
?>

<div class="modal fade" id="addResidentModal" tabindex="-1" aria-labelledby="addResidentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"> <h5 class="modal-title" id="addResidentModalLabel">
          <i class="fas fa-user-plus me-2"></i>Add New Resident
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form action="<?php echo $formAction; ?>" method="POST" id="addResidentForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

          <div class="card-surface mb-4">
            <div class="card-header">
              <h6 class="mb-0"><i class="fas fa-user me-2"></i>Primary Resident Information</h6>
            </div>
            <div class="card-body">

              <div class="row gy-3">
                <div class="col-md-3">
                  <label class="form-label">First Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control primary-first-name" id="primary_firstName" name="firstName" required autocomplete="given-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Middle Name</label>
                  <input type="text" class="form-control primary-middle-name" id="primary_middleName" name="middleName" autocomplete="additional-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Last Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control primary-last-name" id="primary_lastName" name="lastName" required autocomplete="family-name">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Suffix</label>
                  <input type="text" class="form-control primary-suffix-name" id="primary_suffixName" name="suffixName" placeholder="Jr., Sr., III">
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Birth Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="birthDate" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Residency Start Date <span class="text-danger"></span></label>
                  <input type="date" class="form-control" name="residency_start" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Birth Place <span class="text-danger"></span></label>
                  <input type="text" class="form-control" name="birthPlace" required>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Gender <span class="text-danger"></span></label>
                  <select class="form-select" name="gender" required>
                    <option value="" disabled selected>Select Gender</option>
                    <option>Male</option>
                    <option>Female</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="contactNumber" inputmode="numeric" pattern="[0-9]{10,12}" placeholder="09XXXXXXXXX" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Civil Status <span class="text-danger"></span></label>
                  <select class="form-select" name="civilStatus" required>
                    <option value="" disabled selected>Select Status</option>
                    <option>Single</option>
                    <option>Married</option>
                    <option>Divorced</option>
                    <option>Widowed</option>
                  </select>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Province <span class="text-danger">*</span></label>
                  <select class="form-control" id="province" name="province" required disabled>
                    <option value="57" selected>MISAMIS ORIENTAL</option>
                  </select>
                  <input type="hidden" name="province" value="57" />
                </div>
                <div class="col-md-4">
                  <label class="form-label">City/Municipality <span class="text-danger">*</span></label>
                  <select class="form-control" id="city_municipality" name="city_municipality" required disabled>
                    <option value="1229" selected>CAGAYAN DE ORO CITY</option>
                  </select>
                  <input type="hidden" name="city_municipality" value="1229" />
                </div>
                <div class="col-md-4">
                  <label class="form-label">Barangay <span class="text-danger">*</span></label>
                  <select class="form-control" id="barangay" name="barangay" required disabled>
                    <option value="32600" selected>BUGO</option>
                  </select>
                  <input type="hidden" name="barangay" value="32600" />
                </div>
              </div>
              <div class="row gy-3 mt-1">
                <div class="col-md-3">
                  <label class="form-label">Zone <span class="text-danger">*</span></label>
                  <select class="form-control" name="res_zone" required>
                    <option value="">Select Zone</option>
                    <?php foreach ($zones as $zone): ?>
                      <option value="<?= $zone['Zone_Name'] ?>"><?= htmlspecialchars($zone['Zone_Name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Street Address (Phase, Street, Blk, Lot) <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="res_street_address" required>
                </div>
                <div class="col-md-5">
                  <label class="form-label">Zone Leader</label>
                  <input type="text" class="form-control mb-1" id="zone_leader" placeholder="Auto-filled by Zone" readonly>
                  <input type="hidden" class="form-control" name="zone_leader" id="zone_leader_id">
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4" id="emailWrapper">
                  <label class="form-label">Email Address (Optional)</label>
                  <input type="email" class="form-control" id="primary_email" name="email" placeholder="Enter email if available" autocomplete="email" aria-describedby="emailFeedback">
                  <small id="emailFeedback" class="form-text text-muted">If provided, this is where they receive credentials.</small>
                </div>
                <div class="col-md-4" id="usernameWrapper">
                  <label class="form-label">Username</label>
                  <input type="text" class="form-control" id="primary_username" name="username" readonly>
                  <small class="form-text text-muted">Auto-generated from First and Last Name.</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Citizenship <span class="text-danger"></span></label>
                  <input type="text" class="form-control" name="citizenship" value="Filipino" required>
                </div>
              </div>

              <div class="row gy-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label">Religion <span class="text-danger"></span></label>
                  <input type="text" class="form-control" name="religion" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Occupation <span class="text-danger"></span></label>
                  <input type="text" class="form-control" name="occupation" required>
                </div>
              </div>

            </div>
          </div>

          <div class="card-surface mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="fas fa-users me-2"></i>Add Child (Optional)</h6>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="addFamilyMembers" onchange="toggleFamilySection()">
                <label class="form-check-label" for="addFamilyMembers">Add Child</label>
              </div>
            </div>

            <div class="card-body" id="familyMembersSection" style="display: none;">
              <div class="mb-3 d-flex align-items-center">
                <button type="button" class="btn btn-success btn-sm" onclick="addFamilyMember()">
                  <i class="fas fa-plus"></i> Add Child
                </button>
                <small class="text-muted ms-2">Children will share the same address as the primary resident.</small>
              </div>

              <div id="familyMembersContainer"></div>
            </div>
          </div>

          <div class="text-center mt-3">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="fas fa-save me-1"></i> Submit Resident & Child
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>
// SLUGIFY HELPER FUNCTION
function slugify(text) {
  return text.toString().toLowerCase()
    .replace(/\s+/g, '')     // Remove spaces
    .replace(/[^\w-]+/g, '') // Remove non-word chars
    .replace(/--+/g, '-')    // Replace multiple - with single -
    .replace(/^-+/, '')      // Trim - from start of text
    .replace(/-+$/, '');     // Trim - from end of text
}

// === NEW: SETUP USERNAME GENERATOR FOR CHILD ===
function setupChildUsernameGenerator(container) {
  const firstNameInput = container.querySelector(".child-first-name");
  const lastNameInput = container.querySelector(".child-last-name");
  const usernameInput = container.querySelector(".family-username"); // Target the username input in this child block

  if (!firstNameInput || !lastNameInput || !usernameInput) return;

  function updateChildUsername() {
    const first = slugify(firstNameInput.value || '');
    const last = slugify(lastNameInput.value || '');
    usernameInput.value = first + last;
  }

  firstNameInput.addEventListener('input', updateChildUsername);
  lastNameInput.addEventListener('input', updateChildUsername);
}


document.addEventListener("DOMContentLoaded", function() {
  
  // === PRIMARY RESIDENT USERNAME GENERATOR ===
  const addModal = document.getElementById('addResidentModal');
  if (addModal) {
    const firstNameInput = addModal.querySelector('#primary_firstName');
    const lastNameInput = addModal.querySelector('#primary_lastName');
    const usernameInput = addModal.querySelector('#primary_username');

    function updatePrimaryUsername() {
      const first = slugify(firstNameInput.value || '');
      const last = slugify(lastNameInput.value || '');
      usernameInput.value = first + last;
    }
    
    if (firstNameInput && lastNameInput && usernameInput) {
        firstNameInput.addEventListener('input', updatePrimaryUsername);
        lastNameInput.addEventListener('input', updatePrimaryUsername);
    }
  }

  // === CHILD USERNAME GENERATOR (SETUP) ===
  // This logic is now handled in addFamilyMember() and setupChildUsernameGenerator()
  // We just need to modify the 'addFamilyMember' function to call our new setup function.

  // Find the original 'addFamilyMember' function in the global scope and override it
  // This is a bit of a hack, but it's the simplest way without editing resident_info.php's <script>
  if (typeof addFamilyMember === 'function') {
    const oldAddFamilyMember = addFamilyMember; // Save original function

    // Redefine it
    addFamilyMember = function() {
      oldAddFamilyMember(); // Call the original function first to create the HTML

      // Now, find the *newest* family member block added
      const container = document.getElementById('familyMembersContainer');
      const newBlock = container.lastElementChild; // Get the block we just added
      if (newBlock) {
        setupChildUsernameGenerator(newBlock); // Setup the username generator for it
      }
    }
  }

});
</script>