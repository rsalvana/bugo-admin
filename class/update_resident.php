<?php
// class/update_resident.php — update primary + add children (email OR username) + EDIT username/password (admin-only)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_error.log');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// Convert MySQLi errors to exceptions; use UTF-8
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/redirects.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PHPMailer
require_once __DIR__ . '/../logs/logs_trig.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$trigs = new Trigger();

// Ensure CSRF token exists (forms should render it)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------------- Helpers ---------------- */

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}
function generatePassword(int $len = 12): string {
    $base = str_replace(['/', '+', '='], '', base64_encode(random_bytes($len + 2)));
    return substr($base, 0, $len);
}

// ===== NEW SLUGIFY FUNCTION (Copied from resident_info.php) =====
function slugify_lower(string $s): string {
    $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
    if ($t === false) $t = $s;
    // Keep only letters and numbers, remove all other characters
    $t = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $t ?? ''));
    return $t !== '' ? $t : 'user';
}

/**
 * Send credentials to a valid email (optional).
 */
function sendCredentials(string $to, string $password): void {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendCredentials skipped: invalid or empty email");
        return;
    }

    $mailboxUser = 'admin@bugoportal.site';
    $mailboxPass = 'Jayacop@100';  // ⚠️ consider moving to env
    $smtpHost    = 'mail.bugoportal.site';

    $build = function(PHPMailer $m) use ($mailboxUser, $to, $password) {
        $m->setFrom($mailboxUser, 'Barangay Bugo');
        $m->addAddress($to);
        $m->isHTML(false);
        $m->Subject = 'Your Barangay Bugo Resident Portal Credentials';
        $m->Body =
"Hi!

Your Barangay Bugo portal login credentials:
Username: {$to}
Password: {$password}

Please log in and change your password.

https://bugoportal.site/
";
        $m->CharSet  = 'UTF-8';
        $m->Hostname = 'bugoportal.site';
        $m->Sender   = $mailboxUser;
        $m->addReplyTo($mailboxUser, 'Barangay Bugo');
    };

    $attempt = function(string $mode, int $port) use ($smtpHost, $mailboxUser, $mailboxPass, $build) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = $smtpHost;
        $mail->SMTPAuth    = true;
        $mail->Username    = $mailboxUser;
        $mail->Password    = $mailboxPass;
        $mail->Port        = $port;
        $mail->Timeout     = 10;
        $mail->SMTPAutoTLS   = true;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPOptions   = ['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]];
        $mail->SMTPSecure = ($mode === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS    // 465
            : PHPMailer::ENCRYPTION_STARTTLS; // 587
        $build($mail);
        $mail->send();
    };

    try { $attempt('ssl', 465); return; } catch (\Throwable $e1) { error_log("Creds 465 failed: ".$e1->getMessage()); }
    try { $attempt('tls', 587); return; } catch (\Throwable $e2) { error_log("Creds 587 failed: ".$e2->getMessage()); }

    try {
        $fallback = new PHPMailer(true);
        $fallback->isMail();
        $build($fallback);
        $fallback->send();
    } catch (\Throwable $e3) {
        error_log("Creds all methods failed: ".$e3->getMessage());
    }
}

/**
 * SweetAlert page result + fallback.
 */
function page_msg_and_exit(string $icon, string $title, string $text, ?string $redirect = null) {
    // Fallback redirect to referrer to avoid blank page
    if (!$redirect) {
        $redirect = $_SERVER['HTTP_REFERER'] ?? null;
    }

    $prefix  = ($icon === 'success') ? 'Success: ' : 'Error: ';
    $msg     = $prefix . $text;
    $redir   = $redirect ? "<meta http-equiv='refresh' content='5;url=" . htmlspecialchars($redirect, ENT_QUOTES) . "'>" : "";

    echo "<!doctype html>
<html>
<head>
  <meta charset='utf-8'>
  <meta http-equiv='x-ua-compatible' content='ie=edge'>
  {$redir}
  <title>" . htmlspecialchars($title, ENT_QUOTES) . "</title>
  <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:20px}</style>
