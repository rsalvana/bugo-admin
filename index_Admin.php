<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './include/encryption.php';
require_once  './include/redirects.php';

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// Redirect to login if not authenticated
if (!isset($_SESSION['username']) || $mysqli->connect_error) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../security/404.html';
    exit;
}

$loggedInUsername = $_SESSION['username'] ?? null;

// Get page from URL
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
 
  <link rel="stylesheet" href="css/form.css">
  <link href="css/styles.css" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/form_print.css">

  <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <!--<link rel="stylesheet" href="css/responsive.css">-->

  <link rel="icon" type="image/png" href="assets/logo/logo.png">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>-->
  
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
       body.sb-nav-fixed #layoutSidenav_content { padding-top: 56px; }

       /* === ADMIN/LOGOUT DROPDOWN FIX START === */
       /* This ensures the top-right profile dropdown is clickable
           CORRECTED SELECTOR to include .me-4 */
       .sb-topnav ul.navbar-nav.ms-auto.me-4 {
         position: relative !important; /* Required for z-index */
         z-index: 1050 !important;   /* Higher than nav/sidebar */
       }
       /* === ADMIN/LOGOUT DROPDOWN FIX END === */

       @media (max-width: 992px) {
         #layoutSidenav { display: block !important; }
         #layoutSidenav_nav {
          position: fixed !important;
          top: 56px; bottom: 0; left: 0;
          width: 260px !important;
          transform: translateX(-100%) !important;
          transition: transform .25s ease;
          z-index: 1029;
         }
         #layoutSidenav_nav[data-open="true"] { transform: translateX(0) !important; }
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

         /* === DROPDOWN Z-INDEX FIX START === */
         /* This tells the filter card to stack "on top" of other content */
         #reqFilters, 
         form.card,
         .card[data-filters] { 
          position: relative; /* Ensures z-index is applied */
          z-index: 10;        /* Lifts this card (and its children) up */
         }
         /* === DROPDOWN Z-INDEX FIX END === */

         /* === DROPDOWN SIZE & HEIGHT FIX START === */
         /* Force dropdown items back to a normal font size on mobile */
         .dropdown-menu .dropdown-item {
          font-size: 1rem !important; /* 16px. Use !important to override custom styles */
          padding: 0.5rem 1rem !important; /* Add normal padding */
         }
         /* Limit the height of the dropdown menu itself and make it scrollable */
         .dropdown-menu {
          max-height: 250px !important; /* Adjust this value as needed */
          overflow-y: auto !important;
         }
         /* === DROPDOWN SIZE & HEIGHT FIX END === */

         /* === BUTTON TOOLBAR FIX START === */
         /* Targets toolbars with buttons (like Add Event) */
         .btn-toolbar,
         .card-header .btn-group,
         /* Be more specific to avoid filter forms */
         div.text-end > .btn-group {
          display: flex !important;
          flex-wrap: wrap !important; /* Allow wrapping */
          justify-content: flex-start !important;
          gap: 0.5rem;
         }
         .btn-toolbar .btn,
         .card-header .btn-group .btn,
         div.text-end > .btn-group .btn {
          width: auto !important; /* Let buttons be normal width */
          flex-grow: 0 !important;
         }
         /* === BUTTON TOOLBAR FIX END === */

         /* === TABLE COLUMN FIX START === */
         /* Force tables to display as tables (not stacked blocks)
            This allows the .table-responsive wrapper to add horizontal scroll */
         main .section table {
          display: table !important; /* Force display as table */
          width: 100% !important; /* Make table fill the scroll wrapper */
          /* INCREASED min-width AGAIN for wider tables like Cases */
          min-width: 900px !important; /* Force a min-width to ensure scroll */
          table-layout: auto !important; /* Revert from fixed to let columns size naturally */
         }
         main .section table thead {
          display: table-header-group !important; /* Force display as header group */
         }
         main .section table tbody {
          display: table-row-group !important; /* Force display as body group */
         }
         main .section table tr {
          display: table-row !important; /* Force display as row */
         }
         main .section table th,
         main .section table td {
          display: table-cell !important; /* Force display as cell */
          width: auto !important; /* Let cell determine its own width */
          /* REVERT to nowrap to enable horizontal scroll */
          white-space: nowrap !important;
          word-break: normal !important; /* Revert */
          overflow-wrap: normal !important; /* Revert */
          vertical-align: middle !important; /* Keep cells aligned */
         }
         /* Hide any stacked-table labels that might still be showing */
         main .section table td::before {
          display: none !important;
         }
         /* === TABLE COLUMN FIX END === */

         
         
         
         
         /* === TABLE SCROLL FIX (REVISED) === */
         /* Your JS file automatically wraps tables in a <div>
            with the class '.table-responsive'.
            This CSS rule ensures that div is ALWAYS scrollable
            on mobile, overriding any other styles. */
         .table-responsive {
             overflow-x: auto !important;
             -webkit-overflow-scrolling: touch !important;
         }
         /* === TABLE SCROLL FIX END === */
         
         
         
         

         /* === ARCHIVE PAGE FIX START === */
         /* Force Archive nav-tabs (Residents, Employees) to be side-by-side */
         .nav-tabs {
          display: flex !important;
          flex-wrap: wrap !important; /* Allow wrapping on very small screens */
         }
         .nav-tabs .nav-item {
          width: auto !important; /* Let tabs be their natural width */
          flex-grow: 0 !important;
         }
         
         /* Force action buttons (Restore, Delete) inside tables to be side-by-side */
         main .section table td .btn-group {
          display: flex !important;
          flex-wrap: nowrap !important; /* Keep buttons on one line */
          gap: 0.5rem; /* Add spacing */
         }
         main .section table td .btn-group .btn {
          width: auto !important; /* Let buttons be their natural width */
         }
         /* === ARCHIVE PAGE FIX END === */

         /* === ZONE LEADERS FIX START === */
         /* Assuming each leader entry is a div/li with specific classes or structure */
         /* Adjust the selector '.zone-leader-entry' if your HTML uses a different class */
         .zone-leader-entry, 
         /* Add other relevant selectors if needed, e.g., list items */
         .list-group-item.zone-leader { 
          display: flex !important;
          flex-wrap: wrap !important; /* Allow wrapping if needed on very small screens */
          justify-content: space-between !important; /* Space out items */
          align-items: center !important; /* Align items vertically */
          gap: 0.5rem; /* Add some space */
         }
         .zone-leader-entry > *,
         .list-group-item.zone-leader > * {
          flex-basis: auto !important; /* Allow items to take natural width */
          margin-bottom: 0 !important; /* Remove any bottom margin causing stacking */
         }
         /* === ZONE LEADERS FIX END === */
       }
  </style>
 
  </head>
