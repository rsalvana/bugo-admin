<?php
// 1. Start Output Buffering (Crucial for file downloads)
ob_start();

// 2. Error Reporting
ini_set('display_errors', 0); 
ini_set('log_errors', 1); 
error_reporting(E_ALL);

// Session Check
if (session_status() === PHP_SESSION_NONE) session_start();

/* --------------------------------------------------------------------------
   3. SMART FILE LOCATOR
   -------------------------------------------------------------------------- */
// A. Locate connection.php
$connPath = '';
if (file_exists(__DIR__ . '/../include/connection.php')) {
    $connPath = __DIR__ . '/../include/connection.php';
} elseif (file_exists(__DIR__ . '/../../include/connection.php')) {
    $connPath = __DIR__ . '/../../include/connection.php';
} else {
    die("<h1>System Error</h1><p>Could not find <b>connection.php</b>. Please check your folder structure.</p>");
}
require_once $connPath;
$mysqli = db_connection();

// B. Locate vendor/autoload.php
$vendorPath = '';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    $vendorPath = __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists('vendor/autoload.php')) {
    $vendorPath = 'vendor/autoload.php';
}

if ($vendorPath) {
    require_once $vendorPath;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* --------------------------------------------------------------------------
   4. EXPORT HANDLER (Download Skipped Rows)
   -------------------------------------------------------------------------- */
   if (isset($_GET['export_errors']) && $_GET['export_errors'] == '1') {
    if (session_status() === PHP_SESSION_NONE) session_start(); // Ensure session is active

    if (!empty($_SESSION['admin_case_import_errors'])) {
        // Clean buffer to remove any HTML from parent pages
        while (ob_get_level()) { ob_end_clean(); }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="skipped_cases_report.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF"); // BOM for Excel compatibility
        
        foreach ($_SESSION['admin_case_import_errors'] as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        unset($_SESSION['admin_case_import_errors']);
        exit(); // Stop execution immediately
    }
}

// Security: Fatal Error Handler
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../security/500.html';
        exit();
    }
});

// Helper functions
function swal_back(string $title, string $text = '', string $icon = 'error'): void {
    $cfg = ['icon' => $icon, 'title' => $title];
    if ($text !== '') $cfg['text'] = $text;
    echo "<script>Swal.fire(" . json_encode($cfg) . ").then(()=>{ window.history.back(); });</script>";
    exit;
}

function normalize_time_or_fail(string $input): string {
    $tf = trim($input);
    $ok = preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$|^(0?[1-9]|1[0-2]):[0-5]\d(:[0-5]\d)?\s?([APap][Mm])$/', $tf);
    if (!$ok) swal_back('Invalid time format', 'Use HH:MM or HH:MM:SS (24h) or HH:MM(:SS) AM/PM.');
    $ts = strtotime($tf);
    if ($ts === false) swal_back('Invalid time format', 'Unable to parse time.');
    return date('H:i:s', $ts);
}

include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';

// Helper for Router (Admin uses encryption)
if (!function_exists('enc_lupon')) {
    function enc_lupon($page) {
        // Reuse current page param if possible to stay safe
        $p = $_GET['page'] ?? (function_exists('encrypt') ? urlencode(encrypt($page)) : $page);
        return "index_Admin.php?page=$p";
    }
}
$resbaseUrl = enc_admin('case_list');

