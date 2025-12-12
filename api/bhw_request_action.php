<?php
// api/bhw_request_action.php
declare(strict_types=1);

// --- 1. SAFETY BLOCK: Catch Fatal Errors ---
// This ensures that if the script crashes, you get a readable JSON error instead of a blank screen
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'CRITICAL ERROR: ' . $error['message'] . ' in file ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
});

// Standard error settings
ini_set('display_errors', '0'); 
error_reporting(E_ALL);

// Start Output Buffering
ob_start();

session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// --- 2. JSON RESPONSE WITH LOGGING ---
function json_response($success, $message, $debug_log = null) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    
    // Pass the email log to the frontend for debugging
    if ($debug_log) {
        $response['console_log'] = $debug_log;
    }
    
    echo json_encode($response);
    exit;
}

// --- 3. LOAD COMPOSER & PHPMAILER ---
$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    // Try one level higher just in case
    $autoloadPath2 = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoloadPath2)) {
        $autoloadPath = $autoloadPath2;
    } else {
        json_response(false, "Server Error: Cannot find 'vendor/autoload.php'. Please run 'composer install'.");
    }
}
require $autoloadPath;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Security Check
if (!isset($_SESSION['employee_id'])) {
    json_response(false, 'Unauthorized access.');
}

// --- 4. EMAIL HELPER FUNCTION ---
function sendEmailNotification($mysqli, $req_id, $new_status) {
    $log_message = "Email Logic: Started.";

    // IMPORTANT: Ensure 'res_id' matches your actual database column (it might be 'resident_id')
    $query = "SELECT r.email, CONCAT(r.first_name, ' ', r.last_name) AS full_name 
              FROM medicine_requests m 
              JOIN residents r ON m.res_id = r.id 
              WHERE m.id = ?";
              
    $stmt = $mysqli->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $req_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $email = $row['email'];
            $resident_name = $row['full_name'];
            
            if (empty($email)) {
                return "Email Logic: No email address found for this resident.";
            }

            $mail = new PHPMailer(true);
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'jayacop9@gmail.com'; 
                $mail->Password   = 'fsls ywyv irfn ctyc';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                // Recipients
                $mail->setFrom('jayacop9@gmail.com', 'Barangay Office');
                $mail->addAddress($email, $resident_name);

                // Content
                $mail->isHTML(false); 
                $mail->Subject = 'Medicine Request Update';
                $mail->Body    = "Dear $resident_name,\n\nYour medicine request status has been updated to: \"$new_status\".\n\nThank you,\nBarangay Office";

                $mail->send();
                $log_message = "SUCCESS: Email sent to $email";
            } catch (Exception $e) {
                $log_message = "ERROR: Email failed. Info: " . $mail->ErrorInfo;
            }
        } else {
            $log_message = "Email Logic: Request ID not found in DB.";
        }
        $stmt->close();
    } else {
        $log_message = "Email Logic: Database query failed.";
    }
    
    return $log_message;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $email_log = ""; // Variable to hold the log

    try {
        // --- A. APPROVE REQUEST ---
        if ($action === 'process') {
            $req_id = (int)$_POST['request_id'];
            $status = $_POST['status']; 
            $remarks = trim($_POST['remarks']);
            $items = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];

            if ($status === 'Approved' && empty($items)) {
                json_response(false, 'You cannot approve a request without adding medicines.');
            }

            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = ?, remarks = ? WHERE id = ?");
                $stmt->bind_param("ssi", $status, $remarks, $req_id);
                $stmt->execute();
                $stmt->close();

                if ($status === 'Approved') {
                    $stmtCheck = $mysqli->prepare("SELECT stock_quantity, medicine_name FROM medicine_inventory WHERE id = ?");
                    $stmtDeduct = $mysqli->prepare("UPDATE medicine_inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
                    $stmtRecord = $mysqli->prepare("INSERT INTO medicine_request_items (request_id, medicine_id, quantity_requested) VALUES (?, ?, ?)");

                    foreach ($items as $item) {
                        $med_id = (int)$item['id'];
                        $qty = (int)$item['qty'];

                        // Check Stock
                        $stmtCheck->bind_param("i", $med_id);
                        $stmtCheck->execute();
                        $resCheck = $stmtCheck->get_result()->fetch_assoc();
                        $stmtCheck->free_result();
                        
                        if (!$resCheck || $resCheck['stock_quantity'] < $qty) {
                            throw new Exception("Insufficient stock for " . ($resCheck['medicine_name'] ?? 'Unknown Medicine'));
                        }

                        // Deduct Stock
                        $stmtDeduct->bind_param("ii", $qty, $med_id);
                        $stmtDeduct->execute();

                        // Record Item
                        $stmtRecord->bind_param("iii", $req_id, $med_id, $qty);
                        $stmtRecord->execute();
                    }
                    $stmtCheck->close();
                    $stmtDeduct->close();
                    $stmtRecord->close();
                }

                $mysqli->commit();
                
                // 1. Send Email and Capture Log
                $email_log = sendEmailNotification($mysqli, $req_id, $status);

                // 2. Return Response with Log
                json_response(true, "Request marked as $status.", $email_log);

            } catch (Exception $e) {
                $mysqli->rollback();
                json_response(false, "Error: " . $e->getMessage());
            }
        }

        // --- B. UPDATE STATUS ---
        if ($action === 'update_status') {
            $req_id = (int)$_POST['request_id'];
            $new_status = $_POST['new_status'];
            $liaison_id = $_SESSION['employee_id']; 

            $allowed = ['Picked Up', 'On Delivery'];
            if (!in_array($new_status, $allowed)) json_response(false, 'Invalid status.');

            if ($new_status === 'On Delivery') {
                $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = ?, liaison_id = ? WHERE id = ?");
                $stmt->bind_param("sii", $new_status, $liaison_id, $req_id);
            } else {
                $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $new_status, $req_id);
            }
            
            if ($stmt->execute()) {
                // 1. Send Email and Capture Log
                $email_log = sendEmailNotification($mysqli, $req_id, $new_status);
                // 2. Return Response with Log
                json_response(true, "Status updated to $new_status.", $email_log);
            } else {
                json_response(false, "Database error.");
            }
        }

        // --- C. CONFIRM DELIVERY ---
        if ($action === 'confirm_delivery') {
            $req_id = (int)$_POST['request_id'];
            $liaison_id = $_SESSION['employee_id'];

            if (!isset($_FILES['proof_img']) || $_FILES['proof_img']['error'] !== UPLOAD_ERR_OK) {
                json_response(false, 'Proof of delivery picture is required.');
            }

            $fileContent = file_get_contents($_FILES['proof_img']['tmp_name']);

            $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = 'Delivered', delivery_proof = ?, liaison_id = ? WHERE id = ?");
            $null = null;
            $stmt->bind_param("bii", $null, $liaison_id, $req_id);
            $stmt->send_long_data(0, $fileContent);
            
            if ($stmt->execute()) {
                // 1. Send Email and Capture Log
                $email_log = sendEmailNotification($mysqli, $req_id, 'Delivered');
                // 2. Return Response with Log
                json_response(true, "Delivery confirmed successfully!", $email_log);
            } else {
                json_response(false, "Error saving proof.");
            }
        }

    } catch (Exception $e) {
        json_response(false, "Unexpected Error: " . $e->getMessage());
    }
}
?>