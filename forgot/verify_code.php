<?php
// auth/verify_code.php
declare(strict_types=1);

if (!isset($_SESSION)) session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error    = '';
$success  = '';
$attempts = $_SESSION['fp_attempts'] ?? 0;

// Security Check
if (!isset($_SESSION['fp_employee_id'])) {
    http_response_code(403);
    exit('403 Forbidden: Session expired.');
}

// Timer Logic
if (!isset($_SESSION['fp_code_created'])) {
    $_SESSION['fp_code_created'] = time();
}

$currentTime  = time();
$codeCreated  = (int)$_SESSION['fp_code_created'];

// 1. Main Expiration (10 mins = 600s)
$remainingSeconds = max(0, 600 - ($currentTime - $codeCreated));
$isExpired        = ($remainingSeconds === 0);

// 2. Resend Cooldown (60s)
// How many seconds have passed since creation?
$elapsed = $currentTime - $codeCreated;
// If less than 60s passed, calculate how much is left to wait.
$resendWait = max(0, 60 - $elapsed);

// ─────────────────────────────────────────────────────────────────────────────
// BUSINESS LOGIC
// ─────────────────────────────────────────────────────────────────────────────

// 1. HANDLE RESEND REQUEST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    
    // Server-side check: enforce 60s cooldown
    if ($resendWait > 0) {
        $error = "Please wait " . $resendWait . " seconds before resending.";
    } else {
        $newCode = 'BUGO-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $_SESSION['fp_code']         = $newCode;
        $_SESSION['fp_code_created'] = time();
        $_SESSION['fp_attempts']     = 0; 
        $attempts = 0; 

        // Update timers for the UI
        $remainingSeconds = 600;
        $resendWait       = 60; 
        $isExpired        = false;
        
        $email = $_SESSION['fp_email'] ?? '';

        $mail = new PHPMailer(true);
        try {
            // ── GMAIL SMTP ───────────────────────────────────────────────────
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'jayacop9@gmail.com';
            // 🔴 PASTE YOUR APP PASSWORD HERE
            $mail->Password   = 'fsls ywyv irfn ctyc'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
            $mail->Port       = 587;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

            $mail->setFrom('jayacop9@gmail.com', 'Barangay Bugo Admin');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'New Password Reset Code';
            $mail->Body    = "Your new password reset code is: <strong>" . htmlspecialchars($newCode) . "</strong>";
            $mail->AltBody = "Your new password reset code is: $newCode";

            $mail->send();
            
            $success = "New code sent to $email";
        } catch (Exception $e) {
            $error = "Could not resend code: " . $mail->ErrorInfo;
        }
    }

