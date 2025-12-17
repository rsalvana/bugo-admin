<?php
// Modules/staff_modules/resident_info.php

// 1. Start Output Buffering to prevent unwanted text/warnings from breaking JSON
ob_start();

ini_set('display_errors', 0); // Hide errors from output (log them instead)
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// FIX: Use __DIR__ to correctly locate files relative to this script
require_once __DIR__ . '/../../include/connection.php';
require_once __DIR__ . '/../../include/encryption.php';
$mysqli = db_connection();

// Fix path for session_timeout if needed, or suppress if included by parent
if (file_exists(__DIR__ . '/../../class/session_timeout.php')) {
    include __DIR__ . '/../../class/session_timeout.php';
}

date_default_timezone_set('Asia/Manila');

// Fix path for Logs
if (file_exists(__DIR__ . '/../../logs/logs_trig.php')) {
    require_once __DIR__ . '/../../logs/logs_trig.php';
} elseif (file_exists('./logs/logs_trig.php')) {
    require_once './logs/logs_trig.php';
}
$trigs = new Trigger();

// === CRITICAL FIX: Correct path to Vendor Autoload ===
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} else {
    // Fallback if vendor is in a different spot (adjust if necessary)
    die("Error: vendor/autoload.php not found. Please check directory structure.");
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$resbaseUrl = enc_encoder('resident_info');

/* ---------------- Helpers ---------------- */

function sanitize_input($data) { return htmlspecialchars(strip_tags(trim((string)$data))); }

function generatePassword(int $len = 12): string {
    $base = str_replace(['/', '+', '='], '', base64_encode(random_bytes($len + 2)));
    return substr($base, 0, $len);
}

function slugify_lower(string $s): string {
    $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
    if ($t === false) $t = $s;
    $t = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $t ?? ''));
    return $t !== '' ? $t : 'user';
}

function sendCredentials(string $to, string $password): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'mail.bugoportal.site';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'admin@bugoportal.site';
        $mail->Password    = 'Jayacop@100';
        $mail->Port        = 465;
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout     = 12;
        $mail->SMTPAutoTLS = true;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        $mail->setFrom('admin@bugoportal.site', 'Barangay Bugo');
        $mail->addAddress($to);
        $mail->addReplyTo('admin@bugoportal.site', 'Barangay Bugo');

        $portalLink = 'https://bugoportal.site/';
        $mail->isHTML(true);
        $mail->Subject = 'Your Barangay Bugo Resident Portal Credentials';
        $mail->Body    = "<p>Hi {$safeTo},</p>
                          <p>Here are your Barangay Bugo portal login credentials:</p>
                          <ul>
                            <li><strong>Username:</strong> {$safeTo}</li>
                            <li><strong>Password:</strong> {$password}</li>
                          </ul>
                          <p>Please log in and change your password.</p>
                          <p><a href=\"{$portalLink}\">Open Resident Portal</a></p>
                          <br><p>Thank you,<br>Barangay Bugo</p>";
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mail error ({$to}): {$mail->ErrorInfo}");
        return false;
    }
}

if (!defined('SEND_EMAILS')) {
    define('SEND_EMAILS', (getenv('SEND_EMAILS') ?: '1') === '1');
}
function sendCredentialsIfPresent(?string $to, string $password): void {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    if (!SEND_EMAILS) return;
    sendCredentials($to, $password);
}

/* ======================================================================
   AJAX HANDLERS (Batch Import Logic)
   ====================================================================== */

