<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        require_once __DIR__ . '/../../security/500.html';
        exit();
    }
});

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();

// Helper for error alerts (Go Back)
function swal_back(string $title, string $text = '', string $icon = 'error'): void {
    $cfg = [
        'icon'  => $icon,
        'title' => $title,
    ];
    if ($text !== '') $cfg['text'] = $text;
    echo "<script>Swal.fire(" . json_encode($cfg) . ").then(()=>{ window.history.back(); });</script>";
    exit;
}

// Accept 24h or 12h time with optional seconds, and normalize to H:i:s
function normalize_time_or_fail(string $input): string {
    $tf = trim($input);
    // allow: 24h HH:MM[:SS]  OR  12h HH:MM[:SS] AM/PM
    $ok = preg_match(
        '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$|^(0?[1-9]|1[0-2]):[0-5]\d(:[0-5]\d)?\s?([APap][Mm])$/',
        $tf
    );
    if (!$ok) {
        swal_back('Invalid time format', 'Use HH:MM or HH:MM:SS (24h) or HH:MM(:SS) AM/PM.');
    }
    $ts = strtotime($tf);
    if ($ts === false) {
        swal_back('Invalid time format', 'Unable to parse the time you entered.');
    }
    return date('H:i:s', $ts); // normalized for DB
}

include 'class/session_timeout.php';
require_once 'logs/logs_trig.php';
$resbaseUrl = enc_lupon('case_list');

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* ================================
   IMPORT (Excel) - Kept as provided
   ================================ */