</head>
<body>
  <noscript>" . htmlspecialchars($msg, ENT_QUOTES) . "</noscript>
  <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
  <script>
  (function () {
    var go  = " . json_encode($redirect) . ";
    var msg = " . json_encode($msg) . ";
    if (window.Swal) {
      Swal.fire({
        icon: " . json_encode($icon) . ",
        title: " . json_encode($title) . ",
        text: " . json_encode($text) . "
      }).then(function () { if (go) { window.location.href = go; } });
    } else {
      alert(msg);
      if (go) { window.location.href = go; }
    }
  })();
  </script>
</body>
</html>";
    exit;
}

/**
 * SweetAlert and go back using browser history (used ONLY for the duplicate-name case).
 */
function page_msg_back_and_exit(string $icon, string $title, string $text) {
    echo "<!doctype html>
<html><head><meta charset='utf-8'><title>" . htmlspecialchars($title, ENT_QUOTES) . "</title></head>
<body>
<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
Swal.fire({
  icon: " . json_encode($icon) . ",
  title: " . json_encode($title) . ",
  text: " . json_encode($text) . ",
  confirmButtonColor: '#d33'
}).then(function(){ window.history.back(); });
</script>
</body></html>";
    exit;
}

/* ---------- Name normalization + duplicate helpers ---------- */

function _norm_lower($s) {
    $s = (string)$s;
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = trim($s);
    return preg_replace('/\s+/u',' ',$s);
}
function name_key(string $first='', string $middle='', string $last='', string $suffix=''): string {
    return implode('|', [_norm_lower($first), _norm_lower($middle), _norm_lower($last), _norm_lower($suffix)]);
}

/**
 * Check if a full name already exists among active residents (case/space-insensitive).
 * Excludes $excludeId if provided. Returns matched resident id or 0 if none.
 */
function db_fullname_exists(mysqli $mysqli, string $first, string $middle, string $last, string $suffix, int $excludeId = 0): int {
    // Narrow by first+last, then compare full normalized key in PHP (covers middle/suffix spacing & case)
    $sql = "
        SELECT id, first_name, middle_name, last_name, suffix_name
        FROM residents
        WHERE resident_delete_status = 0
          AND id <> ?
          AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
          AND LOWER(TRIM(last_name))  = LOWER(TRIM(?))
        LIMIT 50
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iss', $excludeId, $first, $last);
    $stmt->execute();
    $res = $stmt->get_result();
    $needle = name_key($first, $middle, $last, $suffix);
    while ($row = $res->fetch_assoc()) {
        if (name_key($row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '', $row['suffix_name'] ?? '') === $needle) {
            $stmt->close();
            return (int)$row['id'];
        }
    }
    $stmt->close();
    return 0;
}

/* ---------------- Guards ---------------- */

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    page_msg_and_exit('error', 'Security Alert', 'CSRF token mismatch. Operation blocked.');
}

/* ---------------- Inputs ---------------- */

$id               = (int)($_POST['id'] ?? 0);
$first_name       = sanitize_input($_POST['first_name'] ?? '');
$middle_name      = sanitize_input($_POST['middle_name'] ?? '');
$last_name        = sanitize_input($_POST['last_name'] ?? '');
$suffix_name      = sanitize_input($_POST['suffix_name'] ?? '');

$gender           = sanitize_input($_POST['gender'] ?? '');
$zone             = sanitize_input($_POST['zone'] ?? '');
$contact_number   = sanitize_input($_POST['contact_number'] ?? '');
$email_raw        = $_POST['email'] ?? '';
$email            = filter_var($email_raw, FILTER_SANITIZE_EMAIL);
$username_from_form = strtolower(sanitize_input($_POST['username'] ?? '')); // This is the auto-generated one

$civil_status     = sanitize_input($_POST['civilStatus'] ?? '');
$birth_date       = sanitize_input($_POST['birth_date'] ?? '');
$residency_start  = sanitize_input($_POST['residency_start'] ?? '');
$age              = (int)($_POST['age'] ?? 0); // This should be recalculated
$birth_place      = sanitize_input($_POST['birth_place'] ?? '');
$street_address   = sanitize_input($_POST['street_address'] ?? '');
$citizenship      = sanitize_input($_POST['citizenship'] ?? '');
$religion         = sanitize_input($_POST['religion'] ?? '');
$occupation       = sanitize_input($_POST['occupation'] ?? '');
$employee_id      = (int)($_SESSION['employee_id'] ?? 0);