// 1. AJAX: Parse Excel File & Return Count (Preview)
if (isset($_POST['action']) && $_POST['action'] === 'parse_excel_preview') {
    ob_clean(); // Ensure no previous output
    header('Content-Type: application/json');

    try {
        // CSRF Check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('CSRF token mismatch.');
        }

        if (!isset($_FILES['excel_file']['tmp_name']) || empty($_FILES['excel_file']['tmp_name'])) {
            throw new Exception('No file uploaded.');
        }

        $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Remove header row (index 0)
        array_shift($rows);

        // Filter out completely empty rows
        $validRows = [];
        foreach ($rows as $r) {
            // Check if at least first name or last name exists (Cols 0 and 1)
            if (!empty($r[0]) || !empty($r[1])) {
                $validRows[] = $r;
            }
        }

        // Store rows in session to process one by one
        $_SESSION['batch_import_data'] = $validRows;
        
        echo json_encode([
            'status' => 'success', 
            'total_rows' => count($validRows)
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Parse Error: ' . $e->getMessage()]);
    }
    exit;
}

// 2. AJAX: Process Single Row (Called in loop by JS)
if (isset($_POST['action']) && $_POST['action'] === 'process_single_row') {
    ob_clean();
    header('Content-Type: application/json');

    try {
        // CSRF Check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('CSRF token mismatch.');
        }

        $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;
        
        if ($index < 0 || !isset($_SESSION['batch_import_data']) || !isset($_SESSION['batch_import_data'][$index])) {
            throw new Exception('Invalid row index or session expired.');
        }

        $row = $_SESSION['batch_import_data'][$index];

        // --- Mapping logic ---
        $last_name    = sanitize_input($row[0] ?? 'N/A');
        $first_name   = sanitize_input($row[1] ?? 'N/A');
        $middle_name  = sanitize_input($row[2] ?? '');
        $suffix_name  = sanitize_input($row[3] ?? '');
        $res_zone     = sanitize_input($row[4] ?? 'ZONE N/A');
        
        $birth_raw = $row[5] ?? '2000-01-01';
        if (is_numeric($birth_raw)) {
            $birth_date = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($birth_raw));
        } else {
            $birth_date = date('Y-m-d', strtotime((string)$birth_raw ?: '2000-01-01'));
        }

        $gender       = sanitize_input($row[6] ?? 'N/A');
        $civil_status = sanitize_input($row[7] ?? 'N/A');
        $occupation   = sanitize_input($row[8] ?? 'N/A');
        $email        = strtolower(sanitize_input($row[9] ?? ''));

        // Check Duplication: Email (Active only)
        if ($email !== '') {
            $du = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0 LIMIT 1");
            $du->bind_param('s', $email);
            $du->execute();
            if ($du->get_result()->num_rows > 0) { 
                $du->close();
                echo json_encode(['status' => 'skipped', 'message' => 'Email already exists.']); 
                exit; 
            }
            $du->close();
        }

        // Check Duplication: Name (Active only)
        $chkName = $mysqli->prepare("SELECT id FROM residents WHERE first_name = ? AND last_name = ? AND resident_delete_status = 0 LIMIT 1");
        $chkName->bind_param("ss", $first_name, $last_name);
        $chkName->execute();
        if ($chkName->get_result()->num_rows > 0) {
             $chkName->close();
             echo json_encode(['status' => 'skipped', 'message' => 'Resident name already exists.']);
             exit;
        }
        $chkName->close();

        // Defaults
        $employee_id            = $_SESSION['employee_id'] ?? 0;
        $zone_leader_id         = 0;
        $raw_password           = generatePassword();
        $password               = password_hash($raw_password, PASSWORD_DEFAULT);
        $birth_place            = "N/A";
        $residency_start        = date('Y-m-d');
        $res_province           = 57;
        $res_city               = 1229;
        $res_barangay           = 32600;
        $full_address           = $res_zone; 
        $contact_number         = "0000000000";
        $citizenship            = "N/A";
        $religion               = "N/A";
        $age                    = date_diff(date_create($birth_date), date_create('today'))->y;
        $resident_delete_status = 0;
        
        // Username Generation
        $base_username = slugify_lower($first_name . $last_name);
        $username      = $base_username; 

        // DB Insert
        $insert_stmt = $mysqli->prepare(
            "INSERT INTO residents (
                employee_id, zone_leader_id, username, password, temp_password,
                first_name, middle_name, last_name, suffix_name,
                gender, civil_status, birth_date, residency_start, birth_place, age, contact_number, email,
                res_province, res_city_municipality, res_barangay, res_zone, res_street_address, citizenship,
                religion, occupation, resident_delete_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insert_stmt->bind_param(
            "iissssssssssssissiiisssssi",
            $employee_id, $zone_leader_id, $username, $password, $raw_password,
            $first_name, $middle_name, $last_name, $suffix_name,
            $gender, $civil_status, $birth_date, $residency_start, $birth_place, $age,
            $contact_number, $email, $res_province, $res_city, $res_barangay,
            $res_zone, $full_address, $citizenship, $religion, $occupation, $resident_delete_status
        );

        if (!$insert_stmt->execute()) {
             throw new Exception('DB Insert Error: ' . $insert_stmt->error);
        }
        
        $new_resident_id = $mysqli->insert_id;
        $insert_stmt->close();

        // Handle Username Duplicates (Append ID if exists)
        $check_dupe = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND id != ? AND resident_delete_status = 0 LIMIT 1");
        $check_dupe->bind_param("si", $base_username, $new_resident_id);
        $check_dupe->execute();
        if ($check_dupe->get_result()->num_rows > 0) {
            $final_username = $base_username . $new_resident_id;
            $upd = $mysqli->prepare("UPDATE residents SET username = ? WHERE id = ?");
            $upd->bind_param("si", $final_username, $new_resident_id);
            $upd->execute();
            $upd->close();
        }
        $check_dupe->close();

        // Logs
        global $trigs;
        if(isset($trigs)) $trigs->isResidentBatchAdded(2, 1);

        // Email
        if ($email) {
            sendCredentialsIfPresent($email, $raw_password);
        }

        echo json_encode(['status' => 'success']);

    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Processing Error: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Flush Buffer for HTML page load
ob_end_flush();

/* ---------------- Standard SweetAlert helper ---------------- */
if (!function_exists('swal_and_redirect')) {
    function swal_and_redirect(string $icon, string $title, string $text, string $redirectUrl) {
        $title = addslashes($title);
        $text  = addslashes($text);
        $redirectUrl = addslashes($redirectUrl);
        echo "<!doctype html><html><head><meta charset='utf-8'>
                <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
              </head><body>
                <script>
                  Swal.fire({icon:'{$icon}', title:'{$title}', text:'{$text}'})
                        .then(() => { window.location.href = '{$redirectUrl}'; });
                </script>
              </body></html>";
        exit;
    }
}

/* ---------------- List/Pagination ---------------- */

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$page   = (isset($_GET['pagenum']) && is_numeric($_GET['pagenum'])) ? intval($_GET['pagenum']) : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$count_sql = "SELECT COUNT(*) AS total
              FROM residents
              WHERE resident_delete_status = 0
                AND CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) LIKE ?";

$params = ["%$search%"]; $types = 's';

if (!empty($_GET['filter_gender'])) { $count_sql .= " AND gender = ?";       $params[] = $_GET['filter_gender']; $types .= 's'; }
if (!empty($_GET['filter_zone']))   { $count_sql .= " AND res_zone = ?";     $params[] = $_GET['filter_zone'];   $types .= 's'; }
if (!empty($_GET['filter_status'])) { $count_sql .= " AND civil_status = ?"; $params[] = $_GET['filter_status']; $types .= 's'; }

$stmt = $mysqli->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$count_result = $stmt->get_result();
$total_rows   = (int)$count_result->fetch_assoc()['total'];
$total_pages  = max(ceil($total_rows / $limit), 1);

$baseUrl = $resbaseUrl; 
if ($page > $total_pages) { $page = 1; }
$offset = ($page - 1) * $limit;

/* ---------------- Manual Add (primary + family) ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['firstName'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        swal_and_redirect('error','Security Alert!','CSRF token mismatch. Operation blocked.',$resbaseUrl);
    }

    // Primary
    $firstName        = sanitize_input($_POST['firstName']);
    $middleName       = sanitize_input($_POST['middleName']);
    $lastName         = sanitize_input($_POST['lastName']);
    $suffixName       = sanitize_input($_POST['suffixName']);
    $birthDate        = sanitize_input($_POST['birthDate']);
    $residency_start  = sanitize_input($_POST['residency_start']);
    $birthPlace       = sanitize_input($_POST['birthPlace']);
    $gender           = sanitize_input($_POST['gender']);
    $contactNumber    = sanitize_input($_POST['contactNumber']);
    $civilStatus      = sanitize_input($_POST['civilStatus']);
    $email            = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $usernameField    = sanitize_input($_POST['username'] ?? ''); // This is the auto-generated username
    $province_id      = sanitize_input($_POST['province']);
    $city_municipality_id = sanitize_input($_POST['city_municipality']);
    $barangay_id      = sanitize_input($_POST['barangay']);
    $res_zone         = sanitize_input($_POST['res_zone']);
    $zone_leader      = sanitize_input($_POST['zone_leader']);
    $res_street_address = sanitize_input($_POST['res_street_address']);
    $citizenship      = sanitize_input($_POST['citizenship']);
    $religion         = sanitize_input($_POST['religion']);
    $occupation       = sanitize_input($_POST['occupation']);
    $employee_id      = $_SESSION['employee_id'];

    // Username Logic
    $base_username = slugify_lower($firstName . $lastName);
    $login_username = !empty($usernameField) ? strtolower($usernameField) : $base_username;
    if (empty($login_username)) {
        $login_username = $base_username;
    }

    $required = [$firstName, $lastName, $birthDate, $gender, $contactNumber, $res_zone, $res_street_address, $citizenship, $religion, $occupation];
    foreach ($required as $v) {
        if (empty($v)) {
            swal_and_redirect('error','Error','Missing required field for primary resident.',$resbaseUrl);
        }
    }
    
    if (empty($login_username)) {
        swal_and_redirect('error','Invalid Name','First and Last name must contain letters or numbers to generate a username.',$resbaseUrl);
    }

    // Duplicate NAME
    $stmt = $mysqli->prepare("
        SELECT id FROM residents
        WHERE first_name = ? AND middle_name <=> ? AND last_name = ? AND suffix_name <=> ?
          AND resident_delete_status = 0
    ");
    $stmt->bind_param("ssss", $firstName, $middleName, $lastName, $suffixName);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        swal_and_redirect('error','Duplicate Entry','Primary resident with the same name already exists (active).',$resbaseUrl);
    }
    $stmt->close();

    // Duplicate EMAIL
    if (!empty($email)) {
        $stmt = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0 LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            swal_and_redirect('error','Duplicate Email','That email is already used by an active resident.',$resbaseUrl);
        }
        $stmt->close();
    }
    
    // Age + passwords
    $birthDateObj = new DateTime($birthDate);
    $today        = new DateTime();
    $age          = $today->diff($birthDateObj)->y;
    $raw_password = generatePassword();
    $password     = password_hash($raw_password, PASSWORD_DEFAULT);

    // Tx begins
    $mysqli->begin_transaction();
    try {
        // Insert primary
        $stmt = $mysqli->prepare(
            "INSERT INTO residents(
                employee_id, zone_leader_id, username, password, temp_password,
                first_name, middle_name, last_name, suffix_name, gender, civil_status,
                birth_date, residency_start, birth_place, age, contact_number, email,
                res_province, res_city_municipality, res_barangay, res_zone, res_street_address,
                citizenship, religion, occupation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->bind_param(
            "iissssssssssssissiiisssss",
            $employee_id, $zone_leader, $login_username, $password, $raw_password,
            $firstName, $middleName, $lastName, $suffixName,
            $gender, $civilStatus, $birthDate, $residency_start, $birthPlace, $age, $contactNumber,
            $email, $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address,
            $citizenship, $religion, $occupation
        );
        if (!$stmt->execute()) { throw new Exception("Error inserting primary resident: " . $stmt->error); }

        $primary_resident_id = $mysqli->insert_id;
        $trigs->isAdded(2, $primary_resident_id);

        // Username Duplication Fix
        $final_username = $login_username; 
        $check_stmt = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND id != ? AND resident_delete_status = 0 LIMIT 1");
        $check_stmt->bind_param("si", $login_username, $primary_resident_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $final_username = $login_username . $primary_resident_id; 
            $update_stmt = $mysqli->prepare("UPDATE residents SET username = ? WHERE id = ?");
            $update_stmt->bind_param("si", $final_username, $primary_resident_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        $check_stmt->close();

        /* ----- Family members (optional) ----- */
        $famPasswords = [];
        if (isset($_POST['family_firstName']) && is_array($_POST['family_firstName'])) {
            $family_first_names     = $_POST['family_firstName'];
            $family_middle_names    = $_POST['family_middleName'] ?? [];
            $family_last_names      = $_POST['family_lastName'] ?? [];
            $family_suffix_names    = $_POST['family_suffixName'] ?? [];
            $family_birth_dates     = $_POST['family_birthDate'] ?? [];
            $family_genders         = $_POST['family_gender'] ?? [];
            $family_birthplace      = $_POST['family_birthplace'] ?? [];
            $family_relationships   = $_POST['family_relationship'] ?? [];
            $family_contact_numbers = $_POST['family_contactNumber'] ?? [];
            $family_civil_statuses  = $_POST['family_civilStatus'] ?? [];
            $family_occupations     = $_POST['family_occupation'] ?? [];
            $family_emails          = $_POST['family_email'] ?? [];
            $family_usernames       = $_POST['family_username'] ?? [];

            for ($i = 0; $i < count($family_first_names); $i++) {
                if (empty($family_first_names[$i]) || empty($family_last_names[$i]) || empty($family_birth_dates[$i]) || empty($family_genders[$i])) {
                    continue;
                }

                $fam_firstName = sanitize_input($family_first_names[$i]);
                $fam_middleName = sanitize_input($family_middle_names[$i] ?? '');
                $fam_lastName   = sanitize_input($family_last_names[$i]);
                $fam_suffixName = sanitize_input($family_suffix_names[$i] ?? '');

                if (
                    strcasecmp($fam_firstName, $firstName) === 0 &&
                    strcasecmp($fam_middleName, $middleName) === 0 &&
                    strcasecmp($fam_lastName, $lastName) === 0 &&
                    strcasecmp($fam_suffixName, $suffixName) === 0
                ) {
                    throw new Exception("❌ Error: A family member has the same full name as the primary resident.");
                }

                $fam_birthDate   = sanitize_input($family_birth_dates[$i]);
                $fam_gender      = sanitize_input($family_genders[$i]);
                $fam_birthplace  = sanitize_input($family_birthplace[$i] ?? '');
                $fam_relationship= sanitize_input($family_relationships[$i] ?? '');
                $fam_contact     = sanitize_input($family_contact_numbers[$i] ?? '0000000000');
                $fam_civilStatus = sanitize_input($family_civil_statuses[$i] ?? 'Single');
                $fam_occupation  = sanitize_input($family_occupations[$i] ?? '');
                $fam_email       = filter_var($family_emails[$i] ?? '', FILTER_SANITIZE_EMAIL);
                $fam_username_f  = sanitize_input($family_usernames[$i] ?? ''); 
                
                $fam_base_username = slugify_lower($fam_firstName . $fam_lastName);
                $fam_login_username = !empty($fam_username_f) ? strtolower($fam_username_f) : $fam_base_username;
                if (empty($fam_login_username)) { $fam_login_username = $fam_base_username; }
                if (empty($fam_login_username)) {
                      throw new Exception("Child #".($i+1).": First/Last name cannot be empty.");
                }

                if (!empty($fam_email)) {
                    $chk = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0 LIMIT 1");
                    $chk->bind_param("s", $fam_email);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        throw new Exception("Child #".($i+1).": Email '{$fam_email}' is already used by an active resident.");
                    }
                    $chk->close();
                }

                $fam_birthDateObj = new DateTime($fam_birthDate);
                $fam_age          = $today->diff($fam_birthDateObj)->y;
                $fam_raw_password = generatePassword();
                $fam_password     = password_hash($fam_raw_password, PASSWORD_DEFAULT);
                $famPasswords[$i] = ['email' => $fam_email, 'pass' => $fam_raw_password];

                $fam_stmt = $mysqli->prepare(
                    "INSERT INTO residents (
                        employee_id, zone_leader_id, username, password, temp_password,
                        first_name, middle_name, last_name, suffix_name, gender, civil_status,
                        birth_date, residency_start, birth_place, age, contact_number, email,
                        res_province, res_city_municipality, res_barangay, res_zone, res_street_address,
                        citizenship, religion, occupation
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $fam_stmt->bind_param(
                    "iissssssssssssissiiisssss",
                    $employee_id, $zone_leader, $fam_login_username, $fam_password, $fam_raw_password,
                    $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName,
                    $fam_gender, $fam_civilStatus, $fam_birthDate, $residency_start, $fam_birthplace, $fam_age, $fam_contact, $fam_email,
                    $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address, $citizenship, $religion, $fam_occupation
                );
                if (!$fam_stmt->execute()) {
                    throw new Exception("Error inserting family member: " . $fam_stmt->error);
                }
                $family_member_id = $mysqli->insert_id;
                $trigs->isAdded(2, $family_member_id);

                $fam_final_username = $fam_login_username;
                $check_stmt_fam = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND id != ? AND resident_delete_status = 0 LIMIT 1");
                $check_stmt_fam->bind_param("si", $fam_login_username, $family_member_id);
                $check_stmt_fam->execute();
                
                if ($check_stmt_fam->get_result()->num_rows > 0) {
                    $fam_final_username = $fam_base_username . $family_member_id;
                    $update_stmt_fam = $mysqli->prepare("UPDATE residents SET username = ? WHERE id = ?");
                    $update_stmt_fam->bind_param("si", $fam_final_username, $family_member_id);
                    $update_stmt_fam->execute();
                    $update_stmt_fam->close();
                }
                $check_stmt_fam->close();

                if (!empty($fam_relationship)) {
                    $rel_stmt = $mysqli->prepare(
                        "INSERT INTO resident_relationships (resident_id, related_resident_id, relationship_type, created_by, created_at)
                         VALUES (?, ?, ?, ?, NOW())"
                    );
                    $rel_stmt->bind_param("iisi", $primary_resident_id, $family_member_id, $fam_relationship, $employee_id);
                    if (!$rel_stmt->execute()) {
                        throw new Exception("Error inserting relationship: " . $rel_stmt->error);
                    }
                }
            }
        }

        $mysqli->commit();
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendCredentials($email, $raw_password);
        }
        if (!empty($famPasswords)) {
            foreach ($famPasswords as $fp) {
                if (!empty($fp['email']) && filter_var($fp['email'], FILTER_VALIDATE_EMAIL)) {
                    sendCredentials($fp['email'], $fp['pass']);
                }
            }
        }

        $family_count = isset($_POST['family_firstName']) ? count(array_filter($_POST['family_firstName'])) : 0;
        $success_message = "Primary resident added successfully";
        if ($family_count > 0) {
            $success_message .= " along with {$family_count} family member(s)";
        }
        $success_message .= ".";

        swal_and_redirect('success','Success!',$success_message,$resbaseUrl);

    } catch (Exception $e) {
        $mysqli->rollback();
        swal_and_redirect('error','Oops!',$e->getMessage(),$resbaseUrl);
    }
}