if (isset($_POST['import_excel'])) {
    if (!empty($_FILES['excel_file']['tmp_name'])) {
        $file = $_FILES['excel_file']['tmp_name'];
        $employee_id = (int)($_SESSION['employee_id'] ?? 0);

        /* ---------- LOGGING ---------- */
        $logDirPreferred = __DIR__ . '/logs';
        $logDirFallback  = sys_get_temp_dir();
        $logBasename     = 'case_import.log';
        if (!is_dir($logDirPreferred)) { @mkdir($logDirPreferred, 0755, true); }
        $logDirInUse = (is_dir($logDirPreferred) && is_writable($logDirPreferred)) ? $logDirPreferred : $logDirFallback;
        $logFile = rtrim($logDirInUse, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $logBasename;
        $log = function ($msg) use ($logFile) {
            $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
            @file_put_contents($logFile, $line, FILE_APPEND);
            @error_log("[CaseImport] $msg");
        };
        $log("=== Import start === employee_id=$employee_id, tmp={$file}, logFile={$logFile}");

        /* ---------- helpers ---------- */
        $norm = function ($h) {
            $h = strtolower(trim((string)$h));
            $h = preg_replace('/[^a-z0-9#]+/i', '_', $h);
            return trim($h, '_');
        };
        $splitName = function ($full) {
            $full = trim(preg_replace('/\s+/', ' ', (string)$full));
            if ($full === '') return array('','','','');
            $p = explode(' ', $full);
            $first=$p[0]??''; $middle=''; $last=''; $suffix='';
            if (count($p) == 2) {
                $last = $p[1];
            } elseif (count($p) >= 3) {
                $maybe = strtoupper(end($p));
                $sfx = array('JR','SR','III','IV','V');
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
            if ($s === '') return null;
            $t = strtotime($s);
            return $t ? date('Y-m-d', $t) : null;
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
            $t = strtotime($s);
            return $t ? date('H:i:s', $t) : null;
        };

        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();

            // Map headers
            $headers = array();
            $headerRow = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, true, true);
            $headerRow = $headerRow ? $headerRow[1] : array();
            foreach ($headerRow as $col => $label) {
                $headers[$norm($label)] = $col;
            }

            $need = array(
                'date_filed'          => array('date_filed','date filed'),
                'time_filed'          => array('time_filed','time filed'),
                'case#'               => array('case#','case_no','case_number','case'),
                'complainants'        => array('complainants','complainant','complainant_name'),
                'respondents'         => array('respondents','respondent','respondent_name'),
                'nature_of_offense'   => array('nature_of_offense','nature_offense','offense','offence'),
                'schedule_of_hearing' => array('schedule_of_hearing','date_of_hearing','date_hearing','hearing'),
                'action_taken'        => array('action_taken','status','case_status'),
            );
            $colOf = function($key) use ($need,$headers) {
                foreach ($need[$key] as $alias) {
                    if (isset($headers[$alias])) return $headers[$alias];
                }
                return null;
            };

            $cDate   = $colOf('date_filed');
            $cTime   = $colOf('time_filed');
            $cCase   = $colOf('case#');
            $cComp   = $colOf('complainants');
            $cResp   = $colOf('respondents');
            $cNature = $colOf('nature_of_offense');
            $cHear   = $colOf('schedule_of_hearing');
            $cAct    = $colOf('action_taken');

            if (!$cDate || !$cTime || !$cCase || !$cComp || !$cResp || !$cNature || !$cHear) {
                throw new Exception('Missing required headers.');
            }

            $getCellObj = function($col, $row) use ($sheet) {
                if (!$col) return null;
                return $sheet->getCell($col . $row);
            };

            $highestRow = (int)$sheet->getHighestDataRow();
            $imported = 0;
            $skipped  = array();
            $seen     = array();

            $sql = "INSERT INTO cases
                    (case_number, employee_id,
                     Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name,
                     Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name,
                     nature_offense, date_filed, time_filed, date_hearing, action_taken)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { throw new Exception('Prepare failed: '.$mysqli->error); }

            for ($r = 2; $r <= $highestRow; $r++) {
                $cellCase  = $getCellObj($cCase,  $r);
                $cellComp  = $getCellObj($cComp,  $r);
                $cellResp  = $getCellObj($cResp,  $r);
                $cellNat   = $getCellObj($cNature,$r);
                $cellDate  = $getCellObj($cDate,  $r);
                $cellTime  = $getCellObj($cTime,  $r);
                $cellHear  = $getCellObj($cHear,  $r);
                $cellAct   = $getCellObj($cAct,   $r);

                $case_number   = trim((string)($cellCase ? $cellCase->getValue() : ''));
                $complainant   = trim((string)($cellComp ? $cellComp->getValue() : ''));
                $respondentVal = trim((string)($cellResp ? $cellResp->getValue() : ''));
                $nature        = trim((string)($cellNat  ? $cellNat ->getValue() : ''));
                $dateFiled     = $toDate($cellDate);
                $timeFiled     = $toTime($cellTime);
                $dateHearing   = $toDate($cellHear);
                $actionTaken   = trim((string)($cellAct ? $cellAct->getValue() : ''));

                if ($case_number==='' && $complainant==='' && $respondentVal==='' && $nature==='') continue;
                if ($case_number===''){ $skipped[]="Row $r: missing Case#"; continue; }
                if ($employee_id<=0)  { $skipped[]="Row $r: missing employee_id"; continue; }
                if (!$dateFiled)      { $skipped[]="Row $r: invalid Date_Filed"; continue; }
                if (!$timeFiled)      { $skipped[]="Row $r: invalid Time_Filed"; continue; }
                if (!$dateHearing)    { $skipped[]="Row $r: invalid Hearing date"; continue; }
                if (isset($seen[$case_number])) { $skipped[]="Row $r: duplicate Case# in file"; continue; }
                $seen[$case_number] = $r;

                list($cf,$cm,$cl,$cs) = $splitName($complainant);
                list($rf,$rm,$rl,$rs) = $splitName($respondentVal);

                $stmt->bind_param('sisssssssssssss',
                    $case_number, $employee_id, $cf, $cm, $cl, $cs, $rf, $rm, $rl, $rs,
                    $nature, $dateFiled, $timeFiled, $dateHearing, $actionTaken
                );

                if ($stmt->execute()) {
                    $imported++;
                } else {
                    $skipped[] = "Row $r: DB error ".$stmt->error;
                }
            }
            $stmt->close();

            if ($imported > 0 && class_exists('Trigger')) {
                $trigger = new Trigger();
                $trigger->isCaseBatchAdded(5, $imported);
            }

            $msg = "Imported rows: <b>{$imported}</b>";
            if (!empty($skipped)) {
                $msg .= "<br>Skipped: <b>".count($skipped)."</b><br><small class='text-muted'>".htmlspecialchars(implode('<br>', $skipped))."</small>";
            }

            $currentUrl = $_SERVER['REQUEST_URI'];
            echo "<script>
                Swal.fire({
                    icon:'success',
                    title:'Imported!',
                    html: ".json_encode($msg).",
                    confirmButtonColor:'#3085d6'
                }).then(()=>{ window.location.href = ".json_encode($currentUrl)." });
            </script>";
            exit;

        } catch (Exception $e) {
            $log('Exception: '.$e->getMessage());
            echo "<script>
                Swal.fire({
                    icon:'error',
                    title:'Import Failed',
                    html: ".json_encode($e->getMessage()).",
                    confirmButtonColor:'#d33'
                });
            </script>";
        }
    }
}

