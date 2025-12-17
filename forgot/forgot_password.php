<?php
// auth/forgot_password.php
declare(strict_types=1);

if (!isset($_SESSION)) session_start();

// Adjust these paths if your file structure is different locally
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

require_once __DIR__ . '/../vendor/autoload.php'; // Ensure PHPMailer is installed via Composer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);

    // Check if email exists in database
    $stmt = $mysqli->prepare("SELECT employee_id FROM employee_list WHERE employee_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($employee_id);
        $stmt->fetch();

        // Generate 6-digit code
        $code = 'BUGO-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION['fp_employee_id'] = (int)$employee_id;
        $_SESSION['fp_email']       = $email;
        $_SESSION['fp_code']        = $code;

        $mail = new PHPMailer(true);
        try {
            // ── GMAIL SMTP CONFIGURATION (LOCAL) ─────────────────────────────
            
            // Uncomment the next line if you need to see connection errors in the browser
            // $mail->SMTPDebug = 2; 

            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'jayacop9@gmail.com';
            
            // REPLACE THIS WITH YOUR 16-CHAR GOOGLE APP PASSWORD
            // Do NOT use your normal Gmail login password.
            // Go to Google Account > Security > 2-Step Verification > App Passwords
            $mail->Password   = 'fsls ywyv irfn ctyc'; 

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS implies port 587
            $mail->Port       = 587; 

            // ── SSL CERTIFICATE BYPASS (FOR LOCALHOST) ───────────────────────
            // This prevents "SCREAM: stream_socket_enable_crypto(): SSL operation failed"
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ];

            // ── EMAIL CONTENT ────────────────────────────────────────────────
            $mail->setFrom('jayacop9@gmail.com', 'Barangay Bugo Admin');
            $mail->addAddress($email);
            
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code';
            $mail->Body    = 'Your password reset code is: <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>';
            $mail->AltBody = 'Your password reset code is: ' . $code;

            $mail->send();

            $success = 'Verification code sent. Please check your email.';
        } catch (Exception $e) {
            $error = 'Email error: ' . $mail->ErrorInfo;
        }
    } else {
        // Email not found in DB
        $error = 'Email not found.';
    }

    $stmt->close();
    $mysqli->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Forgot Password</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/logo/logo.png">

  <link rel="stylesheet" href="/bugo-admin/auth/assets/cp_2fa.css">

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php if (!empty($error)): ?>
<script>
  Swal.fire({ icon:'error', title:'Oops', text: <?= json_encode($error) ?> });
</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>
  Swal.fire({
    icon: 'success',
    title: 'Sent!',
    text: <?= json_encode($success) ?>,
    timer: 1500,
    timerProgressBar: true,
    showConfirmButton: false
  }).then(() => {
    // Redirect to the code verification page
    location.href = 'verify_code.php';
  });
</script>
<?php endif; ?>

<div class="card">
  <div class="header">
    <img class="logo" src="/assets/logo/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="title">Forgot your password?</div>
  </div>
  <div class="sub">Enter your registered email. We’ll send a 6-digit code to verify it’s really you.</div>

  <form method="post" autocomplete="off" onsubmit="return onSend(this)">
    <div class="form-group" style="margin-top:16px">
      <label class="label" for="email">Email address</label>
      <input
        id="email"
        class="otp-input"
        style="width:100%; padding:12px 14px; letter-spacing:0; text-align:left"
        type="email"
        name="email"
        inputmode="email"
        placeholder="you@example.com"
        required
      >
    </div>

    <div class="actions" style="margin-top:18px">
      <button id="sendBtn" type="submit" class="btn btn-primary">
        <span id="btnText">Send verification code</span>
        <span id="btnSpinner" class="spinner" style="display:none;margin-left:8px"></span>
      </button>
      <a class="btn" href="../index.php" style="margin-left:8px">Back to Login</a>
    </div>

    <div class="muted" style="margin-top:10px">
      Tip: check your spam/junk if you don’t see the email in a minute.
    </div>
  </form>
</div>

<script>
function onSend(form) {
  const btn = document.getElementById('sendBtn');
  const text = document.getElementById('btnText');
  const spin = document.getElementById('btnSpinner');
  
  // Disable button to prevent double-send
  btn.disabled = true;
  text.textContent = 'Sending…';
  spin.style.display = 'inline-block';
  
  return true; // allow submit
}
</script>

</body>
</html>