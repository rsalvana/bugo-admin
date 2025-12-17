<?php
// api/resident_info.php
// 1. Start Output Buffering to prevent unwanted text/warnings from breaking JSON
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ----------------------------------------------------------------------
   DEPENDENCIES & DB
   ---------------------------------------------------------------------- */
// Use __DIR__ . '/../' to correctly locate files in the parent directory
require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../include/encryption.php';

$mysqli = db_connection();

// Fix paths for these includes as well
// Assuming 'class' and 'logs' are in the ROOT directory, not inside 'api'
if (file_exists(__DIR__ . '/../class/session_timeout.php')) {
    include __DIR__ . '/../class/session_timeout.php';
} elseif (file_exists('class/session_timeout.php')) {
    include 'class/session_timeout.php';
}

date_default_timezone_set('Asia/Manila');

// Fix path for Logs
if (file_exists(__DIR__ . '/../logs/logs_trig.php')) {
    require_once __DIR__ . '/../logs/logs_trig.php';
} else {
    // Fallback if logs folder is inside api (rare)
    require_once './logs/logs_trig.php';
}
$trigs = new Trigger();

// Fix path for Vendor Autoload (Crucial for Excel Import)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once 'vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ----------------------------------------------------------------------
   HELPERS
   ---------------------------------------------------------------------- */

if (!function_exists('swal_and_redirect')) {
    function swal_and_redirect(string $icon, string $title, string $text, string $url): void {
        // Clear buffer before outputting HTML
        ob_end_flush();
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirect</title></head><body>';
        printf(
            "<script>
                if (typeof Swal === 'undefined') { window.location.href = %s; }
                else {
                    Swal.fire({icon:%s, title:%s, text:%s}).then(()=>{ window.location.href = %s; });
                }
            </script>",
            json_encode($url),
            json_encode($icon),
            json_encode($title),
            json_encode($text),
            json_encode($url)
        );
        echo '</body></html>';
        exit;
    }
}

if (!isset($redirects) || !is_array($redirects)) {
    $redirects = [
        'residents'      => (function_exists('enc_admin')) ? enc_admin('resident_info') : 'index_Admin.php?page=' . urlencode(encrypt('resident_info')),
        'residents_api'  => (function_exists('enc_admin')) ? enc_admin('resident_info') : 'index_Admin.php?page=' . urlencode(encrypt('resident_info')),
        'family'         => (function_exists('enc_admin')) ? enc_admin('family') : 'index_Admin.php?page=' . urlencode(encrypt('family')),
    ];
}
$resbaseUrl = $redirects['residents'];

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim((string)$data)));
}

function generatePassword(int $len = 12): string {
    $base = str_replace(['/', '+', '='], '', base64_encode(random_bytes($len + 2)));
    return substr($base, 0, $len);
}

function slugify_lower(string $s): string {
    // 1. Remove whitespace from sides
    $s = trim($s);
    
    // 2. Convert to lowercase safely
    $s = strtolower($s);
    
    // 3. Remove anything that isn't a letter or number
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    
    return $s !== '' ? $s : 'user';
}

function sendCredentials(string $to, string $password): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'mail.bugoportal.site';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'admin@bugoportal.site';
        $mail->Password    = 'Jayacop@100'; 
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port        = 465;
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
        error_log("sendCredentials exception ({$to}): {$mail->ErrorInfo} | {$e->getMessage()}");
        return false;
    }
}

if (!defined('SEND_EMAILS')) {
    define('SEND_EMAILS', (getenv('SEND_EMAILS') ?: '1') === '1');
}

function sendCredentialsIfPresent(?string $to, string $password): bool {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!SEND_EMAILS) {
        return true;
    }
    return sendCredentials($to, $password);
}

/* ======================================================================
   AJAX HANDLERS (Batch Import Progress Logic)
   ====================================================================== */

// 1. AJAX: Parse Excel File & Return Count (Preview)
if (isset($_POST['action']) && $_POST['action'] === 'parse_excel_preview') {
    // 2. Clear buffer ensuring no HTML warnings precede JSON
    ob_clean(); 
    header('Content-Type: application/json');

    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
        exit;
    }

    if (!isset($_FILES['excel_file']['tmp_name']) || empty($_FILES['excel_file']['tmp_name'])) {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
        exit;
    }

    try {
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
    } catch (Throwable $e) { // Changed to Throwable to catch Fatal Errors
        echo json_encode(['status' => 'error', 'message' => 'Parse Error: ' . $e->getMessage()]);
    }
    exit;
}