/* ================================
   PAGE DATA
   ================================ */
if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

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
        echo "<script>
          Swal.fire({icon:'success',title:'Status Updated!',text:'Action taken updated successfully.',confirmButtonColor:'#3085d6'})
          .then(()=>{ window.location = '$resbaseUrl'});
        </script>";
    } else {
        echo "<script>
          Swal.fire({icon:'error',title:'Error!',html:`" . addslashes($stmt->error) . "`});
        </script>";
    }
    $stmt->close();
    exit;
}

/* ================================
   UPDATE APPEARANCE + REMARKS
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appearance'])) {
    $case_number = $_POST['case_number'];
    $status      = $_POST['attendance_status']; // "Appearance" or "Non-Appearance"
    $remarks     = $_POST['appearance_remarks'] ?? '';

    // UPDATED: Now saving 'remarks' column as well
    $stmt = $mysqli->prepare("UPDATE case_participants SET action_taken = ?, remarks = ? WHERE case_number = ?");
    $stmt->bind_param("sss", $status, $remarks, $case_number);

    if ($stmt->execute()) {
        if (class_exists('Trigger')) {
            $trigger = new Trigger();
            $trigger->isStatusUpdate(5, $case_number, $status, $remarks); 
        }
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Attendance Updated',
                text: 'Case status and remarks updated successfully.',
                confirmButtonColor: '#3085d6'
            }).then(()=>{ window.location = '$resbaseUrl'; });
        </script>";
    } else {
        echo "<script>
            Swal.fire({icon:'error', title:'Error!', html:`" . addslashes($stmt->error) . "`});
        </script>";
    }
    $stmt->close();
    exit;
}

/* ================================
   UPDATE CASE DETAILS (Hybrid Save)
   ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_case_details'])) {
    // helpers
    $clean = fn($v) => htmlspecialchars(strip_tags(trim((string)$v)));

    $case_number    = $clean($_POST['case_number'] ?? '');
    $nature_offense = $clean($_POST['nature_offense'] ?? '');
    $date_filed     = $clean($_POST['date_filed'] ?? '');
    $time_filed     = $clean($_POST['time_filed'] ?? '');
    $date_hearing   = $clean($_POST['date_hearing'] ?? '');

    // Primary Names
    $Comp_First_Name  = $clean($_POST['Comp_First_Name']  ?? '');
    $Comp_Middle_Name = $clean($_POST['Comp_Middle_Name'] ?? '');
    $Comp_Last_Name   = $clean($_POST['Comp_Last_Name']   ?? '');
    $Comp_Suffix_Name = $clean($_POST['Comp_Suffix_Name'] ?? '');

    $Resp_First_Name  = $clean($_POST['Resp_First_Name']  ?? '');
    $Resp_Middle_Name = $clean($_POST['Resp_Middle_Name'] ?? '');
    $Resp_Last_Name   = $clean($_POST['Resp_Last_Name']   ?? '');
    $Resp_Suffix_Name = $clean($_POST['Resp_Suffix_Name'] ?? '');

    // Arrays
    $comp_names_arr = $_POST['Complainant'] ?? [];
    $resp_names_arr = $_POST['Respondent'] ?? [];

    // Validations
    if (!$case_number || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name ||
        !$nature_offense || !$date_filed || !$time_filed || !$date_hearing) {
        swal_back('Missing fields', 'Please complete all required fields.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filed) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_hearing)) {
        swal_back('Invalid date format', 'Use YYYY-MM-DD.');
    }

    $time_filed = normalize_time_or_fail($time_filed);

    if (
        strcasecmp($Comp_First_Name, $Resp_First_Name)   === 0 &&
        strcasecmp($Comp_Middle_Name, $Resp_Middle_Name) === 0 &&
        strcasecmp($Comp_Last_Name,  $Resp_Last_Name)    === 0 &&
        strcasecmp($Comp_Suffix_Name,$Resp_Suffix_Name)  === 0
    ) {
        swal_back('Invalid Update', 'Respondent cannot be the same as the Complainant.');
    }

    // --- 1. COMPILE ALL PARTICIPANTS ---
    $all_complainants = [];
    $all_respondents = [];
    
    // Primary (1st entry)
    $all_complainants[] = ['f' => $Comp_First_Name, 'm' => $Comp_Middle_Name, 'l' => $Comp_Last_Name, 's' => $Comp_Suffix_Name];
    // Dynamic
    if (isset($comp_names_arr['first_name'])) {
        foreach ($comp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue; 
            $all_complainants[] = [
                'f' => $clean($f),
                'm' => $clean($comp_names_arr['middle_name'][$i] ?? ''),
                'l' => $clean($comp_names_arr['last_name'][$i] ?? ''),
                's' => $clean($comp_names_arr['suffix_name'][$i] ?? ''),
            ];
        }
    }

    // Primary Respondent (1st entry)
    $all_respondents[] = ['f' => $Resp_First_Name, 'm' => $Resp_Middle_Name, 'l' => $Resp_Last_Name, 's' => $Resp_Suffix_Name];
    // Dynamic
    if (isset($resp_names_arr['first_name'])) {
        foreach ($resp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue; 
            $all_respondents[] = [
                'f' => $clean($f),
                'm' => $clean($resp_names_arr['middle_name'][$i] ?? ''),
                'l' => $clean($resp_names_arr['last_name'][$i] ?? ''),
                's' => $clean($resp_names_arr['suffix_name'][$i] ?? ''),
            ];
        }
    }

    // --- 2. START TRANSACTION ---
    $mysqli->begin_transaction();
    $success = true;

    try {
        // A. Update CASES table
        $sql = "UPDATE cases SET 
                  Comp_First_Name=?,  Comp_Middle_Name=?,  Comp_Last_Name=?,  Comp_Suffix_Name=?,
                  Resp_First_Name=?,  Resp_Middle_Name=?,  Resp_Last_Name=?,  Resp_Suffix_Name=?,
                  nature_offense=?,   date_filed=?,        time_filed=?,      date_hearing=?
                WHERE case_number=?";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: ".$mysqli->error);
        $stmt->bind_param(
            "sssssssssssss",
            $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name,
            $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name,
            $nature_offense, $date_filed, $time_filed, $date_hearing,
            $case_number
        );
        if (!$stmt->execute()) throw new Exception("Update failed: ".$stmt->error);
        $stmt->close();

        // B. DELETE existing participants to re-insert all
        $stmt_delete = $mysqli->prepare("DELETE FROM case_participants WHERE case_number = ?");
        $stmt_delete->bind_param('s', $case_number);
        if (!$stmt_delete->execute()) throw new Exception('Participant Delete failed: '.$stmt_delete->error);
        $stmt_delete->close();
        
        // C. INSERT all participants
        $sql_part = "INSERT INTO case_participants 
            (case_number, role, first_name, middle_name, last_name, suffix_name)
            VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_part = $mysqli->prepare($sql_part);

        $insert_participant_rows = function (array $participants, string $role) use ($stmt_part, $case_number) {
            foreach ($participants as $p) {
                if (empty($p['f']) || empty($p['l'])) continue;
                $stmt_part->bind_param("ssssss",
                    $case_number, $role, $p['f'], $p['m'], $p['l'], $p['s']
                );
                if (!$stmt_part->execute()) throw new Exception("Participant Insert failed for {$p['f']}");
            }
        };

        $insert_participant_rows($all_complainants, 'Complainant');
        $insert_participant_rows($all_respondents, 'Respondent');
        $stmt_part->close();

        $mysqli->commit();

        if (class_exists('Trigger')) {
            $trigger = new Trigger();
            $trigger->isEdit(5, $case_number, [
               'nature_offense'=>$nature_offense, 'date_filed'=>$date_filed
            ]);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $success = false;
        swal_back('Database Error', 'Update failed: ' . $e->getMessage());
    }

    if ($success) {
        echo "<script>
          Swal.fire({icon:'success',title:'Updated!',text:'Case details successfully updated.',confirmButtonColor:'#3085d6'})
            .then(()=>{ window.location = '$resbaseUrl'});
        </script>";
    }
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

    // Get secondary participant arrays (for dynamic rows)
    $comp_names_arr = $_POST['Complainant'] ?? [];
    $resp_names_arr = $_POST['Respondent'] ?? [];

    // --- 1. EXTRACT PRIMARY NAMES (For 'cases' table) ---
    $Comp_First_Name = sanitizeInput($_POST['Comp_First_Name'] ?? ($_POST['Comp_First_Name_P'] ?? ''));
    $Comp_Middle_Name= sanitizeInput($_POST['Comp_Middle_Name']?? ($_POST['Comp_Middle_Name_P'] ?? ''));
    $Comp_Last_Name  = sanitizeInput($_POST['Comp_Last_Name']  ?? ($_POST['Comp_Last_Name_P'] ?? ''));
    $Comp_Suffix_Name= sanitizeInput($_POST['Comp_Suffix_Name']?? ($_POST['Comp_Suffix_Name_P'] ?? ''));

    // Fallback for Complainant Name input
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

    // --- VALIDATIONS ---
    if (!$case_number) swal_back('Case number is required.');
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $case_number)) swal_back('Invalid case number format (letters/numbers/dash only).');
    
    if (!$nature_offense || !$date_filed || !$time_filed || !$date_hearing || !$action_taken
        || !$Comp_First_Name || !$Comp_Last_Name || !$Resp_First_Name || !$Resp_Last_Name) {
        swal_back('All fields are required (First/Last names for primary participants).');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filed) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_hearing)) {
        swal_back('Invalid date format', 'Use YYYY-MM-DD.');
    }

    $time_filed = normalize_time_or_fail($time_filed);

    // Duplicate Check
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM cases WHERE case_number=?");
    $stmt->bind_param("s", $case_number);
    $stmt->execute(); $stmt->bind_result($exists); $stmt->fetch(); $stmt->close();
    if ($exists > 0) swal_back('Case number already exists.');

    // Comp == Resp Check
    if (
        strcasecmp($Comp_First_Name, $Resp_First_Name)   === 0 &&
        strcasecmp($Comp_Middle_Name, $Resp_Middle_Name) === 0 &&
        strcasecmp($Comp_Last_Name,  $Resp_Last_Name)    === 0 &&
        strcasecmp($Comp_Suffix_Name,$Resp_Suffix_Name)  === 0
    ) {
        swal_back('Invalid Entry', 'Respondent cannot be the same as the Complainant.');
    }

    // --- 2. COMPILE LISTS ---
    $all_complainants = [];
    $all_respondents = [];

    // Primary
    $all_complainants[] = ['f' => $Comp_First_Name, 'm' => $Comp_Middle_Name, 'l' => $Comp_Last_Name, 's' => $Comp_Suffix_Name];
    // Dynamic
    if (isset($comp_names_arr['first_name'])) {
        foreach ($comp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue;
            $all_complainants[] = [
                'f' => sanitizeInput($f),
                'm' => sanitizeInput($comp_names_arr['middle_name'][$i] ?? ''),
                'l' => sanitizeInput($comp_names_arr['last_name'][$i] ?? ''),
                's' => sanitizeInput($comp_names_arr['suffix_name'][$i] ?? ''),
            ];
        }
    }

    // Primary Respondent
    $all_respondents[] = ['f' => $Resp_First_Name, 'm' => $Resp_Middle_Name, 'l' => $Resp_Last_Name, 's' => $Resp_Suffix_Name];
    // Dynamic
    if (isset($resp_names_arr['first_name'])) {
        foreach ($resp_names_arr['first_name'] as $i => $f) {
            if ($i === 0 || empty($f)) continue;
            $all_respondents[] = [
                'f' => sanitizeInput($f),
                'm' => sanitizeInput($resp_names_arr['middle_name'][$i] ?? ''),
                'l' => sanitizeInput($resp_names_arr['last_name'][$i] ?? ''),
                's' => sanitizeInput($resp_names_arr['suffix_name'][$i] ?? ''),
            ];
        }
    }

    // --- 3. TRANSACTION ---
    $mysqli->begin_transaction();
    $db_success = true;

    try {
        // A. INSERT INTO cases
        $stmt = $mysqli->prepare("INSERT INTO cases 
            (case_number, employee_id,
             Comp_First_Name, Comp_Middle_Name, Comp_Last_Name, Comp_Suffix_Name,
             Resp_First_Name, Resp_Middle_Name, Resp_Last_Name, Resp_Suffix_Name,
             nature_offense, date_filed, time_filed, date_hearing, action_taken)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception("Prepare failed: ".$mysqli->error);
        
        $stmt->bind_param(
            "sisssssssssssss",
            $case_number, $employee_id,
            $Comp_First_Name, $Comp_Middle_Name, $Comp_Last_Name, $Comp_Suffix_Name,
            $Resp_First_Name, $Resp_Middle_Name, $Resp_Last_Name, $Resp_Suffix_Name,
            $nature_offense, $date_filed, $time_filed, $date_hearing, $action_taken
        );
        if (!$stmt->execute()) throw new Exception("Case insert failed: ".$stmt->error);
        $stmt->close();

        // B. INSERT INTO case_participants
        $sql_part = "INSERT INTO case_participants 
            (case_number, role, first_name, middle_name, last_name, suffix_name)
            VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_part = $mysqli->prepare($sql_part);

        $insert_participant_rows = function (array $participants, string $role) use ($stmt_part, $case_number) {
            foreach ($participants as $p) {
                if (empty($p['f']) || empty($p['l'])) continue;
                $stmt_part->bind_param("ssssss",
                    $case_number, $role, $p['f'], $p['m'], $p['l'], $p['s']
                );
                if (!$stmt_part->execute()) throw new Exception("Participant insert failed: ".$stmt_part->error);
            }
        };

        $insert_participant_rows($all_complainants, 'Complainant');
        $insert_participant_rows($all_respondents, 'Respondent');
        $stmt_part->close();

        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $db_success = false;
        echo "<script>
          Swal.fire({icon:'error',title:'DB Error',html:`".addslashes($e->getMessage())."`});
        </script>";
        exit;
    }

    if ($db_success) {
        echo "<script>
          Swal.fire({icon:'success',title:'Success!',text:'Case and participants successfully added!',confirmButtonColor:'#3085d6'})
            .then(()=>{ window.location = '$resbaseUrl'});
        </script>";
    }
    exit;
}


/* ================================
   LIST (latest 20)
   ================================ */
