<?php
/* --------------------------------------------------------------------------
   1. DIRECT ACCESS CHECK
   (Keep this to prevent direct access to the API file)
-------------------------------------------------------------------------- */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';

/* --------------------------------------------------------------------------
   2. EXPORT HANDLER (MUST BE HERE)
   - Checks if 'export_errors' is in the URL.
   - Cleans the output buffer to remove Admin Sidebar/Header.
   - Force downloads the CSV.
-------------------------------------------------------------------------- */
if (isset($_GET['export_errors']) && $_GET['export_errors'] == '1') {
    if (!empty($_SESSION['beso_import_errors'])) {
        // Wipe any HTML (Sidebar, Navbar, etc.) that index_Admin.php already loaded
        while (ob_get_level()) { ob_end_clean(); }

        // Send Download Headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="skipped_rows_report.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");
        
        // Dump the data
        foreach ($_SESSION['beso_import_errors'] as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        
        // Clear errors
        unset($_SESSION['beso_import_errors']);
        
        // STOP EXECUTION so no more HTML is added
        exit(); 
    }
}

/**
 * NOTE: This fetch file now:
 * - de-duplicates by GROUP BY b.id
 * - exposes: contactNum_resolved, age_resolved
 * (BESO values preferred, fall back to residents)
 */
require_once 'components/beso/beso_fetch.php';
require_once 'components/beso/edit_modal.php';

/* ---------------------------------------------
   CSRF helper
---------------------------------------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_ok($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/* ---------------------------------------------
   Upload directory (create if missing)
---------------------------------------------- */
$UPLOAD_DIR = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$BESO_DIR   = $UPLOAD_DIR . '/beso_imports';
if (!is_dir($BESO_DIR)) { @mkdir($BESO_DIR, 0700, true); }
@chmod($BESO_DIR, 0700);

/* ---------------------------------------------
   Flash helper
---------------------------------------------- */
function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
$flashHtml = '';
if (!empty($_SESSION['flash'])) {
    $color = $_SESSION['flash']['type'] === 'success' ? 'success' : 'danger';
    $flashHtml = '<div class="alert alert-' . $color . ' alert-dismissible fade show" role="alert">'
               . htmlspecialchars($_SESSION['flash']['msg'])
               . '<button type="button" class="btn-close" data-bs-alert="Close" aria-label="Close"></button>'
               . '</div>';
    unset($_SESSION['flash']);
}

/* ---------------------------------------------
   Tiny sanitizer
---------------------------------------------- */
function s50(?string $v): string {
    $v = trim((string)$v);
    $v = strip_tags($v);
    if (mb_strlen($v) > 50) $v = mb_substr($v, 0, 50);
    return $v;
}

/* ---------------------------------------------
   IMPORT: with contactNum/Age + seriesNum (0YY-NNN) + de-dup
---------------------------------------------- */
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    ($_POST['action'] ?? '') === 'import_beso' &&
    csrf_ok($_POST['csrf'] ?? '')
) {
    try {
        if (!isset($_FILES['beso_file']) || !is_array($_FILES['beso_file'])) {
            throw new RuntimeException('No file received.');
        }

        $file = $_FILES['beso_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error code: ' . (int)$file['error']);
        if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('File too large. Max 5 MB.');

        $allowedExt = ['xlsx', 'csv'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('Invalid file type. Only .xlsx and .csv are allowed.');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $okMime = $ext === 'xlsx'
            ? in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true)
            : in_array($mime, ['text/plain', 'text/csv', 'application/vnd.ms-excel', 'application/csv'], true);

        if (!$okMime) throw new RuntimeException('MIME type not allowed: ' . htmlspecialchars($mime));
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Possible file upload attack.');

        // Move upload to a safe place
        $dest = $BESO_DIR . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Failed to move uploaded file.');
        @chmod($dest, 0600);

        // helpers
        $normPhone = function (?string $raw): string {
            $x = preg_replace('/\D+/', '', (string)$raw);
            if (strlen($x) === 11 && str_starts_with($x, '09')) return $x;
            if (strlen($x) === 10 && str_starts_with($x, '9')) return '0' . $x;
            return $x;
        };
        $parseAge = function ($v): ?int {
            $v = trim((string)$v);
            if ($v === '' || !ctype_digit($v)) return null;
            $n = (int)$v;
            return ($n >= 0 && $n <= 120) ? $n : null;
        };
        $norm = function (string $h): string { return strtolower(preg_replace('/[\s_]+/', '', $h)); };

        $headerMap = [];
        $rows = []; // list of assoc rows
        $extract = function(array $cols) use (&$headerMap, $normPhone, $parseAge) {
            $getI = function($name) use (&$headerMap) { return $headerMap[$name] ?? null; };
            $getS = function($i, $cols) { return s50($i === null ? '' : ($cols[$i] ?? '')); };

            $first   = $getS($getI('firstname'), $cols);
            $middle  = $getS($getI('middlename'), $cols);
            $last    = $getS($getI('lastname'), $cols);
            $suffix  = $getS($getI('suffixname'), $cols);
            $edu     = $getS($getI('educationattainment'), $cols);
            $course  = $getS($getI('course'), $cols);
            $contact = $normPhone($getS($getI('contactnum') ?? $getI('contactnumber') ?? $getI('contactno') ?? $getI('contact'), $cols));
            $age     = $parseAge($getS($getI('age'), $cols));

            return compact('first','middle','last','suffix','edu','course','contact','age');
        };

        if ($ext === 'xlsx') {
            if (!class_exists('ZipArchive')) { @unlink($dest); throw new RuntimeException('XLSX requires the PHP ZipArchive extension. Enable php-zip or upload CSV.'); }
            @include_once __DIR__ . '/../vendor/autoload.php';
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) { @unlink($dest); throw new RuntimeException('PhpSpreadsheet not installed. Upload CSV or run: composer require phpoffice/phpspreadsheet'); }

            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest)->getActiveSheet();
            $rowNo = 0;
            foreach ($sheet->toArray(null, true, true, false) as $r) {
                $rowNo++;
                if ($rowNo === 1) { foreach ($r as $i => $h) $headerMap[$norm((string)$h)] = $i; continue; }
                if (empty(array_filter($r, fn($x)=>$x!==null && $x!==''))) continue;
                $rows[] = $extract($r);
            }
        } else {
            $fh = fopen($dest, 'r');
            if (!$fh) { @unlink($dest); throw new RuntimeException('Cannot read uploaded CSV.'); }
            $first = fgets($fh);
            if ($first === false) { fclose($fh); @unlink($dest); throw new RuntimeException('Empty CSV.'); }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $cols  = str_getcsv($first);
            foreach ($cols as $i => $h) $headerMap[$norm((string)$h)] = $i;

            // Fallback legacy order if no header
            if (!isset($headerMap['firstname']) && count($cols) >= 6) {
                $headerMap = [
                    'firstname'=>0,'middlename'=>1,'lastname'=>2,'suffixname'=>3,
                    'educationattainment'=>4,'course'=>5,'contactnum'=>6,'age'=>7
                ];
                $rows[] = $extract($cols);
            }

            while (($data = fgetcsv($fh)) !== false) {
                if (empty(array_filter($data, fn($x)=>$x!==null && $x!==''))) continue;
                $rows[] = $extract($data);
            }
            fclose($fh);
        }
        @unlink($dest);

        // keep only rows with first+last
        $rows = array_values(array_filter($rows, fn($r)=>$r['first']!=='' && $r['last']!==''));
        if (!$rows) throw new RuntimeException('No rows to import.');

        // series generator: 0YY-NNN (e.g., 025-004 for 2025)
        $year2 = date('y');                  // '25'
        $prefix = '0' . $year2 . '-';        // '025-'
        $getNextSeries = function(mysqli $db) use ($prefix): string {
            $q = $db->prepare("SELECT seriesNum FROM beso WHERE seriesNum LIKE CONCAT(?, '%') ORDER BY seriesNum DESC LIMIT 1");
            $q->bind_param('s', $prefix);
            $q->execute();
            $res  = $q->get_result();
            $last = $res && $res->num_rows ? $res->fetch_assoc()['seriesNum'] : null;
            $q->close();
            $n = 1;
            if ($last && preg_match('/^' . preg_quote($prefix,'/') . '(\d{3})$/', $last, $m)) {
                $n = (int)$m[1] + 1;
            }
            return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        };

        // de-dup (same year, same full name + contact)
        $dupStmt = $mysqli->prepare("
            SELECT 1 FROM beso
            WHERE firstName=? AND IFNULL(middleName,'')=? AND lastName=? AND IFNULL(suffixName,'')=?
              AND IFNULL(contactNum,'')=? AND YEAR(created_at)=YEAR(CURDATE())
            LIMIT 1
        ");

        // insert
        $mysqli->begin_transaction();
        $ins = $mysqli->prepare("
            INSERT INTO beso
              (firstName, middleName, lastName, suffixName,
               contactNum, Age, education_attainment, course,
               seriesNum, employee_id, beso_delete_status, created_at)
            VALUES
              (?,?,?,?, ?,?,?,?, ?, ?, 0, NOW())
        ");
        if (!$ins) throw new RuntimeException('DB error (prepare insert): ' . $mysqli->error);

        $types = 'sssssisssi'; // first middle last suffix contact Age edu course series employee_id
        $employeeId = (int)($_SESSION['employee_id'] ?? 0);
        $inserted = 0; $skipped = 0; $dups = 0;

        // Initialize Error Log
        $errorLog = [];
        $errorLog[] = ['REASON', 'IS DUPLICATE', 'IS INCOMPLETE', 'FIRST NAME', 'MIDDLE NAME', 'LAST NAME', 'SUFFIX', 'CONTACT', 'AGE', 'EDUCATION', 'COURSE']; 

        foreach ($rows as $r) {
            $first=$r['first']; $middle=$r['middle']; $last=$r['last']; $suffix=$r['suffix'];
            $contact=$r['contact']; $ageVal=$r['age']; $edu=$r['edu']; $course=$r['course'];

            // 1. STRICT VALIDATION RULE (Incomplete Data)
            if ($first === '' || $last === '' || $contact === '' || $ageVal === null || $edu === '' || $course === '') {
                $skipped++;
                // Add to error log: Reason, Is Dup, Is Incomplete
                $errorLog[] = ['INCOMPLETE DATA', 'No', 'Yes', $first, $middle, $last, $suffix, $contact, $ageVal, $edu, $course];
                continue; 
            }

            // 2. DUPLICATE CHECK
            $dupStmt->bind_param('sssss', $first, $middle, $last, $suffix, $contact);
            $dupStmt->execute(); $dupStmt->store_result();
            if ($dupStmt->num_rows > 0) { 
                $dups++; 
                $errorLog[] = ['DUPLICATE RECORD', 'Yes', 'No', $first, $middle, $last, $suffix, $contact, $ageVal, $edu, $course];
                $dupStmt->free_result(); 
                continue; 
            }
            $dupStmt->free_result();

            // 3. INSERT
            $series = $getNextSeries($mysqli);
            $ins->bind_param($types, $first,$middle,$last,$suffix, $contact,$ageVal,$edu,$course, $series,$employeeId);
            if (!$ins->execute()) {
                if ($mysqli->errno == 1062) {
                    $series = $getNextSeries($mysqli);
                    $ins->bind_param($types, $first,$middle,$last,$suffix, $contact,$ageVal,$edu,$course, $series,$employeeId);
                    if ($ins->execute()) { $inserted++; continue; }
                }
                error_log('[BESO Import][Row Skip] ' . $ins->error);
                $skipped++; 
                $errorLog[] = ['DATABASE ERROR', 'No', 'No', $first, $middle, $last, $suffix, $contact, $ageVal, $edu, $course];
                continue;
            }
            $inserted++;
        }

        $mysqli->commit();
        $ins->close();
        $dupStmt->close();

        // Store Errors in Session
        if (count($errorLog) > 1) { 
            $_SESSION['beso_import_errors'] = $errorLog;
            $hasErrors = true;
        } else {
            $hasErrors = false;
        }

        // 1. Determine redirect URL
        $redirectURL = isset($redirects['beso'])
            ? $redirects['beso']
            : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index_Admin.php?page=beso');
        
        // 2. DYNAMIC DOWNLOAD FIX
        $currentPage = basename($_SERVER['PHP_SELF']);
        
        $pageParam = 'beso';
        if (function_exists('encrypt')) {
            $pageParam = urlencode(encrypt('beso'));
        }

        $downloadURL = $currentPage . '?page=' . $pageParam . '&export_errors=1';

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        ";
        if ($hasErrors) {
            echo "
            Swal.fire({
              icon: 'warning',
              title: 'Import Complete with Issues',
              html: 'Inserted: <b>{$inserted}</b><br>Duplicates Skipped: <b>{$dups}</b><br>Incomplete/Errors: <b>{$skipped}</b><br><br>Download the report to see what was skipped.',
              showDenyButton: true,
              confirmButtonText: 'Okay',
              denyButtonText: 'Download Skipped Report',
              confirmButtonColor: '#3085d6',
              denyButtonColor: '#d33'
            }).then((result) => {
              if (result.isDenied) {
                  window.location.href = '{$downloadURL}';
                  setTimeout(() => { window.location.href = " . json_encode($redirectURL) . "; }, 2000);
              } else {
                  window.location.href = " . json_encode($redirectURL) . ";
              }
            });";
        } else {
            echo "
            Swal.fire({
              icon: 'success',
              title: 'Import Complete',
              html: 'Inserted: <b>{$inserted}</b><br>No errors or duplicates found.',
              confirmButtonColor: '#3085d6'
            }).then(() => { window.location.href = " . json_encode($redirectURL) . "; });";
        }
        echo "</script>";
        exit;

    } catch (Throwable $e) {
        @mysqli_rollback($mysqli);
        error_log('[BESO Import] ' . $e->getMessage());

        $redirectURL = isset($redirects['beso']) ? $redirects['beso'] : 'index_Admin.php?page=beso';

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
          icon: 'error',
          title: 'Import Failed',
          text: " . json_encode($e->getMessage()) . ",
          confirmButtonColor: '#d33'
        }).then(() => { window.location.href = " . json_encode($redirectURL) . "; });
        </script>";
        exit;
    }
}
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="css/beso/beso.css">

