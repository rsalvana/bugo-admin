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
    if (strpos($roleNameLower, 'indigency') === false) {
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
          $trigger->isLogout(7, $employee_id);
    // Unset and destroy session
    session_unset();  // Unset all session variables
    session_destroy(); // Destroy the session
    header("Location: index.php"); // Redirect to login page after logout
    exit();
}

// Fetch barangay info from the database (assuming you already have the 'barangay_info' table)
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
        <!-- Keep both (your original + full-fit). The second is the one we’ll rely on. -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <!-- Your existing early include (kept) -->
        <!-- <link rel="stylesheet" href="css/responsive_revenue.css?v=2"> -->

        <meta name="description" content="" />
        <meta name="author" content="" />
        
        <title>LGU BUGO - Barangay Revenue</title>
        <link rel="stylesheet" href="css/form.css">
        <link rel="icon" type="image/png" href="assets/logo/logo.png">
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
        <link href="css/styles.css" rel="stylesheet" />
        <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="util/logout.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <!-- Captain-proven mobile overrides (loaded LAST so they always win) -->
        <!-- <link rel="stylesheet" href="css/responsive_revenue.css?v=captain-sync-1"> -->
        <style>
          /* inline guard (extra-specific + last) for the SB layout on phones */
          body.sb-nav-fixed #layoutSidenav_content { padding-top: 56px; }
          @media (max-width: 992px){
            #layoutSidenav{ display:block !important; }
            #layoutSidenav_nav{
              position: fixed !important;
              top:56px; bottom:0; left:0;
              width:260px !important;
              transform: translateX(-100%) !important;
              transition: transform .25s ease;
              z-index:1029;
            }
            #layoutSidenav_nav[data-open="true"]{ transform: translateX(0) !important; }
            #layoutSidenav_content{
              margin-left:0 !important;
              padding-left:0 !important;
              width:100% !important;
              max-width:100% !important;
            }
            #layoutSidenav_content > main,
            #layoutSidenav_content .container-fluid,
            #layoutSidenav_content .container{
              width:100% !important;
              max-width:100% !important;
            }
            main.main, .main, .section, section.section { min-width:0 !important; max-width:100% !important; }
            .row > [class*="col-"] { min-width:0 !important; }
            /* Backdrop for overlay nav (mobile) */
            .sidebar-backdrop{
              position: fixed; inset:0;
              background: rgba(0,0,0,.35);
              opacity:0; pointer-events:none; transition:.2s opacity;
              z-index:1028;
            }
            .sidebar-backdrop.is-visible{ opacity:1; pointer-events:auto; }
          }

          /* Extra safety vs. Bootstrap 4/5 mix: keep containers full width on phones */
          @media (max-width: 992px){
            .container-fluid { max-width:100% !important; }
          }

          /* Avoid iOS zoom on focus */
          input, select, textarea { font-size:16px; }
        </style>
    </head>
    <body class="sb-nav-fixed">
        <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
            <!-- Navbar Brand-->
            <a class="navbar-brand ps-3" > <?php if ($logo): ?>
    <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
    <?php else: ?>
        <p>No active Barangay logo found.</p>
    <?php endif; ?>
    <?php echo $barangayName?>
</a>

            <!-- Sidebar Toggle-->
            <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" href="#!" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
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
                        <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirmLogout();">Logout</a></li>
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
        <div id="layoutSidenav" class="container-fluid">
            <div id="layoutSidenav_nav">
                <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                    <div class="sb-sidenav-menu">
                        <div class="nav">
                            <div class="sb-sidenav-menu-heading">Core</div>
                             <a class="nav-link <?php echo ($page === 'indigency_dashboard') ? '' : 'collapsed'; ?>" href="index_indigency_staff.php?page=<?php echo urlencode(encrypt('indigency_dashboard')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                                </a>
                                <a class="nav-link <?php echo ($page === 'view_appointments') ? '' : 'collapsed'; ?>" href="index_indigency_staff.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-calendar-check"></i></div> View Appointments
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
            case 'indigency_dashboard':
                include 'Modules/indigency_modules/indigency_dashboard.php';
              break;
            case 'view_appointments':
                include 'Modules/indigency_modules/view_appointments_indigency.php';
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

    default:
        echo "<div class='alert alert-danger'>Invalid or missing page.</div>";
        break;
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
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
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

        <!-- Backdrop for mobile sidebar -->
        <div class="sidebar-backdrop" data-js="sidebar-backdrop"></div>

        <!-- Captain-style sidebar toggle + table wrap (ADD-ONLY; no backend touched) -->
        <script>
