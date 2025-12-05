<?php
// api/bhw_inventory_action.php
declare(strict_types=1);

// Standard error handling (using string '0' to satisfy strict_types)
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
// Ensure this session key matches your BHW login session (usually 'employee_id' or 'id')
if (!isset($_SESSION['employee_id']) && !isset($_SESSION['id'])) {
    json_response(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ADD MEDICINE ---
    if ($action === 'add') {
        $name     = trim($_POST['medicine_name']);
        $category = trim($_POST['category']);
        $stock    = (int)$_POST['stock_quantity'];
        $unit     = trim($_POST['unit']);
        
        if (empty($name) || $stock < 0) {
            json_response(false, 'Invalid input details.');
        }

        // =========================================================
        // 1. STRICT DUPLICATE CHECK (Name + Category)
        // =========================================================
        // We check if a medicine with the SAME Name AND Category exists (and is not archived)
        $checkStmt = $mysqli->prepare("SELECT id FROM medicine_inventory WHERE medicine_name = ? AND category = ? AND delete_status = 0");
        $checkStmt->bind_param("ss", $name, $category);
        $checkStmt->execute();
        $checkStmt->store_result();
        
        if ($checkStmt->num_rows > 0) {
            $checkStmt->close();
            // Stop here if found
            json_response(false, 'A medicine with this Name and Category already exists. Please edit the existing stock instead.');
        }
        $checkStmt->close();
        // =========================================================

        // 2. INSERT NEW ITEM (Only runs if duplicate check passed)
        $stmt = $mysqli->prepare("INSERT INTO medicine_inventory (medicine_name, category, stock_quantity, unit, status, delete_status) VALUES (?, ?, ?, ?, 'Available', 0)");
        $stmt->bind_param("ssis", $name, $category, $stock, $unit);
        
        if ($stmt->execute()) {
            json_response(true, 'Medicine added successfully.');
        } else {
            json_response(false, 'Database error: ' . $stmt->error);
        }
    }

    // --- EDIT MEDICINE ---
    if ($action === 'edit') {
        $id       = (int)$_POST['med_id'];
        $name     = trim($_POST['medicine_name']);
        $category = trim($_POST['category']);
        $stock    = (int)$_POST['stock_quantity'];
        $unit     = trim($_POST['unit']);
        
        // Auto-update status based on stock
        $status = ($stock > 0) ? 'Available' : 'Out of Stock';

        $stmt = $mysqli->prepare("UPDATE medicine_inventory SET medicine_name=?, category=?, stock_quantity=?, unit=?, status=? WHERE id=?");
        $stmt->bind_param("ssissi", $name, $category, $stock, $unit, $status, $id);

        if ($stmt->execute()) {
            json_response(true, 'Medicine updated successfully.');
        } else {
            json_response(false, 'Database error.');
        }
    }

    // --- ARCHIVE (SOFT DELETE) MEDICINE ---
    if ($action === 'archive') {
        $id = (int)$_POST['med_id'];

        $stmt = $mysqli->prepare("UPDATE medicine_inventory SET delete_status = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            json_response(true, 'Medicine archived successfully.');
        } else {
            json_response(false, 'Database error.');
        }
    }
}
?>