/* ---------------- Data for modal selects ---------------- */

$zones = $mysqli->query("SELECT Id, Zone_Name FROM zone")->fetch_all(MYSQLI_ASSOC);
$provinces = $mysqli->query("SELECT province_id, province_name FROM province")->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT 
    id, first_name, middle_name, last_name, suffix_name,
    gender, res_zone, contact_number, email, civil_status, 
    birth_date, residency_start, age, birth_place, 
    res_street_address, citizenship, religion, occupation, username, temp_password
  FROM residents
  WHERE resident_delete_status = 0 
    AND CONCAT(first_name, ' ', IFNULL(middle_name,''), ' ', last_name) LIKE ?";

if (!empty($_GET['filter_gender'])) $sql .= " AND gender = ?";
if (!empty($_GET['filter_zone']))   $sql .= " AND res_zone = ?";
if (!empty($_GET['filter_status'])) $sql .= " AND civil_status = ?";

$sql .= " LIMIT ? OFFSET ?";

$searchTerm = "%$search%";
$params = [$searchTerm]; $types = 's';

if (!empty($_GET['filter_gender'])) { $params[] = $_GET['filter_gender']; $types .= 's'; }
if (!empty($_GET['filter_zone']))   { $params[] = $_GET['filter_zone'];   $types .= 's'; }
if (!empty($_GET['filter_status'])) { $params[] = $_GET['filter_status']; $types .= 's'; }

