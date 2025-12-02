<?php

session_start(); // Start session

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../include/encryption.php';
require_once '../include/redirects.php';
include_once '../logs/logs_trig.php';

// include 'class/session_timeout.php';
function validateInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}
// Role check: Only allow Admin or Multimedia
$role = $_SESSION['Role_Name'] ?? '';
if (!in_array($role, ['Admin', 'Multimedia'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

// Set correct redirect URL based on role
if ($role === 'Admin') {
    $resbaseUrl = enc_admin('event_list');
} elseif ($role === 'Multimedia') {
    $resbaseUrl = enc_multimedia('event_list');
} else {
    $resbaseUrl = '../index.php'; // Fallback
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // --- Get all possible fields ---
    $event_name_field = validateInput($_POST['event_name']);     // Admin: "My Event", Multi: "other"
    $new_name_field   = validateInput($_POST['new_event_name']); // Admin: "", Multi: "My Event"
    
    $description = validateInput($_POST['description']);
    $date = $_POST['date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $location = validateInput($_POST['location']);
    $emp_id = $_SESSION['employee_id'] ?? 1;
    $trigs = new Trigger();

    $imageData = null;
    $imageType = null;
    $eventNameId = null; // Initialize variable

    // This block is now optional
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
        $imageType = $_FILES['image']['type'];
    }

    // ========== MODIFIED UNIFIED LOGIC START ==========
    
    // Determine the *actual* name string to use
    $final_name_str = '';
    if (!empty($new_name_field)) {
        // This is the Multimedia form sending 'new_event_name'
        $final_name_str = $new_name_field;
    } else if (!empty($event_name_field) && $event_name_field !== 'other') {
        // This is the Admin form sending 'event_name'
        $final_name_str = $event_name_field;
    }

    // If the event name string is not empty, find or create it
    if (!empty($final_name_str)) {
        $stmt = $mysqli->prepare("SELECT id FROM event_name WHERE event_name = ?");
        $stmt->bind_param("s", $final_name_str);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // It exists, get the ID
            $stmt->bind_result($eventNameId);
            $stmt->fetch();
        } else {
            // It doesn't exist, create it
            $insert = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?, 1, ?)");
            $insert->bind_param("si", $final_name_str, $emp_id);
            $insert->execute();
            $eventNameId = $insert->insert_id;
            $insert->close();
        }
        $stmt->close();
    }
    // If $final_name_str was empty, $eventNameId will still be null
    // ========== MODIFIED UNIFIED LOGIC END ==========
    

    // Check if we have a valid eventNameId before proceeding
    if (empty($eventNameId)) {
         echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
         echo "<script>
             document.addEventListener('DOMContentLoaded', function () {
                 Swal.fire({
                     icon: 'error',
                     title: '❌ Error',
                     text: 'Event Name is required. Please provide a name.',
                     confirmButtonColor: '#d33'
                 }).then(() => {
                     window.history.back(); // Go back to form
                 });
             });
           </script>";
         exit;
    }

    // Insert into events table
    $stmt = $mysqli->prepare("INSERT INTO events (emp_id, event_title, event_description, event_location, event_time, event_end_time, event_date, event_image, image_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssss", $emp_id, $eventNameId, $description, $location, $start_time, $end_time, $date, $imageData, $imageType);
    
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>"; // Load SweetAlert

    if ($stmt->execute()) {
        $eventId = $mysqli->insert_id;
        $trigs->isAdded(11, $eventId); // Trigger for the 'events' table
        echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                icon: 'success',
                title: '✅ Success!',
                text: 'Event successfully added.',
                confirmButtonColor: '#3085d6'
            }).then(() => {
                window.location.href = '$resbaseUrl'; // Go back to your UI
            });
        });
        </script>";
    } else {
        $errorMsg = addslashes($stmt->error); // prevent breaking quotes
        echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
                Swal.fire({
                    icon: 'error',
                    title: '❌ Error',
                    text: 'Something went wrong: $errorMsg',
                    confirmButtonColor: '#d33'
                }).then(() => {
                    window.history.back(); // Go back to form
                });
            });
        </script>";
    }

    $stmt->close();
    $mysqli->close();
}
?>