/* ==========================================================================
   5. IMPORT HANDLER (AJAX + LOGIC)
   ========================================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'import_cases') {
    try {
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception("Excel Library not found. Please run 'composer require phpoffice/phpspreadsheet'");
        }

        if (empty($_FILES['excel_file']['tmp_name'])) {
            throw new Exception("No file uploaded.");
        }

        $file = $_FILES['excel_file']['tmp_name'];
        $employee_id = (int)($_SESSION['employee_id'] ?? 0);

        // Helpers
        $norm = function ($h) {
            $h = strtolower(trim((string)$h));
            return trim(preg_replace('/[^a-z0-9#]+/i', '_', $h), '_');
        };

        $splitName = function ($full) {
            $full = trim(preg_replace('/\s+/', ' ', (string)$full));
            $full = preg_replace('/^(Mr\.|Ms\.|Mrs\.|Dr\.|Atty\.)\s+/i', '', $full);
            if ($full === '') return array('','','','');
            $p = explode(' ', $full);
            $first=$p[0]??''; $middle=''; $last=''; $suffix='';
            if (count($p) == 2) { $last = $p[1]; }
            elseif (count($p) >= 3) {
                $maybe = strtoupper(end($p));
                $sfx = ['JR','JR.','SR','SR.','III','IV','V'];
                if (in_array($maybe, $sfx, true)) { array_pop($p); $suffix = $maybe; }
                $first = $p[0] ?? '';
                $last  = $p[count($p)-1] ?? '';
                if (count($p) > 2) $middle = implode(' ', array_slice($p, 1, -1));
            }
            return array($first,$middle,$last,$suffix);
        };

        $toDate = function($cellObj) {
            if ($cellObj === null) return null;
            $v = $cellObj->getValue();
            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
            try {
                if (is_numeric($v) || ExcelDate::isDateTime($cellObj)) {
                    return ExcelDate::excelToDateTimeObject($v)->format('Y-m-d');
                }
            } catch (\Throwable $e) {}
            $s = trim((string)$v);
            return ($s !== '' && ($t = strtotime($s))) ? date('Y-m-d', $t) : null;
        };

        $toTime = function($cellObj) {
            if ($cellObj === null) return null;
            $v = $cellObj->getValue();
            if ($v instanceof \DateTimeInterface) return $v->format('H:i:s');
            try {
                if (is_numeric($v) || ExcelDate::isDateTime($cellObj)) {
                    return ExcelDate::excelToDateTimeObject($v)->format('H:i:s');
                }
            } catch (\Throwable $e) {}
            $s = trim((string)$v);
            if ($s === '') return null;
            if (preg_match('/^\d{1,2}:\d{2}$/', $s)) $s .= ':00';
            return ($t = strtotime($s)) ? date('H:i:s', $t) : null;
        };

        // Load Excel
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = array();
        $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, true, true);
        $headerRow = $headerRow ? $headerRow[1] : array();
        foreach ($headerRow as $col => $label) $headers[$norm($label)] = $col;

        $need = [
            'date_filed' => ['date_filed','date filed'],
            'time_filed' => ['time_filed','time filed'],
            'case#'      => ['case#','case_no','case_number','case'],
            'complainants'=>['complainants','complainant','complainant_name'],
            'respondents'=> ['respondents','respondent','respondent_name'],
            'nature'     => ['nature_of_offense','nature_offense','offense'],
            'hearing'    => ['schedule_of_hearing','date_of_hearing','date_hearing','hearing'],
            'status'     => ['action_taken','status','case_status']
        ];

        $colOf = function($key) use ($need,$headers) {
            foreach ($need[$key] as $alias) if (isset($headers[$alias])) return $headers[$alias];
            return null;
        };

        $cDate = $colOf('date_filed'); $cTime = $colOf('time_filed'); $cCase = $colOf('case#');
        $cComp = $colOf('complainants'); $cResp = $colOf('respondents'); $cNat = $colOf('nature');
        $cHear = $colOf('hearing'); $cStat = $colOf('status');

        if (!$cDate || !$cTime || !$cCase || !$cComp || !$cResp || !$cNat || !$cHear) {
            throw new Exception('Missing required columns in Excel file.');
        }

        $highestRow = (int)$sheet->getHighestDataRow();
        $imported = 0; 
        $dups = 0; 
        $skippedCount = 0;
        
        $errorLog = [];
        $errorLog[] = ['ROW', 'REASON', 'CASE NO', 'DATE FILED', 'COMPLAINANT', 'RESPONDENT'];

        $dupCheck = $mysqli->prepare("SELECT id FROM cases WHERE case_number = ? AND date_filed = ? LIMIT 1");
        
        $sqlCases = "INSERT INTO cases (case_number, employee_id, Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name, Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name, nature_offense, date_filed, time_filed, date_hearing, action_taken) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtCases = $mysqli->prepare($sqlCases);

        $sqlPart = "INSERT INTO case_participants (case_number, role, first_name, middle_name, last_name, suffix_name) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtPart = $mysqli->prepare($sqlPart);

       // ... (Previous code remains the same) ...

       // ... (Previous code remains the same) ...

       $getCell = fn($col, $r) => ($col) ? $sheet->getCell($col . $r) : null;

       for ($r = 2; $r <= $highestRow; $r++) {
           // 1. EXTRACT ALL VALUES
           $case_number = trim((string)($getCell($cCase, $r)?->getValue() ?? ''));
           $compRaw     = trim((string)($getCell($cComp, $r)?->getValue() ?? ''));
           $respRaw     = trim((string)($getCell($cResp, $r)?->getValue() ?? ''));
           $nature      = trim((string)($getCell($cNat, $r)?->getValue() ?? ''));
           $dateFiled   = $toDate($getCell($cDate, $r));
           $timeFiled   = $toTime($getCell($cTime, $r));
           $dateHearing = $toDate($getCell($cHear, $r));
           $status      = trim((string)($getCell($cStat, $r)?->getValue() ?? 'Ongoing'));
           if ($status === '') $status = 'Ongoing';

           // Skip completely empty rows
           if ($case_number === '' && $compRaw === '') continue; 

           // ==========================================================
           // NEW RULE: INCOMPLETE COLUMNS CHECK
           // ==========================================================
           // If any essential field is empty or failed date/time parsing
           if (
               $case_number === '' || 
               $compRaw === '' || 
               $respRaw === '' || 
               $nature === '' || 
               $dateFiled === null || 
               $timeFiled === null || 
               $dateHearing === null
           ) {
               // Determine exactly what is missing for the report
               $missingFields = [];
               if ($case_number === '') $missingFields[] = 'Case #';
               if ($compRaw === '')     $missingFields[] = 'Complainant';
               if ($respRaw === '')     $missingFields[] = 'Respondent';
               if ($nature === '')      $missingFields[] = 'Offense';
               if ($dateFiled === null) $missingFields[] = 'Date Filed';
               if ($timeFiled === null) $missingFields[] = 'Time Filed';
               if ($dateHearing === null)$missingFields[] = 'Hearing Date';

               $reason = 'Incomplete: Missing ' . implode(', ', $missingFields);

               // Add to Error Log (This automatically goes to the download file)
               $errorLog[] = [$r, $reason, $case_number, $dateFiled ?? 'Invalid/Empty', $compRaw, $respRaw];
               $skippedCount++;
               continue; // Skip to the next row immediately
           }

           // ==========================================================
           // EXISTING CHECKS (Run after we know data is complete)
           // ==========================================================

           // Duplicate Check 
           $dupCheck->bind_param('ss', $case_number, $dateFiled);
           $dupCheck->execute();
           if ($dupCheck->get_result()->num_rows > 0) {
               $dups++;
               $errorLog[] = [$r, 'Duplicate Record', $case_number, $dateFiled, $compRaw, $respRaw];
               $skippedCount++; continue;
           }

           // Split Names
           list($cf,$cm,$cl,$cs) = $splitName($compRaw);
           list($rf,$rm,$rl,$rs) = $splitName($respRaw);

           // Check if Splitting resulted in empty Last Names
           if ($cl === '' || $rl === '') {
               $errorLog[] = [$r, 'Invalid Name Format (Last Name required)', $case_number, $dateFiled, $compRaw, $respRaw];
               $skippedCount++; continue;
           }

           // Insert
           $mysqli->begin_transaction();
           try {
                // ... (Your existing Insert Logic remains exactly the same) ...
                // Fix for bind_param (pass variables by reference)
               $stmtCases->bind_param('sisssssssssssss', 
                   $case_number, $employee_id, $cf, $cm, $cl, $cs, 
                   $rf, $rm, $rl, $rs, $nature, $dateFiled, $timeFiled, $dateHearing, $status
               );
               
               if (!$stmtCases->execute()) throw new Exception($stmtCases->error);

               $roleC = 'Complainant';
               $stmtPart->bind_param('ssssss', $case_number, $roleC, $cf, $cm, $cl, $cs);
               $stmtPart->execute();
               
               $roleR = 'Respondent';
               $stmtPart->bind_param('ssssss', $case_number, $roleR, $rf, $rm, $rl, $rs);
               $stmtPart->execute();

               $mysqli->commit();
               $imported++;
           } catch (Exception $e) {
               $mysqli->rollback();
               $errorLog[] = [$r, 'Database Error: '.$e->getMessage(), $case_number, $dateFiled, $compRaw, $respRaw];
               $skippedCount++;
           }
       }

       // ... (Rest of code remains the same) ...

        // ... (Rest of code remains the same) ...

        if (count($errorLog) > 1) {
            $_SESSION['admin_case_import_errors'] = $errorLog;
            $hasErrors = true;
        } else {
            $hasErrors = false;
        }

        // ------------------------------------------------------------------
        // DOWNLOAD LINK FIX: Use current $_GET['page'] to ensure validity
        // ------------------------------------------------------------------
        // ---------------------------------------------------------
        // DOWNLOAD LINK FIX: Reuse current Page ID to prevent Redirects
        // ---------------------------------------------------------
       // ---------------------------------------------------------
        // DOWNLOAD LINK FIX: Reuse current Page ID to prevent Redirects
        // ---------------------------------------------------------
        $currentPage = basename($_SERVER['PHP_SELF']); // e.g., index_Admin.php
        
        // Grab the exact 'page' parameter currently in the URL (it works, so let's use it)
        $pageParam = $_GET['page'] ?? ''; 
        
        // Fallback: If for some reason it's empty, try to encrypt it manually
        if (empty($pageParam) && function_exists('encrypt')) {
             $pageParam = urlencode(encrypt('case_list'));
        } elseif (empty($pageParam)) {
             $pageParam = 'case_list';
        }

        $downloadURL = 'api/case_list.php?export_errors=1';

        // SweetAlert Response
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><script>";
        if ($hasErrors) {
            echo "
            Swal.fire({
                icon: 'warning',
                title: 'Import Complete with Issues',
                html: 'Inserted: <b>{$imported}</b><br>Duplicates: <b>{$dups}</b><br>Skipped/Errors: <b>{$skippedCount}</b><br><br>Download the report to see details.',
                showDenyButton: true,
                confirmButtonText: 'Okay',
                denyButtonText: 'Download Skipped Report',
                confirmButtonColor: '#3085d6',
                denyButtonColor: '#d33'
            }).then((result) => {
                if (result.isDenied) {
                    window.location.href = '{$downloadURL}';
                    setTimeout(() => { location.reload(); }, 2000);
                } else {
                    location.reload();
                }
            });";
        } else {
            echo "
            Swal.fire({
                icon: 'success',
                title: 'Import Successful',
                html: 'Inserted: <b>{$imported}</b><br>No errors found.',
                confirmButtonColor: '#3085d6'
            }).then(() => { location.reload(); });";
        }
        echo "</script>";
        exit;

    } catch (Exception $e) {
        echo "<script>Swal.fire({icon:'error', title:'Import Failed', text:".json_encode($e->getMessage())."});</script>";
        exit;
    }
}

/* ================================
   PAGE DATA & OTHER ACTIONS (Unchanged)
   ================================ */
