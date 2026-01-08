<?php
require_once __DIR__ . '/security/security.php';
require __DIR__ . '/vendor/autoload.php';
require_once 'include/encryption.php';
require_once './logs/logs_trig.php';
require_once __DIR__ . '/include/connection.php';

use PHPMailer\PHPMailer\PHPMailer;

$mysqli   = db_connection();
$trigger  = new Trigger();
$recaptcha_secret = "6Ldid00rAAAAAOXCldjZkhQfad_-fxzaRZVxg9oB";

// Ensure a session + CSRF token exist
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Email OTP sender helper (UPDATED FOR GMAIL) ---
function send_2fa_mail(string $toEmail, string $toName, string $code): void {
  // 1. UPDATED GMAIL CREDENTIALS
  $mailboxUser = 'jayacop9@gmail.com';
  $mailboxPass = 'fsls ywyv irfn ctyc'; // Your specific App Password
  $smtpHost    = 'smtp.gmail.com';

  $safeName = htmlspecialchars($toName ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $safeCode = htmlspecialchars($code ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $buildMessage = function(PHPMailer $m) use ($toEmail, $safeName, $safeCode, $mailboxUser) {
    // Gmail rewrites the From header anyway, but good to match
    $m->setFrom($mailboxUser, 'Barangay Bugo Admin');
    $m->addAddress($toEmail, $safeName);
    
    $m->isHTML(true);
    $m->Subject = 'Barangay Bugo 2FA Code';
    $m->Body = "<p>Hello <strong>{$safeName}</strong>,</p>
                <p>Your verification code is:</p>
                <h2 style='color:#0d6efd;'>{$safeCode}</h2>
                <p>This code is valid for 5 minutes.</p>
                <br><p>Thank you,<br>Barangay Bugo Portal</p>";
    $m->AltBody  = "Your verification code is: {$safeCode}\nThis code is valid for 5 minutes.";
    $m->CharSet  = 'UTF-8';
  };

  $attempt = function(string $mode, int $port) use ($smtpHost, $mailboxUser, $mailboxPass, $buildMessage) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host          = $smtpHost;
    $mail->SMTPAuth      = true;
    $mail->Username      = $mailboxUser;
    $mail->Password      = $mailboxPass;
    $mail->Port          = $port;
    $mail->Timeout       = 10;
    
    // Gmail uses TLS (587) or SSL (465)
    $mail->SMTPSecure = ($mode === 'ssl')
      ? PHPMailer::ENCRYPTION_SMTPS   // 465
      : PHPMailer::ENCRYPTION_STARTTLS; // 587

    // Localhost SSL Bypass
    $mail->SMTPOptions = [
      'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
      ]
    ];
    
    $buildMessage($mail);
    $mail->send();
  };

  error_log("2FA: sending code to {$toEmail}");

  try {
    // Try Port 587 (TLS) first for Gmail
    $attempt('tls', 587);
  } catch (\Throwable $e1) {
    error_log('2FA: 587 failed: '.$e1->getMessage());
    try {
      // Fallback to Port 465 (SSL)
      $attempt('ssl', 465);
    } catch (\Throwable $e2) {
      error_log('2FA: 465 failed: '.$e2->getMessage());
      throw new \RuntimeException('Unable to send verification email. check SMTP config.');
    }
  } finally {
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
      if (!@$GLOBALS['mysqli']->ping() && function_exists('db_connection')) {
        $GLOBALS['mysqli'] = db_connection();
      }
    }
  }
}

