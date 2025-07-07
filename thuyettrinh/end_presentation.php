<?php
require_once '../db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
    echo json_encode(['error' => 'Invalid session ID']);
    exit();
}

$session_id = (int)$_GET['session_id'];

// End the presentation session
$stmt = $conn->prepare("UPDATE presentation_sessions SET is_active = 0, ended_at = NOW() WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to end presentation or session not found']);
}

$stmt->close();
?>
