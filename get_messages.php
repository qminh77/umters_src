<?php
session_start();
include 'db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if (isset($_GET['recipient_id']) && !empty($_GET['recipient_id'])) {
    $recipient_id = (int)$_GET['recipient_id'];
    $sql = "SELECT m.*, u.username, u.full_name 
            FROM messages m 
            JOIN users u ON m.sender_id = u.id 
            WHERE (m.sender_id = $user_id AND m.recipient_id = $recipient_id) 
               OR (m.sender_id = $recipient_id AND m.recipient_id = $user_id) 
            ORDER BY m.sent_at ASC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo json_encode(['error' => 'Lỗi truy vấn tin nhắn cá nhân: ' . mysqli_error($conn)]);
        exit;
    }

    $messages = [];
    while ($msg = mysqli_fetch_assoc($result)) {
        $messages[] = [
            'sender_id' => $msg['sender_id'],
            'recipient_id' => $msg['recipient_id'],
            'content' => $msg['content'],
            'file_path' => $msg['file_path'],
            'sent_at' => $msg['sent_at'],
            'username' => $msg['username'],
            'full_name' => $msg['full_name']
        ];
    }
    echo json_encode($messages);
} elseif (isset($_GET['group_id']) && !empty($_GET['group_id'])) {
    $group_id = (int)$_GET['group_id'];
    $sql = "SELECT gm.*, u.username, u.full_name 
            FROM group_messages gm 
            JOIN users u ON gm.sender_id = u.id 
            WHERE gm.group_id = $group_id 
            ORDER BY gm.sent_at ASC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo json_encode(['error' => 'Lỗi truy vấn tin nhắn nhóm: ' . mysqli_error($conn)]);
        exit;
    }

    $messages = [];
    while ($msg = mysqli_fetch_assoc($result)) {
        $messages[] = [
            'sender_id' => $msg['sender_id'],
            'group_id' => $msg['group_id'],
            'content' => $msg['content'],
            'file_path' => $msg['file_path'],
            'sent_at' => $msg['sent_at'],
            'username' => $msg['username'],
            'full_name' => $msg['full_name']
        ];
    }
    echo json_encode($messages);
} else {
    echo json_encode([]);
}

mysqli_close($conn);
?>