// 2. CHECK LOCKOUT
} elseif ($attempts >= 3) {
    $error = "Too many incorrect attempts. Please request a new code.";

// 3. HANDLE VERIFICATION
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    
    $entered = strtoupper(trim($_POST['code'] ?? ''));
    if (strpos($entered, 'BUGO-') !== 0) {
        $digits  = preg_replace('/\D+/', '', $entered);
        $entered = 'BUGO-' . substr($digits, 0, 6);
    }
    
    $sessionCode = (string)($_SESSION['fp_code'] ?? '');

    if ($isExpired) {
        $error = "Code expired. Please request a new one.";
    } elseif ($sessionCode !== '' && hash_equals($sessionCode, $entered)) {
        $_SESSION['fp_attempts'] = 0;
        header("Location: reset_password.php");
        exit;
    } else {
        $_SESSION['fp_attempts'] = $attempts + 1;
        $error = "Invalid code. Attempt " . $_SESSION['fp_attempts'] . " of 3.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Verify Code</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/logo/logo.png">
  <link rel="stylesheet" href="/bugo-admin/auth/assets/cp_2fa.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php if (!empty($error)): ?>
<script>Swal.fire({icon:'error', title:'Oops', text: <?= json_encode($error) ?>});</script>
<?php endif; ?>

<?php if (!empty($success)): ?>
<script>Swal.fire({icon:'success', title:'Sent', text: <?= json_encode($success) ?>, timer: 1200, showConfirmButton:false});</script>
<?php endif; ?>

<div class="card">
  <div class="header">
    <img class="logo" src="/assets/logo/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="title">Verify your email</div>
  </div>
  <div class="sub">Enter the 6‑digit code we sent to your email to continue.</div>

  <form id="verifyForm" method="post" autocomplete="off" style="margin-top:10px">
    <input type="hidden" name="code" id="fullCode">
    
    <div style="display:flex;justify-content:center;align-items:center">
      <span class="prefix">BUGO‑</span>
      <div class="otp-wrap" id="otpWrap">
        <?php $isDisabled = ($isExpired || ($attempts >= 3)); ?>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
        <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" <?= $isDisabled ? 'disabled' : '' ?>>
      </div>
    </div>

    <div class="actions" style="margin-top:14px">
      <button id="submitBtn" type="submit" class="btn btn-primary" <?= $isDisabled ? 'disabled' : '' ?>>
        Verify
      </button>
    </div>

    <div class="muted" style="margin-top:10px">
      Code expires in: <b id="mainTimer"><?= gmdate("i:s", $remainingSeconds) ?></b>
      <?php if ($attempts > 0 && $attempts < 3): ?>
        · Attempts: <?= (int)$attempts ?>/3
      <?php endif; ?>
    </div>
  </form>

  <form method="post" autocomplete="off" style="margin-top:12px; text-align:center">
    
    <button id="resendBtn" class="btn" name="resend" type="submit" 
            style="<?= ($resendWait > 0) ? 'opacity:0.5;cursor:not-allowed' : '' ?>"
            <?= ($resendWait > 0) ? 'disabled' : '' ?>>
      Resend code
    </button>
    
    <div id="resendMessage" class="muted" style="margin-top:6px; font-size: 0.9em;">
      <?php if ($resendWait > 0): ?>
         You can request a new code in <b id="resendTimer"><?= $resendWait ?></b> seconds.
      <?php else: ?>
         You can request a new code now.
      <?php endif; ?>
    </div>

  </form>
</div>

<script>
  // 1. TIMERS SETUP
  let mainSeconds   = <?= json_encode((int)$remainingSeconds) ?>;
  let resendSeconds = <?= json_encode((int)$resendWait) ?>;
  
  const mainTimerEl   = document.getElementById('mainTimer');
  const resendTimerEl = document.getElementById('resendTimer');
  const resendMsgEl   = document.getElementById('resendMessage');
  const resendBtn     = document.getElementById('resendBtn');
  const verifyBtn     = document.getElementById('submitBtn');

  function tick() {
    // A. Main Expiration Timer (10:00 -> 00:00)
    if (mainSeconds > 0) {
        mainSeconds--;
        const mm = String(Math.floor(mainSeconds / 60)).padStart(2, '0');
        const ss = String(mainSeconds % 60).padStart(2, '0');
        if(mainTimerEl) mainTimerEl.textContent = `${mm}:${ss}`;
    } else {
        if(mainTimerEl) mainTimerEl.textContent = "00:00";
        if(verifyBtn) verifyBtn.disabled = true; // Disable verify when time is up
    }

    // B. Resend Cooldown Timer (60 -> 0)
    if (resendSeconds > 0) {
        resendSeconds--;
        // Update the bold number
        if(resendTimerEl) resendTimerEl.textContent = resendSeconds;
    } else {
        // Time is up! Unlock the button.
        if (resendBtn && resendBtn.disabled) {
            resendBtn.removeAttribute('disabled');
            resendBtn.style.opacity = '1';
            resendBtn.style.cursor  = 'pointer';
            
            // Update text message
            if(resendMsgEl) resendMsgEl.textContent = "You can request a new code now.";
        }
    }

    // Run again in 1 second
    setTimeout(tick, 1000);
  }
  
  // Start the clock
  tick();


  // 2. OTP INPUT UX
  const inputs = Array.from(document.querySelectorAll('.otp-input'));
  const hidden = document.getElementById('fullCode');
  const form   = document.getElementById('verifyForm');

  function syncHidden() {
    const digits = inputs.map(i => i.value.replace(/\D/g,'')).join('').slice(0,6);
    if(hidden) hidden.value = digits ? ('BUGO-' + digits) : '';
    return digits.length === 6;
  }

  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
      e.target.value = e.target.value.replace(/\D/g,'').slice(0,1);
      if (e.target.value && idx < inputs.length - 1) inputs[idx+1].focus();
      syncHidden();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !e.target.value && idx > 0) inputs[idx-1].focus();
    });
    inp.addEventListener('paste', e => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text') || '';
      if (!text) return;
      let m = text.toUpperCase().match(/BUGO[-–—]?(\d{6})/);
      const digits = m ? m[1] : text.replace(/\D/g,'').slice(0,6);
      for (let i=0;i<inputs.length;i++) inputs[i].value = digits[i] || '';
      inputs[Math.min(digits.length,5)].focus();
      syncHidden();
    });
  });

  if(form) {
      form.addEventListener('submit', (e) => {
        if (!syncHidden()) {
          e.preventDefault();
          Swal.fire({icon:'warning',title:'Incomplete',text:'Please enter all 6 digits.'});
          return;
        }
        if(verifyBtn) {
            verifyBtn.textContent = 'Verifying…';
            verifyBtn.disabled = true;
        }
      });
  }

  // Prevent BF Cache issues (back button showing old state)
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
</script>

</body>
</html>