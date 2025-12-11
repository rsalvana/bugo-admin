<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './include/encryption.php';
require_once './include/redirects.php';

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

// Check for session and user role
if (!isset($_SESSION['username']) || $mysqli->connect_error) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../security/404.html';
    exit;
}

$loggedInUsername = $_SESSION['username'] ?? null;

$profile = $mysqli->prepare("
    SELECT employee_fname, employee_mname, employee_lname, employee_sname,
           employee_email, employee_civil_status, profilePicture
    FROM employee_list
    WHERE employee_username = ?
");
$profile->bind_param("s", $loggedInUsername);
$profile->execute();
$result = $profile->get_result();
$employee = $result->fetch_assoc();

$stmt = $mysqli->prepare("
    SELECT el.employee_fname, el.employee_id, er.Role_Name 
    FROM employee_list el
    JOIN employee_roles er ON el.Role_Id = er.Role_Id
    WHERE el.employee_username = ?
");

if ($stmt) {
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $stmt->bind_result($userName, $employee_id, $roleName);
    $stmt->fetch();
    $stmt->close();

    $_SESSION['employee_id'] = $employee_id;
    $_SESSION['Role_Name'] = $roleName;

    $roleNameLower = strtolower($roleName);
    if (strpos($roleNameLower, 'bhw') === false) {
        header("Location: index.php");
        exit();
    }
} else {
    die('Prepare failed: ' . htmlspecialchars($mysqli->error));
}
require_once './logs/logs_trig.php';

$trigger = new Trigger();
// Check if logout request is made
if (isset($_POST['logout']) && $_POST['logout'] === 'true') {
    $trigger->isLogout(7, $employee_id);
    session_unset();  
    session_destroy(); 
    header("Location: index.php"); 
    exit();
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    
    <title>LGU BUGO - Barangay Health Worker</title>
    
    <link rel="stylesheet" href="css/form.css">
    <link rel="icon" type="image/png" href="assets/logo/logo.png">
    
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="util/logout.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        /* Pulse Animation for Notification */
        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        .badge-pulse {
            animation: pulse-red 2s infinite;
        }
        
        /* Fix for Badge Overlap */
        .icon-wrapper {
            position: relative;
            display: inline-block;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.6rem;
            padding: 0.25em 0.4em;
            border: 2px solid #343a40; /* Dark border to separate from icon */
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <a class="navbar-brand ps-3">
            <img src="assets/logo/bugo_logo.png" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
            Barangay Bugo
        </a>

        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" href="#!"><i class="fas fa-bars"></i></button>
        
        <?php require_once 'util/helper/router.php';?>
        
        <ul class="navbar-nav ms-auto me-4 align-items-center">
            
            <li class="nav-item dropdown me-3">
                <a class="nav-link dropdown-toggle" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="icon-wrapper">
                        <i class="bi bi-bell-fill fs-5 text-white"></i>
                        <span id="notif-badge" class="notification-badge badge rounded-pill bg-danger" style="display: none;">
                            0
                        </span>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="notifDropdown" style="width: 320px; max-height: 400px; overflow-y: auto;">
                    <li>
                        <div class="d-flex justify-content-between align-items-center px-3 py-2">
                            <h6 class="dropdown-header text-uppercase fw-bold text-primary p-0 m-0">New Requests</h6>
                            <button class="btn btn-link btn-sm p-0 text-decoration-none" onclick="markAllRead()" style="font-size: 0.75rem;">Clear Badge</button>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider my-0"></li>
                    
                    <div id="notif-list">
                        <li class="dropdown-item text-center small text-muted py-3">Checking...</li>
                    </div>

                    <li><hr class="dropdown-divider my-0"></li>
                    <li>
                        <a class="dropdown-item text-center small fw-bold text-primary py-2 bg-light" href="index_bhw.php?page=<?= urlencode(encrypt('med_request')) ?>">
                            View All Requests
                        </a>
                    </li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle text-white d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php if (!empty($employee['profilePicture'])): ?>
                        <img src="data:image/jpeg;base64,<?= base64_encode($employee['profilePicture']) ?>" class="rounded-circle me-2" style="width: 32px; height: 32px; object-fit: cover;">
                    <?php else: ?>
                        <i class="fas fa-user-circle me-2 fs-4"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($employee['employee_fname'] ?? 'Profile') ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item" href="<?= get_role_based_action('profile') ?>">View Profile</a></li>
                    <li><a class="dropdown-item" href="<?= get_role_based_action('settings') ?>">Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirmLogout();">Logout</a></li>
                </ul>
            </li>
        </ul>
    </nav>
    
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="profileModalLabel"><i class="fas fa-user-circle me-2"></i>Employee Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body row">
            <div class="col-md-4 text-center">
              <?php if (!empty($employee['profilePicture'])): ?>
                  <img src="data:image/jpeg;base64,<?= base64_encode($employee['profilePicture']) ?>" class="rounded-circle img-fluid" style="width: 150px; height: 150px; object-fit: cover;">
              <?php else: ?>
                  <i class="fas fa-user-circle text-secondary" style="font-size: 150px;"></i>
              <?php endif; ?>
            </div>
            <div class="col-md-8">
              <p><strong>Full Name:</strong> <?= htmlspecialchars($employee['employee_fname'] . ' ' . $employee['employee_mname'] . ' ' . $employee['employee_lname'] . ' ' . $employee['employee_sname']) ?></p>
              <p><strong>Email:</strong> <?= htmlspecialchars($employee['employee_email']) ?></p>
              <p><strong>Civil Status:</strong> <?= htmlspecialchars($employee['employee_civil_status']) ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="layoutSidenav">
        <div id="layoutSidenav_nav">
            <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                <div class="sb-sidenav-menu">
                    <div class="nav">
                        <div class="sb-sidenav-menu-heading">Core</div>
                        <a class="nav-link <?php echo ($page === 'bhw_dashboard') ? '' : 'collapsed'; ?>" href="index_bhw.php?page=<?php echo urlencode(encrypt('bhw_dashboard')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                        </a>
                        <a class="nav-link <?php echo ($page === 'med_request') ? '' : 'collapsed'; ?>" href="index_bhw.php?page=<?php echo urlencode(encrypt('med_request')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div> Request List
                        </a>
                        <a class="nav-link <?php echo ($page === 'med_inventory') ? '' : 'collapsed'; ?>" href="index_bhw.php?page=<?php echo urlencode(encrypt('med_inventory')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-gavel"></i></div> Medicine Inventory
                        </a>
                        <a class="nav-link <?php echo ($page === 'message') ? '' : 'collapsed'; ?>" href="index_bhw.php?page=<?php echo urlencode(encrypt('message')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-comment-dots"></i></div> Online Consultation
                        </a>                        
                        <a class="nav-link <?php echo ($page === 'bhw_report') ? '' : 'collapsed'; ?>" href="index_bhw.php?page=<?php echo urlencode(encrypt('bhw_report')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-chart-line"></i></div> Report
                        </a>                        
                    </div>
                </div>
                <div class="sb-sidenav-footer">
                    <div class="small">Logged in as:</div>
                    <h5 class="mt-4">Welcome, <?php echo htmlspecialchars($userName); ?> (<?php echo htmlspecialchars($roleName); ?>)</h5>
                </div>
            </nav>
        </div>
        <div id="layoutSidenav_content">
            <main>
                <?php require_once 'Modals/announcement.php'; ?>
                <main id="main" class="main">
                    <section class="section"> 
                        <?php
                        require_once __DIR__ . '/include/connection.php';
                        $mysqli = db_connection();
                          
                        $decryptedPage = 'bhw_dashboard'; 

                        if (isset($_GET['page'])) {
                            $decrypted = decrypt($_GET['page']);
                            if ($decrypted !== false) {
                                $decryptedPage = $decrypted;
                            }
                        }
                        switch ($decryptedPage) {
                            case 'bhw_dashboard':
                                include 'Modules/bhw_modules/bhw_dashboard.php';
                                break;
                            case 'med_request':
                                include 'Modules/bhw_modules/med_request.php'; 
                                break;
                            case 'bhw_report':
                                include 'Modules/bhw_modules/bhw_report.php';
                                break;
                            case 'message':
                                include 'api/message.php';
                                break;
                            case 'med_inventory':
                                include 'Modules/bhw_modules/med_inventory.php';
                                break;
                            case 'profile':
                                include 'Pages/profile.php';
                                break;
                            case 'settings':
                                include 'Pages/settings.php';
                                break;
                            case 'verify_2fa_password':
                                include 'auth/verify_2fa_password.php';
                                break;
                        }
                        ?>
                    </section>
                </main>
            </main>
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; Your Website 2023</div>
                        <div>
                            <a href="#">Privacy Policy</a> &middot; <a href="#">Terms &amp; Conditions</a>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    
    <script>
    function confirmLogout() {
        Swal.fire({
            title: 'Are you sure?',
            text: "You will be logged out.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Logging out...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                setTimeout(() => { window.location.href = "logout.php"; }, 1000);
            }
        });
        return false;
    }

    // --- BHW NOTIFICATION SYSTEM SCRIPT ---
    document.addEventListener('DOMContentLoaded', function() {
        let lastCount = 0; 
        let tempHidden = false; // Flag to temporarily hide badge

        function checkBhwNotifications() {
            if(tempHidden) return; // Don't update if user manually cleared it

            fetch('api/get_bhw_notifications.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notif-badge');
                const list = document.getElementById('notif-list');
                const currentCount = parseInt(data.count);
                
                // 1. Update Badge
                if (currentCount > 0) {
                    badge.innerText = currentCount > 99 ? '99+' : currentCount;
                    badge.style.display = 'inline-block';
                    badge.classList.add('badge-pulse'); 

                    // Toast Alert for NEW requests
                    if (currentCount > lastCount && lastCount !== 0) {
                        const Toast = Swal.mixin({
                            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true
                        });
                        Toast.fire({ icon: 'info', title: 'New Medicine Request!' });
                    }
                } else {
                    badge.style.display = 'none';
                    badge.classList.remove('badge-pulse');
                }

                lastCount = currentCount;

                // 2. Update Dropdown List
                if (data.data.length > 0) {
                    let html = '';
                    data.data.forEach(item => {
                        html += `
                            <li>
                                <a class="dropdown-item py-2 border-bottom" href="index_bhw.php?page=<?= urlencode(encrypt('med_request')) ?>">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <div class="small fw-bold text-dark"><i class="bi bi-person-fill text-primary"></i> ${item.resident_name}</div>
                                            <div class="small text-muted" style="font-size:0.75rem;">New Medicine Request</div>
                                        </div>
                                        <small class="text-secondary" style="font-size: 0.7rem;">${item.date}</small>
                                    </div>
                                </a>
                            </li>
                        `;
                    });
                    list.innerHTML = html;
                } else {
                    list.innerHTML = '<li class="dropdown-item text-center small text-muted py-3">No pending requests</li>';
                }
            })
            .catch(err => console.error('Notification Error:', err));
        }

        // --- CLEAR BADGE MANUALLY ---
        // Allows user to hide the red dot temporarily (until page refresh)
        window.markAllRead = function() {
            const badge = document.getElementById('notif-badge');
            badge.style.display = 'none';
            tempHidden = true; // Stop polling from showing it again immediately
            
            // Optional: Re-enable checking after 60 seconds
            setTimeout(() => { tempHidden = false; }, 60000);
        }

        checkBhwNotifications();
        setInterval(checkBhwNotifications, 5000);
    });
    </script>
</body>
</html>