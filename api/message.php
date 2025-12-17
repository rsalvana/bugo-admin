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

    // 1. FETCH USERS (Now with Online Status Logic)
    if ($action === 'fetch_users') {
        $search = trim($_POST['search'] ?? '');
        
        // Logic to determine Online Status (Active in last 2 minutes)
        // We fetch last_activity column
        if ($search === '') {
            $sql = "SELECT 
                        r.id, 
                        CONCAT(r.first_name, ' ', r.last_name) as name, 
                        r.profile_picture, 
                        r.last_activity,
                        (SELECT COUNT(*) 
                         FROM support_messages 
                         WHERE resident_id = r.id 
                           AND admin_read = 0
                        ) as unread_count
                    FROM residents r
                    JOIN support_messages sm ON sm.resident_id = r.id
                    GROUP BY r.id
                    ORDER BY unread_count DESC, MAX(sm.created_at) DESC";
            $stmt = $mysqli->prepare($sql);
        } else {
            $searchTerm = "$search%"; 
            $sql = "SELECT 
                        r.id, 
                        CONCAT(r.first_name, ' ', r.last_name) as name, 
                        r.profile_picture,
                        r.last_activity,
                        (SELECT COUNT(*) 
                         FROM support_messages 
                         WHERE resident_id = r.id 
                           AND admin_read = 0
                        ) as unread_count
                    FROM residents r
                    WHERE r.first_name LIKE ? OR r.last_name LIKE ?
                    LIMIT 20"; 
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ss", $searchTerm, $searchTerm);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        $currentTime = time();
        
        while ($row = $result->fetch_assoc()) {
            // IMAGE PROCESSING
            $img = null;
            if (!empty($row['profile_picture'])) {
                $img = 'data:image/jpeg;base64,' . base64_encode($row['profile_picture']);
            }

            // ONLINE STATUS CALCULATION
            $isOnline = false;
            if (!empty($row['last_activity'])) {
                $lastActiveTime = strtotime($row['last_activity']);
                // If active within last 2 minutes (120 seconds), consider online
                if (($currentTime - $lastActiveTime) <= 10) {
                    $isOnline = true;
                }
            }

            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'image' => $img, 
                'unread' => (int)$row['unread_count'],
                'online' => $isOnline // Send boolean to frontend
            ];
        }
        
        echo json_encode($users);
        exit;
    }

    // 2. FETCH CHAT
    if ($action === 'fetch_chat') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        
        if ($residentId > 0) {
            $stmt = $mysqli->prepare("UPDATE support_messages SET admin_read = 1 WHERE resident_id = ? AND admin_read = 0");
            $stmt->bind_param("i", $residentId);
            $stmt->execute();
            $stmt->close();

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
    exit;
}
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
        .chat-wrapper {
            background-color: #f0f2f5; 
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            height: 85vh; 
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .chat-container {
            height: 100%;
            width: 100%;
            max-width: 1600px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            display: flex;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.03);
            position: relative;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 350px;
            border-right: 1px solid #f0f0f0;
            display: flex;
            flex-direction: column;
            background-color: #ffffff;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
            flex-shrink: 0;
        }
        .sidebar-header h5 {
            font-weight: 700; color: #4e73df; margin: 0 0 15px 0;
            display: flex; align-items: center; gap: 10px;
        }
        
        /* SEARCH BAR STYLE */
        .search-container { position: relative; }
        .search-input {
            width: 100%;
            padding: 10px 15px 10px 35px;
            border-radius: 20px;
            border: 1px solid #e3e6f0;
            background-color: #f8f9fc;
            outline: none;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .search-input:focus { background-color: #fff; border-color: #4e73df; box-shadow: 0 0 0 3px rgba(78,115,223,0.1); }
        .search-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #b0b3b8; font-size: 0.9rem;
        }

        .user-list {
            flex: 1; overflow-y: auto; padding: 10px;
            -webkit-overflow-scrolling: touch;
        }
        
        .user-item {
            display: flex; align-items: center; padding: 12px 15px; margin-bottom: 5px;
            border-radius: 12px; cursor: pointer; transition: all 0.2s ease;
            border: 1px solid transparent;
            position: relative; /* For positioning status dot */
        }
        .user-item:hover { background-color: #f8f9fc; }
        .user-item.active { background-color: #eef2ff; border-color: #e0e7ff; }

        .avatar-wrapper { position: relative; margin-right: 15px; flex-shrink: 0; }

        .avatar {
            width: 45px; height: 45px; border-radius: 50%;
            object-fit: cover; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .avatar-initial {
            width: 45px; height: 45px; border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        /* ONLINE STATUS DOT */
        .status-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #fff;
        }
        .status-online { background-color: #2ecc71; } /* Green */
        .status-offline { background-color: #95a5a6; } /* Gray */

        .user-details { flex-grow: 1; min-width: 0; }
        .user-name { font-weight: 600; font-size: 0.95rem; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-preview { font-size: 0.8rem; color: #888; }
        .badge-count {
            background: #ff5b5b; color: white; font-size: 0.7rem; font-weight: 700;
            padding: 4px 8px; border-radius: 20px; box-shadow: 0 2px 5px rgba(255, 91, 91, 0.3);
        }

        /* --- CHAT VIEW --- */
        .chat-view {
            flex: 1; display: flex; flex-direction: column;
            background-color: #fcfcfc; position: relative; min-width: 0;
            transition: transform 0.3s ease;
        }

        .chat-header {
            flex: 0 0 auto;
            padding: 15px 20px; background: #fff; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; justify-content: space-between; height: 70px;
        }
        
        .header-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e3e6f0; }
        .header-avatar-initial { width: 40px; height: 40px; border-radius: 50%; background: #4e73df; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .chat-header-title { font-weight: 700; font-size: 1.1rem; color: #333; }
        
        .header-status-text { font-size: 0.8rem; margin-left: 10px; font-weight: 600; }
        .text-online { color: #2ecc71; }
        .text-offline { color: #95a5a6; }

        .back-btn { 
            display: none; font-size: 1.2rem; color: #5a5c69; 
            margin-right: 15px; cursor: pointer; padding: 5px;
        }

        .messages-box {
            flex: 1; overflow-y: auto; padding: 20px;
            display: flex; flex-direction: column; gap: 15px;
            background-image: radial-gradient(#e9ecef 1px, transparent 1px);
            background-size: 20px 20px;
            -webkit-overflow-scrolling: touch;
        }

        .msg {
            max-width: 75%; padding: 12px 18px; border-radius: 18px;
            font-size: 0.95rem; line-height: 1.4; position: relative;
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
            padding: 15px; background: #fff; border-top: 1px solid #f0f0f0;
            display: flex; gap: 10px; align-items: center;
        }
        .chat-input {
            flex: 1; padding: 12px 20px; border-radius: 30px;
            border: 1px solid #e0e0e0; background: #f8f9fa; outline: none;
        }
        .chat-input:focus { background: #fff; border-color: #4e73df; }
        .send-btn {
            width: 45px; height: 45px; border-radius: 50%;
            background: #4e73df; color: white; border: none;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .empty-state {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; color: #aaa;
        }
        .empty-icon { font-size: 4rem; margin-bottom: 15px; color: #e0e0e0; }

        /* --- RESPONSIVE MOBILE STYLES --- */
        @media (max-width: 992px) {
            .chat-wrapper { height: 80vh; } 
            
            .sidebar {
                width: 100%; 
                height: 100%;
                border-right: none;
            }

            .chat-view {
                position: absolute;
                top: 0; left: 0;
                width: 100%; height: 100%;
                transform: translateX(100%); 
                z-index: 10;
                background: #fff;
            }

            .chat-view.mobile-active {
                transform: translateX(0);
            }

            .back-btn { display: block; }
        }
    </style>
</head>
<body>

<div class="chat-wrapper">
    <div class="chat-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h5><i class="fa-solid fa-comments"></i> Inquiries</h5>
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="userSearch" class="search-input" placeholder="Search residents..." onkeyup="handleSearch()">
                </div>
            </div>
            <div id="users-container" class="user-list">
                <div class="text-center p-4 text-muted small"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>

        <div class="chat-view" id="chatViewLayer">
            <div class="chat-header">
                <div class="d-flex align-items-center" style="gap: 10px;">
                    <i class="fas fa-arrow-left back-btn" onclick="closeChat()"></i>
                    <div id="headerAvatarContainer"></div>
                    <div>
                        <div class="chat-header-title" id="chatHeader">Select a resident</div>
                        <div id="headerStatusText" class="header-status-text"></div>
                    </div>
                </div>
            </div>

            <div id="admin-chat-box" class="messages-box">
                <div class="empty-state">
                    <i class="fas fa-paper-plane empty-icon"></i>
                    <p>Select a conversation to start chatting</p>
                </div>
            </div>

            <div class="input-area">
                <input type="text" id="adminMsgInput" class="chat-input" placeholder="Type message..." onkeypress="handleEnter(event)">
                <button class="send-btn" onclick="sendAdminMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const API_URL = 'api/message.php'; 
    
    let currentResidentId = null;
    let usersInterval = null;
    let chatInterval = null;
    let searchTimeout = null;
    let currentSearchTerm = '';
    
    // Store user data to update header status easily
    let usersCache = {}; 

    fetchUsers();
    // Poll for users every 5s
    usersInterval = setInterval(() => {
        if(currentSearchTerm === '') fetchUsers();
    }, 5000); 
    
    chatInterval = setInterval(fetchChat, 2000); 

    // Handle Search Input (Debounce)
    function handleSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentSearchTerm = document.getElementById('userSearch').value.trim();
            fetchUsers(currentSearchTerm);
        }, 300); // 300ms delay
    }

    // 1. Fetch Users
    async function fetchUsers(search = '') {
        const formData = new FormData();
        formData.append('action', 'fetch_users');
        if(search) formData.append('search', search);

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            if(!res.ok) throw new Error("API Error");
            const users = await res.json();
            
            const container = document.getElementById('users-container');
            if(users.length === 0) {
                container.innerHTML = `<div class="text-center p-4 text-muted small">${search ? 'No residents found.' : 'No active inquiries.'}</div>`;
                return;
            }

            let html = '';
            usersCache = {}; // Reset cache

            users.forEach(user => {
                usersCache[user.id] = user; // Store for easy access

                const isActive = (user.id == currentResidentId) ? 'active' : '';
                const initial = user.name ? user.name.charAt(0).toUpperCase() : '?';
                const count = parseInt(user.unread || 0);
                const isOnline = user.online === true;
                
                // Dot Color
                const dotClass = isOnline ? 'status-online' : 'status-offline';

                let avatarHtml = user.image 
                    ? `<img src="${user.image}" class="avatar" alt="Pic">`
                    : `<div class="avatar-initial">${initial}</div>`;

                let badgeHtml = '';
                if (count > 0 && user.id != currentResidentId) {
                    const display = count > 9 ? '9+' : count;
                    badgeHtml = `<div class="badge-count">${display}</div>`;
                }
                
                const safeName = user.name.replace(/'/g, "\\'");
                const safeImage = user.image ? user.image : ''; 

                html += `
                    <div class="user-item ${isActive}" onclick="handleUserClick(${user.id}, '${safeName}')" data-image="${safeImage}">
                        <div class="avatar-wrapper">
                            ${avatarHtml}
                            <div class="status-dot ${dotClass}"></div>
                        </div>
                        <div class="user-details">
                            <div class="user-name">${user.name}</div>
                            <div class="user-preview">${isOnline ? 'Active now' : (search ? 'Click to message' : 'Click to view chat')}</div>
                        </div>
                        ${badgeHtml}
                    </div>
                `;
            });
            container.innerHTML = html;

            // If a user is currently selected, update their status in header immediately
            if(currentResidentId && usersCache[currentResidentId]) {
                updateHeaderStatus(usersCache[currentResidentId].online);
            }

        } catch(e) { console.error(e); }
    }

    function handleUserClick(id, name) {
        const el = event.currentTarget; 
        const imageUrl = el.getAttribute('data-image');
        selectUser(id, name, imageUrl);
    }

    // 2. Select User
    function selectUser(id, name, imageUrl) {
        currentResidentId = id;
        
        let headerImgHtml = imageUrl && imageUrl.length > 50 
            ? `<img src="${imageUrl}" class="header-avatar">`
            : `<div class="header-avatar-initial">${name.charAt(0).toUpperCase()}</div>`;

        document.getElementById('headerAvatarContainer').innerHTML = headerImgHtml;
        document.getElementById('chatHeader').innerHTML = name;
        
        // Update Status Text in Header
        if(usersCache[id]) {
            updateHeaderStatus(usersCache[id].online);
        }
        
        // --- MOBILE: SLIDE IN CHAT ---
        document.getElementById('chatViewLayer').classList.add('mobile-active');
        
        // Clear chat area for loading state
        const chatBox = document.getElementById('admin-chat-box');
        chatBox.innerHTML = '<div class="empty-state"><i class="fas fa-circle-notch fa-spin text-primary opacity-25 fa-3x mb-3"></i></div>';
        
        fetchUsers(currentSearchTerm); // Refresh list to update active state
        fetchChat();  
    }

    function updateHeaderStatus(isOnline) {
        const statusEl = document.getElementById('headerStatusText');
        if(isOnline) {
            statusEl.innerHTML = '<i class="fas fa-circle" style="font-size:8px; vertical-align:middle;"></i> Active Now';
            statusEl.className = 'header-status-text text-online';
        } else {
            statusEl.innerHTML = 'Offline';
            statusEl.className = 'header-status-text text-offline';
        }
    }

    function closeChat() {
        document.getElementById('chatViewLayer').classList.remove('mobile-active');
        currentResidentId = null;
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
        } catch(e) { console.error(e); }
    }

    function renderMessages(messages) {
        const chatBox = document.getElementById('admin-chat-box');
        
        if (messages.length === 0) {
            chatBox.innerHTML = '<div class="empty-state"><i class="fas fa-comment-dots empty-icon"></i><p>No messages yet. Say hello!</p></div>';
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
        
        // Only update DOM if content changed to prevent scrolling glitches
        if (chatBox.innerHTML !== html) {
             // Check if user was at bottom
             const wasAtBottom = (chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 150);
             chatBox.innerHTML = html;
             // Auto scroll to bottom on first load or if already at bottom
             if (wasAtBottom || chatBox.innerHTML.includes('fa-circle-notch')) {
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

        // Optimistic UI update
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