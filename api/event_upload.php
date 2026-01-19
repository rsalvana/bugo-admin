<?php

session_start(); // Start session

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once '../include/encryption.php';
require_once '../include/redirects.php';
include_once '../logs/logs_trig.php';

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
    $resbaseUrl = '../index.php'; 
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    
    // --- 1. Inputs ---
    $event_name_field = isset($_POST['event_name']) ? validateInput($_POST['event_name']) : ''; 
    $new_name_field   = isset($_POST['new_event_name']) ? validateInput($_POST['new_event_name']) : '';
    
    $description = isset($_POST['description']) ? validateInput($_POST['description']) : '';
    $location    = isset($_POST['location']) ? validateInput($_POST['location']) : '';
    $date        = $_POST['date'] ?? '';
    $start_time  = $_POST['start_time'] ?? '';
    $end_time    = $_POST['end_time'] ?? '';
    
    $emp_id = $_SESSION['employee_id'] ?? 1;
    $trigs = new Trigger();

    // --- 2. Handle Image Upload (Optional) ---
    $imageData = null;
    $imageType = null;
    $eventNameId = null; 

    // Check if image is uploaded
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
        $imageType = $_FILES['image']['type'];
    }
    // If no image is uploaded, $imageData remains NULL. 
    // Ensure your DB column 'event_image' is set to allow NULL.

    // ========== LOGIC FOR EVENT NAME ==========
    $final_name_str = '';
    if (!empty($new_name_field)) {
        $final_name_str = $new_name_field;
    } else if (!empty($event_name_field) && $event_name_field !== 'other') {
        $final_name_str = $event_name_field;
    }

    if (!empty($final_name_str)) {
        $stmt = $mysqli->prepare("SELECT id FROM event_name WHERE event_name = ?");
        $stmt->bind_param("s", $final_name_str);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($eventNameId);
            $stmt->fetch();
        } else {
            $insert = $mysqli->prepare("INSERT INTO event_name (event_name, status, employee_id) VALUES (?, 1, ?)");
            $insert->bind_param("si", $final_name_str, $emp_id);
            $insert->execute();
            $eventNameId = $insert->insert_id;
            $insert->close();
        }
        $stmt->close();
    }

    // Check valid Event Name ID
    if (empty($eventNameId)) {
         echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
         echo "<script>
             document.addEventListener('DOMContentLoaded', function () {
                 Swal.fire({
                     icon: 'error',
                     title: '❌ Error',
                     text: 'Event Name is required.',
                     confirmButtonColor: '#d33'
                 }).then(() => {
                     window.history.back();
                 });
             });
           </script>";
         exit;
    }

    // Insert into events table
    // event_image and image_type will be NULL if no file was uploaded
    $stmt = $mysqli->prepare("INSERT INTO events (emp_id, event_title, event_description, event_location, event_time, event_end_time, event_date, event_image, image_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssss", $emp_id, $eventNameId, $description, $location, $start_time, $end_time, $date, $imageData, $imageType);
    
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>"; 

    if ($stmt->execute()) {
        $eventId = $mysqli->insert_id;
        $trigs->isAdded(11, $eventId); 
        echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                icon: 'success',
                title: '✅ Success!',
                text: 'Event successfully added.',
                confirmButtonColor: '#3085d6'
            }).then(() => {
                window.location.href = '$resbaseUrl';
            });
        });
        </script>";
    } else {
        $errorMsg = addslashes($stmt->error); 
        echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
                Swal.fire({
                    icon: 'error',
                    title: '❌ Database Error',
                    text: 'Something went wrong: $errorMsg',
                    confirmButtonColor: '#d33'
                }).then(() => {
                    window.history.back(); 
                });
            });
        </script>";
    }

    $stmt->close();
    $mysqli->close();
}
?>