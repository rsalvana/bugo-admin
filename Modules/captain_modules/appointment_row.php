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

$displayStatus = $row['status'] ?? '';
$badgeClass = match (strtolower(preg_replace('/\s+/', '', $displayStatus))) {
    'approved'         => 'bg-success',
    'rejected'         => 'bg-danger',
    'released'         => 'bg-primary',
    'approvedcaptain'  => 'bg-warning',
    default            => 'bg-warning text-dark',
};

// Support either camelCase or snake_case column aliases from the UNION
$assignedKagId        = $row['assignedKagId']        ?? $row['assigned_kag_id']        ?? '';
$assignedKagName      = $row['assignedKagName']      ?? $row['assigned_kag_name']      ?? '';
$assignedWitnessId    = $row['assignedWitnessId']    ?? $row['assigned_witness_id']    ?? '';
$assignedWitnessName  = $row['assignedWitnessName']  ?? $row['assigned_witness_name']  ?? '';

$cedulaNumber = $row['cedula_number'] ?? '';
?>
<tr class="align-middle">
  <td class="fw-medium"><?= htmlspecialchars($row['fullname'] ?? '') ?></td>
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
    <!-- View (now carries assigned Kagawad & Witness, ID and Name) -->
    <button type="button" class="btn btn-sm btn-info text-white"
      data-bs-toggle="modal"
      data-bs-target="#viewModal"
      data-fullname="<?= htmlspecialchars($row['fullname'] ?? '') ?>"
      data-certificate="<?= htmlspecialchars($row['certificate'] ?? '') ?>"
      data-tracking-number="<?= htmlspecialchars($row['tracking_number'] ?? '') ?>"
      data-selected-date="<?= htmlspecialchars($row['selected_date'] ?? '') ?>"
      data-selected-time="<?= htmlspecialchars($row['selected_time'] ?? '') ?>"
      data-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
      data-res-id="<?= htmlspecialchars($row['res_id'] ?? '') ?>"
      data-assigned-kag-id="<?= htmlspecialchars((string)$assignedKagId) ?>"
      data-assigned-kag-name="<?= htmlspecialchars($assignedKagName) ?>"
      data-assigned-witness-id="<?= htmlspecialchars((string)$assignedWitnessId) ?>"
      data-assigned-witness-name="<?= htmlspecialchars($assignedWitnessName) ?>"
      title="View Details">
      <i class="bi bi-eye-fill"></i>
    </button>

    <!-- Change Status (NO kagawad passing here) -->
    <!-- <button type="button" class="btn btn-sm btn-primary"
      data-bs-toggle="modal"
      data-bs-target="#statusModal"
      data-tracking-number="<?= htmlspecialchars($row['tracking_number'] ?? '') ?>"
      data-certificate="<?= htmlspecialchars($row['certificate'] ?? '') ?>"
      data-current-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
      data-cedula-number="<?= htmlspecialchars($cedulaNumber) ?>"
      title="Change Status">
      <i class="bi bi-check2-square"></i>
    </button> -->
  </td>
</tr>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