// 2. AJAX: Process Single Row (Called in loop by JS)
if (isset($_POST['action']) && $_POST['action'] === 'process_single_row') {
    // 3. Clear buffer again
    ob_clean();
    header('Content-Type: application/json');

    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch.']);
        exit;
    }

    $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;
    
    if ($index < 0 || !isset($_SESSION['batch_import_data']) || !isset($_SESSION['batch_import_data'][$index])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid row index or session expired.']);
        exit;
    }

    $row = $_SESSION['batch_import_data'][$index];

    try {
        // --- Mapping logic based on your Excel structure ---
        $last_name   = sanitize_input($row[0] ?? 'N/A');
        $first_name  = sanitize_input($row[1] ?? 'N/A');
        $middle_name = sanitize_input($row[2] ?? '');
        $suffix_name = sanitize_input($row[3] ?? '');
        $res_zone    = sanitize_input($row[4] ?? 'ZONE N/A');
        
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
             echo json_encode(['status' => 'error', 'message' => 'DB Insert Error: ' . $insert_stmt->error]);
             exit;
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

    } catch (Throwable $e) { // Changed to Throwable to catch Fatal Errors
        echo json_encode(['status' => 'error', 'message' => 'Processing Error: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Flush Buffer for HTML page load
ob_end_flush();

/* ======================================================================
   POST LOGIC: Add Primary + Family (Manual Form)
   ====================================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['firstName'])) {
    
    $can_proceed = true; 

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $form_error = 'CSRF token mismatch. Operation blocked.';
        $can_proceed = false;
    }

    // Primary resident data
    $firstName            = sanitize_input($_POST['firstName']);
    $middleName           = sanitize_input($_POST['middleName']);
    $lastName             = sanitize_input($_POST['lastName']);
    $suffixName           = sanitize_input($_POST['suffixName']);
    $birthDate            = sanitize_input($_POST['birthDate']);
    $residency_start      = sanitize_input($_POST['residency_start']);
    $birthPlace           = sanitize_input($_POST['birthPlace']);
    $gender               = sanitize_input($_POST['gender']);
    $contactNumber        = sanitize_input($_POST['contactNumber']);
    $civilStatus          = sanitize_input($_POST['civilStatus']);
    $email                = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $usernameField        = sanitize_input($_POST['username'] ?? ''); // auto-generated username
    $province_id          = sanitize_input($_POST['province']);
    $city_municipality_id = sanitize_input($_POST['city_municipality']);
    $barangay_id          = sanitize_input($_POST['barangay']);
    $res_zone             = sanitize_input($_POST['res_zone']);
    $zone_leader          = sanitize_input($_POST['zone_leader']);
    $res_street_address   = sanitize_input($_POST['res_street_address']);
    $citizenship          = sanitize_input($_POST['citizenship']);
    $religion             = sanitize_input($_POST['religion']);
    $occupation           = sanitize_input($_POST['occupation']);
    $employee_id          = $_SESSION['employee_id'];

    // ===== NEW USERNAME LOGIC (Manual Add) =====
    $base_username  = slugify_lower($firstName . $lastName);
    $login_username = !empty($usernameField) ? strtolower($usernameField) : $base_username;
    if (empty($login_username)) {
        $login_username = $base_username;
    }
    // ============================================

    // Required validation
    if ($can_proceed) {
        $required = [$firstName, $lastName, $birthDate, $contactNumber, $res_zone, $res_street_address];
        foreach ($required as $value) {
            if (empty($value)) {
                $form_error = 'Missing required field for primary resident.';
                $can_proceed = false;
                break;
            }
        }
    }

    if (empty($login_username)) {
        $form_error = 'Invalid Name: First and Last name must contain letters or numbers to generate a username.';
        $can_proceed = false;
    }

    // Duplicate name (active only)
    if ($can_proceed) {
        $stmt = $mysqli->prepare("SELECT id FROM residents 
            WHERE first_name = ? AND middle_name <=> ? AND last_name = ? AND suffix_name <=> ? 
            AND resident_delete_status = 0");
        $stmt->bind_param("ssss", $firstName, $middleName, $lastName, $suffixName);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $form_error = 'Primary resident with the same name already exists.';
            $can_proceed = false;
        }
        $stmt->close();
    }

    // Duplicate email (active only)
    if ($can_proceed && !empty($email)) {
        $stmt = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $form_error = 'That email is already registered with an active resident.';
            $can_proceed = false;
        }
        $stmt->close();
    }

    if ($can_proceed) {
        $birthDateObj  = new DateTime($birthDate);
        $today         = new DateTime();
        $age           = $today->diff($birthDateObj)->y;
        $raw_password  = generatePassword();
        $password      = password_hash($raw_password, PASSWORD_DEFAULT);

        $mysqli->begin_transaction();

        try {
            // Insert primary
            $stmt = $mysqli->prepare("INSERT INTO residents (
                employee_id, zone_leader_id, username, password, temp_password,
                first_name, middle_name, last_name, suffix_name, gender, civil_status,
                birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
                res_barangay, res_zone, res_street_address, citizenship, religion, occupation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->bind_param(
                "iissssssssssssissiiisssss",
                $employee_id, $zone_leader, $login_username, $password, $raw_password,
                $firstName, $middleName, $lastName, $suffixName,
                $gender, $civilStatus, $birthDate, $residency_start, $birthPlace, $age, $contactNumber,
                $email, $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address,
                $citizenship, $religion, $occupation
            );

            if (!$stmt->execute()) {
                throw new Exception("Error inserting primary resident: " . $stmt->error);
            }

            $primary_resident_id = $mysqli->insert_id;
            $trigs->isAdded(2, $primary_resident_id);

            // ===== NEW USERNAME DUPLICATE CHECK (Manual Add) =====
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
            // =======================================================

            /* ---- FAMILY MEMBERS ---- */
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
                $family_usernames       = $_POST['family_username'] ?? []; // auto-generated one

                for ($i = 0; $i < count($family_first_names); $i++) {
                    if (empty($family_first_names[$i]) || empty($family_last_names[$i]) || empty($family_birth_dates[$i]) || empty($family_genders[$i])) {
                        continue;
                    }

                    $fam_firstName   = sanitize_input($family_first_names[$i]);
                    $fam_middleName  = sanitize_input($family_middle_names[$i] ?? '');
                    $fam_lastName    = sanitize_input($family_last_names[$i]);
                    $fam_suffixName  = sanitize_input($family_suffix_names[$i] ?? '');
                    $fam_birthDate   = sanitize_input($family_birth_dates[$i]);
                    $fam_gender      = sanitize_input($family_genders[$i]);
                    $fam_birthplace  = sanitize_input($family_birthplace[$i] ?? '');
                    $fam_relationship  = sanitize_input($family_relationships[$i] ?? 'Child');
                    $fam_contactNumber = sanitize_input($family_contact_numbers[$i] ?? '0000000000');
                    $fam_civilStatus   = sanitize_input($family_civil_statuses[$i] ?? 'Single');
                    $fam_occupation    = sanitize_input($family_occupations[$i] ?? '');
                    $fam_email         = filter_var($family_emails[$i] ?? '', FILTER_SANITIZE_EMAIL);
                    $fam_username_field= sanitize_input($family_usernames[$i] ?? ''); // Auto-generated

                    if (
                        strcasecmp($fam_firstName, $firstName) === 0 &&
                        strcasecmp($fam_middleName, $middleName) === 0 &&
                        strcasecmp($fam_lastName, $lastName) === 0 &&
                        strcasecmp($fam_suffixName, $suffixName) === 0
                    ) {
                        throw new Exception("❌ Error: A family member has the same full name as the primary resident.");
                    }

                    // ===== NEW USERNAME LOGIC (Child) =====
                    $fam_base_username  = slugify_lower($fam_firstName . $fam_lastName);
                    $fam_login_username = !empty($fam_username_field) ? strtolower($fam_username_field) : $fam_base_username;
                    if (empty($fam_login_username)) { $fam_login_username = $fam_base_username; }
                    if (empty($fam_login_username)) {
                         throw new Exception("Child #".($i+1).": First/Last name cannot be empty.");
                    }
                    // ======================================
                    
                    // Duplicate email (active only)
                    if (!empty($fam_email)) {
                        $chk = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0");
                        $chk->bind_param("s", $fam_email);
                        $chk->execute();
                        $res = $chk->get_result();
                        if ($res && $res->num_rows > 0) {
                            throw new Exception(sprintf('Child #%d: Email already in use by active resident.', $i + 1));
                        }
                        $chk->close();
                    }

                    // Age & password
                    $fam_birthDateObj  = new DateTime($fam_birthDate);
                    $fam_age           = $today->diff($fam_birthDateObj)->y;
                    $fam_raw_password  = generatePassword();
                    $fam_password      = password_hash($fam_raw_password, PASSWORD_DEFAULT);
                    $famPasswords[$i]  = ['email' => $fam_email, 'pass' => $fam_raw_password];

                    // Insert child
                    $fam_stmt = $mysqli->prepare("INSERT INTO residents (
                        employee_id, zone_leader_id, username, password, temp_password,
                        first_name, middle_name, last_name, suffix_name, gender, civil_status,
                        birth_date, residency_start, birth_place, age, contact_number, email, res_province, res_city_municipality,
                        res_barangay, res_zone, res_street_address, citizenship, religion, occupation
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    $fam_stmt->bind_param(
                        "iissssssssssssissiiisssss",
                        $employee_id, $zone_leader, $fam_login_username, $fam_password, $fam_raw_password,
                        $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName,
                        $fam_gender, $fam_civilStatus, $fam_birthDate, $residency_start, $fam_birthplace, $fam_age, $fam_contactNumber, $fam_email,
                        $province_id, $city_municipality_id, $barangay_id, $res_zone, $res_street_address, $citizenship, $religion, $fam_occupation
                    );

                    if (!$fam_stmt->execute()) {
                        throw new Exception("Error inserting family member: " . $fam_stmt->error);
                    }
                    
                    $family_member_id = $mysqli->insert_id;
                    $trigs->isAdded(2, $family_member_id);
                    
                    // ===== NEW USERNAME DUPLICATE CHECK (Child) =====
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
                    // =================================================

                    if (!empty($fam_relationship)) {
                        $rel_stmt = $mysqli->prepare("INSERT INTO resident_relationships 
                            (resident_id, related_resident_id, relationship_type, created_by, created_at) 
                            VALUES (?, ?, ?, ?, NOW())");
                        $rel_stmt->bind_param("iisi", $primary_resident_id, $family_member_id, $fam_relationship, $employee_id);
                        if (!$rel_stmt->execute()) {
                            throw new Exception("Error inserting relationship: " . $rel_stmt->error);
                        }
                    }
                }
            }

            $mysqli->commit();

            sendCredentialsIfPresent($email, $raw_password);

            if (!empty($famPasswords)) {
                foreach ($famPasswords as $fp) {
                    sendCredentialsIfPresent($fp['email'] ?? '', $fp['pass']);
                }
            }

            $family_count = isset($_POST['family_firstName']) ? count(array_filter($_POST['family_firstName'])) : 0;
            $success_message = "Primary resident added successfully";
            if ($family_count > 0) {
                $success_message .= " along with {$family_count} child/children";
            }

            $form_success = addslashes($success_message);

        } catch (Exception $e) {
            $mysqli->rollback();
            $form_error = addslashes($e->getMessage());
        }
    } 
}

/* ======================================================================
   POST LOGIC: Link Parent-Child (Manual Form)
   ====================================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['child_id']) && empty($form_success)) {

    // Check for CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $form_error = 'CSRF token mismatch. Operation blocked.';
    } else {

        $child_id  = (int)sanitize_input($_POST['child_id']);
        $parent_id = (int)sanitize_input($_POST['parent_id']);
        $type      = sanitize_input($_POST['relationship_type']);
        $can_proceed = true;

        // ❌ Prevent self-linking
        if ($child_id == $parent_id) {
            $form_error = "Invalid Link: A person cannot be their own parent.";
            $can_proceed = false;
        }

        // ✅ Age validation (minimum 12-year gap)
        if ($can_proceed) {
            $stmt_child = $mysqli->prepare("SELECT birth_date FROM residents WHERE id = ?");
            $stmt_child->bind_param("i", $child_id);
            $stmt_child->execute();
            $childRes = $stmt_child->get_result();

            $stmt_parent = $mysqli->prepare("SELECT birth_date FROM residents WHERE id = ?");
            $stmt_parent->bind_param("i", $parent_id);
            $stmt_parent->execute();
            $parentRes = $stmt_parent->get_result();

            if ($childRes && $parentRes && $childRes->num_rows && $parentRes->num_rows) {
                $childDOB = new DateTime($childRes->fetch_assoc()['birth_date']);
                $parentDOB = new DateTime($parentRes->fetch_assoc()['birth_date']);
                $gap = $parentDOB->diff($childDOB)->y;

                if ($gap < 12) {
                    $form_error = "Invalid Relationship: Age gap must be at least 12 years.";
                    $can_proceed = false;
                }
            } else {
                $form_error = "Could not find parent or child record for age check.";
                $can_proceed = false;
            }
            $stmt_child->close();
            $stmt_parent->close();
        }

        // ✅ Check for existing same relationship type
        if ($can_proceed) {
            $check = $mysqli->prepare("
                SELECT r.first_name, r.middle_name, r.last_name 
                FROM resident_relationships rr
                JOIN residents r ON rr.resident_id = r.id
                WHERE rr.related_resident_id = ? 
                AND rr.relationship_type = ? 
                AND rr.resident_id = ?
            ");
            $check->bind_param("isi", $child_id, $type, $parent_id);

            $check->execute();
            $existing = $check->get_result();

            if ($existing->num_rows > 0) {
                $e = $existing->fetch_assoc();
                $fullName = trim("{$e['first_name']} {$e['middle_name']} {$e['last_name']}");
                $form_error = "Relationship Exists: This child is already linked to a $type: $fullName.";
                $can_proceed = false;
            }
            $check->close();
        }

        // ✅ Handle file upload (birth certificate)
        $certificate = null;
        if ($can_proceed) {
            if (isset($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['birth_certificate']['tmp_name'];
                $fileType = mime_content_type($fileTmp);

                // Only accept image or PDF
                if (!in_array($fileType, ['application/pdf', 'image/jpeg', 'image/png'])) {
                    $form_error = "Invalid File Type: Only PDF, JPG, and PNG files are allowed.";
                    $can_proceed = false;
                } else {
                    $certificate = file_get_contents($fileTmp);
                }
            }
        }

        // ✅ Insert into database
        if ($can_proceed) {
            $stmt = $mysqli->prepare("
                INSERT INTO resident_relationships (related_resident_id, resident_id, relationship_type, id_birthcertificate, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $null = NULL; // Variable for bind_param
            $stmt->bind_param("iisb", $child_id, $parent_id, $type, $null);
            
            if ($certificate !== null) {
                $stmt->send_long_data(3, $certificate);
            }

            if ($stmt->execute()) {
                $form_success = "Relationship linked successfully! Status is now pending.";
            } else {
                $form_error = "Link Failed: Failed to link relationship. " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

/* ======================================================================
   Pagination + Filters
   ====================================================================== */

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$page   = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? intval($_GET['pagenum']) : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$count_sql = "SELECT COUNT(*) as total FROM residents
              WHERE resident_delete_status = 0
              AND CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) LIKE ?";