$params[] = $limit;  $params[] = $offset; $types .= 'ii';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
?>

<script>window.CSRF_TOKEN = "<?= $_SESSION['csrf_token'] ?>";</script>

<script>
// SLUGIFY HELPER FUNCTION
function slugify(text) {
  if (!text) return '';
  return text.toString().toLowerCase()
    .replace(/\s+/g, '')     // Remove spaces
    .replace(/[^\w-]+/g, '') // Remove non-word chars
    .replace(/--+/g, '-')    // Replace multiple - with single -
    .replace(/^-+/, '')      // Trim - from start of text
    .replace(/-+$/, '');     // Trim - from end of text
}

function setupChildUsernameGenerator(container) {
  const firstNameInput = container.querySelector(".child-first-name");
  const lastNameInput = container.querySelector(".child-last-name");
  const usernameInput = container.querySelector(".family-username"); 

  if (!firstNameInput || !lastNameInput || !usernameInput) {
    console.warn('Child username generator: missing one or more fields in container.', container);
    return;
  }

  usernameInput.readOnly = true;
  usernameInput.placeholder = "Auto-generated";

  function updateChildUsername() {
    const first = slugify(firstNameInput.value || '');
    const last = slugify(lastNameInput.value || '');
    usernameInput.value = first + last;
  }

  firstNameInput.addEventListener('input', updateChildUsername);
  lastNameInput.addEventListener('input', updateChildUsername);
  updateChildUsername(); 
}

function generateFamilyMemberFields(prefix) {
  return `
    <div class="row mb-3">
      <div class="col-md-3">
        <small>First Name<span class="text-danger">*</span></small>
        <input type="text" class="form-control child-first-name" name="${prefix}_firstName[]" placeholder="First Name *" required>
      </div>
      <div class="col-md-3">
        <small>Middle Name</small>
        <input type="text" class="form-control child-middle-name" name="${prefix}_middleName[]" placeholder="Middle Name">
      </div>
      <div class="col-md-3">
        <small>Last Name<span class="text-danger">*</span></small>
        <input type="text" class="form-control child-last-name" name="${prefix}_lastName[]" placeholder="Last Name *" required>
        <div class="child-name-feedback invalid-feedback"></div>
      </div>
      <div class="col-md-3">
        <small>Suffix</small>
        <input type="text" class="form-control child-suffix-name" name="${prefix}_suffixName[]" placeholder="Suffix">
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Birthdate<span class="text-danger">*</span></small>
        <input type="date" class="form-control" name="${prefix}_birthDate[]" required>
      </div>
      <div class="col-md-3">
        <small>Gender<span class="text-danger">*</span></small>
        <select class="form-select" name="${prefix}_gender[]" required>
          <option value="" disabled selected>Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>
      <div class="col-md-3">
        <small>Relationship<span class="text-danger">*</span></small>
        <select class="form-select" name="${prefix}_relationship[]" required>
          <option value="">Select Relationship</option>
          <option value="Child">Child</option>
        </select>
      </div>
      <div class="col-md-3">
        <small>Contact Number<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_contactNumber[]" placeholder="Contact Number" required>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Civil Status<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_civilStatus[]" placeholder="Civil Status" required>
      </div>
      <div class="col-md-3">
        <small>Occupation<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_occupation[]" placeholder="Occupation" required>
      </div>

      <div class="col-md-3 family-email-wrapper">
        <small>Email (Optional)</small>
        <input type="email" class="form-control family-email" name="${prefix}_email[]" placeholder="Email (if available)">
      </div>
      <div class="col-md-3 family-username-wrapper">
        <small>Username</small>
        <input type="text" class="form-control family-username" name="${prefix}_username[]" readonly>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-3">
        <small>Birth Place<span class="text-danger">*</span></small>
        <input type="text" class="form-control" name="${prefix}_birthplace[]" placeholder="Birth Place" required>
      </div>
    </div>
  `;
}
</script>


