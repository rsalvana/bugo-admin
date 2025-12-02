<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
    if (strpos($roleNameLower, 'punong barangay') === false) {
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

// Fetch barangay info
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
        $barangayName = "Poblacion " . ($barangayName);
    } else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = ($barangayName);
    } else {
        $barangayName = "Barangay " . ($barangayName);
    }
} else {
    $barangayName = "NO BARANGAY FOUND";
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);
$logo = ($logo_result->num_rows > 0) ? $logo_result->fetch_assoc() : null;

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!-- Viewport for mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="" />
    <meta name="author" content="" />
    <title>LGU BUGO - Barangay Captain</title>

    <!-- Your CSS -->
    <link rel="stylesheet" href="css/form.css">
    <link href="css/styles.css" rel="stylesheet" />
    

    <!-- Bootstrap 5 (single, consistent version) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Tables -->
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />

    <!-- Mobile overrides -->
    <!--<link rel="stylesheet" href="css/responsive.css">-->

    <!-- Misc -->
    <link rel="icon" type="image/png" href="assets/logo/logo.png">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- (Optional) jQuery if your own scripts need it -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

            <!-- <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" /> -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <!--<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>-->
        <link href="css/styles.css" rel="stylesheet" />
    <!-- <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet"> -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <link rel="icon" type="image/png" href="assets/logo/logo.png">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Hard mobile overrides to guarantee full-width content & off-canvas sidenav -->
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
      }
    </style>
</head>

<body class="sb-nav-fixed">
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <!-- Navbar Brand-->
        <a class="navbar-brand ps-3">
            <?php if ($logo): ?>
                <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
            <?php else: ?>
                <p>No active Barangay logo found.</p>
            <?php endif; ?>
            <?php echo $barangayName ?>
        </a>

        <!-- Sidebar Toggle-->
        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Navbar Search / Router -->
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

                        <!-- Encrypted Sidebar Links -->
                        <a class="nav-link <?php echo ($page === 'admin_dashboard') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('admin_dashboard')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                        </a>

                        <a class="nav-link <?php echo ($page === 'resident_info') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('resident_info')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div> Resident List
                        </a>

                        <a class="nav-link <?php echo ($page === 'feedbacks') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('feedbacks')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-comment-dots"></i></div> Feedback
                        </a>

                        <a class="nav-link <?php echo ($page === 'reports') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('reports')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-chart-line"></i></div> Report
                        </a>

                        <a class="nav-link <?php echo ($page === 'case_list') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('case_list')); ?>">
                            <div class="sb-nav-link-icon"><i class="fas fa-gavel"></i></div> Case List
                        </a>

                        <a class="nav-link <?php echo ($page === 'view_appointments') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">
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
    case 'admin_dashboard':
        include 'Modules/captain_modules/admin_dashboard.php';
        break;
    case 'feedbacks':
        include 'Modules/captain_modules/Feedbacks.php';
        break;
    case 'reports':
        include 'Modules/captain_modules/reports.php';
        break;
    case 'resident_info':
        include 'Modules/captain_modules/resident_info.php';
        break;
    case 'case_list':
        include 'Modules/captain_modules/case_list.php';
        break;
    case 'view_appointments':
        include 'Modules/captain_modules/view_appointments.php';
        break;
    case 'families':
        include 'Pages/linked_families.php';
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
        echo "<div class='alert alert-danger'>Invalid or missing page: <code>" . htmlspecialchars($decryptedPage) . "</code></div>";
        break;
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
                            <a href="#">Privacy Policy</a>
                            &middot;
                            <a href="#">Terms &amp; Conditions</a>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Bootstrap 5 bundle (single include; unified version 5.3.0) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->

    <!-- Your scripts -->
    <script src="js/scripts.js"></script>

    <!-- Charts & Tables -->
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
            setTimeout(() => {
                window.location.href = "logout.php";
            }, 1000);
        }
    });
    return false;
}
    </script>

    <!-- Mobile sidebar + auto-wrap wide tables -->
    <script>
document.addEventListener('DOMContentLoaded', function () {
  const btn  = document.getElementById('sidebarToggle');
  const nav  = document.getElementById('layoutSidenav_nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      const open = nav.getAttribute('data-open') === 'true';
      nav.setAttribute('data-open', String(!open));
    });
    // Close on outside click for small screens
    document.addEventListener('click', function (e) {
      if (window.matchMedia('(max-width: 992px)').matches) {
        if (!nav.contains(e.target) && !btn.contains(e.target)) {
          nav.removeAttribute('data-open');
        }
      }
    });
  }

  // Wrap any wide tables so they scroll horizontally on phones
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
    </script>

    <!-- iOS/Safari 100vh fix (optional but helpful) -->
    <script>
      function setVhUnit(){ document.documentElement.style.setProperty('--vh', (window.innerHeight*0.01) + 'px'); }
      window.addEventListener('resize', setVhUnit);
      window.addEventListener('orientationchange', setVhUnit);
      setVhUnit();
    </script>

<script>
(function () {
  // Find the appointments table (robust selectors; won’t error if absent)
  function findApptTable() {
    return (
      document.querySelector('.appt-list table') ||
      document.querySelector('#appointmentsTable') ||
      document.querySelector('main .section table')
    );
  }

  function wrapInResponsive(table) {
    if (!table) return;
    if (!table.closest('.table-responsive')) {
      const wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    }
  }

  function addDataLabels(table) {
    if (!table) return;
    const ths = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(tr => {
      tr.querySelectorAll('td').forEach((td, i) => {
        if (!td.hasAttribute('data-label') && ths[i]) {
          td.setAttribute('data-label', ths[i]);
        }
      });
    });
  }

  function makeStacked(table) {
    if (!table) return;
    table.classList.add('stacked-md'); // CSS does the rest under 768px
  }

  function enhance() {
    // Limit to Captain → View Appointments page by checking the title text if present
    const title = document.querySelector('h1,h2,h3,.page-title');
    const looksLikeApptPage = title && /appointment/i.test(title.textContent);
    if (!looksLikeApptPage) {
      // Still try if we see an obvious filters card or list container
      if (!document.querySelector('.appt-filters, .appt-list')) return;
    }

    const table = findApptTable();
    if (!table) return;
    wrapInResponsive(table);
    addDataLabels(table);
    makeStacked(table);
  }

  // Run once now
  enhance();

  // Re-run if the module redraws (pagination/AJAX)
  const obs = new MutationObserver(() => enhance());
  obs.observe(document.getElementById('layoutSidenav_content') || document.body, { childList: true, subtree: true });
})();
</script>

</body>
</html>
