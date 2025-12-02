<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../../security/403.html';
    exit;
}

require_once __DIR__ . '/../../include/connection.php';
$mysqli = db_connection();
$mysqli->query("SET time_zone = '+08:00'"); // MySQL in Asia/Manila
include 'class/session_timeout.php';
require_once './logs/logs_trig.php';

/* ---------- PH now (server-side) for client sync ---------- */
$nowPH   = new DateTime('now', new DateTimeZone('Asia/Manila'));
$nowPHms = (int)$nowPH->format('U') * 1000;      // epoch ms in PH
$todayYmd = $nowPH->format('Y-m-d');              // use PH date for <input min>

/* ---------- pagination & role ---------- */
$results_per_page = 20;
$page   = isset($_GET['pagenum']) && is_numeric($_GET['pagenum']) ? (int)$_GET['pagenum'] : 1;
$offset = ($page - 1) * $results_per_page;
$role   = strtolower($_SESSION['Role_Name'] ?? '');

switch ($role) {
    case 'multimedia':
        $redirectUrl = enc_multimedia('event_list');
        break;
    case 'admin':
    default:
        $redirectUrl = enc_admin('event_list');
        break;
}

/* ---------- archive via delete button ---------- */
if (isset($_POST['delete_event'])) {
    $event_id = (int)($_POST['event_id'] ?? 0);
    if ($event_id > 0) {
        $stmt_update = $mysqli->prepare("UPDATE events SET events_delete_status = 1 WHERE id = ?");
        $stmt_update->bind_param("i", $event_id);
        $stmt_update->execute();
        $stmt_update->close();

        $trigs = new Trigger();
        $trigs->isDelete(11, $event_id);
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
              <script>
                document.addEventListener('DOMContentLoaded', () => {
                  Swal.fire({icon:'success',title:'Event Archived',text:'The event was successfully archived.'})
                      .then(()=>location.href='$redirectUrl');
                });
              </script>";
    }
}

/* ---------- AUTO-ARCHIVE past dates ---------- */
$mysqli->query("UPDATE events SET events_delete_status=1
  WHERE events_delete_status=0
    AND (event_date < CURDATE()
         OR (event_date = CURDATE() AND (event_end_time IS NULL OR event_end_time < CURTIME())));");

/* ---------- filters & search ---------- */
$search        = trim($_GET['search'] ?? '');
$filter_title    = trim($_GET['filter_title'] ?? '');
$filter_year     = ($_GET['filter_year']  ?? '') !== '' ? (int)$_GET['filter_year']  : null;
$filter_month    = ($_GET['filter_month'] ?? '') !== '' ? (int)$_GET['filter_month'] : null;
$filter_location = trim($_GET['filter_location'] ?? '');

$where  = "e.events_delete_status = 0";
$params = [];
$types  = "";

/* Search */
if ($search !== '') {
    $where    .= " AND (en.event_name LIKE ? OR e.event_location LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $types    .= "ss";
}

/* Filter: Title */
if ($filter_title !== '') {
    $where    .= " AND en.event_name = ?";
    $params[] = $filter_title;
    $types    .= "s";
}

/* Filter: Year */
if (!is_null($filter_year)) {
    $where    .= " AND YEAR(e.event_date) = ?";
    $params[] = $filter_year;
    $types    .= "i";
}

/* Filter: Month */
if (!is_null($filter_month)) {
    $where    .= " AND MONTH(e.event_date) = ?";
    $params[] = $filter_month;
    $types    .= "i";
}

/* Filter: Location */
if ($filter_location !== '') {
    $where    .= " AND e.event_location = ?";
    $params[] = $filter_location;
    $types    .= "s";
}

/* ---------- COUNT ---------- */
$count_sql = "SELECT COUNT(*) 
              FROM events e 
              JOIN event_name en ON e.event_title = en.id
              WHERE $where";
$stmt_cnt = $mysqli->prepare($count_sql);
if ($types !== "") { $stmt_cnt->bind_param($types, ...$params); }
$stmt_cnt->execute();
$stmt_cnt->bind_result($total_results);
$stmt_cnt->fetch();
$stmt_cnt->close();

$total_results = (int)($total_results ?? 0);
$total_pages   = (int)ceil($total_results / $results_per_page);

/* ---------- SELECT ---------- */
$sql = "SELECT e.id, en.event_name, e.event_location, e.event_time, e.event_end_time, e.event_date
        FROM events e
        JOIN event_name en ON e.event_title = en.id
        WHERE $where
        ORDER BY e.event_date ASC, e.event_time ASC
        LIMIT ?, ?";
$params_sel = $params;
$params_sel[] = $offset;
$params_sel[] = $results_per_page;
$types_sel = $types . "ii";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types_sel, ...$params_sel);
$stmt->execute();
$result = $stmt->get_result();