$zone_leader_id   = (int)($_POST['zone_leader'] ?? 0);
$res_province     = sanitize_input($_POST['province'] ?? '');
$res_city         = sanitize_input($_POST['city_municipality'] ?? '');
$res_barangay     = sanitize_input($_POST['barangay'] ?? '');

// ===== NEW: role-gated password inputs (admin only) =====
$role = strtolower($_SESSION['Role_Name'] ?? '');
$allowPwEdit     = ($role === 'admin'); // only admin can edit password
$new_password     = $allowPwEdit ? trim((string)($_POST['new_password'] ?? '')) : '';
$confirm_password = $allowPwEdit ? trim((string)($_POST['confirm_password'] ?? '')) : '';

/* ---------------- Fetch old for audit ---------------- */

$oldStmt = $mysqli->prepare("SELECT * FROM residents WHERE id = ?");
$oldStmt->bind_param('i', $id);
$oldStmt->execute();
$oldData = $oldStmt->get_result()->fetch_assoc();
$oldStmt->close();
if (!$oldData) {
    page_msg_and_exit('error', 'Not Found', 'The resident you are trying to edit does not exist.');
}

/* ---------------- Basic validations ---------------- */

// Recalculate Age based on birthdate
if ($birth_date) {
    try {
        $age = (new DateTime($birth_date))->diff(new DateTime())->y;
    } catch (Exception $e) {
        $age = 0; // Or keep old age
    }
}

// Email is optional on EDIT; if blank, store as NULL
$email_for_db = ($email === '') ? null : $email;

if (!is_null($email_for_db) && !filter_var($email_for_db, FILTER_VALIDATE_EMAIL)) {
    page_msg_and_exit('error', 'Invalid Email', 'Please enter a valid email address or leave it blank to remove.');
}
if (!is_null($email_for_db) && strlen($email_for_db) > 191) {
    page_msg_and_exit('error', 'Email Too Long', 'Email must be 191 characters or fewer.');
}

if ($first_name === '' || $last_name === '') {
    page_msg_and_exit('error', 'Missing Name', 'First name and last name are required.');
}

// NEW: Duplicate FULL NAME check
$dupId = db_fullname_exists($mysqli, $first_name, $middle_name, $last_name, $suffix_name, $id);
if ($dupId > 0) {
    page_msg_and_exit('error', 'Duplicate Full Name', 'Another active resident already has the exact same full name.');
}

// ===== NEW USERNAME LOGIC (Edit Resident) =====
// Rule: Username is ALWAYS based on name.
$base_username = slugify_lower($first_name . $last_name);
if (empty($base_username)) {
     page_msg_and_exit('error', 'Invalid Name', 'First and Last name must contain letters or numbers to generate a username.');
}

$final_username = $base_username; // e.g., juandelacruz

// Check if 'juandelacruz' is already taken by *another* user
$check_stmt = $mysqli->prepare("SELECT id FROM residents WHERE username = ? AND id != ? AND resident_delete_status = 0 LIMIT 1");
$check_stmt->bind_param("si", $base_username, $id);
$check_stmt->execute();
if ($check_stmt->get_result()->num_rows > 0) {
    // Duplicate found! Apply rule: username + ID
    $final_username = $base_username . $id; // e.g., juandelacruz123
}
$check_stmt->close();
// $username_for_db is now $final_username
$username_for_db = $final_username;
// =======================================================

// Email uniqueness when provided (active only), excluding this record
if (!is_null($email_for_db)) {
    $dupStmt = $mysqli->prepare(
        'SELECT 1 FROM residents WHERE email = ? AND id <> ? AND resident_delete_status = 0 LIMIT 1'
    );
    $dupStmt->bind_param('si', $email_for_db, $id);
    $dupStmt->execute();
    $exists = $dupStmt->get_result()->fetch_column();
    $dupStmt->close();

    if ($exists) {
        page_msg_and_exit('error', 'Email already in use', 'Please use a different email address.');
    }
}

// (Child duplicate name checks are handled inside the transaction)

/* ---------------- Main UPDATE + Children (transaction) ---------------- */