<body class="sb-nav-fixed">
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <a class="navbar-brand ps-3" > <?php if ($logo): ?>
    <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
    <?php else: ?>
        <p>No active Barangay logo found.</p>
    <?php endif; ?>
    <?php echo $barangayName?>
</a>

        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
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
    <div id="layoutSidenav" class="container-fluid">
        <div id="layoutSidenav_nav">
            <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                <div class="sb-sidenav-menu">
                    <div class="nav">
                        <div class="sb-sidenav-menu-heading">Core</div>
                        <a class="nav-link <?php echo ($page === 'admin_dashboard') ? 'active' : 'collapsed'; ?>"
                               href="index_Admin.php?page=<?php echo urlencode(encrypt('admin_dashboard')); ?>">
                               <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                             </a>
                             <a class="nav-link <?php echo ($page === 'urgent_request') ? 'active' : 'collapsed'; ?>"
                               href="index_Admin.php?page=<?php echo urlencode(encrypt('urgent_request')); ?>">
                               <div class="sb-nav-link-icon"><i class="fas fa-exclamation-circle"></i></div> On-Site Request
                             </a>
                             <a class="nav-link <?php echo ($page === 'view_appointments') ? 'active' : 'collapsed'; ?>"
                               href="index_Admin.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">
                               <div class="sb-nav-link-icon"><i class="fas fa-exclamation-circle"></i></div> View Appointments
                             </a>

                             <a class="nav-link <?php echo in_array($page, ['barangay_official_list','barangay_info','certificate_list','time_slot','Zone_leaders','add_guidelines']) ? '' : 'collapsed'; ?>"
                               data-bs-toggle="collapse" data-bs-target="#barangay_official_nav_desktop">
                               <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div> Barangay Information
                               <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                             </a>
                             <div class="collapse <?php echo in_array($page, ['barangay_official_list','barangay_info','certificate_list','time_slot','Zone_leaders','add_guidelines']) ? 'show' : ''; ?>" id="barangay_official_nav_desktop">
                               <nav class="sb-sidenav-menu-nested nav">
                                <a class="nav-link <?php echo ($page === 'barangay_official_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('barangay_official_list')); ?>">Official List</a>
                                <!--<a class="nav-link <?php echo ($page === 'barangay_info') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('barangay_info')); ?>">Barangay</a>-->
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

                             <a class="nav-link <?php echo in_array($page, ['resident_info','official_info','beso','event_list','case_list']) ? '' : 'collapsed'; ?>"
                               data-bs-toggle="collapse" data-bs-target="#master_files_desktop">
                               <div class="sb-nav-link-icon"><i class="fas fa-folder"></i></div> Master Files
                               <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                             </a>
                             <div class="collapse <?php echo in_array($page, ['resident_info','official_info','beso','event_list','case_list']) ? 'show' : ''; ?>" id="master_files_desktop">
                               <nav class="sb-sidenav-menu-nested nav">
                                <a class="nav-link <?php echo ($page === 'resident_info') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('resident_info')); ?>">Resident List</a>
                                <a class="nav-link <?php echo ($page === 'official_info') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('official_info')); ?>">Employees</a>
                                <a class="nav-link <?php echo ($page === 'beso') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('beso')); ?>">BESO</a>
                                <a class="nav-link <?php echo ($page === 'event_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('event_list')); ?>">Event List</a>
                                <a class="nav-link <?php echo ($page === 'case_list') ? 'active' : ''; ?>" href="index_Admin.php?page=<?php echo urlencode(encrypt('case_list')); ?>">Case List</a>
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
// $decryptedPage variable is already set at the top of the file
switch ($page) { // Use the $page variable
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
  default: echo "<div class='alert alert-danger'>Invalid or missing page.</div>"; break;
}
?>



