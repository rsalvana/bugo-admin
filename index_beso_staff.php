<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// CSRF token generation removed as it wasn't in the original beso file provided

require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './include/encryption.php';
require_once  './include/redirects.php';

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

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

    // Corrected Role Check for BESO
    $roleNameLower = strtolower($roleName);
    if (strpos($roleNameLower, 'beso') === false) { // Check specifically for 'beso'
        header("Location: index.php");
        exit();
    }
}
 else {
    die('Prepare failed: ' . htmlspecialchars($mysqli->error));
}
require_once './logs/logs_trig.php';

$trigger = new Trigger();
// Check if logout request is made
if (isset($_POST['logout']) && $_POST['logout'] === 'true') {
        // employee_id should be available from the session check above
        if(isset($_SESSION['employee_id'])){
             $trigger->isLogout(7, $_SESSION['employee_id']);
        }
    // Unset and destroy session
    session_unset();  // Unset all session variables
    session_destroy(); // Destroy the session
    header("Location: index.php"); // Redirect to login page after logout
    exit();
}
$barangayInfoSql = "SELECT 
                        bm.city_municipality_name, 
                        b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1"; // Adjust the 'WHERE' condition to get the correct row, this could be dynamic

$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();

    // Get the barangay name and remove "(Pob.)" if it exists
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = (preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName)); // Remove "(Pob.)" if it exists

    // If 'Barangay' is present first, just show the barangay name as is
    if (stripos($barangayName, "Barangay") !== false) {
        // Remove any extra "(Pob.)" and leave the name as is
        $barangayName = ($barangayName);
    }
    // If 'Pob' is found but 'Barangay' is not, prepend "POBLACION"
    else if (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "Poblacion " .($barangayName); // Add "POBLACION" and convert to uppercase
    }
    // If 'Poblacion' is already in the name, don't add "POBLACION" again
    else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = ($barangayName); // Keep "Poblacion" name as is
    }
    // If neither 'Barangay' nor 'Pob' is found, add 'Barangay' to the name
    else {
        $barangayName = "Barangay " . ($barangayName); // Add "BARANGAY" and convert to uppercase
    }
} else {
    $barangayName = "NO BARANGAY FOUND";
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);

$logo = null;
if ($logo_result->num_rows > 0) {
    // Fetch the logo details
    $logo = $logo_result->fetch_assoc();
}

$mysqli->close();
?>