$params = ["%$search%"];
$types  = 's';

if (!empty($_GET['filter_gender'])) {
    $count_sql .= " AND gender = ?";
    $params[] = $_GET['filter_gender'];
    $types .= 's';
}
if (!empty($_GET['filter_zone'])) {
    $count_sql .= " AND res_zone = ?";
    $params[] = $_GET['filter_zone'];
    $types .= 's';
}
if (!empty($_GET['filter_status'])) {
    $count_sql .= " AND civil_status = ?";
    $params[] = $_GET['filter_status'];
    $types .= 's';
}

$stmt = $mysqli->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$count_result = $stmt->get_result();
$total_rows   = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages  = max((int)ceil($total_rows / $limit), 1);

if ($page > $total_pages) {
    $page = 1;
}
$offset = ($page - 1) * $limit;

/* Options for Selects */
$zones     = $mysqli->query("SELECT Id, Zone_Name FROM zone")->fetch_all(MYSQLI_ASSOC);
$provinces = $mysqli->query("SELECT province_id, province_name FROM province")->fetch_all(MYSQLI_ASSOC);

/* Main Query */
$sql = "SELECT 
    r.id, 
    r.first_name, r.middle_name, r.last_name, r.suffix_name,
    r.gender, r.res_zone, r.contact_number, r.email, r.civil_status, 
    r.birth_date, r.residency_start, r.age, r.birth_place, 
    r.res_street_address, r.citizenship, r.religion, r.occupation, r.username, r.temp_password,

    /* restriction indicator & details */
    EXISTS(
      SELECT 1 FROM resident_restrictions rr
      WHERE rr.resident_id = r.id AND rr.restricted_until > NOW()
    ) AS is_restricted,

    (SELECT rr.restricted_until FROM resident_restrictions rr
      WHERE rr.resident_id = r.id AND rr.restricted_until > NOW()
      ORDER BY rr.updated_at DESC LIMIT 1) AS restricted_until,

    (SELECT rr.strikes FROM resident_restrictions rr
      WHERE rr.resident_id = r.id AND rr.restricted_until > NOW()
      ORDER BY rr.updated_at DESC LIMIT 1) AS strikes,

    (SELECT rr.reason FROM resident_restrictions rr
      WHERE rr.resident_id = r.id AND rr.restricted_until > NOW()
      ORDER BY rr.updated_at DESC LIMIT 1) AS restriction_reason