</section>

</main>

                </main>
                <footer class="py-4 bg-light mt-auto">
                    <div class="container-fluid px-4">
                        <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; <?php echo $barangayName . ' ' . date('Y'); ?></div>
                            <div>
                                <a href="#">Privacy Policy</a>
                                &middot;
                                <a href="#">Terms &amp; Conditions</a>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

    <script src="js/scripts.js"></script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js" crossorigin="anonymous"></script>
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
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                // Slight delay so loading is visible
                setTimeout(() => {
                    window.location.href = "logout.php";
                }, 1000);
            }
        });
        return false;
    }
    </script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // === This is your NEW mobile toggle logic (preserved) ===
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
        }

        // === This is your NEW table-wrapping logic (preserved) ===
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
        
        
        // === This is your OLD sidebar dropdown logic (RESTORED) ===
        // --- COLLAPSE POLYFILL (supports Bootstrap 4/5 & prevents double-handling) ---
        const TOGGLER_SEL = '[data-bs-toggle="collapse"], [data-toggle="collapse"]';

        // restore open sections from localStorage
        try {
            const saved = JSON.parse(localStorage.getItem('sb-sidenav-sections') || '{}');
            Object.entries(saved).forEach(([id, open]) => {
                const el = document.getElementById(id);
                // Check if it's NOT already open from PHP
                if (el && open && !el.classList.contains('show')) {
                   // el.classList.add('show'); // We let the PHP handle the initial 'show'
                }
            });
        } catch (e) {}

        // Function to save the state
        const persist = () => {
            const map = {};
            document.querySelectorAll('.sb-sidenav .collapse[id]').forEach(c => map[c.id] = c.classList.contains('show'));
            localStorage.setItem('sb-sidenav-sections', JSON.stringify(map));
        };

        // helper: resolve target from bs4/bs5 attrs or href "#id"
        const getTarget = (el) => {
            const sel = el.getAttribute('data-bs-target') || el.getAttribute('data-target') || el.getAttribute('href');
            if (!sel || !sel.startsWith('#')) return null;
            return document.querySelector(sel);
        };

        // Find all sidebar collapse toggles
        document.querySelectorAll(TOGGLER_SEL).forEach(link => {
            const target = getTarget(link);
            // Only act on toggles that point to a valid element
            if (!target) return;
            // Only act on toggles inside the sidebar
            if (!link.closest('.sb-sidenav')) return; 

            // initial a11y/state
            link.setAttribute('role', 'button');
            if (target.id) link.setAttribute('aria-controls', target.id);
            const initOpen = target.classList.contains('show');
            link.setAttribute('aria-expanded', String(initOpen));
            link.classList.toggle('collapsed', !initOpen);

            // This is the click handler that does all the work
            const act = (ev) => {
                // Stop Bootstrap's default click handler
                ev.preventDefault();
                ev.stopPropagation(); 

                const isOpen = target.classList.contains('show');

                // close ALL other sections (this is the accordion behavior)
                document.querySelectorAll('.sb-sidenav .collapse.show').forEach(c => {
                    if (c !== target) c.classList.remove('show');
                });
                document.querySelectorAll(TOGGLER_SEL).forEach(t => {
                    const tar = getTarget(t);
                    if (!tar || tar === target || !t.closest('.sb-sidenav')) return;
                    t.setAttribute('aria-expanded', 'false');
                    t.classList.add('collapsed');
                });

                // toggle current (clicking the same header will CLOSE it)
                target.classList.toggle('show', !isOpen);
                link.setAttribute('aria-expanded', String(!isOpen));
                link.classList.toggle('collapsed', isOpen);

                persist(); // Save state to localStorage
            };

            link.addEventListener('click', act, true); // capture to beat BS handlers
            link.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') act(e);
            });
        });

        // NO LONGER NEEDED because PHP handles it, but we persist state
        // ensure the group containing the current .active link is open on first load
        document.querySelectorAll('.sb-sidenav .nav-link.active').forEach(a => {
            const grp = a.closest('.collapse');
            if (grp && grp.classList.contains('show')) {
                 const trigger = document.querySelector(
                     `${TOGGLER_SEL}[data-bs-target="#${grp.id}"], ${TOGGLER_SEL}[data-target="#${grp.id}"], ${TOGGLER_SEL}[href="#${grp.id}"]`
                 );
                 if (trigger) {
                     trigger.setAttribute('aria-expanded','true');
                     trigger.classList.remove('collapsed');
                 }
                 persist(); // Save the initial state
             }
        });
        // === END OF RESTORED SCRIPT ===
        
    });
    </script>
    <script>
       function setVhUnit(){ document.documentElement.style.setProperty('--vh', (window.innerHeight*0.01) + 'px'); }
       window.addEventListener('resize', setVhUnit);
       window.addEventListener('orientationchange', setVhUnit);
       setVhUnit();
    </script>

</body>
</html>