(function () {
  const body = document.body;
  const navContainer = document.getElementById('layoutSidenav_nav');
  const toggleBtn = document.getElementById('sidebarToggle');
  const backdrop = document.querySelector('[data-js="sidebar-backdrop"]');

  if (!navContainer || !toggleBtn || !backdrop) return;

  function openNav(){
    navContainer.setAttribute('data-open','true');
    backdrop.classList.add('is-visible');
    body.style.overflow = 'hidden';
  }
  function closeNav(){
    navContainer.removeAttribute('data-open');
    backdrop.classList.remove('is-visible');
    body.style.overflow = '';
  }

  toggleBtn.addEventListener('click', function(e){
    if (window.matchMedia('(max-width: 992px)').matches) {
      e.preventDefault();
      (navContainer.getAttribute('data-open') === 'true') ? closeNav() : openNav();
    } else {
      body.classList.toggle('sb-sidenav-toggled');
    }
  });
  backdrop.addEventListener('click', closeNav);
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeNav(); });

  // ——— Table wrappers (for static + dynamically loaded report tables) ———
  const section = document.querySelector('main .section') || document.querySelector('main');

  function wrapTable(t) {
    if (!t.closest('.table-responsive')) {
      const wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      t.parentNode.insertBefore(wrap, t);
      wrap.appendChild(t);
    }
  }

  // wrap tables present on initial load
  if (section) {
    section.querySelectorAll('table').forEach(wrapTable);

    // wrap tables that appear later (e.g., after "Load Report")
    const observer = new MutationObserver((muts) => {
      for (const m of muts) {
        m.addedNodes.forEach(n => {
          if (n.nodeType !== 1) return;
          if (n.tagName === 'TABLE') wrapTable(n);
          n.querySelectorAll?.('table').forEach(wrapTable);
        });
      }
    });
    observer.observe(section, { childList: true, subtree: true });
  }
})();
</script>


        <!-- iOS 100vh helper (optional) -->
        <script>
          function setVhUnit(){ document.documentElement.style.setProperty('--vh', (window.innerHeight*0.01) + 'px'); }
          window.addEventListener('resize', setVhUnit);
          window.addEventListener('orientationchange', setVhUnit);
          setVhUnit();
        </script>

<script>
/* Ensure wide tables actually overflow so you can drag them */
(function(){
  // make a table at least wide enough per column to create overflow
  function setMinWidth(table){
    const guessCols =
      (table.tHead && table.tHead.rows[0] && table.tHead.rows[0].cells.length) ||
      (table.rows[0] && table.rows[0].cells.length) || 6;
    // ~120px per column, but never less than 680px
    table.style.minWidth = Math.max(680, guessCols * 120) + 'px';
  }

  function prepareTables(root){
    root.querySelectorAll('.table-responsive > table').forEach(setMinWidth);
  }

  // run now for any existing tables
  prepareTables(document);

  // and also when new report tables are injected (e.g., after "Load Report")
  const target = document.querySelector('main .section') || document.querySelector('main') || document.body;
  const obs = new MutationObserver(muts => {
    muts.forEach(m => {
      m.addedNodes.forEach(n => {
        if (n.nodeType !== 1) return;
        if (n.matches?.('.table-responsive > table')) setMinWidth(n);
        n.querySelectorAll?.('.table-responsive > table').forEach(setMinWidth);
      });
    });
  });
  obs.observe(target, {childList: true, subtree: true});
})();
</script>

    </body>
</html>