FROM residents r
WHERE r.resident_delete_status = 0 
  AND CONCAT(r.first_name, ' ', IFNULL(r.middle_name,''), ' ', r.last_name) LIKE ?";

if (!empty($_GET['filter_gender'])) {
    $sql .= " AND r.gender = ?";
}
if (!empty($_GET['filter_zone'])) {
    $sql .= " AND r.res_zone = ?";
}
if (!empty($_GET['filter_status'])) {
    $sql .= " AND r.civil_status = ?";
}
$sql .= " LIMIT ? OFFSET ?";

// Note: We used $params earlier for count, let's rebuild params for the main query
// because order matters and we added limit/offset.
$params = ["%$search%"];
$types  = 's';

if (!empty($_GET['filter_gender'])) { $params[] = $_GET['filter_gender']; $types .= 's'; }
if (!empty($_GET['filter_zone']))   { $params[] = $_GET['filter_zone'];   $types .= 's'; }
if (!empty($_GET['filter_status'])) { $params[] = $_GET['filter_status']; $types .= 's'; }

$params[] = $limit;
$params[] = $offset;
$types    .= 'ii';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resident List</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/Resident/res.css">
</head>

<body>

<script>window.CSRF_TOKEN = "<?= $_SESSION['csrf_token'] ?>";</script>

