<?php
session_start();
include 'db_config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check session
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Get current user info using prepared statement
$stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
$user = $result_user->fetch_assoc() ?: ['username' => 'Unknown', 'full_name' => ''];

// Get list of users using prepared statement
$stmt = $conn->prepare("SELECT id, username, full_name FROM users WHERE id != ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_users = $stmt->get_result();
$users = $result_users->fetch_all(MYSQLI_ASSOC);

// Create tables if not exists
$tables = [
    "CREATE TABLE IF NOT EXISTS groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT NOT NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",
    "CREATE TABLE IF NOT EXISTS group_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE (group_id, user_id)
    )",
    "CREATE TABLE IF NOT EXISTS group_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        sender_id INT NOT NULL,
        content TEXT,
        file_path VARCHAR(255),
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        content TEXT,
        file_path VARCHAR(255),
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
    )"
];

foreach ($tables as $sql) {
    $conn->query($sql);
}

// Get user's groups using prepared statement
$stmt = $conn->prepare("SELECT g.id, g.name 
                       FROM groups g 
                       JOIN group_members gm ON g.id = gm.group_id 
                       WHERE gm.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_groups = $stmt->get_result();
$groups = $result_groups->fetch_all(MYSQLI_ASSOC);

// Handle private message sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message']) && isset($_POST['recipient_id'])) {
    $recipient_id = (int)$_POST['recipient_id'];
    $content = trim($_POST['content']);
    $file_path = null;

    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $file = $_FILES['file'];
        $fileName = basename($file['name']);
        $fileSize = $file['size'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        if ($fileSize > $maxFileSize) {
            $_SESSION['error'] = "File quá lớn! Tối đa 5MB.";
            header("Location: chat.php?recipient_id=$recipient_id");
            exit;
        }

        if (!in_array($fileExt, $allowedTypes)) {
            $_SESSION['error'] = "Loại file không được hỗ trợ!";
            header("Location: chat.php?recipient_id=$recipient_id");
            exit;
        }

        $newFileName = 'chat_' . uniqid() . '.' . $fileExt;
        $uploadDir = 'uploads/chat/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $file_path = $uploadDir . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            $_SESSION['error'] = "Lỗi khi tải lên file!";
            header("Location: chat.php?recipient_id=$recipient_id");
            exit;
        }
    }

    if (!empty($content) || $file_path) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, recipient_id, content, file_path) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $user_id, $recipient_id, $content, $file_path);
        if (!$stmt->execute()) {
            $_SESSION['error'] = "Lỗi khi gửi tin nhắn!";
        }
    }
    header("Location: chat.php?recipient_id=$recipient_id");
    exit;
}

