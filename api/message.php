<?php
// api/message.php
declare(strict_types=1);

// Error reporting for debugging (Disable in production)
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../include/connection.php';

session_start();

// Check if this is an API request (POST action)
$action = $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $mysqli = db_connection();

    // 1. FETCH USERS (With Profile Picture)
    if ($action === 'fetch_users') {
        // CORRECTED COLUMN NAME: profile_picture
        $sql = "SELECT 
                    r.id, 
                    CONCAT(r.first_name, ' ', r.last_name) as name, 
                    r.profile_picture, 
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
                // Convert BLOB to Base64 for display
                $img = null;
                if (!empty($row['profile_picture'])) {
                    $img = 'data:image/jpeg;base64,' . base64_encode($row['profile_picture']);
                }

                $users[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'image' => $img, // Sending the image data
                    'unread' => (int)$row['unread_count']
                ];
            }
        }
        
        echo json_encode($users);
        exit;
    }

    // 2. FETCH CHAT
    if ($action === 'fetch_chat') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        
        if ($residentId > 0) {
            // Mark messages as read
            $stmt = $mysqli->prepare("UPDATE support_messages SET admin_read = 1 WHERE resident_id = ? AND admin_read = 0");
            $stmt->bind_param("i", $residentId);
            $stmt->execute();
            $stmt->close();

            // Get messages
            $stmt = $mysqli->prepare("SELECT sent_by, message, created_at FROM support_messages WHERE resident_id = ? ORDER BY created_at ASC");
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

    // 3. SEND MESSAGE
    if ($action === 'send') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        
        if ($residentId > 0 && $message !== '') {
            $stmt = $mysqli->prepare("INSERT INTO support_messages (resident_id, sent_by, message, created_at, admin_read, resident_read) VALUES (?, 'admin', ?, NOW(), 1, 0)");
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
    
    // If action not matched
    exit;
}

// --- HTML OUTPUT STARTS HERE ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Consultation</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* --- MODERN THEME --- */
        /* Note: We scope to .chat-wrapper to avoid breaking the main dashboard layout */
        
        .chat-wrapper {
            background-color: #f0f2f5; 
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            height: 85vh;
            width: 100%;
        }

        .chat-container {
            height: 100%;
            max-width: 1600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            display: flex;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.03);
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 350px;
            border-right: 1px solid #f0f0f0;
            display: flex;
            flex-direction: column;
            background-color: #ffffff;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }
        .sidebar-header h5 {
            font-weight: 700;
            color: #4e73df;
            margin: 0;
            display: flex; align-items: center; gap: 10px;
        }

        .user-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        
        .user-item {
            display: flex; align-items: center; padding: 12px 15px; margin-bottom: 5px;
            border-radius: 12px; cursor: pointer; transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .user-item:hover { background-color: #f8f9fc; transform: translateX(3px); }
        .user-item.active { background-color: #eef2ff; border-color: #e0e7ff; }

        /* AVATAR STYLE */
        .avatar {
            width: 45px; height: 45px; border-radius: 50%;
            object-fit: cover; margin-right: 15px; flex-shrink: 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .avatar-initial {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem;
        }

        .user-details { flex-grow: 1; min-width: 0; }
        .user-name { font-weight: 600; font-size: 0.95rem; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-preview { font-size: 0.8rem; color: #888; }
        .badge-count {
            background: #ff5b5b; color: white; font-size: 0.7rem; font-weight: 700;
            padding: 4px 8px; border-radius: 20px; box-shadow: 0 2px 5px rgba(255, 91, 91, 0.3);
        }

        /* --- CHAT AREA --- */
        .chat-view {
            flex: 1; display: flex; flex-direction: column;
            background-color: #fcfcfc; position: relative; min-width: 0;
        }

        .chat-header {
            flex: 0 0 auto;
            padding: 15px 25px; background: #fff; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; justify-content: space-between; height: 70px;
        }
        
        .header-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e3e6f0; }
        .header-avatar-initial { width: 40px; height: 40px; border-radius: 50%; background: #4e73df; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        
        .chat-header-title { font-weight: 700; font-size: 1.1rem; color: #333; }
        .status-indicator { font-size: 0.8rem; color: #1cc88a; font-weight: 600; display: flex; align-items: center; gap: 5px; }

        .messages-box {
            flex: 1; overflow-y: auto; padding: 25px;
            display: flex; flex-direction: column; gap: 15px;
            background-image: radial-gradient(#e9ecef 1px, transparent 1px);
            background-size: 20px 20px;
        }

        .msg {
            max-width: 70%; padding: 12px 18px; border-radius: 18px;
            font-size: 0.95rem; line-height: 1.5; position: relative;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03); word-wrap: break-word;
        }
        .msg.admin {
            align-self: flex-end;
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white; border-bottom-right-radius: 4px;
        }
        .msg.resident {
            align-self: flex-start;
            background: #ffffff; color: #444; border: 1px solid #f0f0f0; border-bottom-left-radius: 4px;
        }
        .msg-time { font-size: 0.7rem; margin-top: 4px; text-align: right; opacity: 0.7; }

        .input-area {
            flex: 0 0 auto;
            padding: 20px; background: #fff; border-top: 1px solid #f0f0f0;
            display: flex; gap: 10px; align-items: center;
        }
        .chat-input {
            flex: 1; padding: 12px 20px; border-radius: 30px;
            border: 1px solid #e0e0e0; background: #f8f9fa; outline: none; transition: 0.2s;
        }
        .chat-input:focus { background: #fff; border-color: #4e73df; }

        .send-btn {
            width: 45px; height: 45px; border-radius: 50%;
            background: #4e73df; color: white; border: none;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            transition: transform 0.2s;
        }
        .send-btn:hover { transform: scale(1.05); }

        .empty-state {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; color: #aaa;
        }
        .empty-icon { font-size: 4rem; margin-bottom: 15px; color: #e0e0e0; }
    </style>
</head>
<body>

<div class="chat-wrapper">
    <div class="chat-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h5><i class="fa-solid fa-comments"></i> Inquiries</h5>
            </div>
            <div id="users-container" class="user-list">
                <div class="text-center p-4 text-muted small"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>

        <div class="chat-view">
            <div class="chat-header">
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <div id="headerAvatarContainer"></div>
                    <div class="chat-header-title" id="chatHeader">Select a resident</div>
                </div>
                <div class="status-indicator" id="onlineStatus" style="display: none;">
                    <i class="fas fa-circle" style="font-size: 8px;"></i> Online
                </div>
            </div>

            <div id="admin-chat-box" class="messages-box">
                <div class="empty-state">
                    <i class="fas fa-paper-plane empty-icon"></i>
                    <p>Select a conversation to start chatting</p>
                </div>
            </div>

            <div class="input-area">
                <input type="text" id="adminMsgInput" class="chat-input" placeholder="Type your reply..." onkeypress="handleEnter(event)">
                <button class="send-btn" onclick="sendAdminMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Point to THIS file since it contains the PHP backend logic at the top
    const API_URL = 'api/message.php'; 
    
    let currentResidentId = null;
    let usersInterval = null;
    let chatInterval = null;

    // Start Polling
    fetchUsers();
    usersInterval = setInterval(fetchUsers, 3000); 
    chatInterval = setInterval(fetchChat, 2000); 

    // 1. Fetch Users
    async function fetchUsers() {
        const formData = new FormData();
        formData.append('action', 'fetch_users');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            if(!res.ok) throw new Error("API Error");
            const users = await res.json();
            
            const container = document.getElementById('users-container');
            if(users.length === 0) {
                container.innerHTML = '<div class="text-center p-4 text-muted small">No active inquiries.</div>';
                return;
            }

            let html = '';
            users.forEach(user => {
                const isActive = (user.id == currentResidentId) ? 'active' : '';
                const initial = user.name ? user.name.charAt(0).toUpperCase() : '?';
                const count = parseInt(user.unread || 0);
                
                // IMAGE LOGIC (Sidebar)
                let avatarHtml = '';
                if(user.image) {
                    avatarHtml = `<img src="${user.image}" class="avatar" alt="Pic">`;
                } else {
                    avatarHtml = `<div class="avatar avatar-initial">${initial}</div>`;
                }

                let badgeHtml = '';
                if (count > 0 && user.id != currentResidentId) {
                    const display = count > 9 ? '9+' : count;
                    badgeHtml = `<div class="badge-count">${display}</div>`;
                }
                
                const safeName = user.name.replace(/'/g, "\\'");
                // Use a safer way to pass the image string (empty string if null)
                const safeImage = user.image ? user.image : ''; 

                // Pass the image to selectUser, but we need to be careful with quotes. 
                // Best practice: Store data in data attributes instead of function args for large strings
                html += `
                    <div class="user-item ${isActive}" onclick="handleUserClick(${user.id}, '${safeName}')" data-image="${safeImage}">
                        ${avatarHtml}
                        <div class="user-details">
                            <div class="user-name">${user.name}</div>
                            <div class="user-preview">Click to view chat</div>
                        </div>
                        ${badgeHtml}
                    </div>
                `;
            });
            container.innerHTML = html;

        } catch(e) { 
            console.error("Fetch Error:", e);
            document.getElementById('users-container').innerHTML = '<div class="text-center p-3 text-danger small">Connection Failed</div>';
        }
    }

    // Helper to handle click and retrieve image data safely
    function handleUserClick(id, name) {
        // Find the clicked element to get the data-image attribute
        const el = event.currentTarget; 
        const imageUrl = el.getAttribute('data-image');
        selectUser(id, name, imageUrl);
    }

    // 2. Select User (Updates Header with Image)
    function selectUser(id, name, imageUrl) {
        currentResidentId = id;
        
        let headerImgHtml = '';
        if(imageUrl && imageUrl.length > 50) {
            headerImgHtml = `<img src="${imageUrl}" class="header-avatar">`;
        } else {
            const initial = name.charAt(0).toUpperCase();
            headerImgHtml = `<div class="header-avatar-initial">${initial}</div>`;
        }

        document.getElementById('headerAvatarContainer').innerHTML = headerImgHtml;
        document.getElementById('chatHeader').innerHTML = name;
        document.getElementById('onlineStatus').style.display = 'flex';
        
        const chatBox = document.getElementById('admin-chat-box');
        chatBox.innerHTML = '<div class="empty-state"><i class="fas fa-circle-notch fa-spin text-primary opacity-25 fa-3x mb-3"></i><p>Loading history...</p></div>';
        
        fetchUsers(); 
        fetchChat();  
    }

    // 3. Fetch Chat
    async function fetchChat() {
        if(!currentResidentId) return;

        const formData = new FormData();
        formData.append('action', 'fetch_chat');
        formData.append('resident_id', currentResidentId);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();

            if (data.status === 'success') {
                renderMessages(data.messages);
            }
        } catch(e) { console.error("Chat Error:", e); }
    }

    function renderMessages(messages) {
        const chatBox = document.getElementById('admin-chat-box');
        
        if (messages.length === 0) {
            chatBox.innerHTML = '<div class="empty-state"><p>No messages yet. Say hello!</p></div>';
            return;
        }

        let html = '';
        messages.forEach(msg => {
            const msgContent = escapeHtml(msg.message).replace(/\n/g, '<br>');
            const roleClass = (msg.sent_by === 'admin') ? 'admin' : 'resident';
            
            html += `
                <div class="msg ${roleClass}">
                    ${msgContent}
                    <div class="msg-time">${msg.time}</div>
                </div>
            `;
        });
        
        if (chatBox.innerHTML !== html) {
             const wasAtBottom = (chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 150);
             chatBox.innerHTML = html;
             if (wasAtBottom || chatBox.innerHTML.includes('Loading...')) {
                 chatBox.scrollTop = chatBox.scrollHeight;
             }
        }
    }

    // 4. Send Message
    async function sendAdminMessage() {
        if(!currentResidentId) return;
        
        const input = document.getElementById('adminMsgInput');
        const msg = input.value.trim();
        if(!msg) return;

        const chatBox = document.getElementById('admin-chat-box');
        const tempId = Date.now();
        
        if(chatBox.querySelector('.empty-state')) chatBox.innerHTML = '';

        chatBox.insertAdjacentHTML('beforeend', `
            <div class="msg admin" id="temp-${tempId}" style="opacity:0.7">
                ${escapeHtml(msg)}
                <div class="msg-time">Sending...</div>
            </div>
        `);
        chatBox.scrollTop = chatBox.scrollHeight;
        input.value = '';

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('resident_id', currentResidentId);
        formData.append('sent_by', 'admin'); 
        formData.append('message', msg);

        try {
            await fetch(API_URL, { method: 'POST', body: formData });
            fetchChat(); 
        } catch (e) {
            console.error("Send failed", e);
            document.getElementById(`temp-${tempId}`).style.background = '#dc3545';
        }
    }

    function handleEnter(e) {
        if (e.key === 'Enter') sendAdminMessage();
    }

    function escapeHtml(text) {
        if (!text) return "";
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
</script>

</body>
</html>