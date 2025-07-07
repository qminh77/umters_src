<?php
require_once '../db_config.php';

if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
    exit();
}

$session_id = (int)$_GET['session_id'];
$viewer_ip = $_SERVER['REMOTE_ADDR'];

// Update viewer's last active time
$stmt = $conn->prepare("UPDATE presentation_viewers SET last_active = NOW() WHERE session_id = ? AND viewer_ip = ?");
$stmt->bind_param("is", $session_id, $viewer_ip);
$stmt->execute();
$stmt->close();
?>