// Handle group message sending
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_group_message']) && isset($_POST['group_id'])) {
    $group_id = (int)$_POST['group_id'];
    $content = trim($_POST['content']);
    $file_path = null;

    // Check if user is member of the group
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $_SESSION['error'] = "Bạn không phải thành viên của nhóm này!";
        header("Location: chat.php");
        exit;
    }

    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $file = $_FILES['file'];
        $fileName = basename($file['name']);
        $fileSize = $file['size'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        if ($fileSize > $maxFileSize) {
            $_SESSION['error'] = "File quá lớn! Tối đa 5MB.";
            header("Location: chat.php?group_id=$group_id");
            exit;
        }

        if (!in_array($fileExt, $allowedTypes)) {
            $_SESSION['error'] = "Loại file không được hỗ trợ!";
            header("Location: chat.php?group_id=$group_id");
            exit;
        }

        $newFileName = 'chat_' . uniqid() . '.' . $fileExt;
        $uploadDir = 'uploads/chat/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $file_path = $uploadDir . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            $_SESSION['error'] = "Lỗi khi tải lên file!";
            header("Location: chat.php?group_id=$group_id");
            exit;
        }
    }

    if (!empty($content) || $file_path) {
        $stmt = $conn->prepare("INSERT INTO group_messages (group_id, sender_id, content, file_path) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $group_id, $user_id, $content, $file_path);
        if (!$stmt->execute()) {
            $_SESSION['error'] = "Lỗi khi gửi tin nhắn nhóm!";
        }
    }
    header("Location: chat.php?group_id=$group_id");
    exit;
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    
    if (empty($group_name)) {
        $_SESSION['error'] = "Tên nhóm không được để trống!";
        header("Location: chat.php");
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO groups (name, created_by) VALUES (?, ?)");
    $stmt->bind_param("si", $group_name, $user_id);
    
    if (!$stmt->execute()) {
        $_SESSION['error'] = "Lỗi khi tạo nhóm!";
        header("Location: chat.php");
        exit;
    }

    $group_id = $conn->insert_id;

    // Add creator to group
    $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();

    // Add selected users to group
    if (isset($_POST['group_members']) && is_array($_POST['group_members'])) {
        $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        foreach ($_POST['group_members'] as $member_id) {
            $member_id = (int)$member_id;
            if ($member_id != $user_id) {
                $stmt->bind_param("ii", $group_id, $member_id);
                $stmt->execute();
            }
        }
    }

    header("Location: chat.php?group_id=$group_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Tin Nhắn</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .chat-container {
            display: flex;
            height: calc(100vh - 100px);
            max-width: 1200px;
            margin: 20px auto;
            gap: 20px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .user-list {
            width: 300px;
            background: #f8f9fa;
            border-right: 1px solid #dee2e6;
            overflow-y: auto;
            padding: 15px;
        }

        .user-item, .group-item {
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-item:hover, .user-item.active, .group-item:hover, .group-item.active {
            background: #e9ecef;
            color: #0d6efd;
        }

        .user-item img, .group-item img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .chat-box {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        .chat-header {
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 70%;
            padding: 12px;
            border-radius: 12px;
            position: relative;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.sent {
            background: #0d6efd;
            color: #fff;
            align-self: flex-end;
            border-bottom-right-radius: 0;
        }

        .message.received {
            background: #e9ecef;
            color: #212529;
            align-self: flex-start;
            border-bottom-left-radius: 0;
        }

        .message img {
            max-width: 300px;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 8px;
        }

        .message-file {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 8px;
            background: rgba(0,0,0,0.1);
            border-radius: 8px;
        }

        .message-sender {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .message-time {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-top: 4px;
        }

        .chat-input {
            padding: 15px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .chat-input textarea {
            flex: 1;
            padding: 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            resize: none;
            height: 50px;
            font-family: inherit;
        }

        .chat-input textarea:focus {
            outline: none;
            border-color: #0d6efd;
        }

        .chat-input button {
            padding: 12px 20px;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .chat-input button:hover {
            background: #0b5ed7;
        }

        .file-input-wrapper {
            position: relative;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-wrapper i {
            font-size: 1.5rem;
            color: #6c757d;
            cursor: pointer;
            padding: 12px;
        }

        .file-input-wrapper i:hover {
            color: #0d6efd;
        }

        .error-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: #dc3545;
            color: #fff;
            border-radius: 8px;
            animation: slideIn 0.3s ease;
            z-index: 1000;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .loading {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .chat-container {
                flex-direction: column;
                height: calc(100vh - 60px);
                margin: 0;
                border-radius: 0;
            }

            .user-list {
                width: 100%;
                height: 200px;
                border-right: none;
                border-bottom: 1px solid #dee2e6;
            }

            .chat-box {
                height: calc(100% - 200px);
            }

            .message {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h2>Chat Tin Nhắn</h2>
        <?php include 'taskbar.php'; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <div class="chat-container">
            <!-- User and Group List -->
            <div class="user-list">
                <h3>Danh sách người dùng</h3>
                <?php foreach ($users as $u): ?>
                    <div class="user-item <?php echo (isset($_GET['recipient_id']) && $_GET['recipient_id'] == $u['id']) ? 'active' : ''; ?>" 
                         onclick="location.href='chat.php?recipient_id=<?php echo $u['id']; ?>'">
                        <img src="assets/avatars/<?php echo htmlspecialchars($u['id']); ?>.jpg" alt="Avatar" onerror="this.src='assets/default-avatar.jpg'">
                        <span><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></span>
                    </div>
                <?php endforeach; ?>

                <h3>Nhóm chat</h3>
                <?php foreach ($groups as $g): ?>
                    <div class="group-item <?php echo (isset($_GET['group_id']) && $_GET['group_id'] == $g['id']) ? 'active' : ''; ?>" 
                         onclick="location.href='chat.php?group_id=<?php echo $g['id']; ?>'">
                        <img src="assets/groups/<?php echo htmlspecialchars($g['id']); ?>.jpg" alt="Group" onerror="this.src='assets/default-group.jpg'">
                        <span><?php echo htmlspecialchars($g['name']); ?></span>
                    </div>
                <?php endforeach; ?>

                <button onclick="showCreateGroupModal()" style="width: 100%; margin-top: 15px;">
                    <i class="fas fa-plus"></i> Tạo nhóm mới
                </button>
            </div>

            <!-- Chat Area -->
            <div class="chat-box">
                <?php if (isset($_GET['recipient_id']) && !empty($_GET['recipient_id'])): 
                    $recipient_id = (int)$_GET['recipient_id'];
                    $stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
                    $stmt->bind_param("i", $recipient_id);
                    $stmt->execute();
                    $recipient = $stmt->get_result()->fetch_assoc();
                ?>
                    <div class="chat-header">
                        <img src="assets/avatars/<?php echo $recipient_id; ?>.jpg" alt="Avatar" onerror="this.src='assets/default-avatar.jpg'">
                        <span>Chat với <?php echo htmlspecialchars($recipient['full_name'] ?: $recipient['username']); ?></span>
                    </div>
                    <div class="chat-messages" id="chat-messages">
                        <?php
                        $stmt = $conn->prepare("SELECT m.*, u.username, u.full_name 
                                              FROM messages m 
                                              JOIN users u ON m.sender_id = u.id 
                                              WHERE (m.sender_id = ? AND m.recipient_id = ?) 
                                                 OR (m.sender_id = ? AND m.recipient_id = ?) 
                                              ORDER BY m.sent_at ASC");
                        $stmt->bind_param("iiii", $user_id, $recipient_id, $recipient_id, $user_id);
                        $stmt->execute();
                        $result_messages = $stmt->get_result();
                        
                        while ($msg = $result_messages->fetch_assoc()): ?>
                            <div class="message <?php echo $msg['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                <div class="message-sender"><?php echo htmlspecialchars($msg['full_name'] ?: $msg['username']); ?></div>
                                <?php if ($msg['file_path']): ?>
                                    <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $msg['file_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" alt="Attachment">
                                    <?php else: ?>
                                        <div class="message-file">
                                            <i class="fas fa-file"></i>
                                            <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" download>
                                                <?php echo htmlspecialchars(basename($msg['file_path'])); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($msg['content']): ?>
                                    <p><?php echo nl2br(htmlspecialchars($msg['content'])); ?></p>
                                <?php endif; ?>
                                <div class="message-time"><?php echo htmlspecialchars($msg['sent_at']); ?></div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <form class="chat-input" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="recipient_id" value="<?php echo $recipient_id; ?>">
                        <textarea name="content" placeholder="Nhập tin nhắn..." required></textarea>
                        <div class="file-input-wrapper">
                            <i class="fas fa-paperclip"></i>
                            <input type="file" name="file" accept="image/*,.pdf,.doc,.docx,.txt">
                        </div>
                        <button type="submit" name="send_message">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>

                <?php elseif (isset($_GET['group_id']) && !empty($_GET['group_id'])): 
                    $group_id = (int)$_GET['group_id'];
                    $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ?");
                    $stmt->bind_param("i", $group_id);
                    $stmt->execute();
                    $group = $stmt->get_result()->fetch_assoc();
                ?>
                    <div class="chat-header">
                        <img src="assets/groups/<?php echo $group_id; ?>.jpg" alt="Group" onerror="this.src='assets/default-group.jpg'">
                        <span>Nhóm: <?php echo htmlspecialchars($group['name']); ?></span>
                    </div>
                    <div class="chat-messages" id="chat-messages">
                        <?php
                        $stmt = $conn->prepare("SELECT gm.*, u.username, u.full_name 
                                              FROM group_messages gm 
                                              JOIN users u ON gm.sender_id = u.id 
                                              WHERE gm.group_id = ? 
                                              ORDER BY gm.sent_at ASC");
                        $stmt->bind_param("i", $group_id);
                        $stmt->execute();
                        $result_messages = $stmt->get_result();
                        
                        while ($msg = $result_messages->fetch_assoc()): ?>
                            <div class="message <?php echo $msg['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                                <div class="message-sender"><?php echo htmlspecialchars($msg['full_name'] ?: $msg['username']); ?></div>
                                <?php if ($msg['file_path']): ?>
                                    <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $msg['file_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" alt="Attachment">
                                    <?php else: ?>
                                        <div class="message-file">
                                            <i class="fas fa-file"></i>
                                            <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" download>
                                                <?php echo htmlspecialchars(basename($msg['file_path'])); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($msg['content']): ?>
                                    <p><?php echo nl2br(htmlspecialchars($msg['content'])); ?></p>
                                <?php endif; ?>
                                <div class="message-time"><?php echo htmlspecialchars($msg['sent_at']); ?></div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <form class="chat-input" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                        <textarea name="content" placeholder="Nhập tin nhắn..." required></textarea>
                        <div class="file-input-wrapper">
                            <i class="fas fa-paperclip"></i>
                            <input type="file" name="file" accept="image/*,.pdf,.doc,.docx,.txt">
                        </div>
                        <button type="submit" name="send_group_message">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>

                <?php else: ?>
                    <div class="chat-header">Chọn một người dùng hoặc nhóm để bắt đầu chat</div>
                    <div class="chat-messages"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Group Modal -->
    <div id="createGroupModal" class="modal" style="display: none;">
        <div class="modal-content">
            <h3>Tạo nhóm mới</h3>
            <form method="POST">
                <input type="text" name="group_name" placeholder="Tên nhóm" required>
                <div class="group-members">
                    <h4>Chọn thành viên</h4>
                    <?php foreach ($users as $u): ?>
                        <label>
                            <input type="checkbox" name="group_members[]" value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="create_group">Tạo nhóm</button>
            </form>
        </div>
    </div>

    <script>
        // Auto-scroll to bottom
        function scrollToBottom() {
            const chatMessages = document.getElementById('chat-messages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }

        // Show create group modal
        function showCreateGroupModal() {
            document.getElementById('createGroupModal').style.display = 'block';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('createGroupModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Auto-resize textarea
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });

        // Show loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const loading = document.createElement('div');
                loading.className = 'loading';
                loading.innerHTML = '<div class="loading-spinner"></div>';
                document.body.appendChild(loading);
            });
        });

        // Auto-scroll on page load
        window.onload = scrollToBottom;

        // Auto-update messages
        setInterval(() => {
            const chatMessages = document.getElementById('chat-messages');
            if (chatMessages) {
                const isNearBottom = chatMessages.scrollHeight - chatMessages.scrollTop <= chatMessages.clientHeight + 100;
                
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newMessages = doc.getElementById('chat-messages');
                        if (newMessages) {
                            chatMessages.innerHTML = newMessages.innerHTML;
                            if (isNearBottom) {
                                scrollToBottom();
                            }
                        }
                    });
            }
        }, 2000);
    </script>
</body>
</html>
<?php $conn->close(); ?>