<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <!-- ADDED: Mobile Viewport -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>LGU BUGO - BESO</title>

        <!-- CSS -->
        <link rel="stylesheet" href="css/form.css">
        <link href="css/styles.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <!-- Unified Bootstrap 5 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"> 
        <link rel="icon" type="image/png" href="assets/logo/logo.png">
        <!-- ADDED: Responsive CSS Link (can be optional if inline styles cover everything) -->
        <!--<link rel="stylesheet" href="css/responsive.css"> -->

        <!-- Scripts (Moved BS5 Bundle here) -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> <!-- Moved here -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Removed redundant logout.js, using inline script like others -->
        <!-- Removed redundant Font Awesome script -->
        
        
                <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
        <link href="css/styles.css" rel="stylesheet" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">  
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="util/logout.js"></script>
        <link rel="icon" type="image/png" href="assets/logo/logo.png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- ADDED: Inline Mobile Styles -->
         <style>
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
             #filters, 
             form.card, 
             .card[data-filters] { 
               position: relative !important; 
               z-index: 10 !important;      
             }
             /* === DROPDOWN Z-INDEX FIX END === */

             /* === ADDED: DROPDOWN POSITIONING FIX START === */
             /* Ensure filter form grid columns are positioned relatively */
             #filters .col-12, #filters [class*="col-md-"] {
                 position: relative !important;
             }
             /* You might need to adjust Bootstrap's default dropdown styles if they interfere */
             .dropdown-menu {
                /* Resetting potentially problematic overrides, let Popper.js handle positioning */
                left: auto !important; 
                right: auto !important;
             }
             /* === ADDED: DROPDOWN POSITIONING FIX END === */

             /* === General Table Fix for Horizontal Scroll === */
             /* Ensure the container allows scroll */
             .table-responsive {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
             }
             /* Apply standard table layout */
             main .section table,
             .table-responsive > table { /* Target table inside the wrapper */
                display: table !important; 
                width: 100% !important; 
                /* Removed fixed min-width, let content decide */
                /* min-width: 800px !important;  */
                table-layout: auto !important; 
                border-collapse: collapse !important; /* Ensure borders collapse */
             }
             main .section table thead,
             .table-responsive > table thead { 
                display: table-header-group !important; 
                visibility: visible !important; 
                height: auto !important; 
                opacity: 1 !important; 
             }
             main .section table tbody,
             .table-responsive > table tbody { display: table-row-group !important; }
             
             main .section table tr,
             .table-responsive > table tr { display: table-row !important; }
             
             main .section table th, 
             main .section table td,
             .table-responsive > table th,
             .table-responsive > table td { 
                display: table-cell !important; 
                width: auto !important; 
                white-space: nowrap !important; /* Prevent wrapping */
                word-break: normal !important; 
                overflow-wrap: normal !important; 
                vertical-align: middle !important; 
                padding: 0.5rem !important; 
                visibility: visible !important; 
                height: auto !important; 
                opacity: 1 !important; 
                font-size: inherit !important; 
                line-height: inherit !important; 
                border: 1px solid #dee2e6; /* Add borders back if needed */
             }
             main .section table td::before,
             .table-responsive > table td::before { display: none !important; }
             /* === General Table Fix End === */
             
             /* Fix for filter forms stacking */
              .filters .btn, .filter-bar .btn, .search-bar .btn,
              .filters .form-control, .filter-bar .form-control, .search-bar .form-control,
              .filters .form-select, .filter-bar .form-select, .search-bar .form-select {
                  width: 100% !important;
              }
              .filters, .filter-bar, .search-bar, [data-filters] {
                  display: grid !important;
                  grid-template-columns: 1fr !important;
                  gap: 0.5rem !important;
              }
           }
         </style>     
        </head>
    <body class="sb-nav-fixed">
        <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
            <!-- Navbar Brand-->
            <a class="navbar-brand ps-3" >
                 <?php if ($logo): ?>
                 <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
                 <?php else: ?>
                 <!-- Optional: Placeholder if no logo -->
                 <?php endif; ?>
                 <?php echo htmlspecialchars($barangayName); ?>
            </a>

            <!-- Sidebar Toggle-->
            <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button> <!-- Changed href to type="button" -->
            <!-- Navbar Search-->
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
                        <li><a class="dropdown-item text-danger" href="#" onclick="return confirmLogout();">Logout</a></li> <!-- Changed href -->
                    </ul>
                </li>
            </ul>
            <!-- Navbar-->
        </nav>
        <!-- Profile Modal -->
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
        <div id="layoutSidenav" class="container-fluid"> <!-- Added container-fluid -->
            <div id="layoutSidenav_nav">
                <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                    <div class="sb-sidenav-menu">
                        <div class="nav">
                            <div class="sb-sidenav-menu-heading">Core</div>
                            <a class="nav-link <?php echo ($page === 'admin_dashboard') ? '' : 'collapsed'; ?>" href="index_beso_staff.php?page=<?php echo urlencode(encrypt('admin_dashboard')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                                </a>
                               <a class="nav-link <?php echo ($page === 'urgent_request') ? '' : 'collapsed'; ?>" href="index_beso_staff.php?page=<?php echo urlencode(encrypt('urgent_request')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-exclamation-circle"></i></div> On-Site Request
                                </a>
                                <a class="nav-link <?php echo ($page === 'view_appointments') ? '' : 'collapsed'; ?>" href="index_beso_staff.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-calendar-check"></i></div> View Appointments
                                </a>
                                <a class="nav-link <?php echo ($page === 'beso') ? '' : 'collapsed'; ?>" href="index_beso_staff.php?page=<?php echo urlencode(encrypt('beso')); ?>">
                                    <div class="sb-nav-link-icon"><i class="fas fa-scroll"></i></div> BESO
                                </a>   
                                <a class="nav-link <?php echo ($page === 'reports') ? '' : 'collapsed'; ?>" href="index_beso_staff.php?page=<?php echo urlencode(encrypt('reports')); ?>">
                                    <div class="sb-nav-link-icon"><i class="fas fa-scroll"></i></div> Report
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
                    <div class="container-fluid px-4"> <!-- Added px-4 for padding -->
