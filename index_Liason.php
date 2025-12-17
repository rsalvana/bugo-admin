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
    if (strpos($roleNameLower, 'liason') === false) {
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

// --- DETERMINE CURRENT PAGE FOR ACTIVE STATE ---
$page = 'liason_dashboard'; // Default
if (isset($_GET['page'])) {
    $decrypted = decrypt($_GET['page']);
    if ($decrypted !== false) {
        $page = $decrypted;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    
    <title>LGU BUGO - Liaison Officer</title>
    
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
        /* Pulse Animation */
        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        .badge-pulse { animation: pulse-red 2s infinite; }
        
        .icon-wrapper { position: relative; display: inline-block; }
        .notification-badge {
            position: absolute; top: -5px; right: -5px;
            font-size: 0.6rem; padding: 0.25em 0.4em;
            border: 2px solid #343a40; 
        }

        /* --- ACTIVE STATE HIGHLIGHT (IDENTIFIER) --- */
        .sb-sidenav .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1); /* Subtle highlight */
            color: #fff !important;
            border-left: 4px solid #0d6efd; /* Blue identifier line */
            font-weight: 600;
        }
        .sb-sidenav .nav-link.active .sb-nav-link-icon {
            color: #0d6efd !important; 
        }

        /* --- MOBILE SIDEBAR CSS --- */
        body.sb-nav-fixed #layoutSidenav_content { padding-top: 56px; }
        
        @media (max-width: 992px) {
            #layoutSidenav { display: block !important; }
            
            #layoutSidenav_nav {
                position: fixed !important;
                top: 56px; bottom: 0; left: 0;
                width: 260px !important;
                transform: translateX(-100%) !important; 
                transition: transform .25s ease;
                z-index: 1029;
                background-color: #212529;
                overflow-y: auto;
            }
            
            #layoutSidenav_nav[data-open="true"] { 
                transform: translateX(0) !important; 
            }
            
            #layoutSidenav_content {
                margin-left: 0 !important;
                padding-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            #layoutSidenav_content > main,
            #layoutSidenav_content .container-fluid,
            #layoutSidenav_content .container {
                width: 100% !important;
                max-width: 100% !important;
            }
            
            main.main, .main, .section, section.section {
                min-width: 0 !important;
                max-width: 100% !important;
            }
            .row > [class*="col-"] { min-width: 0 !important; }

            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            
            .table-responsive table th,
            .table-responsive table td {
                white-space: nowrap !important;
                word-break: normal !important;
            }
        }
    </style>
</head>