<div class="container my-5">
    <h2 class="page-title"><i class="fas fa-users"></i> Resident List</h2>
    
<div class="d-flex justify-content-start mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addResidentModal">
        <i class="fas fa-user-plus"></i> Add Resident
    </button>
    <button class="btn btn-secondary ms-2" data-bs-toggle="modal" data-bs-target="#linkRelationshipModal">
      <i class="fas fa-link"></i> Link Parent-Child
    </button>

    <a href="<?= htmlspecialchars($redirects['family']) ?>" class="btn btn-info ms-2">
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

<form method="GET" action="index_Admin.php" class="row g-2 mb-3 mt-3">
  <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page'] ?? 'resident_info') ?>">

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
        <option value="<?= htmlspecialchars($zone['Zone_Name']) ?>" <?= ($_GET['filter_zone'] ?? '') === $zone['Zone_Name'] ? 'selected' : '' ?>>
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
    <button type="submit"
            class="btn btn-primary w-100 d-flex align-items-center justify-content-center"
            aria-label="Search/Filter" title="Search/Filter">
      <i class="bi bi-search"></i>
    </button>
  </div>

  <div class="col-md-2">
    <a href="<?= htmlspecialchars($resbaseUrl) ?>" class="btn btn-secondary w-100">Reset</a>
  </div>
