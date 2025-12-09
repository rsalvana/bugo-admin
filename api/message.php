<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Consultation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
        
        .chat-dashboard {
            display: flex;
            background: #fff;
            height: 85vh;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* LEFT SIDEBAR */
        .users-list {
            width: 320px;
            background: #fff;
            border-right: 1px solid #e1e4e8;
            display: flex;
            flex-direction: column;
        }
        .list-header {
            padding: 20px;
            background: #343a40;
            color: #fff;
            font-weight: 600;
        }
        #users-container {
            flex-grow: 1;
            overflow-y: auto;
        }
        .user-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between; /* Keeps badge to the right */
        }
        .user-item:hover { background-color: #f8f9fa; }
        .user-item.active { background-color: #e3f2fd; border-left: 4px solid #0d6efd; }
        
        .user-info { display: flex; align-items: center; gap: 10px; }
        
        .avatar-circle {
            width: 40px; height: 40px; background: #ddd; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-weight: bold; color: #555;
            flex-shrink: 0;
        }

        /* --- RED BADGE CSS --- */
        .unread-badge {
            background-color: #dc3545; /* Red color */
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .unread-badge.wide {
            width: auto;
            padding: 0 6px;
            border-radius: 10px;
        }

        /* RIGHT CHAT AREA */
        .chat-area {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }
        .chat-area-header {
            padding: 15px 25px;
            border-bottom: 1px solid #e1e4e8;
            font-weight: bold;
            font-size: 1.1rem;
            color: #333;
            background: #f8f9fa;
        }
        #admin-chat-box {
            flex-grow: 1;
            padding: 25px;
            background-color: #fff;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .msg { padding: 10px 15px; border-radius: 15px; max-width: 70%; font-size: 14px; line-height: 1.4; position: relative; word-wrap: break-word; }
        .msg.admin { align-self: flex-end; background: #0d6efd; color: white; border-bottom-right-radius: 2px; }
        .msg.resident { align-self: flex-start; background: #e9ecef; color: #333; border-bottom-left-radius: 2px; }
        
        .admin-input {
            padding: 20px;
            border-top: 1px solid #e1e4e8;
            display: flex;
            gap: 10px;
            background: #fff;
        }
        .admin-input input { flex-grow: 1; padding: 12px; border: 1px solid #ced4da; border-radius: 6px; outline: none; }
        .admin-input button { padding: 10px 25px; background: #198754; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<div class="chat-dashboard">
    <div class="users-list">
        <div class="list-header">Incoming Inquiries</div>
        <div id="users-container">
            <div style="padding:20px; text-align:center; color:#999;">Loading...</div>
        </div>
    </div>

    <div class="chat-area">
        <div class="chat-area-header" id="chatHeader">Select a resident to start</div>
        
        <div id="admin-chat-box">
            <div style="display:flex; height:100%; align-items:center; justify-content:center; color:#ccc;">
                <i class="fas fa-comments fa-3x"></i>
            </div>
        </div>

        <div class="admin-input">
            <input type="text" id="adminMsgInput" placeholder="Type a reply..." onkeypress="handleEnter(event)">
            <button onclick="sendAdminMessage()">Send</button>
        </div>
    </div>
</div>

<script>
    const API_URL = 'ajax/message.php'; 
    let currentResidentId = null;
    let usersInterval = null;
    let chatInterval = null;

    // Start polling immediately
    fetchUsers();
    usersInterval = setInterval(fetchUsers, 3000); // Check for new messages every 3s
    chatInterval = setInterval(fetchChat, 2000);   // Refresh active chat every 2s

    // 1. Fetch User List (With Badge Logic)
    async function fetchUsers() {
        const formData = new FormData();
        formData.append('action', 'fetch_users');

        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const users = await res.json();
            
            const container = document.getElementById('users-container');
            if(users.length === 0) {
                container.innerHTML = '<div style="padding:20px; text-align:center; color:#999;">No inquiries found.</div>';
                return;
            }

            let html = '';
            users.forEach(user => {
                const isActive = (user.id == currentResidentId);
                const activeClass = isActive ? 'active' : '';
                const initial = user.name ? user.name.charAt(0).toUpperCase() : '?';
                
                // --- BADGE LOGIC START ---
                let badgeHtml = '';
                const count = parseInt(user.unread || 0);
                
                // Show badge if they have unread messages AND their chat isn't currently open
                if (count > 0 && !isActive) {
                    const display = count > 9 ? '9+' : count;
                    const wideClass = count > 9 ? 'wide' : '';
                    badgeHtml = `<div class="unread-badge ${wideClass}">${display}</div>`;
                }
                // --- BADGE LOGIC END ---

                html += `
                    <div class="user-item ${activeClass}" onclick="selectUser(${user.id}, '${escapeHtml(user.name)}')">
                        <div class="user-info">
                            <div class="avatar-circle">${initial}</div>
                            <div>
                                <div style="font-weight:600">${escapeHtml(user.name)}</div>
                                <div style="font-size:12px; color:#777;">ID: ${user.id}</div>
                            </div>
                        </div>
                        ${badgeHtml}
                    </div>
                `;
            });
            container.innerHTML = html;

        } catch(e) { console.error("Error fetching users:", e); }
    }

    // 2. Select User
    function selectUser(id, name) {
        currentResidentId = id;
        document.getElementById('chatHeader').innerText = "Chatting with: " + name;
        document.getElementById('admin-chat-box').innerHTML = '<div style="padding:20px; text-align:center; color:#999;">Loading...</div>';
        
        // Refresh list immediately (this will hide the badge for the clicked user)
        fetchUsers();
        
        // Load messages (this will mark them as read in DB)
        fetchChat();
    }

    // 3. Fetch Chat Messages
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
        } catch(e) { console.error("Error fetching chat:", e); }
    }

    function renderMessages(messages) {
        const chatBox = document.getElementById('admin-chat-box');
        
        if (messages.length === 0) {
            chatBox.innerHTML = '<div style="padding:20px; text-align:center; color:#999;">No messages yet.</div>';
            return;
        }

        let html = '';
        messages.forEach(msg => {
            const msgContent = escapeHtml(msg.message).replace(/\n/g, '<br>');
            html += `
                <div class="msg ${msg.sent_by}">
                    ${msgContent}
                    <div style="font-size:10px; opacity:0.6; text-align:right; margin-top:4px;">${msg.time}</div>
                </div>
            `;
        });
        
        // Only update HTML if changed (prevents flickering/scrolling issues)
        if (chatBox.innerHTML !== html) {
             // Check if user is near bottom before updating, to decide whether to auto-scroll
             const wasAtBottom = (chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 100);
             chatBox.innerHTML = html;
             
             // Scroll to bottom if it was already at bottom, or if it's the first load
             if (wasAtBottom || chatBox.innerHTML.includes('Loading...')) {
                 chatBox.scrollTop = chatBox.scrollHeight;
             }
        }
    }

    // 4. Send Message
    async function sendAdminMessage() {
        if(!currentResidentId) { alert("Select a user first"); return; }
        
        const input = document.getElementById('adminMsgInput');
        const msg = input.value.trim();
        if(!msg) return;

        // UI Optimistic Update
        const chatBox = document.getElementById('admin-chat-box');
        chatBox.insertAdjacentHTML('beforeend', `
            <div class="msg admin" style="opacity:0.6">
                ${escapeHtml(msg)}
                <div style="font-size:10px; opacity:0.6; text-align:right;">Sending...</div>
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
            alert("Failed to send message");
        }
    }

    function handleEnter(e) {
        if (e.key === 'Enter') sendAdminMessage();
    }

    function escapeHtml(text) {
        if (!text) return "";
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
</script>

</body>
</html>