if (!isset($_SESSION['employee_id'])) { header("Location: index.php"); exit(); }

/* ================================
   UPDATE STATUS (Action only)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action_only'])) {
    $case_number = $_POST['case_number'];
    $action_taken = $_POST['action_taken'];
    $stmt = $mysqli->prepare("UPDATE cases SET action_taken = ? WHERE case_number = ?");
    $stmt->bind_param("ss", $action_taken, $case_number);
    if ($stmt->execute()) {
        $trigger = new Trigger();
        $trigger->isStatusUpdate(5, $case_number, $action_taken, null);
        echo "<script>Swal.fire({icon:'success',title:'Status Updated!',text:'Action taken updated successfully.',confirmButtonColor:'#3085d6'}).then(()=>{ window.location = '$resbaseUrl'});</script>";
    } else {
        echo "<script>Swal.fire({icon:'error',title:'Error!',html:`" . addslashes($stmt->error) . "`});</script>";
    }
    $stmt->close(); exit;
}

/* ================================
   UPDATE APPEARANCE + REMARKS
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appearance'])) {
    $case_number = $_POST['case_number'];
    $status      = $_POST['attendance_status'];
    $remarks     = $_POST['appearance_remarks'] ?? '';
    $stmt = $mysqli->prepare("UPDATE case_participants SET action_taken = ?, remarks = ? WHERE case_number = ?");
    $stmt->bind_param("sss", $status, $remarks, $case_number);
    if ($stmt->execute()) {
        if (class_exists('Trigger')) {
            $trigger = new Trigger();
            $trigger->isStatusUpdate(5, $case_number, $status, $remarks); 
        }
        echo "<script>Swal.fire({icon: 'success', title: 'Attendance Updated', text: 'Case status and remarks updated successfully.', confirmButtonColor: '#3085d6'}).then(()=>{ window.location = '$resbaseUrl'; });</script>";
    } else {
        echo "<script>Swal.fire({icon:'error', title:'Error!', html:`" . addslashes($stmt->error) . "`});</script>";
    }
    $stmt->close(); exit;
}

/* ================================
   UPDATE CASE DETAILS (Hybrid Save)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_case_details'])) {
    $clean = fn($v) => htmlspecialchars(strip_tags(trim((string)$v)));
    $case_number    = $clean($_POST['case_number'] ?? '');
    $nature_offense = $clean($_POST['nature_offense'] ?? '');
    $date_filed     = $clean($_POST['date_filed'] ?? '');
    $time_filed     = $clean($_POST['time_filed'] ?? '');
    $date_hearing   = $clean($_POST['date_hearing'] ?? '');
    $Comp_First_Name  = $clean($_POST['Comp_First_Name']  ?? '');
    $Comp_Middle_Name = $clean($_POST['Comp_Middle_Name'] ?? '');
    $Comp_Last_Name   = $clean($_POST['Comp_Last_Name']   ?? '');
    $Comp_Suffix_Name = $clean($_POST['Comp_Suffix_Name'] ?? '');
    $Resp_First_Name  = $clean($_POST['Resp_First_Name']  ?? '');
    $Resp_Middle_Name = $clean($_POST['Resp_Middle_Name'] ?? '');
    $Resp_Last_Name   = $clean($_POST['Resp_Last_Name']   ?? '');
    $Resp_Suffix_Name = $clean($_POST['Resp_Suffix_Name'] ?? '');
    $comp_names_arr = $_POST['Complainant'] ?? [];
    $resp_names_arr = $_POST['Respondent'] ?? [];

    if (!$case_number || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name || !$nature_offense || !$date_filed || !$time_filed || !$date_hearing) {
        swal_back('Missing fields', 'Please complete all required fields.');
    }
    
    // Normalize Time
    $time_filed = normalize_time_or_fail($time_filed);

    $all_complainants = []; $all_respondents = [];
    $all_complainants[] = ['f' => $Comp_First_Name, 'm' => $Comp_Middle_Name, 'l' => $Comp_Last_Name, 's' => $Comp_Suffix_Name];
    if (isset($comp_names_arr['first_name'])) {
        foreach ($comp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue; 
            $all_complainants[] = ['f' => $clean($f), 'm' => $clean($comp_names_arr['middle_name'][$i] ?? ''), 'l' => $clean($comp_names_arr['last_name'][$i] ?? ''), 's' => $clean($comp_names_arr['suffix_name'][$i] ?? '')];
        }
    }
    $all_respondents[] = ['f' => $Resp_First_Name, 'm' => $Resp_Middle_Name, 'l' => $Resp_Last_Name, 's' => $Resp_Suffix_Name];
    if (isset($resp_names_arr['first_name'])) {
        foreach ($resp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue; 
            $all_respondents[] = ['f' => $clean($f), 'm' => $clean($resp_names_arr['middle_name'][$i] ?? ''), 'l' => $clean($resp_names_arr['last_name'][$i] ?? ''), 's' => $clean($resp_names_arr['suffix_name'][$i] ?? '')];
        }
    }

    $mysqli->begin_transaction();
    $success = true;
    try {
        $sql = "UPDATE cases SET Comp_First_Name=?, Comp_Middle_Name=?, Comp_Last_Name=?, Comp_Suffix_Name=?, Resp_First_Name=?, Resp_Middle_Name=?, Resp_Last_Name=?, Resp_Suffix_Name=?, nature_offense=?, date_filed=?, time_filed=?, date_hearing=? WHERE case_number=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssssssssss", $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name, $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name, $nature_offense, $date_filed, $time_filed, $date_hearing, $case_number);
        $stmt->execute();
        $stmt->close();

        $stmt_delete = $mysqli->prepare("DELETE FROM case_participants WHERE case_number = ?");
        $stmt_delete->bind_param('s', $case_number);
        $stmt_delete->execute();
        $stmt_delete->close();
        
        $sql_part = "INSERT INTO case_participants (case_number, role, first_name, middle_name, last_name, suffix_name) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_part = $mysqli->prepare($sql_part);
        $insert_rows = function (array $participants, string $role) use ($stmt_part, $case_number) {
            foreach ($participants as $p) {
                if (empty($p['f']) || empty($p['l'])) continue;
                $stmt_part->bind_param("ssssss", $case_number, $role, $p['f'], $p['m'], $p['l'], $p['s']);
                $stmt_part->execute();
            }
        };
        $insert_rows($all_complainants, 'Complainant');
        $insert_rows($all_respondents, 'Respondent');
        $stmt_part->close();
        $mysqli->commit();
        if (class_exists('Trigger')) {
            $trigger = new Trigger();
            $trigger->isEdit(5, $case_number, ['nature_offense'=>$nature_offense, 'date_filed'=>$date_filed]);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $success = false;
        swal_back('Database Error', 'Update failed: ' . $e->getMessage());
    }
    if ($success) echo "<script>Swal.fire({icon:'success',title:'Updated!',text:'Case details successfully updated.',confirmButtonColor:'#3085d6'}).then(()=>{ window.location = '$resbaseUrl'});</script>";
}

/* ================================
   ADD CASE (Hybrid Save)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_case'])) {
    function sanitizeInput($d){ return htmlspecialchars(strip_tags(trim((string)$d))); }
    $case_number    = sanitizeInput($_POST['case_number']);
    $nature_offense = sanitizeInput($_POST['nature_offense']);
    $date_filed     = sanitizeInput($_POST['date_filed']);
    $time_filed     = sanitizeInput($_POST['time_filed']);
    $date_hearing   = sanitizeInput($_POST['date_hearing']);
    $action_taken   = sanitizeInput($_POST['action_taken']);
    $employee_id    = (int)($_SESSION['employee_id'] ?? 0);
    $comp_names_arr = $_POST['Complainant'] ?? [];
    $resp_names_arr = $_POST['Respondent'] ?? [];

    $Comp_First_Name = sanitizeInput($_POST['Comp_First_Name'] ?? ($_POST['Comp_First_Name_P'] ?? ''));
    $Comp_Middle_Name= sanitizeInput($_POST['Comp_Middle_Name']?? ($_POST['Comp_Middle_Name_P'] ?? ''));
    $Comp_Last_Name  = sanitizeInput($_POST['Comp_Last_Name']  ?? ($_POST['Comp_Last_Name_P'] ?? ''));
    $Comp_Suffix_Name= sanitizeInput($_POST['Comp_Suffix_Name']?? ($_POST['Comp_Suffix_Name_P'] ?? ''));
    if (!$Comp_First_Name && isset($_POST['complainant_name'])) {
        $split = function($n){
            $n = trim(preg_replace('/\s+/', ' ', (string)$n));
            if ($n==='') return array('','','','');
            $p = explode(' ', $n);
            $first=$p[0]??''; $middle=''; $last=''; $suffix='';
            if (count($p) == 2) { $last = $p[1]; }
            elseif (count($p) >= 3) {
                $maybe = strtoupper(end($p)); $sfx = ['JR','SR','III','IV','V'];
                if (in_array($maybe, $sfx, true)) { array_pop($p); $suffix = $maybe; }
                $first = $p[0] ?? ''; $last = $p[count($p)-1] ?? '';
                if (count($p) > 2) $middle = implode(' ', array_slice($p, 1, -1));
            }
            return [$first,$middle,$last,$suffix];
        };
        list($Comp_First_Name,$Comp_Middle_Name,$Comp_Last_Name,$Comp_Suffix_Name) = $split($_POST['complainant_name']);
    }
    $Resp_First_Name = sanitizeInput($_POST['Resp_First_Name'] ?? ($_POST['Resp_First_Name_P'] ?? ''));
    $Resp_Middle_Name= sanitizeInput($_POST['Resp_Middle_Name']?? ($_POST['Resp_Middle_Name_P'] ?? ''));
    $Resp_Last_Name  = sanitizeInput($_POST['Resp_Last_Name']  ?? ($_POST['Resp_Last_Name_P'] ?? ''));
    $Resp_Suffix_Name= sanitizeInput($_POST['Resp_Suffix_Name']?? ($_POST['Resp_Suffix_Name_P'] ?? ''));

    if (!$case_number || !$nature_offense || !$date_filed || !$time_filed || !$date_hearing || !$action_taken || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name) {
        swal_back('All fields are required.');
    }
    $time_filed = normalize_time_or_fail($time_filed);

    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM cases WHERE case_number=?");
    $stmt->bind_param("s", $case_number);
    $stmt->execute(); $stmt->bind_result($exists); $stmt->fetch(); $stmt->close();
    if ($exists > 0) swal_back('Case number already exists.');

    $all_complainants = []; $all_respondents = [];
    $all_complainants[] = ['f' => $Comp_First_Name, 'm' => $Comp_Middle_Name, 'l' => $Comp_Last_Name, 's' => $Comp_Suffix_Name];
    if (isset($comp_names_arr['first_name'])) {
        foreach ($comp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue;
            $all_complainants[] = ['f' => sanitizeInput($f), 'm' => sanitizeInput($comp_names_arr['middle_name'][$i] ?? ''), 'l' => sanitizeInput($comp_names_arr['last_name'][$i] ?? ''), 's' => sanitizeInput($comp_names_arr['suffix_name'][$i] ?? '')];
        }
    }
    $all_respondents[] = ['f' => $Resp_First_Name, 'm' => $Resp_Middle_Name, 'l' => $Resp_Last_Name, 's' => $Resp_Suffix_Name];
    if (isset($resp_names_arr['first_name'])) {
        foreach ($resp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue;
            $all_respondents[] = ['f' => sanitizeInput($f), 'm' => sanitizeInput($resp_names_arr['middle_name'][$i] ?? ''), 'l' => sanitizeInput($resp_names_arr['last_name'][$i] ?? ''), 's' => sanitizeInput($resp_names_arr['suffix_name'][$i] ?? '')];
        }
    }

    $mysqli->begin_transaction();
    $db_success = true;
    try {
        $stmt = $mysqli->prepare("INSERT INTO cases (case_number, employee_id, Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name, Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name, nature_offense, date_filed, time_filed, date_hearing, action_taken) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisssssssssssss", $case_number, $employee_id, $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name, $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name, $nature_offense, $date_filed, $time_filed, $date_hearing, $action_taken);
        $stmt->execute();
        $stmt->close();

        $sql_part = "INSERT INTO case_participants (case_number, role, first_name, middle_name, last_name, suffix_name) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_part = $mysqli->prepare($sql_part);
        $insert_rows = function (array $participants, string $role) use ($stmt_part, $case_number) {
            foreach ($participants as $p) {
                if (empty($p['f']) || empty($p['l'])) continue;
                $stmt_part->bind_param("ssssss", $case_number, $role, $p['f'], $p['m'], $p['l'], $p['s']);
                $stmt_part->execute();
            }
        };
        $insert_rows($all_complainants, 'Complainant');
        $insert_rows($all_respondents, 'Respondent');
        $stmt_part->close();
        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $db_success = false;
        echo "<script>Swal.fire({icon:'error',title:'DB Error',html:`".addslashes($e->getMessage())."`});</script>";
        exit;
    }
    if ($db_success) echo "<script>Swal.fire({icon:'success',title:'Success!',text:'Case and participants successfully added!',confirmButtonColor:'#3085d6'}).then(()=>{ window.location = '$resbaseUrl'});</script>";
    exit;
}

/* ================================
   LIST (latest 20)
   ================================ */