// Auto-login via remember_token
if (!isset($_SESSION['employee_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $hashedToken = hash('sha256', $token);

    // ❗ Block auto-login if password_changed = 0
    $stmt = $mysqli->prepare("
        SELECT lt.employee_id, el.Role_Id, er.Role_Name, el.employee_username, el.password_changed
        FROM login_tokens lt
        JOIN employee_list el ON lt.employee_id = el.employee_id
        LEFT JOIN employee_roles er ON el.Role_Id = er.Role_Id
        WHERE lt.token_hash = ? 
          AND lt.expiry >= NOW() 
          AND el.employee_delete_status = 0
          AND el.password_changed = 1
    ");
    $stmt->bind_param("s", $hashedToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $_SESSION['username']    = $row['employee_username'];
        $_SESSION['employee_id'] = $row['employee_id'];
        $_SESSION['Role_Id']     = $row['Role_Id'];
        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';
        $trigger->isLogin(6, $_SESSION['employee_id']);
    }
    $stmt->close();
}

// Redirect if already logged in
if (isset($_SESSION['employee_id'])) {
    // If still forced to change password, don't go to dashboard
    if (!empty($_SESSION['force_change_password'])) {
        header("Location: /bugo-admin/change_password.php");
        exit();
    }
    $redirect_page = role_redirect($_SESSION['Role_Name'] ?? 'Staff');
    header("Location: $redirect_page");
    exit();
}

// Handle login
$error_message = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF guard
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error_message = "Invalid Login";
    } elseif (!empty($_POST["username"]) && !empty($_POST["password"]) && !empty($_POST["g-recaptcha-response"])) {
        $username = validateInput($_POST["username"]);
        $password = validateInput($_POST["password"]);
        $recaptcha_response = $_POST["g-recaptcha-response"];

        $verify_url = "https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret&response=$recaptcha_response";
        $response = @file_get_contents($verify_url);
        $response_keys = $response ? json_decode($response, true) : ['success'=>false];

        if (empty($response_keys["success"])) {
            $error_message = "reCAPTCHA verification failed.";
        } else {
            // Add password_changed to SELECT
            $stmt = $mysqli->prepare("
                SELECT el.employee_id,
                       el.employee_username,
                       el.Role_Id,
                       COALESCE(er.Role_Name,'Staff') AS Role_Name,
                       el.employee_password,
                       el.employee_email,
                       el.password_changed,
                       COALESCE(NULLIF(TRIM(CONCAT(el.employee_fname,' ',el.employee_lname)),''), el.employee_username) AS employee_name
                FROM employee_list el
                LEFT JOIN employee_roles er ON el.Role_Id = er.Role_Id
                WHERE LOWER(el.employee_username) = LOWER(?) AND el.employee_delete_status = 0
            ");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stored_password = $row['employee_password'];
                $role_name = strtolower($row['Role_Name'] ?? 'staff');
                $emp_id = (int)$row['employee_id'];

                // 🔒 Brute-force check BEFORE verifying password
                $lock_remaining = is_locked_out($mysqli, $emp_id);
                if ($lock_remaining !== false) {
                    $error_message = "LOCKED:" . $lock_remaining;
                    usleep(250000);
                } elseif (password_verify($password, $stored_password)) {
                    reset_attempts($mysqli, $emp_id);

                    // FORCE PASSWORD CHANGE FIRST if flag is 0
                    if ((int)$row['password_changed'] === 0) {
                        session_regenerate_id(true);
                        $_SESSION['username']    = $row['employee_username'] ?? $username;
                        $_SESSION['employee_id'] = $emp_id;
                        $_SESSION['Role_Id']     = $row['Role_Id'];
                        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';
                        $_SESSION['force_change_password'] = true;

                        // Do not "remember me" until password is changed
                        $_POST['remember'] = 0;

                        header("Location: /bugo-admin/change_password.php"); // same level as index.php
                        exit();
                    }

                    // Decide post-login redirect based on role (used after OTP success OR bypass)
                    switch (true) {
                        case strpos($role_name, 'admin') !== false:
                            $redirect_page = enc_page('admin_dashboard'); break;                                     
                        case strpos($role_name, 'revenue') !== false:
                            $redirect_page = enc_revenue('admin_dashboard'); break;
                        case strpos($role_name, 'indigency') !== false:
                            $redirect_page = enc_indigency('indigency_dashboard'); break;    
                        case strpos($role_name, 'lupon') !== false:
                            $redirect_page = enc_lupon('admin_dashboard'); break;
                        case strpos($role_name, 'captain') !== false || strpos($role_name, 'punong barangay') !== false:
                            $redirect_page = enc_captain('admin_dashboard'); break;
                        case strpos($role_name, 'staff') !== false || strpos($role_name, 'encoder') !== false:
                            $redirect_page = enc_encoder('admin_dashboard'); break;
                        case strpos($role_name, 'multimedia') !== false:
                            $redirect_page = enc_multimedia('admin_dashboard'); break;
                        case strpos($role_name, 'secretary') !== false:
                            $redirect_page = enc_brgysec('admin_dashboard'); break;
                        case strpos($role_name, 'beso') !== false:
                            $redirect_page = enc_beso('admin_dashboard'); break;
                        case strpos($role_name, 'tanod') !== false:
                            $redirect_page = enc_tanod('admin_dashboard'); break;  
                        case strpos($role_name, 'bhw') !== false:
                            $redirect_page = enc_bhw('bhw_dashboard'); break; 
                            case strpos($role_name, 'liason') !== false:
                                $redirect_page = enc_liason('liason_dashboard'); break;                                                              
                        default:
                            $redirect_page = enc_page('admin_dashboard');
                    }

                    // ---------------- Email OTP: create & send (with BYPASS when no email) ----------------
                    $toEmail = $row['employee_email'] ?? '';
                    $toName  = $row['employee_name'] ?? $username;

                    // BYPASS 2FA if no valid email on the account
                    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                        // 🔐 Complete login without OTP
                        session_regenerate_id(true);
                        $_SESSION['username']    = $row['employee_username'] ?? $username;
                        $_SESSION['employee_id'] = $emp_id;
                        $_SESSION['Role_Id']     = $row['Role_Id'];
                        $_SESSION['Role_Name']   = $row['Role_Name'] ?? 'Staff';

                        // Flag UI to show “add email to enable 2FA”
                        $_SESSION['needs_email_for_2FA'] = true;

                        // Optionally ignore remember-me when 2FA absent
                        $_POST['remember'] = 0;

                        // Audit login with bypass
                        $trigger->isLogin(6, $_SESSION['employee_id']);
                        error_log("LOGIN: 2FA bypass used (no email) for employee_id={$emp_id}");

                        // Redirect to role landing
                        $_SESSION['login_success'] = true;
                        $_SESSION['redirect_page'] = $redirect_page;
                        header("Location: $redirect_page");
                        exit;
                    }

                    // Normal OTP flow when email is present
                    $otp_code  = (string)random_int(100000, 999999);
                    $otp_hash  = password_hash($otp_code, PASSWORD_DEFAULT);
                    $expiresAt = (new DateTime('+5 minutes'))->format('Y-m-d H:i:s');

                    $ins = $mysqli->prepare("
                        INSERT INTO employee_email_otp (employee_id, code_hash, expires_at)
                        VALUES (?, ?, ?)
                    ");
                    $ins->bind_param('iss', $emp_id, $otp_hash, $expiresAt);
                    $ins->execute();
                    $ins->close();

                    try {
                        send_2fa_mail($toEmail, $toName, $otp_code);
                    } catch (Throwable $e) {
                        error_log('2FA email send failed: ' . $e->getMessage());
                        $error_message = "Unable to send verification email right now.";
                    }

                    if (empty($error_message)) {
                        // Stash details for verify step; DO NOT log in yet.
                        $_SESSION['email_otp_pending'] = [
                            'employee_id' => $emp_id,
                            'username'    => $row['employee_username'] ?? $username,
                            'Role_Id'     => $row['Role_Id'],
                            'Role_Name'   => $row['Role_Name'] ?? 'Staff',
                            'remember'    => !empty($_POST['remember']),
                            'redirect'    => $redirect_page,
                        ];

                        // Use an absolute path so it works from anywhere
                        header('Location: /bugo-admin/auth/login_auth/verify_email.php');
                        exit;
                    }
                } else {
                    // ❌ Failure: increment attempts and return generic error
                    record_failed_attempt($mysqli, $emp_id);
                    $error_message = "Invalid login credentials.";
                    usleep(250000);
                }
            } else {
                // Username not found — keep generic
                $error_message = "Invalid login credentials.";
                usleep(250000);
            }

            $stmt->close();
        }
    } else {
        $error_message = "Please fill out all fields and complete reCAPTCHA.";
    }
}

function validateInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Fetch barangay info
$barangayInfoSql = "SELECT bm.city_municipality_name, b.barangay_name FROM barangay_info bi
    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $barangayName = preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayInfo['barangay_name']);

    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = $barangayName;
    } elseif (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "Poblacion " . $barangayName;
    } elseif (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = $barangayName;
    } else {
        $barangayName = "Barangay " . $barangayName;
    }
} else {
    $barangayName = "NO BARANGAY FOUND";
}