<style>
    .form-select-fix {
        min-width: 120px !important;
        width: auto !important;
    }
</style>

<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center">
    <form method="GET" action="index_Admin.php" class="mb-3">
      <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page'] ?? 'beso') ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Resident Name</label>
          <input type="text" name="search" class="form-control" placeholder="Search by name" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Month</label>
          <select name="month" class="form-select form-select-fix">
            <option value="">All</option>
            <?php 
            $urlMonth = isset($_GET['month']) ? $_GET['month'] : '';
            for ($m=1;$m<=12;$m++): 
                $selected = ($urlMonth == $m) ? 'selected' : ''; 
            ?>
              <option value="<?= $m ?>" <?= $selected ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Year</label>
          <select name="year" class="form-select form-select-fix">
            <option value="">All</option>
            <?php 
            $urlYear = isset($_GET['year']) ? $_GET['year'] : '';
            $currentYear=date('Y'); 
            for ($y=$currentYear; $y>=2020; $y--): 
                $selected = ($urlYear == $y) ? 'selected' : ''; 
            ?>
              <option value="<?= $y ?>" <?= $selected ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Course</label>
          <select name="course" class="form-select">
            <option value="">All</option>
            <?php
            $urlCourse = isset($_GET['course']) ? $_GET['course'] : '';
            $courses = $mysqli->query("SELECT DISTINCT course FROM beso WHERE course IS NOT NULL AND course != ''");
            while ($c = $courses->fetch_assoc()):
              $selected = ($urlCourse == $c['course']) ? 'selected' : '';
            ?>
              <option value="<?= htmlspecialchars($c['course']) ?>" <?= $selected ?>><?= htmlspecialchars($c['course']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button type="submit" class="btn btn-primary w-100">Filter</button>
          <a href="<?= ($redirects['beso'] ?? 'index_Admin.php?page=beso') ?>" class="btn btn-secondary w-100">Clear</a>
        </div>
      </div>
    </form>

    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importBesoModal">
      <i class="fa fa-file-excel-o me-1"></i> Batch Upload
    </button>
  </div>

  <div class="mt-3"><?= $flashHtml ?></div>

  <div class="card shadow-sm mb-4 mt-2">
    <div class="card-header bg-primary text-white">🧾 BESO List</div>
    <div class="card-body p-0">
      <div class="table-responsive" style="overflow-y: auto; max-height: 600px; overflow-x: hidden;">
        <table class="table table-hover table-bordered align-middle mb-0">
          <thead>
            <tr>
              <th style="width: 160px;">Series No.</th>
              <th style="width: 320px;">Resident Name</th>
              <th style="width: 160px;">Contact No.</th>
              <th style="width: 70px;">Age</th>
              <th style="width: 240px;">Education</th>
              <th style="width: 240px;">Course</th>
              <th style="width: 220px;">Created At</th>
              <th style="width: 120px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if (!empty($result) && $result instanceof mysqli_result && $result->num_rows > 0) {
              while ($row = $result->fetch_assoc()) {
                $first  = $row['first_name']  ?? $row['firstName']  ?? '';
                $middle = $row['middle_name'] ?? $row['middleName'] ?? '';
                $last   = $row['last_name']   ?? $row['lastName']   ?? '';
                $suffix = $row['suffix_name'] ?? $row['suffixName'] ?? '';

                $fullNameRaw = trim(preg_replace('/\s+/', ' ', "$first $middle $last"));
                if ($suffix !== '') $fullNameRaw .= " $suffix";
                $fullName = htmlspecialchars($fullNameRaw, ENT_QUOTES);

                $contact = htmlspecialchars($row['contactNum_resolved'] ?? '', ENT_QUOTES);
                $age     = htmlspecialchars($row['age_resolved'] ?? '', ENT_QUOTES);
                $series  = htmlspecialchars($row['seriesNum'] ?? '', ENT_QUOTES);

                echo "<tr>
                    <td>{$series}</td>
                    <td>{$fullName}</td>
                    <td>{$contact}</td>
                    <td>{$age}</td>
                    <td>" . htmlspecialchars($row['education_attainment'] ?? '') . "</td>
                    <td>" . htmlspecialchars($row['course'] ?? '') . "</td>
                    <td>" . (!empty($row['created_at']) ? date('F j, Y g:i A', strtotime($row['created_at'])) : '') . "</td>
                    <td class='text-center'>
                        <button 
                          class='btn btn-sm btn-warning me-1 editBtn' 
                          title='Edit'
                          data-bs-toggle='modal' 
                          data-bs-target='#editBesoModal'
                          data-id='" . (int)($row['id'] ?? 0) . "'
                          data-education='" . htmlspecialchars($row['education_attainment'] ?? '', ENT_QUOTES) . "'
                          data-course='" . htmlspecialchars($row['course'] ?? '', ENT_QUOTES) . "'>
                          <i class='fas fa-edit'></i>
                        </button>
                        <button class='btn btn-sm btn-danger' title='Delete' onclick='confirmDelete(" . (int)($row['id'] ?? 0) . ")'>
                          <i class='fas fa-trash-alt'></i>
                        </button>
                    </td>
                </tr>";
              }
            } else {
              echo "<tr><td colspan='8' class='text-center'>No BESO applications found</td></tr>";
            }
            ?>
          </tbody>
        </table>
      </div>