$case_query = "SELECT c.*, 
    (SELECT action_taken FROM case_participants WHERE case_number = c.case_number AND action_taken IN ('Appearance', 'Non-Appearance') LIMIT 1) as attendance_status,
    GROUP_CONCAT(CASE WHEN cp.role = 'Complainant' THEN CONCAT(cp.first_name, CASE WHEN cp.middle_name IS NOT NULL AND cp.middle_name != '' THEN CONCAT(' ', SUBSTR(cp.middle_name, 1, 1), '.') ELSE '' END, ' ', cp.last_name, CASE WHEN cp.suffix_name IS NOT NULL AND cp.suffix_name != '' THEN CONCAT(' ', cp.suffix_name) ELSE '' END) END ORDER BY cp.participant_id SEPARATOR '; ') AS complainant_full_names,
    GROUP_CONCAT(CASE WHEN cp.role = 'Respondent' THEN CONCAT(cp.first_name, CASE WHEN cp.middle_name IS NOT NULL AND cp.middle_name != '' THEN CONCAT(' ', SUBSTR(cp.middle_name, 1, 1), '.') ELSE '' END, ' ', cp.last_name, CASE WHEN cp.suffix_name IS NOT NULL AND cp.suffix_name != '' THEN CONCAT(' ', cp.suffix_name) ELSE '' END) END ORDER BY cp.participant_id SEPARATOR '; ') AS respondent_full_names
    FROM cases c LEFT JOIN case_participants cp ON c.case_number = cp.case_number
    GROUP BY c.case_number ORDER BY c.date_filed DESC LIMIT 20";
