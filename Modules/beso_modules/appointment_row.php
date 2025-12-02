<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
include 'class/session_timeout.php';

if (!function_exists('calculate_age')) {
    function calculate_age($birth_date) {
        if (!$birth_date) return '';
        $birth = new DateTime($birth_date);
        $today = new DateTime();
        return $birth->diff($today)->y;
    }
}

/* ---- Normalize status/certificate ---- */
$rawStatus   = (string)($row['status'] ?? '');
$normStatus  = strtolower(preg_replace('/\s+/', '', $rawStatus)); // e.g. "Approved by Captain" -> "approvedcaptain"
$certificate = strtolower((string)($row['certificate'] ?? ''));
$role        = $_SESSION['Role_Name'] ?? '';

/* ✅ Allow print only if status is Approved by Captain */
$canPrint = ($normStatus === 'approvedcaptain');

/* ---- Gather fields for printing ---- */
$residentId        = (string)((int)($row['res_id'] ?? 0));
$signatoryEmpId    = (string)((int)($row['signatory_employee_id'] ?? 0));
$assignedKagName   = (string)($row['assigned_kag_name'] ?? $row['assignedKagName'] ?? '');
$assignedWitnessName = (string)($row['assigned_witness_name'] ?? '');

/* ──────────────────────────────────────────────────────────────
    NEW: Resolve seriesNum for BESO print (format YY-NNN)
    - beso has no res_id, so we match on resident’s full name.
    - We fetch resident name by id, then pull latest BESO.seriesNum.
    ────────────────────────────────────────────────────────────── */