</form>
    
<div class="card shadow-sm mb-4">
  <div class="card-header bg-primary text-white"> Resident List </div>
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
            <th style="width: 200px;">Actions</th>
          </tr>
        </thead>

        <tbody id="residentTableBody">
        <?php
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                include 'components/resident_modal/resident_row.php';
            }
        } else {
            echo '<tr><td colspan="10" class="text-center">No residents found.</td></tr>';
        }
        ?>
        </tbody>
      </table>

<?php 
// These includes are for your OTHER modals
include 'components/resident_modal/view_modal.php'; 
include 'components/resident_modal/edit_modal.php'; 
include 'components/resident_modal/add_modal.php'; 
?>

<div class="modal fade" id="linkRelationshipModal" tabindex="-1" aria-labelledby="linkRelationshipModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="" class="modal-content" id="linkRelationshipForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

      <div class="modal-header">
        <h5 class="modal-title" id="linkRelationshipModalLabel"><i class="fas fa-link"></i> Link Parent to Child</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body row g-3">
        <?php
        $res = $mysqli->query("SELECT id, first_name, middle_name, last_name, birth_date FROM residents WHERE resident_delete_status = 0");
        $residents = [];
        while ($r = $res->fetch_assoc()) {
          $r['age'] = date_diff(date_create($r['birth_date']), date_create('today'))->y;
          $residents[] = $r;
        }

        $children = array_filter($residents, fn($r) => $r['age'] < 18);
        $adults = array_filter($residents, fn($r) => $r['age'] >= 18);
        ?>

        <div class="col-md-6">
          <label class="form-label">Select Child (Under 18)</label>
          <select class="form-select" name="child_id" id="childSelect" required>
            <option value="">-- Choose Child --</option>
            <?php foreach ($children as $child): ?>
              <?php
                $name = "{$child['first_name']} {$child['middle_name']} {$child['last_name']}";
                echo "<option value='{$child['id']}' data-lastname='{$child['last_name']}' data-middlename='{$child['middle_name']}'>{$name} (Age: {$child['age']})</option>";
              ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Select Matching Parent</label>
          <select class="form-select" name="parent_id" id="parentSelect" required>
            <option value="">-- Choose Matching Parent --</option>
            <?php foreach ($adults as $parent): ?>
              <?php
                $parentName = "{$parent['first_name']} {$parent['middle_name']} {$parent['last_name']}";
                echo "<option value='{$parent['id']}' data-lastname='{$parent['last_name']}' style='display:none;'>{$parentName} (Age: {$parent['age']})</option>";
              ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Relationship Type</label>
          <select class="form-select" name="relationship_type" required>
            <option value="father">Father</option>
            <option value="mother">Mother</option>
            <option value="guardian">Guardian</option>
          </select>
        </div>
        
        <div class="col-md-6">
            <label for="birth_certificate" class="form-label">Birth Certificate (Optional)</label>
            <input class="form-control" type="file" name="birth_certificate" id="birth_certificate" accept=".pdf,.jpg,.jpeg,.png">
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="confirm" id="linkConfirmCheck" required>
            <label class="form-check-label" for="linkConfirmCheck">I confirm this relationship is valid.</label>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Relationship</button>
      </div>
    </form>
  </div>
</div>

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
// This JavaScript handles the confirmation popup for the Link Modal
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('linkRelationshipForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    // Quick client-side check
    const childId = document.getElementById('childSelect')?.value;
    const parentId = document.getElementById('parentSelect')?.value;

    if (childId && parentId && childId === parentId) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Link',
            text: 'A person cannot be their own parent. Please select a different parent or child.'
        });
        return; // Stop submission
    }

    Swal.fire({
      title: 'Confirm Relationship',
      text: 'Are you sure you want to save this relationship?',
      icon: 'question',
      showCancelButton: true,
      cancelButtonText: 'Cancel',
      confirmButtonText: 'Yes, Save it!',
      reverseButtons: false
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit(); // Proceed with actual submission
      }
    });
  });
});
</script>