$case_query = "
    SELECT 
        c.*,
        GROUP_CONCAT(
            CASE 
                WHEN cp.role = 'Complainant' 
                THEN CONCAT(cp.first_name, 
                            CASE WHEN cp.middle_name IS NOT NULL AND cp.middle_name != '' THEN CONCAT(' ', SUBSTR(cp.middle_name, 1, 1), '.') ELSE '' END,
                            ' ', cp.last_name, 
                            CASE WHEN cp.suffix_name IS NOT NULL AND cp.suffix_name != '' THEN CONCAT(' ', cp.suffix_name) ELSE '' END)
            END
            ORDER BY cp.participant_id
            SEPARATOR '; '
        ) AS complainant_full_names,
        GROUP_CONCAT(
            CASE 
                WHEN cp.role = 'Respondent' 
                THEN CONCAT(cp.first_name, 
                            CASE WHEN cp.middle_name IS NOT NULL AND cp.middle_name != '' THEN CONCAT(' ', SUBSTR(cp.middle_name, 1, 1), '.') ELSE '' END,
                            ' ', cp.last_name, 
                            CASE WHEN cp.suffix_name IS NOT NULL AND cp.suffix_name != '' THEN CONCAT(' ', cp.suffix_name) ELSE '' END)
            END
            ORDER BY cp.participant_id
            SEPARATOR '; '
        ) AS respondent_full_names
    FROM cases c
    LEFT JOIN case_participants cp ON c.case_number = cp.case_number
    GROUP BY c.case_number
    ORDER BY c.date_filed DESC
    LIMIT 20