$result = $mysqli->query($case_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Cases List</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/case/case.css">
  <style>
    #addCaseModal .modal-body { max-height: 70vh; overflow-y: auto; }
  </style>
</head>
<body>
<div class="container my-5">
  <div class="card shadow">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="card-title mb-0"><i class="bi bi-archive"></i> Cases List</h2>
        <div>
            <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#importCaseModal">
                <i class="bi bi-file-earmark-spreadsheet"></i> Batch Upload
            </button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCaseModal">➕ Add Case</button>
        </div>
      </div>

      <input type="text" id="searchInput" class="form-control mb-3" placeholder="🔍 Search by complainant, respondent, or case number">

      <div class="table-responsive fit-table table-height-compact mt-4">
        <table class="table table-striped table-hover table-bordered align-middle mb-0">
          <thead>
            <tr>
              <th>Case Number</th><th>Complainant</th><th>Respondent</th><th>Offense</th>
              <th>Date Filed</th><th>Time</th><th>Hearing</th><th>Appearance</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody id="residentTableBody">
            <?php while ($row = $result->fetch_assoc()): 
                $user_role = $_SESSION['Role_Name'] ?? '';
                $isPunongBarangay = strtolower($user_role) === 'punong barangay';
            ?>
            <tr>
                <td><?= htmlspecialchars($row['case_number']); ?></td>
                <td><?= htmlspecialchars($row['complainant_full_names'] ?? $row['Comp_First_Name']); ?></td>
                <td><?= htmlspecialchars($row['respondent_full_names'] ?? $row['Resp_First_Name']); ?></td>
                <td><?= htmlspecialchars($row['nature_offense']); ?></td>
                <td><?= htmlspecialchars($row['date_filed']); ?></td>
                <td><?= htmlspecialchars($row['time_filed']); ?></td>
                <td><?= htmlspecialchars($row['date_hearing']); ?></td>
                <td>
                    <?php 
                    $att = $row['attendance_status'] ?? '';
                    if ($att === 'Appearance') echo '<span class="badge bg-success">Appeared</span>';
                    elseif ($att === 'Non-Appearance') echo '<span class="badge bg-danger">Absent</span>';
                    else echo '<span class="badge bg-secondary">Pending</span>';
                    ?>
                </td>
                <td>
                <?php if ($isPunongBarangay): ?>
                    <span class="form-control bg-light" readonly><?= htmlspecialchars($row['action_taken']) ?: 'No action' ?></span>
                <?php else: ?>
                    <form method="POST" action="" onsubmit="return confirmUpdate();">
                        <input type="hidden" name="case_number" value="<?= $row['case_number']; ?>">
                        <div class="input-group input-group-sm">
                            <select name="action_taken" class="form-select">
                                <?php foreach (['Conciliated', 'Mediated', 'Dismissed', 'Withdrawn', 'Ongoing', 'Arbitration'] as $action): ?>
                                <option value="<?= $action ?>" <?= ($row['action_taken'] === $action) ? 'selected' : ''; ?>><?= $action ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="update_action_only" class="btn btn-primary"><i class="bi bi-check"></i></button>
                        </div>
                    </form>
                <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editCaseModal" onclick='populateEditModal(<?= json_encode($row) ?>)'><i class="bi bi-pencil-square"></i></button>
                        <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#appearanceModal" onclick="document.getElementById('appearance_case_number').value = '<?= $row['case_number'] ?>'"><i class="bi bi-person-check"></i></button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php include 'components/case_modal/add_modal.php'; ?>
      
      <?php
      $page = max(1, (int)($_GET['pagenum'] ?? 1));
      $total_pages = $total_pages ?? 1;
      ?>
    </div>
  </div>
</div>

<div class="modal fade" id="importCaseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content" id="importForm">
      <div class="modal-header">
        <h5 class="modal-title">Batch Upload Cases (Excel)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="import_cases">
        <div class="mb-3">
          <label class="form-label">Choose Excel File</label>
          <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="appearanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="appearanceModalLabel"><i class="bi bi-journal-check"></i> Update Appearance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="case_number" id="appearance_case_number">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Attendance Status</label>
                        <select name="attendance_status" class="form-select" required>
                            <option value="" disabled selected>-- Select Status --</option>
                            <option value="Appearance">Appearance (Present)</option>
                            <option value="Non-Appearance">Non-Appearance (Absent)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea name="appearance_remarks" class="form-control" rows="3" placeholder="Enter notes regarding the hearing..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_appearance" class="btn btn-warning">Save Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="components/case_modal/case.js"></script>
<script src="util/case.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('importForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); 
            const fileInput = form.querySelector('input[type="file"]');
            if (fileInput.files.length === 0) return;

            const modalEl = document.getElementById('importCaseModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();

            Swal.fire({
                title: 'Uploading Cases',
                html: `
                    <div class="mt-2">
                        <h2 id="upload-percent-text" class="display-4 text-primary fw-bold mb-3">0%</h2>
                        <div class="progress" style="height: 20px;">
                            <div id="upload-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 0%;"></div>
                        </div>
                        <div id="upload-status-text" class="mt-3 text-muted small">Initializing...</div>
                    </div>`,
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
                    if (progressBar) {
                        progressBar.style.width = currentProgress + '%';
                        percentText.innerText = currentProgress + '%';
                        if(currentProgress < 30) statusText.innerText = 'Reading file...';
                        else if(currentProgress < 60) statusText.innerText = 'Processing cases...';
                        else statusText.innerText = 'Checking duplicates...';
                    }
                }
            }, 100); 

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);

            xhr.onload = function() {
                clearInterval(animationInterval);
                if (xhr.status === 200) {
                    if (progressBar) {
                        progressBar.style.width = '100%';
                        progressBar.classList.replace('bg-primary', 'bg-success');
                        percentText.innerText = '100%';
                        percentText.classList.replace('text-primary', 'text-success');
                        statusText.innerHTML = 'Done!';
                    }
                    setTimeout(() => {
                        document.open(); document.write(xhr.responseText); document.close();
                    }, 500);
                } else {
                    Swal.fire('Error', 'Server Error: ' + xhr.status, 'error');
                }
            };
            xhr.onerror = function() {
                clearInterval(animationInterval);
                Swal.fire('Error', 'Connection Failed', 'error');
            };
            xhr.send(formData);
        });
    }
});
</script>

<script>
  const userRole = "<?= $_SESSION['Role_Name'] ?? '' ?>";
  document.getElementById('searchInput')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#residentTableBody tr').forEach(tr => {
      tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
  });
  function confirmUpdate() { return confirm("Are you sure you want to update the case status?"); }
</script>
</body>
</html>