$seriesNum = '';
try {
    if ($residentId && $certificate === 'beso application') {
        // We expect $mysqli to be available from the parent include
        if (!isset($mysqli)) {
            require_once __DIR__ . '/../include/connection.php';
            $mysqli = db_connection();
        }

        if ($stmtN = $mysqli->prepare("SELECT first_name, middle_name, last_name, IFNULL(suffix_name,'') 
                                        FROM residents WHERE id = ? LIMIT 1")) {
            $ridInt = (int)$residentId;
            $stmtN->bind_param('i', $ridInt);
            if ($stmtN->execute()) {
                $stmtN->bind_result($fn, $mn, $ln, $sn);
                if ($stmtN->fetch()) {
                    $fn = $fn ?? ''; $mn = $mn ?? ''; $ln = $ln ?? ''; $sn = $sn ?? '';
                }
            }
            $stmtN->close();
        }

        if (!empty($fn) && !empty($ln)) {
            if ($stmtB = $mysqli->prepare("
                SELECT seriesNum
                FROM beso
                WHERE firstName = ? AND middleName = ? AND lastName = ? AND IFNULL(suffixName,'') = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ")) {
                $stmtB->bind_param('ssss', $fn, $mn, $ln, $sn);
                if ($stmtB->execute()) {
                    $stmtB->bind_result($ser);
                    if ($stmtB->fetch()) $seriesNum = (string)$ser;
                }
                $stmtB->close();
            }
        }
    }
} catch (Throwable $e) {
    // fail-soft: just skip seriesNum if anything goes wrong
    error_log('[appointment_row seriesNum] ' . $e->getMessage());
}

/* ---- Build safe onclick (order MUST match printAppointment signature) ----
    printAppointment(
      certificate, fullname, res_zone, birth_date, birth_place, res_street_address,
      purpose, issued_on, issued_at, cedula_number, civil_status,
      residency_start, age, residentId,
      assignedKagName,
      signatoryEmployeeId,
      seriesNum,
      assignedWitnessName  <-- NEW FINAL ARG
    )
-------------------------------------------------------------------------- */
$onclick = '';
if ($canPrint) {
    $onclick =
        "printAppointment('" .
        addslashes($row['certificate'] ?? '') . "','" .
        addslashes($row['fullname'] ?? '') . "','" .
        addslashes($row['res_zone'] ?? '') . "','" .
        addslashes($row['birth_date'] ?? '') . "','" .
        addslashes($row['birth_place'] ?? '') . "','" .
        addslashes($row['res_street_address'] ?? '') . "','" .
        addslashes($row['purpose'] ?? '') . "','" .
        addslashes($row['issued_on'] ?? '') . "','" .
        addslashes($row['issued_at'] ?? '') . "','" .
        addslashes($row['cedula_number'] ?? '') . "','" .
        addslashes($row['civil_status'] ?? '') . "','" .
        addslashes($row['residency_start'] ?? '') . "','" .
        (isset($row['birth_date']) ? calculate_age($row['birth_date']) : '') . "','" .
        addslashes($residentId) . "','" .
        addslashes($assignedKagName) . "','" .
        addslashes($signatoryEmpId) . "','" .
        addslashes($seriesNum) . "','" .
        addslashes($assignedWitnessName) .
        "')";
}

/* ---- Display status text ---- */
$displayStatus = $row['status'] ?? '';
if (strcasecmp($displayStatus, 'Released') === 0) {
    $displayStatus = 'Released / Paid';
}

/* ---- Badge class map ---- */
$badgeClass = match (strtolower(preg_replace('/\s+/', '', $row['status'] ?? ''))) {
    'approved'        => 'bg-success',
    'rejected'        => 'bg-danger',
    'released'        => 'bg-primary',
    'approvedcaptain' => 'bg-warning',
    default           => 'bg-warning text-dark',
};
?>
<tr class="align-middle">
  <td class="fw-medium">
    <?= htmlspecialchars($row['fullname'] ?? '') ?>
    <?php if (!empty($assignedKagName)): ?>
      <div class="small text-muted">Assigned Kagawad: <br> <?= htmlspecialchars($assignedKagName) ?></div>
    <?php endif; ?>
  </td>
  <td><?= htmlspecialchars($row['certificate'] ?? '') ?></td>
  <td><span class="text-uppercase fw-semibold"><?= htmlspecialchars($row['tracking_number'] ?? '') ?></span></td>
  <td><?= htmlspecialchars($row['selected_date'] ?? '') ?></td>
  <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row['selected_time'] ?? '') ?></span></td>
  <td>
    <span class="badge px-3 py-2 fw-semibold <?= $badgeClass ?>">
      <?= htmlspecialchars($displayStatus) ?>
    </span>
  </td>
  <td class="d-flex gap-2 justify-content-end">
    <button type="button" class="btn btn-sm btn-info text-white"
      data-bs-toggle="modal"
      data-bs-target="#viewModal"
      data-fullname="<?= htmlspecialchars($row['fullname'] ?? '') ?>"
      data-certificate="<?= htmlspecialchars($row['certificate'] ?? '') ?>"
      data-tracking-number="<?= htmlspecialchars($row['tracking_number'] ?? '') ?>"
      data-selected-date="<?= htmlspecialchars($row['selected_date'] ?? '') ?>"
      data-selected-time="<?= htmlspecialchars($row['selected_time'] ?? '') ?>"
      data-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
      data-assigned-kag-name="<?= htmlspecialchars($assignedKagName) ?>"
      data-assigned-witness-name="<?= htmlspecialchars($assignedWitnessName) ?>"
      title="View Details">
      <i class="bi bi-eye-fill"></i>
    </button>

    <button 
      type="button"
      class="btn btn-sm <?= $canPrint ? 'btn-secondary' : 'btn-light text-muted' ?>" 
      <?= $canPrint ? '' : 'disabled' ?>
      onclick="<?= $canPrint ? $onclick : '' ?>"
      title="<?= $canPrint ? 'Print' : 'Print not available' ?>">
      <i class="bi bi-printer<?= $canPrint ? '-fill' : '' ?>"></i>
    </button>
  </td>
</tr>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>