";
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
</head>
<body>
<div class="container my-5">
  <div class="card shadow">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="card-title mb-0"><i class="bi bi-archive"></i> Cases List</h2>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCaseModal">➕ Add Case</button>
      </div>

      <form action="" method="POST" enctype="multipart/form-data" class="mb-4">
        <label for="excel_file" class="form-label">📂 Upload Excel File (Batch Cases)</label>
        <input type="file" name="excel_file" id="excel_file" class="form-control mb-2" accept=".xlsx, .xls" required>
        <button type="submit" name="import_excel" class="btn btn-success">Import Cases</button>
      </form>

      <input type="text" id="searchInput" class="form-control mb-3" placeholder="🔍 Search by complainant, respondent, or case number">

      <div class="table-responsive fit-table table-height-compact mt-4">
        <table class="table table-striped table-hover table-bordered align-middle mb-0">
          <thead>
            <tr>
              <th>Case Number</th>
              <th>Complainant Name</th>
              <th>Respondent Name</th>
              <th>Nature of Offense</th>
              <th>Date Filed</th>
              <th>Time Filed</th>
              <th>Date of Hearing</th>
              <th>Case Status</th>
              <!-- <th>Remarks</th> -->
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="residentTableBody">
            <?php
            while ($row = $result->fetch_assoc()):
                $user_role = $_SESSION['Role_Name'] ?? '';
                $isPunongBarangay = strtolower($user_role) === 'punong barangay';
            ?>
            <!-- <tr>
                <td><?= htmlspecialchars($row['case_number']); ?></td>
                <td><?= htmlspecialchars($row['complainant_full_names'] ?? $row['Comp_First_Name']); ?></td>
                <td><?= htmlspecialchars($row['respondent_full_names'] ?? $row['Resp_First_Name']); ?></td>
                <td><?= htmlspecialchars($row['nature_offense']); ?></td>
                <td><?= htmlspecialchars($row['date_filed']); ?></td>
                <td><?= htmlspecialchars($row['time_filed']); ?></td>
                <td><?= htmlspecialchars($row['date_hearing']); ?></td>
                <td>
                <?php if ($isPunongBarangay): ?>
                    <span class="form-control bg-light" readonly><?= htmlspecialchars($row['action_taken']) ?: 'No action' ?></span>
                <?php else: ?>
                    <form method="POST" action="" onsubmit="return confirmUpdate();">
                        <input type="hidden" name="case_number" value="<?= $row['case_number']; ?>">
                        <select name="action_taken" class="form-select">
                            <?php
                            $actions = ['Conciliated', 'Mediated', 'Dismissed', 'Withdrawn', 'Ongoing', 'Arbitration'];
                            foreach ($actions as $action) {
                                $selected = ($row['action_taken'] === $action) ? 'selected' : '';
                                echo "<option value=\"$action\" $selected>$action</option>";
                            }
                            ?>
                        </select>
                        <button type="submit" name="update_action_only" class="btn btn-primary mt-2 btn-sm">Update</button>
                    </form>
                <?php endif; ?>
                </td>
                
                <td><?= htmlspecialchars($row['remarks'] ?? ''); ?></td>

                <td>
                    <button class="btn btn-warning btn-sm" 
                            data-bs-toggle="modal" 
                            data-bs-target="#editCaseModal" 
                            onclick="populateEditModal(<?= htmlspecialchars(json_encode($row)) ?>)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                    <button class="btn btn-info btn-sm" 
                            data-bs-toggle="modal" 
                            data-bs-target="#appearanceModal"
                            onclick="document.getElementById('appearance_case_number').value = '<?= $row['case_number'] ?>'">
                        <i class="bi bi-person-check"></i>
                    </button>
                </td>
            </tr> -->
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php include 'components/case_modal/add_modal.php'; ?>

      <?php
      $page        = isset($page) ? (int)$page : (isset($_GET['pagenum']) ? max(1,(int)$_GET['pagenum']) : 1);
      $total_pages = isset($total_pages) ? (int)$total_pages : 1;
      $window      = 5; 
      $start       = max(1, $page - floor($window/2));
      $end         = min($total_pages, $start + $window - 1);
      if (($end - $start + 1) < $window) { $start = max(1, $end - $window + 1); }
      $pageBase    = $baseUrl ?? ($_SERVER['PHP_SELF'] ?? '');
      $qsArray = $_GET; unset($qsArray['pagenum']);
      $qs = '';
      if (!empty($qsArray)) {
        $qs = (strpos($pageBase, '?') === false ? '?' : '&') . http_build_query($qsArray);
      } else {
        $qs = (strpos($pageBase, '?') === false ? '?' : '');
      }
      ?>
      <nav aria-label="Page navigation" class="mt-3">
        <ul class="pagination justify-content-end">
          <?php if ($page <= 1): ?>
            <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . (empty($qs)?'':'&') . 'pagenum=1' ?>">&laquo;</a></li>
          <?php endif; ?>

          <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
              <a class="page-link" href="<?= $pageBase . $qs . (empty($qs)?'':'&') . 'pagenum=' . $i; ?>"><?= $i; ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($page >= $total_pages): ?>
            <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . (empty($qs)?'':'&') . 'pagenum=' . $total_pages ?>">&raquo;</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<div class="modal fade" id="appearanceModal" tabindex="-1" aria-labelledby="appearanceModalLabel" aria-hidden="true">
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
  const userRole = "<?= $_SESSION['Role_Name'] ?? '' ?>";
  document.getElementById('searchInput')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#residentTableBody tr').forEach(tr => {
      tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
  });
  
  function confirmUpdate() {
      return confirm("Are you sure you want to update the case status?");
  }
</script>
</body>
</html>