<script>
document.getElementById("childSelect").addEventListener("change", function() {
    const selectedOption = this.options[this.selectedIndex];
    const childLast = (selectedOption.getAttribute("data-lastname") || '').toLowerCase();
    const childMiddle = (selectedOption.getAttribute("data-middlename") || '').toLowerCase();

    const parentSelect = document.getElementById("parentSelect");
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

document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("addResidentForm");
  if (!form) return;

  // Auto-username generator for PRIMARY resident
  const firstNameInput = form.querySelector('#primary_firstName');
  const lastNameInput  = form.querySelector('#primary_lastName');
  const usernameInput  = form.querySelector('#primary_username');

  function slugify(text) {
    if (!text) return '';
    return text.toString().toLowerCase()
      .replace(/\s+/g, '')
      .replace(/[^\w-]+/g, '')
      .replace(/--+/g, '-')
      .replace(/^-+/, '')
      .replace(/-+$/, '');
  }

  function updatePrimaryUsername() {
      if (!firstNameInput || !lastNameInput || !usernameInput) return;
      const first = slugify(firstNameInput.value || '');
      const last  = slugify(lastNameInput.value  || '');
      usernameInput.value = first + last;
  }
  if (firstNameInput && lastNameInput) {
      firstNameInput.addEventListener('input', updatePrimaryUsername);
      lastNameInput .addEventListener('input', updatePrimaryUsername);
  }

  form.addEventListener("submit", function (e) {
    let valid = true;
    let msg = "";

    // Children
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
    } else {
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
</script>

<script src="components/resident_modal/email.js"></script>

<script>
// SLUGIFY HELPER FUNCTION (shared)
function slugify(text) {
  if (!text) return '';
  return text.toString().toLowerCase()
    .replace(/\s+/g, '')     // Remove spaces
    .replace(/[^\w-]+/g, '') // Remove non-word chars
    .replace(/--+/g, '-')    // Replace multiple - with single -
    .replace(/^-+/, '')      // Trim - from start
    .replace(/-+$/, '');     // Trim - from end
}

// === NEW: SETUP USERNAME GENERATOR FOR A CHILD BLOCK ===
function setupChildUsernameGenerator(container) {
  const firstNameInput = container.querySelector(".child-first-name");
  const lastNameInput  = container.querySelector(".child-last-name");
  const usernameInput  = container.querySelector(".family-username"); 

  if (!firstNameInput || !lastNameInput || !usernameInput) {
    console.warn('Child username generator: missing fields.', container);
    return;
  }

  // Make username field readonly
  usernameInput.readOnly = true;
  usernameInput.placeholder = "Auto-generated";

  function updateChildUsername() {
    const first = slugify(firstNameInput.value || '');
    const last  = slugify(lastNameInput.value  || '');
    usernameInput.value = first + last;
  }

  firstNameInput.addEventListener('input', updateChildUsername);
  lastNameInput .addEventListener('input', updateChildUsername);
  updateChildUsername(); // initialize
}

// ---------- Add / Remove Family (Create) ----------

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
        h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
    });
}

// ---------- Edit Modal Version ----------

function toggleEditFamilySection() {
    const checkbox = document.getElementById('editAddFamilyMembers');
    const section = document.getElementById('editFamilyMembersSection');

    if (checkbox.checked) {
        section.style.display = 'block';
        if (editFamilyMemberCount === 0) {
            addEditFamilyMember();
        }
    } else {
        section.style.display = 'none';
        document.getElementById('editFamilyMembersContainer').innerHTML = '';
        editFamilyMemberCount = 0;
    }
}

function addEditFamilyMember() {
  editFamilyMemberCount++;
  const container = document.getElementById('editFamilyMembersContainer');

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
        h6.innerHTML = `<i class="fas fa-user-friends"></i> Family Member #${index + 1}`;
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
    const feedback   = current.element.querySelector('.child-name-feedback');
    const firstInput  = current.element.querySelector('.child-first-name');
    const middleInput = current.element.querySelector('.child-middle-name');
    const lastInput   = current.element.querySelector('.child-last-name');
    const suffixInput = current.element.querySelector('.child-suffix-name');

    if (!feedback || !firstInput || !lastInput) return;

    let isDuplicate = false;

    // Compare against other children
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

    // Compare against primary resident
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

// ---------- Shared Template ----------

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

$(document).ready(function () {
    // Legacy location dropdowns (if present)
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

document.getElementById('editForm')?.addEventListener('submit', function(event) {
    event.preventDefault(); // Always prevent default to wait for confirmation

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
            this.submit(); // Proceed with form submission
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
</script>

<script>
// ================== Validation Helpers ==================
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

// ================== Field Validators ==================
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

// ================== Delegated Bindings ==================
document.addEventListener('input', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))       validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))   validateChildBirthdateEl(t);
}, true);

document.addEventListener('change', (e)=>{
  const t=e.target;
  if(t.matches('input[name="birthDate"]'))       validatePrimaryBirthdateEl(t);
  if(t.matches('input[name="residency_start"]')) validateResidencyStartEl(t);
  if(t.matches('input[name$="_birthDate[]"]'))   validateChildBirthdateEl(t);
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

// Toggle show/hide of Username field when Email is present
function applyEmailUsernameToggle(container) {
  const emailInput      = container.querySelector(".family-email");
  const usernameInput   = container.querySelector(".family-username");
  const usernameWrap    = container.querySelector(".family-username-wrapper");

  if (!emailInput || !usernameInput || !usernameWrap) return;

  function toggleFields() {
    if (emailInput.value.trim() !== "") {
      usernameWrap.style.display = "none";
    } else {
      usernameWrap.style.display = "";
    }
  }
  emailInput.addEventListener("input", toggleFields);
  toggleFields();
}
</script>

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
    formData.append('action', 'parse_excel_preview'); // Action for PHP

    try {
        const response = await fetch('api/resident_info.php', { method: 'POST', body: formData });
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
            // Update UI BEFORE processing
            pText.innerText = `Processing ${i + 1} of ${totalRows}`;
            pDetail.innerText = `Importing row ${i + 1}...`;
            
            // Calculate percentage
            const pct = Math.round(((i) / totalRows) * 100);
            pBar.style.width = `${pct}%`;
            pBar.innerText = `${i}/${totalRows}`; // e.g. "4/10"

            // Call API for single row
            const rowData = new FormData();
            rowData.append('action', 'process_single_row');
            rowData.append('index', i);
            rowData.append('csrf_token', window.CSRF_TOKEN);

            const rowRes = await fetch('api/resident_info.php', { method: 'POST', body: rowData });
            const rowResult = await rowRes.json();

            if (rowResult.status === 'success') {
                successCount++;
            } else if (rowResult.status === 'skipped') {
                skipCount++;
            } else {
                // Log error visually
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
                location.reload(); // Refresh to show new residents
            });
        }, 1000);

    } catch (error) {
        modal.hide();
        Swal.fire('Import Failed', error.message, 'error');
    }
}
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
// --- This block displays ALL alerts after the page and libraries have loaded ---