try {
    $mysqli->begin_transaction();

    // 1) Update primary (now including username + suffix_name)
    $stmt = $mysqli->prepare(
        "UPDATE residents 
         SET first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, gender = ?, 
             res_zone = ?, contact_number = ?, email = ?, username = ?, civil_status = ?, 
             birth_date = ?, residency_start = ?, age = ?, birth_place = ?, res_street_address = ?, 
             citizenship = ?, religion = ?, occupation = ?,
             zone_leader_id = ?, res_province = ?, res_city_municipality = ?, res_barangay = ?
         WHERE id = ?"
    );

    $stmt->bind_param(
        "ssssssssssssisssssisssi",
        $first_name, $middle_name, $last_name, $suffix_name, $gender,
        $zone, $contact_number, $email_for_db, $username_for_db, $civil_status,
        $birth_date, $residency_start, $age, $birth_place, $street_address,
        $citizenship, $religion, $occupation,
        $zone_leader_id, $res_province, $res_city, $res_barangay,
        $id
    );
    $stmt->execute();
    $stmt->close();

    // 1b) Optional password change — ADMIN ONLY
    if ($allowPwEdit && ($new_password !== '' || $confirm_password !== '')) {
        if (strlen($new_password) < 8) {
            throw new Exception('New password must be at least 8 characters.');
        }
        if ($new_password !== $confirm_password) {
            throw new Exception('New password and confirm password do not match.');
        }
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $p = $mysqli->prepare("UPDATE residents SET password = ?, temp_password = NULL, pass_updated_at = NOW() WHERE id = ? AND resident_delete_status = 0");
        $p->bind_param('si', $hash, $id);
        $p->execute();
        $p->close();
    }
    // If NOT admin and someone tried to post password fields, they were zeroed above and ignored.

    // 2) Insert NEW children coming from Edit modal (if any)
    $efn  = $_POST['edit_family_firstName']    ?? null;
    $eln  = $_POST['edit_family_lastName']     ?? null;
    $ebd  = $_POST['edit_family_birthDate']    ?? null;
    $egd  = $_POST['edit_family_gender']       ?? null;

    if (is_array($efn) && is_array($eln) && is_array($ebd) && is_array($egd)) {
        // Optional arrays
        $emn   = $_POST['edit_family_middleName']   ?? [];
        $esuf  = $_POST['edit_family_suffixName']   ?? [];
        $erel  = $_POST['edit_family_relationship']   ?? [];
        $eph   = $_POST['edit_family_contactNumber']  ?? [];
        $ecs   = $_POST['edit_family_civilStatus']    ?? [];
        $eocc  = $_POST['edit_family_occupation']     ?? [];
        $eemail= $_POST['edit_family_email']          ?? [];
        $euser = $_POST['edit_family_username']       ?? []; // Auto-generated
        $ebpl  = $_POST['edit_family_birthplace']     ?? [];

        $today = new DateTime();

        for ($i = 0; $i < count($efn); $i++) {
            // Required minimum fields
            $fam_firstName = sanitize_input($efn[$i] ?? '');
            $fam_lastName  = sanitize_input($eln[$i] ?? '');
            $fam_birthDate = sanitize_input($ebd[$i] ?? '');
            $fam_gender    = sanitize_input($egd[$i] ?? '');

            if ($fam_firstName === '' && $fam_lastName === '' && $fam_birthDate === '' && $fam_gender === '') {
                continue;
            }
            if ($fam_firstName === '' || $fam_lastName === '' || $fam_birthDate === '' || $fam_gender === '') {
                throw new Exception("Child #".($i+1).": Missing required name/birthdate/gender.");
            }

            $fam_middleName   = sanitize_input($emn[$i]  ?? '');
            $fam_suffixName   = sanitize_input($esuf[$i] ?? '');
            
            // NEW: Check child full name against existing residents (prevent duplicates DB-wide)
            $childDup = db_fullname_exists($mysqli, $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName, 0);
            if ($childDup > 0) {
                throw new Exception("Child #".($i+1).": A resident already exists with the exact same full name.");
            }

            $fam_relationship  = sanitize_input($erel[$i] ?? 'Child');
            $fam_contactNumber = sanitize_input($eph[$i]  ?? '0000000000');
            $fam_civilStatus   = sanitize_input($ecs[$i]  ?? 'Single');
            $fam_occupation    = sanitize_input($eocc[$i] ?? '');
            $fam_email         = filter_var($eemail[$i] ?? '', FILTER_SANITIZE_EMAIL);
            $fam_username_field= sanitize_input($euser[$i] ?? ''); // Auto-generated
            $fam_birthplace    = sanitize_input($ebpl[$i] ?? 'N/A');

            // ===== NEW USERNAME LOGIC (Child) =====
            $fam_base_username = slugify_lower($fam_firstName . $fam_lastName);
            $fam_login_username = !empty($fam_username_field) ? strtolower($fam_username_field) : $fam_base_username;
            if (empty($fam_login_username)) { $fam_login_username = $fam_base_username; }
            if (empty($fam_login_username)) {
                throw new Exception("Child #".($i+1).": First/Last name cannot be empty.");
            }
            // ======================================

            // Uniqueness (active only)
            if ($fam_email !== '') {
                $chk = $mysqli->prepare("SELECT id FROM residents WHERE email = ? AND resident_delete_status = 0 LIMIT 1");
                $chk->bind_param("s", $fam_email);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    throw new Exception("Child #".($i+1).": Email already in use by active resident.");
                }
                $chk->close();
            }

            // Age
            $bdObj   = new DateTime($fam_birthDate);
            $fam_age = $today->diff($bdObj)->y;

            // Credentials (store temp_password)
            $fam_raw_password = generatePassword();
            $fam_password     = password_hash($fam_raw_password, PASSWORD_DEFAULT);

            // Insert child with temp_password
            $fam_stmt = $mysqli->prepare("
                INSERT INTO residents (
                    employee_id, zone_leader_id, username, password, temp_password,
                    first_name, middle_name, last_name, suffix_name, gender, civil_status,
                    birth_date, residency_start, birth_place, age, contact_number, email,
                    res_province, res_city_municipality, res_barangay, res_zone, res_street_address,
                    citizenship, religion, occupation, resident_delete_status
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)
            ");
            $fam_stmt->bind_param(
                "iissssssssssssissiiisssss",
                $employee_id, $zone_leader_id, $fam_login_username, $fam_password, $fam_raw_password,
                $fam_firstName, $fam_middleName, $fam_lastName, $fam_suffixName,
                $fam_gender, $fam_civilStatus, $fam_birthDate, $residency_start, $fam_birthplace,
                $fam_age, $fam_contactNumber, $fam_email,
                $res_province, $res_city, $res_barangay, $zone, $street_address,
                $citizenship, $religion, $fam_occupation
            );
            $fam_stmt->execute();
            $family_member_id = $mysqli->insert_id;
            $fam_stmt->close();
            
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

            // Link relationship
            $rel_stmt = $mysqli->prepare("
                INSERT INTO resident_relationships
                    (resident_id, related_resident_id, relationship_type, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $rel_stmt->bind_param("iisi", $id, $family_member_id, $fam_relationship, $employee_id);
            $rel_stmt->execute();
            $rel_stmt->close();

            // Optional: email credentials if email present
            if ($fam_email !== '') {
                sendCredentials($fam_email, $fam_raw_password);
            }
        }
    }

    // 3) Audit + commit
    $trigs->isEdit(2, $id, $oldData);
    $mysqli->commit();

} catch (mysqli_sql_exception $e) {
    $mysqli->rollback();
    if ((int)$e->getCode() === 1062) {
        if (str_contains($e->getMessage(), 'username')) {
             page_msg_and_exit('error', 'Duplicate', 'A resident with that username already exists.');
        } else if (str_contains($e->getMessage(), 'email')) {
             page_msg_and_exit('error', 'Duplicate', 'A resident with that email already exists.');
        }
    }
    error_log("update_resident TX failed: ".$e->getMessage());
    page_msg_and_exit('error', 'Update failed', $e->getMessage());
} catch (Exception $e) {
    $mysqli->rollback();
    error_log("update_resident logic failed: ".$e->getMessage());
    page_msg_and_exit('error', 'Update failed', $e->getMessage());
}

/* ---------------- Success redirect by role ---------------- */

$roleText = $_SESSION['Role_Name'] ?? '';
if ($roleText === 'Barangay Secretary') {
    $link = enc_brgysec('resident_info');
} elseif ($roleText === 'Encoder') {
    $link = enc_encoder('resident_info');
} else {
    global $redirects;
    $link = $redirects['residents_api'];
}
page_msg_and_exit('success', 'Resident updated', 'Resident and family info updated successfully!', $link);