<?php
$baseUrl   = strtok(($redirects['beso'] ?? 'index_Admin.php?page=beso'), '?');
$params    = $_GET ?? [];
unset($params['pagenum']);
$qs        = http_build_query($params);
$pageBase  = $baseUrl . ($qs ? ('?' . $qs) : '?');

$page         = max(1, (int)($page ?? ($_GET['pagenum'] ?? 1)));
$total_pages  = max(1, (int)($total_pages ?? 1));

$window   = 5;
$half     = (int) floor($window / 2);
$start    = max(1, $page - $half);
$end      = min($total_pages, $start + $window - 1);
$start    = max(1, $end - $window + 1);
?>
      <nav aria-label="Page navigation">
        <ul class="pagination justify-content-end">
          <?php if ($page <= 1): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></a></li>
          <?php endif; ?>

          <?php if ($page <= 1): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></a></li>
          <?php endif; ?>

          <?php if ($start > 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>

          <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
              <a class="page-link" href="<?= $pageBase . '&pagenum=' . $i; ?>"><?= $i; ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($end < $total_pages): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>

          <?php if ($page >= $total_pages): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></a></li>
          <?php endif; ?>

          <?php if ($page >= $total_pages): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></a></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<div class="modal fade" id="importBesoModal" tabindex="-1" aria-labelledby="importBesoLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content" id="importForm">
      <div class="modal-header">
        <h5 class="modal-title" id="importBesoLabel">Batch Upload (Excel/CSV)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="import_beso">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="mb-3">
          <label class="form-label">Choose file</label>
          <input type="file" name="beso_file" class="form-control" accept=".xlsx,.csv" required>
          <div class="form-text">
            Max 5MB. Headers (case-insensitive): 
            <code>firstName, middleName, lastName, suffixName, education_attainment, course, contactNum, Age</code>.
            <br>Contact/Age are optional. If XLSX fails, enable <b>php-zip</b> or use CSV.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload & Import</button>
      </div>
    </form>
  </div>
</div>

<script>const deleteBaseUrl = "<?= $redirects['beso'] ?? 'index_Admin.php?page=beso' ?>";</script>
<script src="components/beso/beso_script.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('importForm');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); 
            const fileInput = form.querySelector('input[type="file"]');
            if (fileInput.files.length === 0) return;

            const modalEl = document.getElementById('importBesoModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();

            Swal.fire({
                title: 'Uploading File',
                html: `
                    <div class="mt-2">
                        <h2 id="upload-percent-text" class="display-4 text-primary fw-bold mb-3">0%</h2>
                        <div class="progress" style="height: 20px;">
                            <div id="upload-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%;"></div>
                        </div>
                        <div id="upload-status-text" class="mt-3 text-muted small">Initializing...</div>
                    </div>
                `,
                showConfirmButton: false, allowOutsideClick: false, allowEscapeKey: false
            });

            const progressBar = document.getElementById('upload-progress-bar');
            const percentText = document.getElementById('upload-percent-text');
            const statusText = document.getElementById('upload-status-text');

            let currentProgress = 0;
            const animationInterval = setInterval(() => {
                if (currentProgress < 90) {
                    currentProgress += Math.floor(Math.random() * 5) + 1; 
                    if(currentProgress > 90) currentProgress = 90;
                    if (progressBar && percentText) {
                        progressBar.style.width = currentProgress + '%';
                        percentText.innerText = currentProgress + '%';
                        if(currentProgress < 30) statusText.innerText = 'Reading file...';
                        else if(currentProgress < 60) statusText.innerText = 'Uploading to server...';
                        else statusText.innerText = 'Verifying data...';
                    }
                }
            }, 100); 

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);

            xhr.onload = function() {
                clearInterval(animationInterval);
                if (xhr.status === 200) {
                    if (progressBar && percentText) {
                        progressBar.style.width = '100%';
                        progressBar.classList.remove('bg-primary');
                        progressBar.classList.add('bg-success');
                        percentText.innerText = '100%';
                        percentText.classList.remove('text-primary');
                        percentText.classList.add('text-success');
                        statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing Result...';
                    }
                    setTimeout(() => {
                        document.open(); document.write(xhr.responseText); document.close();
                    }, 500);
                } else {
                    Swal.fire('Error', 'An error occurred during upload.', 'error');
                }
            };

            xhr.onerror = function() {
                clearInterval(animationInterval);
                Swal.fire('Connection Error', 'Could not connect to server.', 'error');
            };

            xhr.send(formData);
        });
    }
});
</script>