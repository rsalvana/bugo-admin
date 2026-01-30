<?php
// Modules/lupon_modules/get_participants.php
session_start();

// Go up twice to reach the include folder from Modules/lupon_modules/
require_once '../../include/connection.php'; 

$mysqli = db_connection();
header('Content-Type: application/json');

if (isset($_GET['case_number'])) {
    $case_number = $_GET['case_number'];

    // Select individual participants for the modal
    $sql = "SELECT participant_id, first_name, last_name, role, action_taken, remarks 
            FROM case_participants 
            WHERE case_number = ? 
            ORDER BY role ASC";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $case_number);
    $stmt->execute();
    $result = $stmt->get_result();

    $participants = [];
    while ($row = $result->fetch_assoc()) {
        $participants[] = $row;
    }

    echo json_encode($participants);
    $stmt->close();
} else {
    echo json_encode(['error' => 'No case number provided']);
}
exit;