$logo_result = $mysqli->query("SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1");
$logo = $logo_result->num_rows > 0 ? $logo_result->fetch_assoc() : null;

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Bugo - Login</title>
    <link rel="icon" type="image/png" href="assets/logo/logo.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --primary-dark: #0a58ca;
            --bg-overlay: rgba(0, 0, 0, 0.55);
            --card-bg: rgba(255, 255, 255, 0.92);
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: url('logo/bugo.png') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Modern Gradient Overlay */
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,30,60,0.5) 100%);
            z-index: 1;
            backdrop-filter: blur(4px);
        }

        /* Glassmorphism Card */
        .login-card {
            position: relative;
            z-index: 2;
            background: var(--card-bg);
            padding: 45px 35px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 420px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.4);
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-card img {
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
            transition: transform 0.3s ease;
        }
        .login-card img:hover {
            transform: scale(1.05);
        }

        .login-card h2 {
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }

        .login-card p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 25px;
        }

        /* Modern Inputs */
        .form-floating-custom {
            position: relative;
            margin-bottom: 20px;
        }

        .input-group {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            background: #fff;
        }

        .input-group:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
            transform: translateY(-1px);
        }

        .input-group-text {
            background: transparent;
            border: none;
            color: #999;
            padding-left: 15px;
        }

        .form-control {
            border: none;
            padding: 12px 15px;
            font-size: 0.95rem;
            color: #333;
        }
        
        .form-control:focus {
            box-shadow: none;
        }

        .btn-toggle-password {
            background: transparent;
            border: none;
            color: #999;
            z-index: 5;
        }
        .btn-toggle-password:hover {
            color: var(--primary-color);
            background: transparent;
        }

        /* Modern Button */
        .btn-primary {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            border: none;
            border-radius: 50px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 1rem;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.35);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 110, 253, 0.5);
            background: linear-gradient(135deg, #0b5ed7 0%, #004494 100%);
        }

        /* Checkbox & Links */
        .form-check-input {
            cursor: pointer;
        }
        .form-check-label {
            font-size: 0.9rem;
            color: #555;
            cursor: pointer;
        }
        .forgot-link {
            font-size: 0.9rem;
            color: #0d6efd;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .forgot-link:hover {
            color: #0043a8;
            text-decoration: underline;
        }

        /* Footer */
        .footer {
            position: relative;
            z-index: 2;
            color: rgba(255,255,255,0.8);
            margin-top: 30px;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        /* Recaptcha Container */
        .recaptcha-container {
            display: flex;
            justify-content: center;
            margin: 20px 0;
            transform-origin: center;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
                border-radius: 15px;
            }
            .recaptcha-container {
                transform: scale(0.85); /* Shrink recaptcha slightly on mobile */
            }
        }
    </style>
