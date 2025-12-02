<?php
session_start();
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once __DIR__ . '/../logs/logs_trig.php';

header('Content-Type: application/json');

$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin','Multimedia'])) { 
    echo json_encode(['success'=>false,'message'=>'Forbidden']); 
    exit; 
}

function clean($s){ return htmlspecialchars(stripslashes(trim((string)$s))); }

// --- Get all possible fields ---
$id               = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$event_name_field = clean($_POST['event_name'] ?? '');     // This is "My Event" for Admin, or "other" for Multimedia
$new_name_field   = clean($_POST['new_event_name'] ?? ''); // This is "" for Admin, or "My Event" for Multimedia
$desc             = clean($_POST['description'] ?? '');
$date             = $_POST['date'] ?? '';
$start_time       = $_POST['start_time'] ?? '';
$end_time         = $_POST['end_time'] ?? '';
$location         = clean($_POST['location'] ?? '');

if ($id <= 0) { 
    echo json_encode(['success'=>false,'message'=>'Invalid ID']); 
    exit; 
}

/* --- server-side validation --- */
$today = date('Y-m-d');
if (!$date || $date < $today) {
  echo json_encode(['success'=>false,'message'=>'Date must be today or later.']); 
  exit;
}
if (!$start_time || !$end_time || ($end_time <= $start_time)) {
  echo json_encode(['success'=>false,'message'=>'End time must be later than start time.']); 
  exit;
}

// ========== NEW UNIFIED LOGIC START ==========
// Determine the *actual* name string to use, regardless of which form sent it
$final_name_str = '';
if (!empty($new_name_field)) {
    // This is the Multimedia form sending 'new_event_name'
    $final_name_str = $new_name_field;
} else if (!empty($event_name_field) && $event_name_field !== 'other') {
    // This is the Admin form sending 'event_name'
    $final_name_str = $event_name_field;
}

// Check if we ended up with a valid name
if (empty($final_name_str)) {
    echo json_encode(['success'=>false,'message'=>'Event Name is required. Please provide a name.']);
    exit;
}

// Now, find or create the event name ID using the final string
$eventTitleId = null;
$find = $mysqli->prepare("SELECT id FROM event_name WHERE event_name = ? LIMIT 1");
$find->bind_param("s", $final_name_str);
$find->execute();
$find->bind_result($foundId);

if ($find->fetch()) {
    // It exists, use this ID
    $eventTitleId = (int)$foundId;
    $find->close();
} else {
    // It doesn't exist, create it
    $find->close();
    $ins = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?,1,?)");
    $emp_id = $_SESSION['employee_id'] ?? 1;
    $ins->bind_param("si", $final_name_str, $emp_id);
    $ins->execute();
    $eventTitleId = $ins->insert_id;
    $ins->close();
}
// ========== NEW UNIFIED LOGIC END ==========


/* --- update with/without new image --- */
$hasImage = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;

if ($hasImage) {
  $img = file_get_contents($_FILES['image']['tmp_name']);
  $typ = $_FILES['image']['type'];
  $sql = "UPDATE events 
          SET event_title=?, event_description=?, event_location=?, event_time=?, event_end_time=?, event_date=?, event_image=?, image_type=?
          WHERE id=?";
  $stmt = $mysqli->prepare($sql);
  if ($stmt === false) {
      echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error]); exit;
  }
  $null = NULL; // For the blob
  $stmt->bind_param("isssssbsi", $eventTitleId, $desc, $location, $start_time, $end_time, $date, $null, $typ, $id);
  $stmt->send_long_data(6, $img);
} else {
  $sql = "UPDATE events 
          SET event_title=?, event_description=?, event_location=?, event_time=?, event_end_time=?, event_date=?
          WHERE id=?";
  $stmt = $mysqli->prepare($sql);
  if ($stmt === false) {
      echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error]); exit;
  }
  $stmt->bind_param("isssssi", $eventTitleId, $desc, $location, $start_time, $end_time, $date, $id);
}

$ok = $stmt->execute();
if (!$ok) {
    $errorMsg = $stmt->error;
    $stmt->close();
    echo json_encode(['success'=>false,'message'=>'Execute failed: '.$errorMsg]);
    exit;
}
$stmt->close();

if ($ok) {
  $trigs = new Trigger();
  $trigs->isUpdated(11, $id);
  echo json_encode(['success'=>true]);
} else {
  echo json_encode(['success'=>false,'message'=>'Database update failed.']);
}
?>