<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee List</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/Resident/res.css">
</head>

<body>

<div class="container my-5">
    <h2><i class="fas fa-users"></i> Resident List</h2>
    
<div class="d-flex justify-content-start mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addResidentModal">
        <i class="fas fa-user-plus"></i> Add Resident
    </button>

<?php $linkbaseUrl = enc_encoder('families'); ?>

<a href="<?= $linkbaseUrl ?>" class="btn btn-info ms-2">
    <i class="fas fa-users"></i> View Linked Families
</a>

</div>

<form id="batchUploadForm" class="mb-2 p-3 border rounded bg-light">
    <label for="excel_file" class="form-label fw-bold mb-1">
        <i class="fa-solid fa-file-excel me-1 text-success"></i> Batch Upload Residents
    </label>
    <div class="input-group">
        <input type="file" name="excel_file" id="excel_file" class="form-control" accept=".xlsx, .xls" required>
        <button type="button" class="btn btn-primary" onclick="startBatchUpload()">
            <i class="fas fa-upload"></i> Start Import
        </button>
    </div>
    <small class="text-muted">Select an Excel file to upload multiple residents at once. Wait for progress bar.</small>
</form>
<form method="GET" action="index_barangay_staff.php" class="row g-2 mb-3">
  <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'resident_info' ?>">

  <div class="col-md-2">
    <select name="filter_gender" class="form-select">
      <option value="">All Genders</option>
      <option value="Male" <?= ($_GET['filter_gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
      <option value="Female" <?= ($_GET['filter_gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
    </select>
  </div>

  <div class="col-md-2">
    <select name="filter_zone" class="form-select">
      <option value="">All Zones</option>
      <?php foreach ($zones as $zone): ?>
        <option value="<?= $zone['Zone_Name'] ?>" <?= ($_GET['filter_zone'] ?? '') === $zone['Zone_Name'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($zone['Zone_Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-2">
    <select name="filter_status" class="form-select">
      <option value="">All Status</option>
      <option value="Single" <?= ($_GET['filter_status'] ?? '') === 'Single' ? 'selected' : '' ?>>Single</option>
      <option value="Married" <?= ($_GET['filter_status'] ?? '') === 'Married' ? 'selected' : '' ?>>Married</option>
      <option value="Widowed" <?= ($_GET['filter_status'] ?? '') === 'Widowed' ? 'selected' : '' ?>>Widowed</option>
      <option value="Divorced" <?= ($_GET['filter_status'] ?? '') === 'Divorced' ? 'selected' : '' ?>>Divorced</option>
    </select>
  </div>

  <div class="col-md-3">
    <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" class="form-control" placeholder="Search name...">
  </div>

  <div class="col-md-1">
    <button type="submit" class="btn btn-primary w-100">Search/Filter</button>
  </div>

  <div class="col-md-2">
    <?php $resbaseUrl = enc_encoder('resident_info'); ?>
    <a href="<?= $resbaseUrl ?>" class="btn btn-secondary w-100">Reset</a>
  </div>
</form>

    
    <div class="card shadow-sm mb-4">

  <div class="card-header bg-primary text-white">
    👥 Resident List
  </div>
  <div class="card-body p-0">
    <div class="table-responsive w-100" style="height: 400px; overflow-y: auto;">

<table class="table table-bordered table-striped table-hover w-100 mb-0" style="table-layout: auto;">

<thead>
    <tr>
        <th style="width: 200px;">Last Name</th>
        <th style="width: 200px;">First Name</th>
        <th style="width: 200px;">Middle Name</th>
        <th style="width: 200px;">Extension</th>
        <th style="width: 200px;">Address</th>
        <th style="width: 200px;">Birthdate</th>
        <th style="width: 200px;">Sex</th>
        <th style="width: 200px;">Status</th>
        <th style="width: 200px;">Occupation</th>
        <th style="width: 200px;">Actions</th> </tr>
</thead>

<tbody id="residentTableBody">
<?php
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        include 'components/resident_modal/resident_row.php';
    }
} else {
    echo '<tr><td colspan="5" class="text-center">No residents found.</td></tr>';
}
?>
</tbody>

</table>
<?php include 'components/resident_modal/view_modal.php'; ?>
<?php include 'components/resident_modal/edit_modal.php'; ?>
<?php include 'components/resident_modal/add_modal.php'; ?>
<?php include 'components/resident_modal/link_modal.php'; ?>

<div class="modal fade" id="batchProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Batch Import Progress</h5>
      </div>
      <div class="modal-body text-center">
        <h3 id="progressText" class="mb-3">Preparing...</h3>
        
        <div class="progress" style="height: 25px;">
          <div id="batchProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
               role="progressbar" style="width: 0%">0%</div>
        </div>
        
        <p id="progressDetail" class="mt-2 text-muted">Please wait, do not close this window.</p>
        
        <div id="uploadErrors" class="text-start mt-3 text-danger" style="display:none; max-height:100px; overflow-y:auto; font-size:0.85em;">
            <strong>Errors:</strong><br>
            <ul id="errorList"></ul>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
    const searchEl = document.getElementById('searchResidentInput');
    if (!searchEl) return;
    const tbody = document.getElementById('residentTableBody');
    
    searchEl.addEventListener('input', function () {
        const query = this.value.trim();
        fetch('./Search/search_residents.php?q=' + encodeURIComponent(query))
            .then(res => res.text())
            .then(data => {
                if (tbody) tbody.innerHTML = data;
            })
            .catch(err => {
                console.error("Error loading residents:", err);
            });
    });
})();

(function () {
    const childSelect = document.getElementById("childSelect");
    if (!childSelect) return;
    
    childSelect.addEventListener("change", function() {
        const selectedOption = this.options[this.selectedIndex];
        const childLast = selectedOption.getAttribute("data-lastname").toLowerCase();
        const childMiddle = selectedOption.getAttribute("data-middlename").toLowerCase();

        const parentSelect = document.getElementById("parentSelect");
        if (!parentSelect) return;
        
        for (let option of parentSelect.options) {
            const parentLast = option.getAttribute("data-lastname")?.toLowerCase();
            if (parentLast && (parentLast === childLast || parentLast === childMiddle)) {
                option.style.display = "block";
            } else {
                option.style.display = "none";
            }
        }
        parentSelect.value = "";
    });
})();
</script>

</div>
<?php
$baseUrl    = enc_encoder('resident_info'); 
$pageParam = ['page' => $_GET['page'] ?? 'resident_info'];
$filters    = array_filter([
    'search'        => $_GET['search'] ?? '',
    'filter_gender' => $_GET['filter_gender'] ?? '',
    'filter_zone'   => $_GET['filter_zone'] ?? '',
    'filter_status' => $_GET['filter_status'] ?? ''
]);
$qs = '&' . http_build_query(array_merge($pageParam, $filters));

// Window compute
$window = 2;
$start  = max(1, $page - $window);
$end    = min($total_pages, $page + $window);

if ($start > 1 && ($end - $start) < $window * 2) {
    $start = max(1, $end - $window * 2);
}
if ($end < $total_pages && ($end - $start) < $window * 2) {
    $end = min($total_pages, $start + $window * 2);
}
?>

<nav aria-label="Page navigation" class="mt-3">
  <ul class="pagination justify-content-end">

    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=1' ?>"><i class="fa fa-angle-double-left"></i></a>
      </li>
    <?php endif; ?>

    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . ($page - 1) ?>"><i class="fa fa-angle-left"></i></a>
      </li>
    <?php endif; ?>

    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . $i ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>

    <?php if ($end < $total_pages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . ($page + 1) ?>"><i class="fa fa-angle-right"></i></a>
      </li>
    <?php endif; ?>

    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i></span></li>
    <?php else: ?>
      <li class="page-item">
        <a class="page-link" href="<?= $baseUrl . $qs . '&pagenum=' . $total_pages ?>"><i class="fa fa-angle-double-right"></i></a>
      </li>
    <?php endif; ?>

  </ul>
</nav>
<script src="components/resident_modal/email.js"></script>

<script>
// ================== AJAX BATCH UPLOAD SCRIPT ==================
async function startBatchUpload() {
    const fileInput = document.getElementById('excel_file');
    if (!fileInput.files.length) {
        Swal.fire('Error', 'Please select a file first.', 'error');
        return;
    }

    // 1. Show Modal
    const modalEl = document.getElementById('batchProgressModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    const pText   = document.getElementById('progressText');
    const pDetail = document.getElementById('progressDetail');
    const pBar    = document.getElementById('batchProgressBar');
    const errDiv  = document.getElementById('uploadErrors');
    const errList = document.getElementById('errorList');
    
    // Reset UI
    pText.innerText = "Analyzing file...";
    pBar.style.width = "0%";
    pBar.innerText = "0%";
    errDiv.style.display = 'none';
    errList.innerHTML = '';
    
    // 2. Upload File for Preview (Get Count)
    const formData = new FormData();
    formData.append('excel_file', fileInput.files[0]);
    formData.append('csrf_token', window.CSRF_TOKEN);
    formData.append('action', 'parse_excel_preview'); 

    try {
        // Updated Path: Point to the file location (relative to index_barangay_staff.php which is likely in root)
        // If resident_info.php is in Modules/staff_modules/, we must call it there.
        const response = await fetch('Modules/staff_modules/resident_info.php', { method: 'POST', body: formData });
        
        const data = await response.json();

        if (data.status !== 'success') {
            throw new Error(data.message || 'Failed to parse file.');
        }

        const totalRows = data.total_rows;
        if (totalRows === 0) {
            throw new Error('File appears to be empty or has no valid data.');
        }

        // 3. Start Loop
        let successCount = 0;
        let skipCount = 0;

        for (let i = 0; i < totalRows; i++) {
            pText.innerText = `Processing ${i + 1} of ${totalRows}`;
            pDetail.innerText = `Importing row ${i + 1}...`;
            
            const pct = Math.round(((i) / totalRows) * 100);
            pBar.style.width = `${pct}%`;
            pBar.innerText = `${i}/${totalRows}`; 

            const rowData = new FormData();
            rowData.append('action', 'process_single_row');
            rowData.append('index', i);
            rowData.append('csrf_token', window.CSRF_TOKEN);

            const rowRes = await fetch('Modules/staff_modules/resident_info.php', { method: 'POST', body: rowData });
            const rowResult = await rowRes.json();

            if (rowResult.status === 'success') {
                successCount++;
            } else if (rowResult.status === 'skipped') {
                skipCount++;
            } else {
                errDiv.style.display = 'block';
                const li = document.createElement('li');
                li.innerText = `Row ${i+1}: ${rowResult.message}`;
                errList.appendChild(li);
            }
        }

        // 4. Finish
        pBar.style.width = "100%";
        pBar.innerText = "Done!";
        pText.innerText = "Import Complete";
        
        setTimeout(() => {
            modal.hide();
            Swal.fire({
                icon: 'success',
                title: 'Batch Import Finished',
                html: `Successfully imported: <b>${successCount}</b><br>Skipped (Duplicates/Errors): <b>${skipCount}</b>`,
                allowOutsideClick: false
            }).then(() => {
                location.reload(); 
            });
        }, 1000);

    } catch (error) {
        modal.hide();
        console.error(error);
        Swal.fire('Import Failed', error.message + " (Check console for details)", 'error');
    }
}
</script>

<script>

let familyMemberCount = 0;
let editFamilyMemberCount = 0;

function toggleFamilySection() {
    const checkbox = document.getElementById('addFamilyMembers');
    const section = document.getElementById('familyMembersSection');

    if (checkbox.checked) {
        section.style.display = 'block';
        if (familyMemberCount === 0) {
            addFamilyMember();
        }
    } else {
        section.style.display = 'none';
        document.getElementById('familyMembersContainer').innerHTML = '';
        familyMemberCount = 0;
    }
}

function addFamilyMember() {
  familyMemberCount++;
  const container = document.getElementById('familyMembersContainer');
  if (!container) return;

  const html = `
    <div class="family-member border rounded p-3 mb-3 bg-light" id="familyMember${familyMemberCount}">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-primary mb-0"><i class="fas fa-user-friends"></i> Child #${familyMemberCount}</h6>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeFamilyMember(${familyMemberCount})">
          <i class="fas fa-trash"></i> Remove
        </button>
      </div>
      ${generateFamilyMemberFields('family')}
    </div>
  `;

  container.insertAdjacentHTML('beforeend', html);

  const newBlock = document.getElementById(`familyMember${familyMemberCount}`);
  if (newBlock) {
      setupChildUsernameGenerator(newBlock);
      applyEmailUsernameToggle(newBlock);
  }

  setupChildNameValidation();
}


function removeFamilyMember(id) {
    const el = document.getElementById(`familyMember${id}`);
    if (el) el.remove();
    familyMemberCount--;

    const members = document.querySelectorAll('#familyMembersContainer .family-member');
    members.forEach((el, index) => {
        const h6 = el.querySelector('h6');
        if (h6) h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
    });
}

// ---------- Edit Modal Version ----------

function toggleEditFamilySection() {
    const checkbox = document.getElementById('editAddFamilyMembers');
    const section = document.getElementById('editFamilyMembersSection');
    if (!checkbox || !section) return;

    if (checkbox.checked) {
        section.style.display = 'block';
        if (editFamilyMemberCount === 0) {
            addEditFamilyMember();
        }
    } else {
        section.style.display = 'none';
        const c = document.getElementById('editFamilyMembersContainer');
        if (c) c.innerHTML = '';
        editFamilyMemberCount = 0;
    }
}

function addEditFamilyMember() {
  editFamilyMemberCount++;
  const container = document.getElementById('editFamilyMembersContainer');
  if (!container) return;

  const html = `
    <div class="family-member border rounded p-3 mb-3 bg-light" id="editFamilyMember${editFamilyMemberCount}">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-primary mb-0"><i class="fas fa-user-friends"></i> Child #${editFamilyMemberCount}</h6>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeEditFamilyMember(${editFamilyMemberCount})">
          <i class="fas fa-trash"></i> Remove
        </button>
      </div>
      ${generateFamilyMemberFields('edit_family')}
    </div>
  `;

  container.insertAdjacentHTML('beforeend', html);

  const newEditBlock = document.getElementById(`editFamilyMember${editFamilyMemberCount}`);
  if (newEditBlock) {
      setupChildUsernameGenerator(newEditBlock);
      applyEmailUsernameToggle(newEditBlock);
  }

  setupChildNameValidation();
}


function removeEditFamilyMember(id) {
    const el = document.getElementById(`editFamilyMember${id}`);
    if (el) el.remove();
    editFamilyMemberCount--;

    const members = document.querySelectorAll('#editFamilyMembersContainer .family-member');
    members.forEach((el, index) => {
        const h6 = el.querySelector('h6');
        if (h6) h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
    });
}

function setupChildNameValidation() {
  const container = document.getElementById('familyMembersContainer');
  if (!container) return;

  const selectors = ['.child-first-name', '.child-middle-name', '.child-last-name', '.child-suffix-name'];

  selectors.forEach(selector => {
    container.querySelectorAll(selector).forEach(input => {
      input.addEventListener('blur', checkForDuplicateNames);
    });
  });

  const primarySelectors = ['.primary-first-name', '.primary-middle-name', '.primary-last-name', '.primary-suffix-name'];
  primarySelectors.forEach(selector => {
    const el = document.querySelector(selector);
    if (el) {
        el.addEventListener('blur', checkForDuplicateNames);
    }
  });
}


function checkForDuplicateNames() {
  const container = document.getElementById('familyMembersContainer');
  if (!container) return;
    
  const allMembers = container.querySelectorAll('.family-member');
  const names = [];

  const primaryFirst  = (document.querySelector('.primary-first-name')?.value || '').trim().toLowerCase();
  const primaryMiddle = (document.querySelector('.primary-middle-name')?.value || '').trim().toLowerCase();
  const primaryLast   = (document.querySelector('.primary-last-name')?.value || '').trim().toLowerCase();
  const primarySuffix = (document.querySelector('.primary-suffix-name')?.value || '').trim().toLowerCase();

  allMembers.forEach(member => {
    const first  = member.querySelector('.child-first-name')?.value.trim().toLowerCase()  || '';
    const middle = member.querySelector('.child-middle-name')?.value.trim().toLowerCase() || '';
    const last   = member.querySelector('.child-last-name')?.value.trim().toLowerCase()   || '';
    const suffix = member.querySelector('.child-suffix-name')?.value.trim().toLowerCase() || '';

    names.push({ first, middle, last, suffix, element: member });
  });

  names.forEach((current, i) => {
    const feedback = current.element.querySelector('.child-name-feedback');
    const firstInput  = current.element.querySelector('.child-first-name');
    const middleInput = current.element.querySelector('.child-middle-name');
    const lastInput   = current.element.querySelector('.child-last-name');
    const suffixInput = current.element.querySelector('.child-suffix-name');

    if (!feedback || !firstInput || !lastInput) return;

    let isDuplicate = false;

    for (let j = 0; j < names.length; j++) {
      if (i !== j &&
          current.first === names[j].first &&
          current.middle === names[j].middle &&
          current.last === names[j].last &&
          current.suffix === names[j].suffix
      ) {
        isDuplicate = true;
        break;
      }
    }

    const matchesPrimary = (
      current.first && current.last && 
      current.first === primaryFirst &&
      current.middle === primaryMiddle &&
      current.last === primaryLast &&
      current.suffix === primarySuffix
    );

    if (isDuplicate || matchesPrimary) {
      [firstInput, middleInput, lastInput, suffixInput].forEach(input => {
        if (input) input.classList.add('is-invalid');
      });

      feedback.textContent = matchesPrimary
        ? "Child's name must not be the same as the primary resident."
        : "Duplicate child name detected.";
      feedback.style.display = 'block';
    } else {
      [firstInput, middleInput, lastInput, suffixInput].forEach(input => {
        if (input) input.classList.remove('is-invalid');
      });
      feedback.textContent = "";
      feedback.style.display = 'none';
    }
  });
}

    $(document).ready(function () {
    $('#province').change(function () {
        let provinceId = $(this).val();
        $('#city_municipality').html('<option value="">Loading...</option>').prop('disabled', true);
        $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);

        $.ajax({
            url: 'include/get_locations.php',
            method: 'POST',
            data: { province_id: provinceId },
            success: function (response) {
                let data = JSON.parse(response);
                if (data.type === 'city_municipality') {
                    $('#city_municipality').html(data.options.join('')).prop('disabled', false);
                }
            }
        });
    });

    $('#city_municipality').change(function () {
        let cityId = $(this).val();
        $('#barangay').html('<option value="">Loading...</option>').prop('disabled', true);

        $.ajax({
            url: 'include/get_locations.php',
            method: 'POST',
            data: { municipality_id: cityId },
            success: function (response) {
                let data = JSON.parse(response);
                if (data.status === 'success' && data.type === 'barangay') {
                    let options = '<option value="">Select Barangay</option>';
                    $.each(data.data, function (index, barangay) {
                        options += '<option value="' + barangay.id + '">' + barangay.name + '</option>';
                    });
                    $('#barangay').html(options).prop('disabled', false);
                }
            }
        });
    });
});


document.getElementById('editForm').addEventListener('submit', function(event) {
    event.preventDefault(); 

    Swal.fire({
        title: 'Are you sure?',
        text: "Do you want to save the changes?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, save it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            this.submit();
        } else {
            Swal.fire({
                icon: 'info',
                title: 'Cancelled',
                text: 'Changes were not saved.',
                confirmButtonColor: '#3085d6'
            });
        }
    });
});
document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("addResidentForm");
  if (!form) return;

  const firstNameInput = form.querySelector('#primary_firstName');
  const lastNameInput = form.querySelector('#primary_lastName');
  const usernameInput = form.querySelector('#primary_username');
  
  function updatePrimaryUsername() {
      if (!firstNameInput || !lastNameInput || !usernameInput) return;
      const first = slugify(firstNameInput.value || '');
      const last = slugify(lastNameInput.value || '');
      usernameInput.value = first + last;
  }
  if (firstNameInput && lastNameInput) {
      firstNameInput.addEventListener('input', updatePrimaryUsername);
      lastNameInput.addEventListener('input', updatePrimaryUsername);
  }

  form.addEventListener("submit", function (e) {
    let valid = true;
    let msg = "";

    const familyBlocks = document.querySelectorAll('#familyMembersContainer .family-member');
    familyBlocks.forEach((block, idx) => {
      const cEmail = (block.querySelector('.family-email')?.value || '').trim();
      const cUser  = (block.querySelector('.family-username')?.value || '').trim();
      
      if (!cEmail && (cUser === 'user' || cUser === '')) {
         valid = false;
         msg = `Child #${idx + 1}: First and Last Name are required to generate a username.`;
      }
    });

    if (!valid) {
      e.preventDefault();
      Swal.fire({
        icon: 'error',
        title: 'Missing Info',
        text: msg
      });
    }
    else {
        e.preventDefault();
        Swal.fire({
            title: 'Confirm Submission',
            text: "Add this resident and any family members?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, add',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
            form.submit();
            }
        });
    }
  });
}); 
$('select[name="res_zone"]').change(function () {
    var selectedZone = $(this).val();

    if (!selectedZone) {
        $('#zone_leader').val('');
        $('#zone_leader_id').val('');
        return;
    }

    $.ajax({
        url: 'include/get_zone_leader.php',
        type: 'POST',
        data: { zone: selectedZone },
        success: function (response) {
            let data = JSON.parse(response);
            if (data.status === 'success') {
                $('#zone_leader').val(data.leader_name); 
                $('#zone_leader_id').val(data.leader_id); 
            } else {
                $('#zone_leader').val('No leader found');
                $('#zone_leader_id').val('');
            }
        }
    });
});

