<?php
// ajax/message.php
declare(strict_types=1);

require_once __DIR__ . '/../include/connection.php';

session_start();
header('Content-Type: application/json');

$mysqli = db_connection();
$action = $_POST['action'] ?? '';

// 1. FETCH USERS (With Profile Picture)
if ($action === 'fetch_users') {
    // Added r.profile_pic to the query
    $sql = "SELECT 
                r.id, 
                CONCAT(r.first_name, ' ', r.last_name) as name, 
                r.profile_pic, 
                (SELECT COUNT(*) 
                 FROM support_messages 
                 WHERE resident_id = r.id 
                   AND admin_read = 0
                ) as unread_count
            FROM residents r
            JOIN support_messages sm ON sm.resident_id = r.id
            GROUP BY r.id
            ORDER BY unread_count DESC, MAX(sm.created_at) DESC";

    $result = $mysqli->query($sql);
    
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Convert Blob to Base64 if image exists
            $img = null;
            if (!empty($row['profile_pic'])) {
                $img = 'data:image/jpeg;base64,' . base64_encode($row['profile_pic']);
            }

            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'image' => $img, // Send image data to frontend
                'unread' => (int)$row['unread_count']
            ];
        }
    }
    
    echo json_encode($users);
    exit;
}

// 2. FETCH CHAT (Mark as READ by Admin)
if ($action === 'fetch_chat') {
    $residentId = (int)($_POST['resident_id'] ?? 0);
    
    if ($residentId > 0) {
        $updateSql = "UPDATE support_messages 
                      SET admin_read = 1 
                      WHERE resident_id = ? AND admin_read = 0";
        $stmt = $mysqli->prepare($updateSql);
        $stmt->bind_param("i", $residentId);
        $stmt->execute();
        $stmt->close();

        $sql = "SELECT sent_by, message, created_at 
                FROM support_messages 
                WHERE resident_id = ? 
                ORDER BY created_at ASC";
                
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $residentId);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $messages = [];
        while ($row = $res->fetch_assoc()) {
            $dt = new DateTime($row['created_at']);
            $row['time'] = $dt->format('M j, g:i A');
            $messages[] = $row;
        }
        echo json_encode(['status' => 'success', 'messages' => $messages]);
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// 3. SEND MESSAGE (Admin -> Resident)
if ($action === 'send') {
    $residentId = (int)($_POST['resident_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($residentId > 0 && $message !== '') {
        $sql = "INSERT INTO support_messages (resident_id, sent_by, message, created_at, admin_read, resident_read) 
                VALUES (?, 'admin', ?, NOW(), 1, 0)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("is", $residentId, $message);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

$mysqli->close();
?>