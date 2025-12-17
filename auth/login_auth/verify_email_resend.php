<?php
// auth/login_auth/verify_email_resend.php
declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Adjust paths to go up TWO levels (../../) to find include/vendor
require_once __DIR__ . '/../../include/connection.php';
require_once __DIR__ . '/../../vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Security Check: User must be in the "pending OTP" state
if (empty($_SESSION['email_otp_pending'])) {
    // If accessed directly, send them to login
    header('Location: ../../index.php');
    exit;
}

$mysqli = db_connection();
$ctx    = $_SESSION['email_otp_pending'];
$empId  = (int)$ctx['employee_id'];

// 2. Fetch fresh email from DB
$q = $mysqli->prepare("
  SELECT employee_email,
         COALESCE(NULLIF(TRIM(CONCAT(employee_fname,' ',employee_lname)),''), employee_username) AS employee_name
  FROM employee_list
  WHERE employee_id = ? AND employee_delete_status = 0
");
$q->bind_param('i', $empId);
$q->execute();
$r = $q->get_result();
$u = $r->fetch_assoc();
$q->close();

// If no email found, redirect back with error
if (!$u || !filter_var($u['employee_email'], FILTER_VALIDATE_EMAIL)) {
    $_SESSION['resend_error'] = 'Cannot resend — invalid email on file.';
    header('Location: verify_email.php'); 
    exit;
}

// 3. Generate New OTP
$code      = (string)random_int(100000, 999999);
$code_hash = password_hash($code, PASSWORD_DEFAULT);
$expires   = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

// 4. Save to DB
$ins = $mysqli->prepare("
  INSERT INTO employee_email_otp (employee_id, code_hash, expires_at)
  VALUES (?, ?, ?)
");
$ins->bind_param('iss', $empId, $code_hash, $expires);
$ins->execute();
$ins->close();

// 5. Send Email via Gmail (UPDATED SETTINGS)
$toEmail = $u['employee_email'];
$toName  = $u['employee_name'];

$mail = new PHPMailer(true);
try {
    // ── GMAIL SMTP CONFIGURATION ─────────────────────────────────────────────
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'jayacop9@gmail.com';
    
    // 🔴 PASTE YOUR 16-CHAR GOOGLE APP PASSWORD HERE
    $mail->Password   = 'fsls ywyv irfn ctyc'; 

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS (Port 587)
    $mail->Port       = 587;
    
    // SSL Bypass (Required for Localhost/XAMPP)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];

    // Email Content
    $mail->setFrom('jayacop9@gmail.com', 'Barangay Bugo Admin');
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = 'Barangay Bugo 2FA Code';
    $mail->Body    = "
        <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
        <p>Your new verification code is:</p>
        <h2 style='color:#0d6efd;'>" . $code . "</h2>
        <p>This code is valid for 5 minutes.</p>
        <br><p>Thank you,<br>Barangay Bugo Portal</p>
    ";
    $mail->AltBody = "Your verification code is: {$code}";

    $mail->send();

    $_SESSION['resend_success'] = true;

} catch (Exception $e) {
    // Log the actual error for debugging
    error_log("Resend Error: " . $mail->ErrorInfo);
    $_SESSION['resend_error'] = "Unable to send verification email.";
}

// 6. Redirect back to the verification page
// Since both files are in 'login_auth', we just use the filename
header('Location: verify_email.php');
exit;
?>