function applyEmailUsernameToggle(container) {
  const emailInput = container.querySelector(".family-email");
  const usernameInput = container.querySelector(".family-username");
  const emailWrapper = container.querySelector(".family-email-wrapper");
  const usernameWrapper = container.querySelector(".family-username-wrapper");

  if (!emailInput || !usernameInput || !emailWrapper || !usernameWrapper) return;

  function toggleFields() {
    if (emailInput.value.trim() !== "") {
      usernameWrapper.style.display = "none";
    } else {
      usernameWrapper.style.display = "";
    }
  }

  emailInput.addEventListener("input", toggleFields);
  toggleFields();
}
</script>
<script>
// ================== HELPERS ==================
function ymdToday(){ return new Date().toISOString().slice(0,10); }
function parseYMD(s){ return new Date(`${s}T00:00:00`); }
function ageFromYMD(s){
  const d=parseYMD(s), t=new Date();
  let a=t.getFullYear()-d.getFullYear();
  const m=t.getMonth()-d.getMonth();
  if(m<0 || (m===0 && t.getDate()<d.getDate())) a--;
  return a;
}
function ensureFeedbackEl(input){
  let fb=input.nextElementSibling;
  if(!fb || !fb.classList?.contains('invalid-feedback')){
    fb=document.createElement('div');
    fb.className='invalid-feedback';
    input.insertAdjacentElement('afterend', fb);
  }
  return fb;
}
function setInvalid(input,msg){
  input.classList.add('is-invalid');
  const fb=ensureFeedbackEl(input);
  fb.textContent=msg; fb.style.display='block';
  input.setCustomValidity(msg);
}
function clearInvalid(input){
  input.classList.remove('is-invalid');
  const fb=input.nextElementSibling;
  if(fb?.classList?.contains('invalid-feedback')){ fb.textContent=''; fb.style.display='none'; }
  input.setCustomValidity('');
}