</head>
<body class="is-login">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5 col-xl-4">
                <div class="login-card">
                    <?php if($logo): ?>
                        <?php 
                        // Default logo
                        $logoDisplay = 'bugo_logo.png'; 

                        // Check if database result exists and try to find the correct column name
                        if ($logo) {
                            if (isset($logo['image']) && !empty($logo['image'])) {
                                $logoDisplay = $logo['image'];
                            } elseif (isset($logo['logo_img']) && !empty($logo['logo_img'])) {
                                $logoDisplay = $logo['logo_img']; // Alternative column name
                            } elseif (isset($logo['file_name']) && !empty($logo['file_name'])) {
                                $logoDisplay = $logo['file_name']; // Alternative column name
                            }
                        }
                    ?>
                    
                    <img src="assets/logo/<?php echo htmlspecialchars($logoDisplay); ?>" alt="Barangay Logo" width="90" class="mb-2">
                    <?php endif; ?>
                    
                    <h2>Welcome Back</h2>
                    <p>Sign in to access your dashboard</p>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                            <input type="text" name="username" class="form-control" placeholder="Username" required autocomplete="username">
                        </div>
                        
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Password" required autocomplete="current-password">
                            <button type="button" class="btn btn-toggle-password" id="togglePassword" aria-label="Show password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                        
                        <div class="recaptcha-container">
                            <div class="g-recaptcha" data-sitekey="6Ldid00rAAAAAJW0Uh8pFV_ZPyvhICFCnqesb6Mv"></div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100 mb-3">Sign In</button>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="form-check text-start">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label" for="remember">Remember me</label>
                            </div>
                            <a href="forgot/forgot_password.php" class="forgot-link">Forgot Password?</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($barangayName); ?>. All rights reserved.
    </div>

    <?php if (!empty($error_message)) : ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        <?php if (strpos($error_message, "LOCKED:") === 0): 
            $secondsLeft = (int) str_replace("LOCKED:", "", $error_message);
        ?>
            let remaining = <?php echo $secondsLeft; ?>;
            Swal.fire({
                icon: 'error',
                title: 'Account Locked',
                html: 'Too many failed login attempts.<br>Please wait <b id="countdown"></b>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                buttonsStyling: false,
                customClass: { confirmButton: 'btn btn-primary' },
                didOpen: () => {
                    const countdownEl = Swal.getHtmlContainer().querySelector('#countdown');
                    const timer = setInterval(() => {
                        let mins = Math.floor(remaining / 60);
                        let secs = remaining % 60;
                        countdownEl.textContent = `${mins}m ${secs}s`;
                        remaining--;
                        if (remaining < 0) {
                            clearInterval(timer);
                            Swal.close();
                            location.reload();
                        }
                    }, 1000);
                }
            });
        <?php else: ?>
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: <?php echo json_encode($error_message); ?>,
                confirmButtonColor: '#0d6efd',
                buttonsStyling: true
            });
        <?php endif; ?>
    });
    </script>
    <?php endif; ?>

    <?php if (!empty($_SESSION['login_success']) && !empty($_SESSION['redirect_page'])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });

            Toast.fire({
                icon: 'success',
                title: 'Welcome back!'
            }).then(() => {
                window.location.href = <?php echo json_encode($_SESSION['redirect_page']); ?>;
            });
        });
    </script>
    <?php
        unset($_SESSION['login_success']);
        unset($_SESSION['redirect_page']);
    endif; ?>

    <?php if (!empty($_SESSION['needs_email_for_2fa'])): ?>
    <script>
      document.addEventListener("DOMContentLoaded", function () {
        Swal.fire({
          icon: 'info',
          title: 'Security Notice',
          text: 'For better security, please add a valid email to your profile to enable Two-Factor Authentication.',
          confirmButtonText: 'Understood',
          confirmButtonColor: '#0d6efd'
        });
      });
    </script>
    <?php unset($_SESSION['needs_email_for_2fa']); endif; ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const pw = document.getElementById('password');
      const btn = document.getElementById('togglePassword');
      if (!pw || !btn) return;
      btn.addEventListener('click', function () {
        const isText = pw.type === 'text';
        pw.type = isText ? 'password' : 'text';
        const icon = this.querySelector('i');
        icon.classList.toggle('fa-eye', isText);
        icon.classList.toggle('fa-eye-slash', !isText);
        this.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
      });
    });
    </script>

</body>
</html>