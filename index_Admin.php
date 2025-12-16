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

// Redirect to login if not authenticated
if (!isset($_SESSION['username']) || $mysqli->connect_error) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../security/404.html';
    exit;
}

$loggedInUsername = $_SESSION['username'] ?? null;

// Get page from URL for Active State
$page = 'admin_dashboard';
if (isset($_GET['page'])) {
    $tmp = decrypt($_GET['page']);
    if ($tmp !== false) $page = $tmp;
}

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

    // ✅ Restrict access to only 'admin'
    if (strtolower($roleName) !== 'admin') {
        header("Location: /index.php");
        exit();
    }
} else {
    die('Database error: ' . htmlspecialchars($mysqli->error));
}

require_once './logs/logs_trig.php';

$trigger = new Trigger();
// Handle logout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
     $trigger->isLogout(7, $employee_id);
    session_unset();
    session_destroy();
    header("Location: /index.php");
    exit();
}

$barangayInfoSql = "SELECT
                        bm.city_municipality_name,
                        b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1";
$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = (preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName));
    if (stripos($barangayName, "Barangay") !== false) {
        $barangayName = ($barangayName);
    } else if (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "Poblacion " .($barangayName);
    } else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = ($barangayName);
    } else {
        $barangayName = "Barangay " . ($barangayName);
    }
} else {
    $barangayName = "NO BARANGay FOUND";
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo = null;
if ($logo_result->num_rows > 0) {
    $logo = $logo_result->fetch_assoc();
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>LGU BUGO - Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="css/form.css">
    <link href="css/styles.css" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/form_print.css">
    <link rel="icon" type="image/png" href="assets/logo/logo.png">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body.sb-nav-fixed #layoutSidenav_content { padding-top: 56px; }

        /* Ensure profile dropdown is clickable */
        .sb-topnav ul.navbar-nav.ms-auto.me-4 {
            position: relative !important;
            z-index: 1050 !important;
        }

        /* === ACTIVE STATE HIGHLIGHT === */
        .sb-sidenav .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff !important;
            border-left: 4px solid #0d6efd; /* Blue identifier line */
            font-weight: 600;
        }
        .sb-sidenav .nav-link.active .sb-nav-link-icon {
            color: #0d6efd !important;
        }

        /* Mobile Sidebar Adjustments */
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
            #layoutSidenav_nav[data-open="true"] { transform: translateX(0) !important; }
            
            #layoutSidenav_content {
                margin-left: 0 !important;
                padding-left: 0 !important;
                width: 100% !important;
            }

            /* Fix table scrolling */
            .table-responsive {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
            }
            main .section table {
                min-width: 900px !important; 
            }
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <a class="navbar-brand ps-3"> 
            <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
            <?php else: ?>
                <p>No Logo</p>
            <?php endif; ?>
            <?php echo $barangayName?>
        </a>

        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" type="button">
            <i class="fas fa-bars"></i>
        </button>
        
        <?php require_once 'util/helper/router.php';?>
        
        <ul class="navbar-nav ms-auto me-4">
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
            <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>Employee Profile</h5>
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

    <div id="layoutSidenav" class="container-fluid">
        <div id="layoutSidenav_nav">
            <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                <div class="sb-sidenav-menu">
                    <div class="nav">
                        <div class="sb-sidenav-menu-heading">Core</div>
                        
                        <a class="nav-link nav-link-mobile <?php echo ($page === 'admin_dashboard') ? 'active' : ''; ?>"
                           href="index_Admin.php?page=<?php echo urlencode(encrypt('admin_dashboard')); ?>">
                           <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                        </a>

                        <a class="nav-link <?php echo in_array($page, ['urgent_request','view_appointments']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#revenueDepartment_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> Revenue Department
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['urgent_request','view_appointments']) ? 'show' : ''; ?>" id="revenueDepartment_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'urgent_request') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('urgent_request')); ?>">On-Site Request</a>
                            <a class="nav-link <?php echo ($page === 'view_appointments') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">Appointment List</a>
                           </nav>
                        </div>

                        <a class="nav-link <?php echo in_array($page, ['beso']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#besoDepartment_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> BESO Department
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['beso']) ? 'show' : ''; ?>" id="besoDepartment_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'beso') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('beso')); ?>">BESO List</a>
                           </nav>
                        </div>                                                   

                        <a class="nav-link <?php echo in_array($page, ['case_list']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#luponDepartment_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> Lupon Department
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['case_list']) ? 'show' : ''; ?>" id="luponDepartment_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'case_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('case_list')); ?>">Case List</a>
                           </nav>
                        </div>   

                        <a class="nav-link <?php echo in_array($page, ['event_list']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#multimediaDepartment_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> Multimedia Department
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['event_list']) ? 'show' : ''; ?>" id="multimediaDepartment_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'event_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('event_list')); ?>">Event List</a>
                           </nav>
                        </div>   

                        <a class="nav-link <?php echo in_array($page, ['med_request','med_inventory','resident_info']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#bhw_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> BHW Department
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['med_request','med_inventory','resident_info']) ? 'show' : ''; ?>" id="bhw_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'med_request') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('med_request')); ?>">Request List</a>
                            <a class="nav-link <?php echo ($page === 'resident_info') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('resident_info')); ?>">Resident List</a>        
                            <a class="nav-link <?php echo ($page === 'med_inventory') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('med_inventory')); ?>">Medicine Inventory</a>
                           </nav>
                         </div>
                          
                        <a class="nav-link <?php echo in_array($page, ['official_info','barangay_official_list','certificate_list','time_slot','Zone_leaders','add_guidelines']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#barangay_official_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> Barangay Information
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['official_info','barangay_official_list','certificate_list','time_slot','Zone_leaders','add_guidelines']) ? 'show' : ''; ?>" id="barangay_official_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'barangay_official_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('barangay_official_list')); ?>">Official List</a>
                            <a class="nav-link <?php echo ($page === 'official_info') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('official_info')); ?>">Employees</a>                        
                            <a class="nav-link <?php echo ($page === 'certificate_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('certificate_list')); ?>">Certificate List</a>
                            <a class="nav-link <?php echo ($page === 'time_slot') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('time_slot')); ?>">Time Slot List</a>
                            <a class="nav-link <?php echo ($page === 'Zone_leaders') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('Zone_leaders')); ?>">Add Zone Leader</a>
                            <a class="nav-link <?php echo ($page === 'add_guidelines') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('add_guidelines')); ?>">Add Guidelines</a>
                           </nav>
                        </div>
                       
                        <a class="nav-link <?php echo in_array($page, ['feedbacks','announcements','faq']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#notice_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-bullhorn"></i></div> Notice
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['feedbacks','announcements','faq']) ? 'show' : ''; ?>" id="notice_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'feedbacks') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('feedbacks')); ?>">Feedbacks</a>
                            <a class="nav-link <?php echo ($page === 'announcements') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('announcements')); ?>">Announcements</a>
                            <a class="nav-link <?php echo ($page === 'faq') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('faq')); ?>">FAQ</a>
                           </nav>
                        </div>

                        <a class="nav-link <?php echo in_array($page, ['audit','residents_audit']) ? '' : 'collapsed'; ?>"
                           data-bs-toggle="collapse" data-bs-target="#audit_nav_desktop">
                           <div class="sb-nav-link-icon"><i class="fas fa-user-secret"></i></div> Audit Logs
                           <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                        </a>
                        <div class="collapse <?php echo in_array($page, ['audit','residents_audit']) ? 'show' : ''; ?>" id="audit_nav_desktop">
                           <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link <?php echo ($page === 'audit') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('audit')); ?>">Admin Audit Logs</a>
                            <a class="nav-link <?php echo ($page === 'residents_audit') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('residents_audit')); ?>">Residents Audit Logs</a>
                           </nav>
                        </div>

                        <a class="nav-link <?php echo ($page === 'reports') ? 'active' : 'collapsed'; ?>"
                           href="index_Admin.php?page=<?php echo urlencode(encrypt('reports')); ?>">
                           <div class="sb-nav-link-icon"><i class="fas fa-chart-line"></i></div> Report
                        </a>
                        <a class="nav-link <?php echo ($page === 'archive') ? 'active' : 'collapsed'; ?>"
                           href="index_Admin.php?page=<?php echo urlencode(encrypt('archive')); ?>">
                           <div class="sb-nav-link-icon"><i class="fas fa-archive"></i></div> Archive
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
                <div class="container-fluid px-4">
                <?php require_once 'Modals/announcement.php'; ?>
                <main id="main" class="main">

                <section class="section">

                <?php
                require_once __DIR__ . '/include/connection.php';
                $mysqli = db_connection();

                switch ($page) { 
                  case 'admin_home':
                  case 'homepage': include 'api/homepage.php'; break;
                  case 'admin_dashboard': include 'api/admin_dashboard.php'; break;
                  case 'official_info': include 'api/official_info.php'; break;
                  case 'resident_info': include 'api/resident_info.php'; break;
                  case 'barangay_information': include 'api/barangay_info.php'; break;
                  case 'view_appointments': include 'api/view_appointments.php'; break;
                  case 'senior_certification': include 'forms/senior_certification.php'; break;
                  case 'postalId_certification': include 'forms/postalId_certification.php'; break;
                  case 'barangay_certification': include 'forms/barangay_certification.php'; break;
                  case 'barangay_clearance': include 'forms/barangay_clearance.php'; break;
                  case 'event_list': include 'api/event_list.php'; break;
                  case 'event_calendar': include 'api/event_calendar.php'; break;
                  case 'add_guidelines': include 'api/add_guidelines.php'; break;
                  case 'feedbacks': include 'api/Feedbacks.php'; break;
                  case 'case_list': include 'api/case_list.php'; break;
                  case 'faq': include 'api/faq.php'; break;
                  case 'Zone_add': include 'api/Zone_add.php'; break;
                  case 'Zone_leaders': include 'api/Zone_leaders.php'; break;
                  case 'archive': include 'api/archive.php'; break;
                  case 'barangay_official_list': include 'api/barangay_official_list.php'; break;
                  case 'barangay_info': include 'api/barangay_info.php'; break;
                  case 'urgent_request': include 'api/urgent_request.php'; break;
                  case 'reports': include 'api/reports.php'; break;
                  case 'certificate_list': include 'api/certificate_list.php'; break;
                  case 'time_slot': include 'api/time_slot.php'; break;
                  case 'linked_families': include 'Pages/linked_families.php'; break;
                  case 'unlink_relationship': include './Pages/unlink_relationship.php'; break;
                  case 'profile': include 'Pages/profile.php'; break;
                  case 'settings': include 'Pages/settings.php'; break;
                  case 'audit': include 'api/audit_logs.php'; break;
                  case 'residents_audit': include 'api/residents_audit_logs.php'; break;
                  case 'beso': include 'api/beso.php'; break;
                  case 'announcements': include 'api/announcements.php'; break;
                  case 'verify_2fa_password': include 'auth/verify_2fa_password.php'; break;
                  case 'add_announcement': include 'components/announcement/add_announcement.php'; break;
                  
                  // BHW MODULES REUSE
                  case 'med_inventory': include 'Modules/bhw_modules/med_inventory.php'; break;
                  case 'med_request': include 'Modules/bhw_modules/med_request.php'; break;
                  
                  default: echo "<div class='alert alert-danger'>Invalid or missing page.</div>"; break;
                }
                ?>
                </section>
                </main>
                </div>
            </main>
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; <?php echo $barangayName . ' ' . date('Y'); ?></div>
                        <div>
                            <a href="#">Privacy Policy</a> &middot; <a href="#">Terms &amp; Conditions</a>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
    <script src="js/scripts.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js" crossorigin="anonymous"></script>
    
    <script>
    // === Logic for Mobile Sidebar ===
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
            // Auto close sidebar when clicking a link on mobile
            const mobileLinks = document.querySelectorAll('.nav-link-mobile');
            mobileLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.matchMedia('(max-width: 992px)').matches) {
                        nav.removeAttribute('data-open');
                    }
                });
            });
        }
        
        // Auto-wrap tables for mobile responsiveness
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
    });

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
    </script>
</body>
</html>