<body class="sb-nav-fixed">
    
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <a class="navbar-brand ps-3">
            <img src="assets/logo/bugo_logo.png" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
            <span class="d-none d-sm-inline">Barangay Bugo</span>
        </a>

        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" href="#!"><i class="fas fa-bars"></i></button>
        
        <?php require_once 'util/helper/router.php';?>
        
        <ul class="navbar-nav ms-auto me-3 me-lg-4 align-items-center">
            
            <li class="nav-item dropdown me-3">
                <a class="nav-link" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="icon-wrapper">
                        <i class="bi bi-bell-fill fs-5 text-white"></i>
                        <span id="notif-badge" class="notification-badge badge rounded-pill bg-danger" style="display: none;">0</span>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="notifDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                    <li>
                        <div class="d-flex justify-content-between align-items-center px-3 py-2">
                            <h6 class="dropdown-header text-uppercase fw-bold text-primary p-0 m-0">To Deliver</h6>
                            <button class="btn btn-link btn-sm p-0 text-decoration-none" onclick="markAllRead()" style="font-size: 0.75rem;">Clear</button>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider my-0"></li>
                    <div id="notif-list">
                        <li class="dropdown-item text-center small text-muted py-3">Checking...</li>
                    </div>
                    <li><hr class="dropdown-divider my-0"></li>
                    <li>
                        <a class="dropdown-item text-center small fw-bold text-primary py-2 bg-light" href="index_Liason.php?page=<?= urlencode(encrypt('med_request')) ?>">
                            View Request List
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
                    <span class="d-none d-md-inline"><?= htmlspecialchars($employee['employee_fname'] ?? 'Profile') ?></span>
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
                        
                        <a class="nav-link nav-link-mobile <?php echo ($page === 'liason_dashboard') ? 'active' : ''; ?>" href="index_Liason.php?page=<?php echo urlencode(encrypt('liason_dashboard')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                        </a>
                        <a class="nav-link nav-link-mobile <?php echo ($page === 'med_request') ? 'active' : ''; ?>" href="index_Liason.php?page=<?php echo urlencode(encrypt('med_request')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div> Request List
                        </a>
                    </div>
                </div>
                <div class="sb-sidenav-footer">
                    <div class="small">Logged in as:</div>
                    <div class="small text-white-50"><?php echo htmlspecialchars($roleName); ?></div>
                </div>
            </nav>
        </div>
        <div id="layoutSidenav_content">
            <main>
                <?php require_once 'Modals/announcement.php'; ?>
                <main id="main" class="main">
                    <section class="section"> 
                        <?php
                        // Page Routing Logic
                        require_once __DIR__ . '/include/connection.php';
                        $mysqli = db_connection();
                          
                        switch ($page) {
                            case 'liason_dashboard':
                                include 'Modules/liason_modules/liason_dashboard.php';
                                break;
                            case 'med_request':
                                include 'Modules/liason_modules/med_request.php'; 
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
                        <div class="text-muted">Copyright &copy; Barangay Bugo 2024</div>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    
    <script src="js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    
    <script>
    // --- SIDEBAR TOGGLE LOGIC ---
    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('sidebarToggle');
        const nav = document.getElementById('layoutSidenav_nav');
        
        if (btn && nav) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); 
                const open = nav.getAttribute('data-open') === 'true';
                nav.setAttribute('data-open', String(!open));
            });

            document.addEventListener('click', function (e) {
                if (window.matchMedia('(max-width: 992px)').matches) {
                    if (!nav.contains(e.target) && !btn.contains(e.target)) {
                        nav.removeAttribute('data-open');
                    }
                }
            });

            const mobileLinks = document.querySelectorAll('.nav-link-mobile');
            mobileLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.matchMedia('(max-width: 992px)').matches) {
                        nav.removeAttribute('data-open');
                    }
                });
            });
        }
    });

    function confirmLogout() {
        Swal.fire({
            title: 'Logout?',
            text: "Are you sure you want to end your session?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "logout.php";
            }
        });
        return false;
    }

    // --- NOTIFICATION SYSTEM (Liaison Only) ---
    document.addEventListener('DOMContentLoaded', function() {
        let lastCount = 0; 
        let tempHidden = false; 

        function checkLiasonNotifications() {
            if(tempHidden) return; 

            fetch('api/get_liason_notifications.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notif-badge');
                const list = document.getElementById('notif-list');
                const currentCount = parseInt(data.count);
                
                if (currentCount > 0) {
                    badge.innerText = currentCount > 99 ? '99+' : currentCount;
                    badge.style.display = 'inline-block';
                    badge.classList.add('badge-pulse'); 
                } else {
                    badge.style.display = 'none';
                    badge.classList.remove('badge-pulse');
                }

                if (data.data.length > 0) {
                    let html = '';
                    data.data.forEach(item => {
                        html += `<li><a class="dropdown-item py-2 border-bottom small" href="index_Liason.php?page=<?= urlencode(encrypt('med_request')) ?>">
                                    <div class="fw-bold">${item.resident_name}</div>
                                    <div class="text-muted" style="font-size:0.7rem">Ready for Delivery</div>
                                </a></li>`;
                    });
                    list.innerHTML = html;
                } else {
                    list.innerHTML = '<li class="dropdown-item text-center small text-muted py-2">No new deliveries</li>';
                }
            })
            .catch(err => console.error('Notification Error:', err));
        }

        window.markAllRead = function() {
            document.getElementById('notif-badge').style.display = 'none';
            tempHidden = true; 
            setTimeout(() => { tempHidden = false; }, 60000);
        }

        checkLiasonNotifications();
        setInterval(checkLiasonNotifications, 5000);
    });

    // --- Table Responsiveness Helper ---
    document.addEventListener('DOMContentLoaded', function () {
        function wrapTables() {
            const main = document.querySelector('main .section, main');
            if (main) {
                main.querySelectorAll('table').forEach(t => {
                    if (!t.closest('.table-responsive')) {
                        const wrap = document.createElement('div');
                        wrap.className = 'table-responsive';
                        t.parentNode.insertBefore(wrap, t);
                        wrap.appendChild(t);
                    }
                });
            }
        }
        wrapTables();
    });
    </script>
</body>
</html>