/* ---------- persist filters in pagination ---------- */
$persist = $_GET;
unset($persist['pagenum']);
$persistQS = http_build_query($persist);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event List</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">
  <link rel="stylesheet" href="css/event/event.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-wZf3..." crossorigin="anonymous" referrerpolicy="no-referrer"/>
  <style>
    .form-hint{font-size:.85rem;color:var(--muted)}
    .dropzone{
      border:1px dashed var(--ring); border-radius:12px; padding:1rem;
      background:#fff; text-align:center; cursor:pointer;
    }
    .dropzone.drag{ background:rgba(0,0,0,.03) }
    .img-preview{ max-height:240px; border:1px solid var(--ring); border-radius:12px }
    .field-note{ font-size:.8rem; color:#6b7280 }

    /* ===== Bigger, clearer time inputs ===== */
    .input-group.time-group { gap: .5rem; }
    .time-group .form-control.time-lg{
      font-size: 1.75rem;
      line-height: 1.2;
      height: 3.25rem;
      padding: .25rem .75rem;
      text-align: center;
      flex: 1 1 50%;
      min-width: 180px;
    }
    .time-group .input-group-text{
      font-size: 1.0rem;
      padding: .25rem .75rem;
      background: #f3f4f6;
    }
    .is-invalid{ border-color:#dc3545 !important; }

    /* WebKit time-field digit scaling */
    .time-group .form-control.time-lg::-webkit-datetime-edit,
    .time-group .form-control.time-lg::-webkit-date-and-time-value { padding: 0 .15rem; }
    .time-group .form-control.time-lg::-webkit-datetime-edit-hour-field,
    .time-group .form-control.time-lg::-webkit-datetime-edit-minute-field,
    .time-group .form-control.time-lg::-webkit-datetime-edit-second-field,
    .time-group .form-control.time-lg::-webkit-datetime-edit-ampm-field{
      font-size: 1.75rem;
    }

    @media (max-width: 480px){
      .input-group.time-group{ flex-wrap: wrap; }
      .time-group .form-control.time-lg{ flex: 1 0 100%; }
      .time-group .input-group-text{ width: 100%; justify-content: center; }
    }
  </style>
</head>
<body>

<div class="container my-5 table-responsive">
  <h2 class="text-start mb-4">Event List</h2>
  <div class="d-flex justify-content-start gap-2 mb-2" style="margin-top: -10px; margin-bottom: 10px;">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">+ Add Event</button>
    </div>

  <form method="GET" action="index_multimedia.php" class="row g-2 mb-3 filter-form">
      <input type="hidden" name="page" value="<?= $_GET['page'] ?? 'event_list' ?>">
    <div class="col-md-3">
      <input type="text" name="search" class="form-control" placeholder="Search by Title or Location"
             value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    </div>
    <div class="col-md-2">
      <select name="filter_title" class="form-select">
        <option value="">All Titles</option>
        <?php
        $titles = $mysqli->query("SELECT DISTINCT en.event_name 
                                  FROM events e 
                                  JOIN event_name en ON e.event_title = en.id");
        while ($t = $titles->fetch_assoc()) {
          $sel = ($filter_title === $t['event_name']) ? 'selected' : '';
          echo "<option value='".htmlspecialchars($t['event_name'])."' $sel>".htmlspecialchars($t['event_name'])."</option>";
        }
        ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="filter_year" class="form-select">
        <option value="">All Years</option>
        <?php
        $years = $mysqli->query("SELECT DISTINCT YEAR(event_date) as y FROM events ORDER BY y DESC");
        while ($y = $years->fetch_assoc()) {
          $sel = ($filter_year == $y['y']) ? 'selected' : '';
          echo "<option value='{$y['y']}' $sel>{$y['y']}</option>";
        }
        ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="filter_month" class="form-select">
        <option value="">All Months</option>
        <?php
        for ($m = 1; $m <= 12; $m++) {
          $monthName = date("F", mktime(0, 0, 0, $m, 1));
          $sel = ($filter_month == $m) ? 'selected' : '';
          echo "<option value='$m' $sel>$monthName</option>";
        }
        ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="filter_location" class="form-select">
        <option value="">All Locations</option>
        <?php
        $locs = $mysqli->query("SELECT DISTINCT event_location FROM events");
        while ($l = $locs->fetch_assoc()) {
          $sel = ($filter_location === $l['event_location']) ? 'selected' : '';
          echo "<option value='".htmlspecialchars($l['event_location'])."' $sel>".htmlspecialchars($l['event_location'])."</option>";
        }
        ?>
      </select>
    </div>
    <div class="col-md-1">
      <button type="submit" class="btn btn-primary w-100">Filter</button>
    </div>
  </form>

  <div class="card-body">
    <?php if ($result->num_rows > 0) : ?>
      <div class="table-responsive w-100 table-wrap">
        <table class="table table-bordered table-hover align-middle text-center w-100">
          <thead class="table-light">
          <tr>
            <th style="width: 400px;">Title</th>
            <th style="width: 400px;">Location</th>
            <th style="width: 400px;">Time</th>
            <th style="width: 400px;">Date</th>
            <th style="width: 400px;">Actions</th>
          </tr>
          </thead>
          <tbody>
          <?php while ($row = $result->fetch_assoc()) : ?>
            <tr>
              <td data-label="Title"><?= htmlspecialchars($row['event_name']) ?></td>
              <td data-label="Location"><span class="badge bg-info text-dark"><?= htmlspecialchars($row['event_location']) ?></span></td>
              <td data-label="Time"><?= date("g:i A", strtotime($row['event_time'])) ?> - <?= date("g:i A", strtotime($row['event_end_time'])) ?></td>
              <td data-label="Date"><?= date("F d, Y", strtotime($row['event_date'])) ?></td>
              <td data-label="Actions" class="d-flex gap-2 justify-content-center">
                
                <button type="button" class="btn btn-info btn-sm view-edit-btn" 
                        data-id="<?= (int)$row['id']; ?>"
                        data-eventname="<?= htmlspecialchars($row['event_name']); ?>">
                  <i class="bi bi-pencil-square"></i> View/Edit
                </button>

                <form method="POST" class="delete-event-form m-0">
                  <input type="hidden" name="event_id" value="<?= (int)$row['id']; ?>">
                  <input type="hidden" name="delete_event" value="1">
                  <button type="button" class="btn btn-danger btn-sm delete-btn">
                    <i class="bi bi-trash-fill"></i> Archive
                  </button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

<?php
$window = 5; $half = (int)floor($window/2);
$start  = max(1, $page - $half);
$end    = min($total_pages, $start + $window - 1);
$start  = max(1, min($start, $end - $window + 1));
$pageBase = $redirectUrl;
$qs       = $persistQS ? '&'.$persistQS : '';
?>
<nav aria-label="Page navigation">
  <ul class="pagination justify-content-end">
    <?php if ($page <= 1): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angles-left"></i><span class="visually-hidden">First</span></span></li>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i><span class="visually-hidden">Previous</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=1' ?>" aria-label="First"><i class="fa fa-angles-left"></i></a></li>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page - 1) ?>" aria-label="Previous"><i class="fa fa-angle-left"></i></a></li>
    <?php endif; ?>

    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
        <a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $i; ?>"><?= $i; ?></a>
      </li>
    <?php endfor; ?>

    <?php if ($end < $total_pages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php if ($page >= $total_pages): ?>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i><span class="visually-hidden">Next</span></span></li>
      <li class="page-item disabled"><span class="page-link"><i class="fa fa-angles-right"></i><span class="visually-hidden">Last</span></span></li>
    <?php else: ?>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . ($page + 1) ?>" aria-label="Next"><i class="fa fa-angle-right"></i></a></li>
      <li class="page-item"><a class="page-link" href="<?= $pageBase . $qs . '&pagenum=' . $total_pages ?>" aria-label="Last"><i class="fa fa-angles-right"></i></a></li>
    <?php endif; ?>
  </ul>
</nav>
    <?php else : ?>
      <div class='alert alert-info text-center'>No events available.</div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" action="api/event_upload.php" enctype="multipart/form-data" onsubmit="return validateAddDateTime();" id="addEventForm">
        
        <input type="hidden" name="event_name" value="other">

        <div class="modal-header">
          <h5 class="modal-title" id="addEventModalLabel">
            <i class="bi bi-calendar-plus me-2"></i>Add New Event
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        
        <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
          <div class="row g-3">
            <div class="col-lg-7">
              
              <div class="mb-3" id="customEventName">
                <label for="new_event_name" class="form-label">Event Name</label>
                <input type="text" name="new_event_name" id="new_event_name" class="form-control" placeholder="e.g., Barangay Outreach Fair" required>
              </div>

              <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="4" maxlength="600" required placeholder="What is this event about?"></textarea>
                <div class="d-flex justify-content-between">
                  <span class="form-hint">Keep it concise and informative.</span>
                  <span class="form-hint"><span id="addDescCount">0</span>/600</span>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label for="date" class="form-label">Date</label>
                  <input type="date" class="form-control" id="date" name="date" required min="<?= $todayYmd ?>">
                  <small id="dateErrorAdd" class="text-danger d-none">Date must be today or later.</small>
                </div>
              </div>
              
              <div class="row g-3">
                <div class="col-md-12">
                  <label class="form-label">Time</label>
                  <div class="input-group time-group">
                    <input type="time" class="form-control time-lg" id="start_time" name="start_time" required>
                    <span class="input-group-text">to</span>
                    <input type="time" class="form-control time-lg" id="end_time" name="end_time" required>
                  </div>
                  <small id="nowErrorAdd" class="text-danger d-none">Start time cannot be earlier than the current time (Asia/Manila).</small><br>
                  <small id="timeErrorAdd" class="text-danger d-none">End time must be later than start time.</small>
                  <div class="form-text field-note">Duration: <span id="addDuration">—</span></div>
                </div>
              </div>              

              <div class="mt-3">
                <label for="location" class="form-label">Location</label>
                <input type="text" class="form-control" id="location" name="location" required placeholder="e.g., City Hall Grounds">
                <div class="form-text field-note">Be specific (building, room, barangay, etc.).</div>
              </div>
            </div>

            <div class="col-lg-5">
              <label class="form-label">Event Image (Optional)</label>
              <div id="addDrop" class="dropzone">
                <i class="bi bi-image fs-3 d-block mb-2"></i>
                <p class="mb-1">Drag & drop image here, or click to browse</p>
                <small class="text-muted">Optional. JPG/PNG up to 3 MB</small>
                <input class="form-control mt-3" type="file" id="image" name="image" accept="image/*" hidden>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="addBrowseBtn">Choose File</button>
              </div>
              <div class="mt-3">
                <img id="add_image_preview" src="" alt="Preview" class="img-preview d-none w-100">
                <div id="addImageMeta" class="form-text field-note d-none"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Post Event</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addEventNameModal" tabindex="-1" aria-labelledby="addEventNameModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="class/eventname.php">
        <div class="modal-header">
          <h5 class="modal-title" id="addEventNameModalLabel"><i class="bi bi-tags me-2"></i>Add New Event Name</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="new_event_name2" class="form-label">Event Name</label>
            <input type="text" class="form-control" id="new_event_name2" name="new_event_name" required placeholder="e.g., Community Cleanup Day">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="viewEditModal" tabindex="-1" aria-labelledby="viewEditModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="editEventForm" action="class/event_update.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" id="edit_id">
        
        <input type="hidden" name="event_name" value="other">

        <div class="modal-header">
          <h5 class="modal-title" id="viewEditModalLabel"><i class="bi bi-pencil-square me-2"></i>View / Edit Event</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
          <div class="row g-3">
            <div class="col-lg-7">
              
              <div class="mb-3">
                <label class="form-label">Event Name</label>
                <input type="text" class="form-control" name="new_event_name" id="edit_new_event_name" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" id="edit_description" rows="4" maxlength="600"></textarea>
                <div class="d-flex justify-content-between">
                  <span class="form-hint">Summarize any changes or details.</span>
                  <span class="form-hint"><span id="editDescCount">0</span>/600</span>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Date</label>
                  <input type="date" class="form-control" name="date" id="edit_date" required min="<?= $todayYmd ?>">
                  <small id="dateErrorEdit" class="text-danger d-none">Date must be today or later.</small>
                </div>
              </div>
              <div class="row g-3">
                <div class="col-md-12">
                  <label class="form-label">Time</label>
                  <div class="input-group time-group">
                    <input type="time" class="form-control time-lg" name="start_time" id="edit_start_time" required>
                    <span class="input-group-text">to</span>
                    <input type="time" class="form-control time-lg" name="end_time" id="edit_end_time" required>
                  </div>
                  <small id="nowErrorEdit" class="text-danger d-none">Start time cannot be earlier than the current time (Asia/Manila).</small><br>
                  <small id="timeErrorEdit" class="text-danger d-none">End time must be later than start time.</small>
                  <div class="form-text field-note">Duration: <span id="editDuration">—</span></div>
                </div>
              </div>              

              <div class="mt-3">
                <label class="form-label">Location</label>
                <input type="text" class="form-control" name="location" id="edit_location" required>
              </div>
            </div>

            <div class="col-lg-5">
              <label class="form-label d-block">Current Image</label>
              <img id="edit_image_preview" src="" alt="Event Image" class="img-preview w-100">
              <div class="mt-3">
                <label class="form-label">Replace Image (Optional)</label>
                <input class="form-control" type="file" name="image" id="edit_image" accept="image/*">
                <div class="form-text">Leave blank to keep current image. Max 3 MB.</div>
                <img id="edit_image_new_preview" src="" alt="New Preview" class="img-preview d-none mt-2 w-100">
                <div id="editImageMeta" class="form-text field-note d-none"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Changes</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ===== Build “live” Asia/Manila now() from server epoch ===== */
const BASE_PH_MS      = <?= $nowPHms ?>;      // PH epoch (server)
const BASE_CLIENT_MS  = Date.now();           // client epoch now (load time)
function nowPHDate(){ return new Date(BASE_PH_MS + (Date.now() - BASE_CLIENT_MS)); }
function ymdPH(){
  const d = nowPHDate();
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function hhmmPH(){
  const d = nowPHDate();
  return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}
function toAmPm(hhmm){
  const [h,m] = hhmm.split(':').map(Number);
  const ampm = h>=12 ? 'PM' : 'AM';
  const h12 = (h%12)||12;
  return `${h12}:${String(m).padStart(2,'0')} ${ampm}`;
}

/* ===== Helpers ===== */
function isStartBeforeEnd(startHHMM, endHHMM) {
  if (!startHHMM || !endHHMM) return false;
  const [sh, sm] = startHHMM.split(':').map(Number);
  const [eh, em] = endHHMM.split(':').map(Number);
  return (eh*60 + em) > (sh*60 + sm);
}
function humanDuration(startHHMM, endHHMM){
  if (!isStartBeforeEnd(startHHMM, endHHMM)) return '—';
  const [sh, sm] = startHHMM.split(':').map(Number);
  const [eh, em] = endHHMM.split(':').map(Number);
  let mins = (eh*60+em) - (sh*60+sm);
  const h = Math.floor(mins/60); mins%=60;
  return (h?`${h} hr${h>1?'s':''} `:'') + (mins?`${mins} min`:'').trim() || '0 min';
}
function setInvalid(inputEl, isBad){ inputEl.classList.toggle('is-invalid', !!isBad); }

/* ===== Validation (Add) ===== */
function revalidateAdd(){
  const d = document.getElementById('date').value;
  const s = document.getElementById('start_time').value;
  const n = document.getElementById('end_time').value;
  const nowY = ymdPH();
  const nowT = hhmmPH();

  // default hide
  const nowErr = document.getElementById('nowErrorAdd');
  const timeErr= document.getElementById('timeErrorAdd');
  nowErr.classList.add('d-none'); timeErr.classList.add('d-none');
  setInvalid(start_time,false); setInvalid(end_time,false);

  // today-in-PH rule
  let ok = true;
  if (d && s && d === nowY && s < nowT){
    nowErr.textContent = `Start time cannot be earlier than the current time in Asia/Manila (${toAmPm(nowT)}).`;
    nowErr.classList.remove('d-none');
    setInvalid(start_time,true); ok = false;
  }

  // end > start rule
  if (s && n && !isStartBeforeEnd(s,n)){
    timeErr.classList.remove('d-none');
    setInvalid(end_time,true); ok = false;
  }

  // duration preview
  const addDur = document.getElementById('addDuration');
  addDur.textContent = humanDuration(s, n);

  return ok;
}
function validateAddDateTime(){
  // also ensure date not in the past vs PH today
  const d = document.getElementById('date').value;
  const dateOk = d && d >= ymdPH();
  document.getElementById('dateErrorAdd').classList.toggle('d-none', dateOk);
  if (!dateOk) return false;

  return revalidateAdd();
}

/* ===== Validation (Edit) ===== */
function revalidateEdit(){
  const d = document.getElementById('edit_date').value;
  const s = document.getElementById('edit_start_time').value;
  const n = document.getElementById('edit_end_time').value;
  const nowY = ymdPH();
  const nowT = hhmmPH();

  const nowErr = document.getElementById('nowErrorEdit');
  const timeErr= document.getElementById('timeErrorEdit');
  nowErr.classList.add('d-none'); timeErr.classList.add('d-none');
  setInvalid(edit_start_time,false); setInvalid(edit_end_time,false);

  let ok = true;
  if (d && s && d === nowY && s < nowT){
    nowErr.textContent = `Start time cannot be earlier than the current time in Asia/Manila (${toAmPm(nowT)}).`;
    nowErr.classList.remove('d-none');
    setInvalid(edit_start_time,true); ok = false;
  }
  if (s && n && !isStartBeforeEnd(s,n)){
    timeErr.classList.remove('d-none');
    setInvalid(edit_end_time,true); ok = false;
  }

  document.getElementById('editDuration').textContent = humanDuration(s, n);
  return ok;
}
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleCustomEventName(select){
  // This function is no longer used by the Add Modal
}
function toggleCustomEditName(select){
  // This function is no longer used by the Edit Modal
}

/* DELETE confirm + modals */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.delete-btn').forEach(btn=>{
    btn.addEventListener('click', function(){
      const form = this.closest('.delete-event-form');
      Swal.fire({
        title: 'Are you sure?',
        text: 'This event will be archived.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, archive it!',
        reverseButtons: true
      }).then(r=>{ if(r.isConfirmed){ form.submit(); } });
    });
  });

  // View/Edit loader
  document.querySelectorAll('.view-edit-btn').forEach(btn=>{
    btn.addEventListener('click', async function(){
      
      // CHANGE: Get the event name from the button's data-eventname attribute
      const eventName = this.dataset.eventname; 
      
      const id = this.dataset.id;
      try{
        const res = await fetch(`class/event_get.php?id=${id}`);
        const data = await res.json();
        if(!data.success) throw new Error(data.message || 'Failed to load event.');
        const ev = data.event;
        
        document.getElementById('edit_id').value = ev.id;
        
        // CHANGE: Populate the new text input field with the name
        document.getElementById('edit_new_event_name').value = eventName; 
        
        document.getElementById('edit_description').value = ev.event_description || '';
        document.getElementById('edit_date').value = ev.event_date;
        const s = (ev.event_time || '').substring(0,5);
        const n = (ev.event_end_time || '').substring(0,5);
        document.getElementById('edit_start_time').value = s;
        document.getElementById('edit_end_time').value   = n;
        document.getElementById('editDuration').textContent = humanDuration(s, n);
        document.getElementById('edit_location').value = ev.event_location || '';
        document.getElementById('edit_image_preview').src = `class/event_image.php?id=${ev.id}&_=${Date.now()}`;
        document.getElementById('edit_image_new_preview').classList.add('d-none');
        document.getElementById('editImageMeta').classList.add('d-none');
        new bootstrap.Modal(document.getElementById('viewEditModal')).show();

        // Run initial validation against current PH time
        revalidateEdit();
      }catch(err){
        Swal.fire('Error', err.message, 'error');
      }
    });
  });

  // Live validation + duration: ADD
  ['date','start_time','end_time'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', revalidateAdd);
  });

  // Live validation + duration: EDIT
  ['edit_date','edit_start_time','edit_end_time'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', revalidateEdit);
  });

  // Add image drop/preview
  const drop = document.getElementById('addDrop');
  const file = document.getElementById('image');
  const browseBtn = document.getElementById('addBrowseBtn');
  const preview = document.getElementById('add_image_preview');
  const meta = document.getElementById('addImageMeta');

  const pick = () => file.click();
  browseBtn.addEventListener('click', pick);
  drop.addEventListener('click', pick);
  ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev, e=>{e.preventDefault(); drop.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>drop.addEventListener(ev, e=>{e.preventDefault(); drop.classList.remove('drag');}));
  drop.addEventListener('drop', e=>{
    const f = e.dataTransfer.files?.[0];
    if (f) { file.files = e.dataTransfer.files; file.dispatchEvent(new Event('change')); }
  });
  file.addEventListener('change', ()=>{
    const f = file.files?.[0];
    if (!f) return;
    if (f.size > 3*1024*1024) {
      Swal.fire('Too large', 'Please choose an image up to 3 MB.', 'warning');
      file.value = '';
      preview.classList.add('d-none'); meta.classList.add('d-none');
      return;
    }
    const reader = new FileReader();
    reader.onload = e=>{
      preview.src = e.target.result;
      preview.classList.remove('d-none');
      meta.textContent = `${f.name} — ${(f.size/1024/1024).toFixed(2)} MB`;
      meta.classList.remove('d-none');
    };
    reader.readAsDataURL(f);
  });
});

/* Submit handler (Edit) with PH-time enforcement */
const editForm = document.getElementById('editEventForm');
if (editForm){
  editForm.addEventListener('submit', async function(e){
    const d = document.getElementById('edit_date').value;
    const ok = revalidateEdit() && (d && d >= ymdPH());
    document.getElementById('dateErrorEdit').classList.toggle('d-none', d && d >= ymdPH());
    if (!ok) { e.preventDefault(); Swal.fire('Invalid', 'Please fix the time/date errors.', 'warning'); return; }

    e.preventDefault();
    const formData = new FormData(this);
    const res = await fetch(this.action, { method:'POST', body: formData });
    const data = await res.json();
    if(data.success){
      Swal.fire({icon:'success', title:'Saved', text:'Event updated successfully.'}).then(()=> location.reload());
    }else{
      Swal.fire('Error', data.message || 'Update failed.', 'error');
    }
  });
}
</script>
</body>
</html>