// ================== VALIDATORS ==================
function validatePrimaryBirthdateEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}
function validateChildBirthdateEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Birthdate cannot be in the future.'); return false; }
  if(ageFromYMD(v)>17){ setInvalid(el,'Family member must be 17 years old or below.'); return false; }
  clearInvalid(el); return true;
}
function validateResidencyStartEl(el){
  const v=(el.value||'').trim(); if(!v){ clearInvalid(el); return true; }
  if(v>ymdToday()){ setInvalid(el,'Residency start cannot be in the future.'); return false; }
  clearInvalid(el); return true;
}

// ================== DELEGATED BINDINGS ==================
document.addEventListener('input', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))          validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))    validateChildBirthdateEl(t);
}, true);

document.addEventListener('change', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))          validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))    validateChildBirthdateEl(t);
}, true);

function enforceResidencyMax(el){ if(el) el.setAttribute('max', ymdToday()); }
document.addEventListener('focusin', (e)=>{
  if(e.target.matches('input[name="residency_start"]')) enforceResidencyMax(e.target);
});

document.addEventListener('shown.bs.modal', (e)=>{
  if(e.target.id==='addResidentModal'){
    enforceResidencyMax(e.target.querySelector('input[name="residency_start"]'));
    e.target.querySelectorAll('input[name="birthDate"]').forEach(validatePrimaryBirthdateEl);
    e.target.querySelectorAll('input[name="residency_start"]').forEach(validateResidencyStartEl);
    e.target.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
  }
});