// Build base + query string (for paginator + alert redirect)
$baseUrl   = strtok($redirects['residents'], '?');
$pageParam = ['page' => $_GET['page'] ?? 'resident_info'];
$filters   = array_filter([
    'search'        => $_GET['search'] ?? '',
    'filter_gender' => $_GET['filter_gender'] ?? '',
    'filter_zone'   => $_GET['filter_zone'] ?? '',
    'filter_status' => $_GET['filter_status'] ?? ''
]);
$pageBase = $baseUrl;
$qs       = '?' . http_build_query(array_merge($pageParam, $filters));

// Window compute
$window = 2; // pages left/right of current
$start  = max(1, $page - $window);
$end    = min($total_pages, $page + $window);
if ($start > 1 && $end - $start < $window*2) $start = max(1, $end - $window*2);
if ($end < $total_pages && $end - $start < $window*2) $end = min($total_pages, $start + $window*2);

// Handle Form SUCCESS Alert (from Add Resident OR Linking)
if (isset($form_success)) {
    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '" . $form_success . "',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            // ✅ Post/Redirect/Get: go back to the Resident List via GET (no re-post)
            window.location.href = " . json_encode($resbaseUrl) . ";
        });
    </script>";
}

// Handle Form ERROR Alert (from Add Resident OR Linking)
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

  <nav aria-label="Page navigation" class="mt-3">
    <ul class="pagination justify-content-end">

      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>" aria-label="First">
            <i class="fa fa-angle-double-left" aria-hidden="true"></i>
            <span class="visually-hidden">First</span>
          </a>
        </li>
      <?php endif; ?>

      <?php if ($page <= 1): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>" aria-label="Previous">
            <i class="fa fa-angle-left" aria-hidden="true"></i>
            <span class="visually-hidden">Previous</span>
          </a>
        </li>
      <?php endif; ?>

      <?php if ($start > 1): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <?php for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
        </li>
      <?php endfor; ?>

      <?php if ($end < $total_pages): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
      <?php endif; ?>

      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>" aria-label="Next">
            <i class="fa fa-angle-right" aria-hidden="true"></i>
            <span class="visually-hidden">Next</span>
          </a>
        </li>
      <?php endif; ?>

      <?php if ($page >= $total_pages): ?>
        <li class="page-item disabled">
          <span class="page-link" aria-disabled="true">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </span>
        </li>
      <?php else: ?>
        <li class="page-item">
          <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>" aria-label="Last">
            <i class="fa fa-angle-double-right" aria-hidden="true"></i>
            <span class="visually-hidden">Last</span>
          </a>
        </li>
      <?php endif; ?>

    </ul>
  </nav>

</div> </div> </div> </body>
</html>