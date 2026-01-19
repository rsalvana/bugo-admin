<?php
/**
 * components/appointment_row.php
 * Renders a single <tr> for an appointment in the list, with robust print support.
 */

if (!defined('APP_ROW_INCLUDED')) {
    define('APP_ROW_INCLUDED', true);
}

if (!function_exists('calculate_age')) {
    function calculate_age($birth_date) {
        if (!$birth_date) return '';
        try {
            $birth = new DateTime($birth_date);
            $today = new DateTime();
            return $birth->diff($today)->y;
        } catch (Throwable $e) {
            return '';
        }
    }
}

/* ---------- Cedula fields fallback for printing ---------- */
$printCedNo = trim((string)($row['cedula_number'] ?? ''));
$printIssOn = trim((string)($row['issued_on'] ?? ''));
$printIssAt = trim((string)($row['issued_at'] ?? ''));
if ($printIssOn === '0000-00-00') $printIssOn = '';

$rid = (int)($row['res_id'] ?? 0);

if ($rid && ($printCedNo === '' || $printIssOn === '' || $printIssAt === '')) {
    // 1) URGENT CEDULA
    $q = $mysqli->prepare("
        SELECT cedula_number, issued_on, issued_at
        FROM urgent_cedula_request
        WHERE res_id = ?
          AND cedula_status = 'Released'
          AND cedula_delete_status = 0
        ORDER BY COALESCE(NULLIF(issued_on,'0000-00-00'), appointment_date) DESC, urg_ced_id DESC
        LIMIT 1
    ");
    if ($q) {
        $q->bind_param('i', $rid);
        if ($q->execute()) {
            $q->bind_result($uc_no, $uc_on, $uc_at);
            if ($q->fetch()) {
                if ($printCedNo === '' && $uc_no) $printCedNo = $uc_no;
                if ($printIssOn === '' && $uc_on && $uc_on !== '0000-00-00') $printIssOn = $uc_on;
                if ($printIssAt === '' && $uc_at) $printIssAt = $uc_at;
            }
        }
        $q->close();
    }
}

if ($rid && ($printCedNo === '' || $printIssOn === '' || $printIssAt === '')) {
    // 2) REGULAR CEDULA
    $q2 = $mysqli->prepare("
        SELECT cedula_number, issued_on, issued_at
        FROM cedula
        WHERE res_id = ?
          AND cedula_status = 'Released'
          AND cedula_delete_status = 0
        ORDER BY COALESCE(NULLIF(issued_on,'0000-00-00'), appointment_date) DESC
        LIMIT 1
    ");
    if ($q2) {
        $q2->bind_param('i', $rid);
        if ($q2->execute()) {
            $q2->bind_result($c_no, $c_on, $c_at);
            if ($q2->fetch()) {
                if ($printCedNo === '' && $c_no) $printCedNo = $c_no;
                if ($printIssOn === '' && $c_on && $c_on !== '0000-00-00') $printIssOn = $c_on;
                if ($printIssAt === '' && $c_at) $printIssAt = $c_at;
            }
        }
        $q2->close();
    }
}

/* ---------- Status/Certificate helpers ---------- */
$statusRaw  = (string)($row['status'] ?? '');
$certRaw    = (string)($row['certificate'] ?? '');
$statusNorm = strtolower(trim($statusRaw));
$certNorm   = strtolower(trim($certRaw));

/* ---------- Base rule: only ApprovedCaptain and not Cedula ---------- */
$canPrint = ($statusNorm === 'approvedcaptain');
$blockTitle = $canPrint ? 'Print' : 'Print not available';

/* ---------- EXTRA RULE: block printing if same-day Cedula not Released ---------- */
$selectedDate = (string)($row['selected_date'] ?? '');
if ($canPrint && $certNorm !== 'cedula' && $rid > 0 && $selectedDate !== '') {
    $stmtBlock = $mysqli->prepare("
        SELECT (
            (SELECT COUNT(*) FROM cedula
             WHERE res_id = ? AND appointment_date = ? AND COALESCE(cedula_status,'') <> 'Released')
          + (SELECT COUNT(*) FROM urgent_cedula_request
             WHERE res_id = ? AND appointment_date = ? AND COALESCE(cedula_status,'') <> 'Released')
        ) AS pending_cnt
    ");
    if ($stmtBlock) {
        $stmtBlock->bind_param('isis', $rid, $selectedDate, $rid, $selectedDate);
        if ($stmtBlock->execute()) {
            $pending = (int)($stmtBlock->get_result()->fetch_assoc()['pending_cnt'] ?? 0);
            if ($pending > 0) {
                $canPrint  = false;
                $blockTitle = 'Print blocked: release Cedula first for this date';
            }
        }
        $stmtBlock->close();
    }
}

/* ---------- Status label ---------- */
$displayStatus = $statusRaw;
if (strcasecmp($displayStatus, 'Released') === 0) $displayStatus = 'Released / Paid';

/* ---------- Soft badge class ---------- */
$badgeClass = match (strtolower(preg_replace('/\s+/', '', $statusRaw))) {
    'pending'         => 'badge-soft-warning',
    'approved'        => 'badge-soft-info',
    'approvedcaptain' => 'badge-soft-primary',
    'rejected'        => 'badge-soft-danger',
    'released'        => 'badge-soft-success',
    default           => 'badge-soft-secondary',
};

/* ---------- signatory (from schedules/urgent_request.employee_id) ---------- */
$signatoryEmployeeId = (int)($row['employee_id'] ?? 0);
$signatoryName = '';
$signatoryPosition = '';
if ($signatoryEmployeeId > 0) {
    static $EMP_CACHE = [];
    if (!isset($EMP_CACHE[$signatoryEmployeeId])) {
        $stmt = $mysqli->prepare("
            SELECT
              CONCAT_WS(' ', e.employee_fname, e.employee_mname, e.employee_lname, e.employee_sname) AS emp_name,
              COALESCE(r.Role_Name, 'Staff') AS emp_role
            FROM employee_list e
            LEFT JOIN employee_roles r ON r.Role_Id = e.Role_id
            WHERE e.id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $signatoryEmployeeId);
            if ($stmt->execute()) {
                $stmt->bind_result($empName, $empRole);
                if ($stmt->fetch()) {
                    $EMP_CACHE[$signatoryEmployeeId] = [
                        'name' => trim($empName ?: ''),
                        'role' => trim($empRole ?: 'Staff'),
                    ];
                }
            }
            $stmt->close();
        }
    }
    if (isset($EMP_CACHE[$signatoryEmployeeId])) {
        $signatoryName     = $EMP_CACHE[$signatoryEmployeeId]['name'];
        $signatoryPosition = $EMP_CACHE[$signatoryEmployeeId]['role'];
    }
}

/* ---------- NEW: seriesNum lookup (Sched/Urgent by tracking; BESO fallback) ---------- */
$seriesNum = (string)($row['seriesNum'] ?? '');
if ($seriesNum === '') {
    $trk = (string)($row['tracking_number'] ?? '');
    if ($trk !== '') {
        if ($st = $mysqli->prepare("SELECT seriesNum FROM urgent_request WHERE tracking_number = ? LIMIT 1")) {
            $st->bind_param('s', $trk);
            if ($st->execute()) { $st->bind_result($sn); if ($st->fetch()) $seriesNum = (string)($sn ?? ''); }
            $st->close();
        }
        if ($seriesNum === '' && ($st = $mysqli->prepare("SELECT seriesNum FROM schedules WHERE tracking_number = ? LIMIT 1"))) {
            $st->bind_param('s', $trk);
            if ($st->execute()) { $st->bind_result($sn); if ($st->fetch()) $seriesNum = (string)($sn ?? ''); }
            $st->close();
        }
    }
}

/* ---------- Bundle data-* for the print button ---------- */
$btnData = [
    'allowed'                 => $canPrint ? '1' : '0',
    'certificate'             => (string)($row['certificate'] ?? ''),
    'fullname'                => (string)($row['fullname'] ?? ''),
    'res_zone'                => (string)($row['res_zone'] ?? ''),
    'birth_date'              => (string)($row['birth_date'] ?? ''),
    'birth_place'             => (string)($row['birth_place'] ?? ''),
    'res_street'              => (string)($row['res_street_address'] ?? ''),
    'purpose'                 => (string)($row['purpose'] ?? ''),
    'issued_on'               => (string)($printIssOn),
    'issued_at'               => (string)($printIssAt),
    'cedula_no'               => (string)($printCedNo),
    'civil_status'            => (string)($row['civil_status'] ?? ''),
    'residency_start'         => (string)($row['residency_start'] ?? ''),
    'age'                     => (string)(isset($row['birth_date']) ? calculate_age($row['birth_date']) : ''),
    'res_id'                  => (string)((int)($row['res_id'] ?? 0)),
    'tracking'                => (string)($row['tracking_number'] ?? ''),
    'assigned_kag_name'       => (string)($row['assigned_kag_name'] ?? $row['assignedKagName'] ?? ''),
    'sig_emp_id'              => (string)((int)($row['signatory_employee_id'] ?? 0)),
    'sig_name'                => (string)($row['signatory_name'] ?? ''),
    'sig_pos'                 => (string)($row['signatory_position'] ?? ''),
    'series_num'              => (string)$seriesNum,
    'assigned_witness_name'   => (string)($row['assigned_witness_name'] ?? ''),
    'oneness_fullname'        => (string)($row['oneness_fullname'] ?? ''),
];
?>

<tr class="align-middle">
  <td data-label="Full Name" class="fw-medium">
    <?= htmlspecialchars($row['fullname'] ?? '') ?>
  </td>
  <td data-label="Certificate">
    <?= htmlspecialchars($row['certificate'] ?? '') ?>
  </td>
  <td data-label="Tracking Number">
    <span class="text-uppercase fw-semibold">
      <?= htmlspecialchars($row['tracking_number'] ?? '') ?>
    </span>
  </td>
  <td data-label="Date">
    <?= htmlspecialchars($row['selected_date'] ?? '') ?>
  </td>
  <td data-label="Time Slot">
    <span class="badge bg-info text-dark">
      <?= htmlspecialchars($row['selected_time'] ?? '') ?>
    </span>
  </td>
  <td data-label="Status">
    <span class="badge px-3 py-2 fw-semibold <?= $badgeClass ?>">
      <?= htmlspecialchars($displayStatus) ?>
    </span>
  </td>

  <td data-label="Actions" class="text-end d-flex gap-2 justify-content-end">

    <button
      type="button"
      class="btn btn-sm btn-info text-white"
      data-bs-toggle="modal"
      data-bs-target="#viewModal"
      data-fullname="<?= htmlspecialchars($row['fullname'] ?? '') ?>"
      data-certificate="<?= htmlspecialchars($row['certificate'] ?? '') ?>"
      data-tracking-number="<?= htmlspecialchars($row['tracking_number'] ?? '') ?>"
      data-res-id="<?= (int)($row['res_id'] ?? 0) ?>"
      data-selected-date="<?= htmlspecialchars($row['selected_date'] ?? '') ?>"
      data-selected-time="<?= htmlspecialchars($row['selected_time'] ?? '') ?>"
      data-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
      data-cedula-income="<?= htmlspecialchars($row['cedula_income'] ?? '') ?>"
      title="View Details"
      onclick="logAppointmentView(<?= (int)($row['res_id'] ?? 0) ?>)"
    >
      <i class="bi bi-eye-fill"></i>
    </button>

    <button
      type="button"
      class="btn btn-sm <?= $canPrint ? 'btn-secondary js-print' : 'btn-light text-muted' ?>"
      title="<?= htmlspecialchars($blockTitle) ?>"
      <?= $canPrint ? '' : 'disabled aria-disabled="true" tabindex="-1"' ?>
      <?php foreach ($btnData as $k => $v): ?>
        data-<?= htmlspecialchars($k) ?>="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>"
      <?php endforeach; ?>
    >
      <i class="bi bi-printer<?= $canPrint ? '-fill' : '' ?>"></i>
    </button>

  </td>
</tr>

<?php
if (!defined('PRINT_ROW_JS_EMITTED')): define('PRINT_ROW_JS_EMITTED', true); ?>
<script>
(function () {
  if (window.__APPT_PRINT_BOUND__) return;
  window.__APPT_PRINT_BOUND__ = true;

  function logPrint(resId, trackingNumber) {
    try {
      const body = new URLSearchParams({
        action: 'print',
        filename: String(3),
        viewedID: String(resId || 0),
        tracking_number: trackingNumber || ''
      });
      fetch('./logs/logs_trig.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body
      }).catch(()=>{});
    } catch (e) {}
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-print');
    if (!btn) return;

    if (btn.dataset.allowed !== '1') {
      e.preventDefault();
      if (btn.title) alert(btn.title);
      return;
    }

    const d = btn.dataset;

    // ✅ INSERTED HERE

    const resId  = Number(d.res_id || 0);

    if (resId && d.tracking) logPrint(resId, d.tracking);

    try {
      printAppointment(
        d.certificate || '',
        d.fullname || '',
        d.res_zone || '',
        d.birth_date || '',
        d.birth_place || '',
        d.res_street || '',
        d.purpose || '',
        d.issued_on || '',
        d.issued_at || '',
        d.cedula_no || '',
        d.civil_status || '',
        d.residency_start || '',
        d.age || '',
        resId,
        d.assigned_kag_name || '',
        d.sig_emp_id || 0,
        d.series_num || '',
        d.assigned_witness_name || '',
        d.oneness_fullname || ''
      );
    } catch (err) {
      console.error('Print click failed:', err);
    }
  }, { passive: true });
})();
</script>
<?php endif; ?>