document.addEventListener('DOMContentLoaded', ()=>{
  enforceResidencyMax(document.querySelector('input[name="residency_start"]'));
  const p=document.querySelector('input[name="birthDate"]'); if(p) validatePrimaryBirthdateEl(p);
  document.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
});

// ================== DYNAMIC FAMILY ROW SUPPORT ==================
(function wrapDynamicAdders(){
  const origAdd=window.addFamilyMember;
  if(typeof origAdd==='function'){
    window.addFamilyMember=function(){
      origAdd.apply(this, arguments);
      const block=document.getElementById(`familyMember${window.familyMemberCount}`);
      if(block) block.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
    };
  }
  const origAddEdit=window.addEditFamilyMember;
  if(typeof origAddEdit==='function'){
    window.addEditFamilyMember=function(){
      origAddEdit.apply(this, arguments);
      const block=document.getElementById(`editFamilyMember${window.editFamilyMemberCount}`);
      if(block) block.querySelectorAll('input[name$="_birthDate[]"]').forEach(validateChildBirthdateEl);
    };
  }
})();
</script>

<script>
function toggleRestriction(residentId){
  Swal.fire({
    title: 'Unrestrict resident?',
    text: 'This will lift the restriction and remove the offending appointments.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Unrestrict'
  }).then(({isConfirmed}) => {
    if(!isConfirmed) return;
    fetch('api/restrictions/unrestrict.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'resident_id=' + encodeURIComponent(residentId) +
            '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(d => {
      if(d?.success){
        const del = d.deleted || {};
        const msg =
          `Restriction lifted. ` +
          `Deleted — schedules: ${del.schedules||0}, cedula: ${del.cedula||0}, ` +
          `urgent req: ${del.urgent_request||0}, urgent cedula: ${del.urgent_cedula_request||0}.`;
        Swal.fire({icon:'success', title:'Unrestricted', text: msg, timer:1800, showConfirmButton:false})
          .then(()=>location.reload());
      }else{
        Swal.fire({icon:'error', title:'Failed', text: d?.error || 'Could not unrestrict.'});
      }
    })
    .catch(err => Swal.fire({icon:'error', title:'Error', text:String(err)}));
  });
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php
if (isset($form_success)) {
    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '" . $form_success . "',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            location.reload();
        });
    </script>";
}

if (isset($form_error)) {
    echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Oops!',
            text: '" . $form_error . "',
            confirmButtonColor: '#d33'
        }); 
    </script>";
}
?>

</body>
</html>