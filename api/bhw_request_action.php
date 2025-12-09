<?php
// api/bhw_request_action.php
declare(strict_types=1);

// Standard error handling
ini_set('display_errors', '0'); 
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

function json_response($success, $message) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Security Check
if (!isset($_SESSION['employee_id'])) {
    json_response(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- 1. APPROVE REQUEST (Deduct Stock) ---
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
            json_response(true, "Request marked as $status.");
        } catch (Exception $e) {
            $mysqli->rollback();
            json_response(false, "Error: " . $e->getMessage());
        }
    }

    // --- 2. UPDATE DELIVERY STATUS (Picked Up / On Delivery) ---
    if ($action === 'update_status') {
        $req_id = (int)$_POST['request_id'];
        $new_status = $_POST['new_status'];

        $allowed = ['Picked Up', 'On Delivery'];
        if (!in_array($new_status, $allowed)) {
            json_response(false, 'Invalid status update.');
        }

        // *** FIX: Use employee_id instead of id ***
        if ($new_status === 'On Delivery') {
            $liaison_id = $_SESSION['employee_id']; 

            $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = ?, liaison_id = ? WHERE id = ?");
            $stmt->bind_param("sii", $new_status, $liaison_id, $req_id);
        } else {
            $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $req_id);
        }
        
        if ($stmt->execute()) {
            json_response(true, "Status updated to $new_status.");
        } else {
            json_response(false, "Database error.");
        }
    }

    // --- 3. CONFIRM DELIVERY (With Picture Proof) ---
    if ($action === 'confirm_delivery') {
        $req_id = (int)$_POST['request_id'];
        
        if (!isset($_FILES['proof_img']) || $_FILES['proof_img']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'Proof of delivery picture is required.');
        }

        $fileTmp = $_FILES['proof_img']['tmp_name'];
        $fileContent = file_get_contents($fileTmp);

        // *** FIX: Use employee_id here too ***
        $liaison_id = $_SESSION['employee_id'];

        $stmt = $mysqli->prepare("UPDATE medicine_requests SET status = 'Delivered', delivery_proof = ?, liaison_id = ? WHERE id = ?");
        $null = null;
        $stmt->bind_param("bii", $null, $liaison_id, $req_id);
        $stmt->send_long_data(0, $fileContent);
        
        if ($stmt->execute()) {
            json_response(true, "Delivery confirmed successfully!");
        } else {
            json_response(false, "Error saving proof.");
        }
    }
}
?>