<?php require_once 'Modals/announcement.php'; ?>
                    <main id="main" class="main">

<!-- section for main content -->
<section class="section"> 

          <?php
require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
  
$decryptedPage = 'admin_dashboard'; // fallback/default

if (isset($_GET['page'])) {
    $decrypted = decrypt($_GET['page']);
    if ($decrypted !== false) {
        $decryptedPage = $decrypted;
    }
}
switch ($decryptedPage) {
            case 'admin_dashboard':
                include 'Modules/beso_modules/admin_dashboard.php';
              break;
            case 'view_appointments':
                include 'Modules/beso_modules/view_appointments.php';
              break;
            case 'urgent_request':
                include 'Modules/beso_modules/urgent_request.php';
              break;
            case 'beso':
                include 'Modules/beso_modules/beso.php';
              break;   
            case 'reports':
                include 'Modules/beso_modules/reports.php';
              break;                 
              case 'add_announcement':
    include 'components/announcement/add_announcement.php';
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

</main><!-- End #main -->






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
        <!-- Original Scripts -->
        <script src="js/scripts.js"></script> 
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>

        <!-- Logout Confirmation -->
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
                    // Use a form submission for logout to ensure POST request
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = ''; // Post to the same page
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'logout';
                    input.value = 'true';
                    form.appendChild(input);
                    
                    document.body.appendChild(form);
                    
                    Swal.fire({
                        title: 'Logging out...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    
                    // Submit the form after showing loading indicator
                     setTimeout(() => { form.submit(); }, 500); // Short delay
                }
            });
            return false; // Prevent default link behavior
        }
        </script>

        <!-- ADDED: Mobile Sidebar Toggle + Table Wrapper Script -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
          const btn  = document.getElementById('sidebarToggle');
          const nav  = document.getElementById('layoutSidenav_nav');
          
          // Mobile-specific toggle logic
          if (btn && nav) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); // Stop the default behavior
                // Use data-open attribute for mobile toggling
                if (window.matchMedia('(max-width: 992px)').matches) {
                    const open = nav.getAttribute('data-open') === 'true';
                    nav.setAttribute('data-open', String(!open));
                } else {
                    // Default desktop toggle (if you still use sb-sidenav-toggled class)
                    document.body.classList.toggle('sb-sidenav-toggled');
                }
            });
            
            // Close on outside click for mobile
            document.addEventListener('click', function (e) {
              if (window.matchMedia('(max-width: 992px)').matches) {
                if (!nav.contains(e.target) && !btn.contains(e.target)) {
                  nav.removeAttribute('data-open');
                }
              }
            });
          }

          // Wrap wide tables
          const main = document.querySelector('main .section, main');
          if (main) {
            main.querySelectorAll('table').forEach(t => {
              // Check if it's NOT the feedback table and NOT already wrapped
              if (!t.closest('#feedbackTable') && !t.closest('.table-responsive')) {
                const wrap = document.createElement('div');
                wrap.className = 'table-responsive';
                t.parentNode.insertBefore(wrap, t);
                wrap.appendChild(t);
              }
            });
          }
        });
        </script>

        <!-- ADDED: iOS/Safari 100vh fix -->
        <script>
          function setVhUnit(){ document.documentElement.style.setProperty('--vh', (window.innerHeight*0.01) + 'px'); }
          window.addEventListener('resize', setVhUnit);
          window.addEventListener('orientationchange', setVhUnit);
          setVhUnit();
        </script>

    </body>
</html>

