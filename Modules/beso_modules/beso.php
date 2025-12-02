<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once 'components/beso/beso_fetch.php';   // assumes this builds $result, $page, $total_pages
require_once 'components/beso/edit_modal.php';

// Ensure encryption helpers are available (for enc_beso / enc_page, etc.)
if (!function_exists('enc_beso')) {
    @require_once __DIR__ . '/../../include/encryption.php';
}

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
$UPLOAD_DIR = realpath(__DIR__ . '/../../uploads') ?: (__DIR__ . '/../../uploads');
$BESO_DIR   = $UPLOAD_DIR . '/beso_imports';
if (!is_dir($BESO_DIR)) { @mkdir($BESO_DIR, 0700, true); }
@chmod($BESO_DIR, 0700);

/* ---------------------------------------------
   Tiny sanitizers
---------------------------------------------- */
function s50(?string $v): string {
    $v = trim((string)$v);
    $v = strip_tags($v);
    if (mb_strlen($v) > 50) $v = mb_substr($v, 0, 50);
    return $v;
}

/* ---------------------------------------------
   Import handler (CSV/XLSX)
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
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code: ' . (int)$file['error']);
        }

        // Size limit: 5 MB
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('File too large. Max 5 MB.');
        }

        // Extension + MIME validation
        $allowedExt   = ['xlsx', 'csv'];
        $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Invalid file type. Only .xlsx and .csv are allowed.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        // include application/zip to accommodate some PHP setups
        $allowedMime = [
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'csv'  => ['text/plain', 'text/csv', 'application/vnd.ms-excel', 'application/csv']
        ];
        $okMime = $ext === 'xlsx'
            ? in_array($mime, $allowedMime['xlsx'], true)
            : in_array($mime, $allowedMime['csv'], true);

        if (!$okMime) throw new RuntimeException('MIME type not allowed: ' . htmlspecialchars($mime));
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Possible file upload attack.');

        // Move to locked folder with random name
        $randName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = $BESO_DIR . '/' . $randName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }
        @chmod($dest, 0600);

        // Helpers
        $normPhone = function (?string $raw): string {
            $x = preg_replace('/\D+/', '', (string)$raw);
            if (strlen($x) === 11 && str_starts_with($x, '09')) return $x; // 09xxxxxxxxx
            if (strlen($x) === 10 && str_starts_with($x, '9')) return '0' . $x; // 9xxxxxxxxx -> 09xxxxxxxxx
            return $x;
        };
        $parseAge = function ($v): ?int {
            $v = trim((string)$v);
            if ($v === '') return null;
            if (!ctype_digit($v)) return null;
            $n = (int)$v;
            return ($n >= 0 && $n <= 120) ? $n : null;
        };

        // Parse rows (header mapping)
        // Supported headers (case-insensitive; spaces/underscores ignored):
        // firstName, middleName, lastName, suffixName, education_attainment, course, contactNum/contact_number/contactNo/contact, Age/age
        $rows = [];
        $headerMap = []; // normalizedName => index

        $normalizeHeader = function (string $h): string {
            return strtolower(preg_replace('/[\s_]+/', '', $h));
        };

        $resolveKey = function(array $map, array $candidates) {
            foreach ($candidates as $c) {
                if (isset($map[$c])) return $map[$c];
            }
            return null;
        };

        $extractRow = function (array $cols) use (&$headerMap, $normPhone, $parseAge, $resolveKey) {
            $idx = [
                'first'   => $resolveKey($headerMap, ['firstname']),
                'middle'  => $resolveKey($headerMap, ['middlename']),
                'last'    => $resolveKey($headerMap, ['lastname']),
                'suffix'  => $resolveKey($headerMap, ['suffixname']),
                'edu'     => $resolveKey($headerMap, ['educationattainment']),
                'course'  => $resolveKey($headerMap, ['course']),
                'contact' => $resolveKey($headerMap, ['contactnum','contactnumber','contactno','contact']),
                'age'     => $resolveKey($headerMap, ['age']),
            ];
            $get = function (?int $i) use ($cols) { return s50($i === null ? '' : ($cols[$i] ?? '')); };

            return [
                'first'   => $get($idx['first']),
                'middle'  => $get($idx['middle']),
                'last'    => $get($idx['last']),
                'suffix'  => $get($idx['suffix']),
                'edu'     => $get($idx['edu']),
                'course'  => $get($idx['course']),
                'contact' => $normPhone($get($idx['contact'])),
                'age'     => $parseAge($get($idx['age'])),
            ];
        };

        if ($ext === 'xlsx') {
            if (!class_exists('ZipArchive')) {
                @unlink($dest);
                throw new RuntimeException('XLSX requires the PHP ZipArchive extension. Enable php-zip or upload CSV.');
            }
            @include_once __DIR__ . '/../../vendor/autoload.php';
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                @unlink($dest);
                throw new RuntimeException('PhpSpreadsheet not installed. Upload CSV or run: composer require phpoffice/phpspreadsheet');
            }

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest);
            $sheet = $spreadsheet->getActiveSheet();
            $rowNum = 0;
            foreach ($sheet->toArray(null, true, true, false) as $r) {
                $rowNum++;
                if ($rowNum === 1) {
                    foreach ($r as $i => $h) {
                        $headerMap[$normalizeHeader((string)$h)] = $i;
                    }
                    continue;
                }
                if (empty(array_filter($r, fn($x) => $x !== null && $x !== ''))) continue;
                $rows[] = $extractRow($r);
            }
        } else { // CSV
            $fh = fopen($dest, 'r');
            if (!$fh) { @unlink($dest); throw new RuntimeException('Cannot read uploaded CSV.'); }

            $first = fgets($fh);
            if ($first === false) { fclose($fh); @unlink($dest); throw new RuntimeException('Empty CSV.'); }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $cols  = str_getcsv($first);

            foreach ($cols as $i => $h) {
                $headerMap[$normalizeHeader((string)$h)] = $i;
            }

            // No recognizable headers? fall back to legacy order
            if (!isset($headerMap['firstname']) && count($cols) >= 6) {
                $headerMap = [
                    'firstname'            => 0,
                    'middlename'           => 1,
                    'lastname'             => 2,
                    'suffixname'           => 3,
                    'educationattainment'  => 4,
                    'course'               => 5,
                    'contactnum'           => 6, // optional
                    'age'                  => 7, // optional
                ];
                $rows[] = $extractRow($cols);
            }

            while (($data = fgetcsv($fh)) !== false) {
                if (count(array_filter($data, fn($x) => $x !== null && $x !== '')) === 0) continue;
                $rows[] = $extractRow($data);
            }
            fclose($fh);
        }

        @unlink($dest);
        // Drop empty/invalid name rows
        $rows = array_values(array_filter($rows, fn($r) => ($r['first'] !== '' && $r['last'] !== '')));
        if (empty($rows)) throw new RuntimeException('No rows to import.');

        // --- series number generator: YYY-NNN (e.g. 025-023) ---
        $getNextSeries = function (mysqli $db, int $year): string {
            // last 3 digits of the year, zero-padded
            $yearPart = sprintf('%03d', $year % 1000);
            $prefix   = $yearPart . '-';

            // Find the largest NNN used this year in the new format YYY-NNN
            $sql = "
                SELECT CAST(SUBSTRING_INDEX(seriesNum, '-', -1) AS UNSIGNED) AS num
                FROM beso
                WHERE seriesNum LIKE CONCAT(?, '%')
                  AND seriesNum REGEXP '^[0-9]{3}-[0-9]{3}$'
                ORDER BY num DESC
                LIMIT 1
            ";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $prefix);
            $stmt->execute();
            $res = $stmt->get_result();
            $lastNum = ($res && $res->num_rows) ? (int)$res->fetch_assoc()['num'] : 0;
            $stmt->close();

            $next = $lastNum + 1;                // start at 1 when none exists
            return sprintf('%s-%03d', $yearPart, $next);
        };

        // Duplicate checker (same year, same full name & contact number)
        $dupStmt = $mysqli->prepare("
            SELECT 1
            FROM beso
            WHERE firstName = ?
              AND IFNULL(middleName,'') = ?
              AND lastName = ?
              AND IFNULL(suffixName,'') = ?
              AND IFNULL(contactNum,'') = ?
              AND YEAR(created_at) = YEAR(CURDATE())
            LIMIT 1
        ");

        // Insert
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

        // types for the 10 placeholders:
        // s s s s | s i s s | s i
        $types = 'sssssisssi';

        $employeeId = (int)($_SESSION['employee_id'] ?? 0);
        $inserted = 0; $skipped = 0; $dups = 0;

        foreach ($rows as $r) {
            $first   = $r['first'];
            $middle  = $r['middle'];
            $last    = $r['last'];
            $suffix  = $r['suffix'];
            $contact = $r['contact'];   // normalized
            $ageVal  = $r['age'];       // int|null
            $edu     = $r['edu'];
            $course  = $r['course'];

            // de-dupe: name+contact in current year
            $dupStmt->bind_param('sssss', $first, $middle, $last, $suffix, $contact);
            $dupStmt->execute();
            $dupStmt->store_result();
            if ($dupStmt->num_rows > 0) {
                $dups++; $dupStmt->free_result(); continue;
            }
            $dupStmt->free_result();

            // seriesNum (handle race by retrying once on duplicate key)
            $series = $getNextSeries($mysqli, (int)date('Y'));

            $ins->bind_param(
                $types,
                $first, $middle, $last, $suffix,
                $contact, $ageVal, $edu, $course,
                $series, $employeeId
            );
            if (!$ins->execute()) {
                if ($mysqli->errno == 1062) { // duplicate seriesNum, regenerate once
                    $series = $getNextSeries($mysqli, (int)date('Y'));
                    $ins->bind_param(
                        $types,
                        $first, $middle, $last, $suffix,
                        $contact, $ageVal, $edu, $course,
                        $series, $employeeId
                    );
                    if ($ins->execute()) { $inserted++; continue; }
                }
                error_log('[BESO Import][Row Skip] ' . $ins->error);
                $skipped++;
                continue;
            }
            $inserted++;
        }

        $mysqli->commit();
        $ins->close();
        $dupStmt->close();

        $redirectURL = (function_exists('enc_beso') ? enc_beso('beso') : 'index_beso_staff.php?page=beso');

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
          icon: 'success',
          title: 'Import Complete',
          html: 'Inserted: <b>" . (int)$inserted . "</b><br>Duplicates skipped: <b>" . (int)$dups . "</b><br>Other skipped: <b>" . (int)$skipped . "</b>',
          confirmButtonColor: '#3085d6'
        }).then(() => { window.location.href = " . json_encode($redirectURL) . "; });
        </script>";
        exit;

    } catch (Throwable $e) {
        @mysqli_rollback($mysqli);
        error_log('[BESO Import] ' . $e->getMessage());

        $redirectURL = (function_exists('enc_beso') ? enc_beso('beso') : 'index_beso_staff.php?page=beso');

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

<?php
// Page-load SweetAlert flash reader (kept for other flows)
$__swal = $_SESSION['swal'] ?? null;
unset($_SESSION['swal']);
if ($__swal) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
      Swal.fire({
        icon: " . json_encode($__swal['icon']) . ",
        title: " . json_encode($__swal['title']) . ",
        text: " . json_encode($__swal['text']) . ",
        confirmButtonColor: '#3085d6'
      });
    </script>";
}
?>

<div class="container my-5">
  <h2>BESO List</h2>
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <form method="GET" action="index_beso_staff.php" class="mb-0 w-100 me-3">
        <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'beso' ?>">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Resident Name</label>
            <input type="text" name="search" class="form-control" placeholder="Search by name" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
          </div>
          <div class="col-md-1">
            <label class="form-label">Month</label>
            <select name="month" class="form-select">
              <option value="">All</option>
              <?php for ($m=1;$m<=12;$m++): $sel=(isset($_GET['month'])&&$_GET['month']==$m)?'selected':''; ?>
                <option value="<?= $m ?>" <?= $sel ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-1">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
              <option value="">All</option>
              <?php $yy=date('Y'); for($y=$yy;$y>=2020;$y--): $sel=(isset($_GET['year'])&&$_GET['year']==$y)?'selected':''; ?>
                <option value="<?= $y ?>" <?= $sel ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Course</label>
            <select name="course" class="form-select">
              <option value="">All</option>
              <?php
              $courses = $mysqli->query("SELECT DISTINCT course FROM beso WHERE IFNULL(course,'')!=''");
              while ($c = $courses->fetch_assoc()):
                $sel = (isset($_GET['course']) && $_GET['course'] == $c['course']) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($c['course']) ?>" <?= $sel ?>><?= htmlspecialchars($c['course']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-dark w-100">Filter</button>
            <a href="<?= function_exists('enc_beso') ? enc_beso('beso') : 'index_beso_staff.php?page=beso' ?>" class="btn btn-outline-light w-100">Clear</a>
          </div>
        </div>
      </form>

      <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#importBesoModal">
        <i class="fa fa-file-excel-o me-1"></i> Batch Upload
      </button>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive" style="overflow-y:auto; max-height: 600px; overflow-x:hidden;">
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

                // Use resolved (BESO → residents fallback)
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

      <!-- Windowed Pagination -->
      <?php
        // Build preserved query string excluding pagenum
        $pageBase = function_exists('enc_beso') ? enc_beso('beso') : 'index_beso_staff.php?page=beso';
        $params = $_GET; unset($params['pagenum']);
        $qs = '';
        if (!empty($params)) {
          $pairs = [];
          foreach ($params as $k => $v) {
            if (is_array($v)) { foreach ($v as $vv) $pairs[] = urlencode($k).'='.urlencode($vv); }
            else { $pairs[] = urlencode($k).'='.urlencode($v ?? ''); }
          }
          $qs = '&'.implode('&', $pairs);
        }

        $window = 5;
        $half   = (int)floor($window/2);
        $start  = max(1, ($page ?? 1) - $half);
        $end    = min($total_pages ?? 1, $start + $window - 1);
        if (($end - $start + 1) < $window) $start = max(1, $end - $window + 1);
      ?>

      <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-end pagination-soft mb-0">
          <?php if (($page ?? 1) <= 1): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i><span class="visually-hidden">First</span></a></li>
          <?php endif; ?>

          <?php if (($page ?? 1) <= 1): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . (($page ?? 1) - 1) ?>"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></a></li>
          <?php endif; ?>

          <?php if ($start > 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>

          <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= ($i == ($page ?? 1)) ? 'active' : '' ?>">
              <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($end < ($total_pages ?? 1)): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>

          <?php if (($page ?? 1) >= ($total_pages ?? 1)): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . (($page ?? 1) + 1) ?>"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></a></li>
          <?php endif; ?>

          <?php if (($page ?? 1) >= ($total_pages ?? 1)): ?>
            <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($total_pages ?? 1) ?>"><i class="fa fa-angle-double-right"></i><span class="visually-hidden">Last</span></a></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importBesoModal" tabindex="-1" aria-labelledby="importBesoLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
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
            The last two are optional. First row is header.
            If you see “XLSX requires PHP ZipArchive”, enable the <b>php-zip</b> extension or upload CSV instead.
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

<script>const deleteBaseUrl = "<?= function_exists('enc_beso') ? enc_beso('beso') : 'index_beso_staff.php?page=beso' ?>";</script>
<script src="components/beso/beso_script.js"></script>
