<?php
require_once '../db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

if (!isset($_POST['session_id']) || !is_numeric($_POST['session_id']) || !isset($_POST['slide']) || !is_numeric($_POST['slide'])) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

$session_id = (int)$_POST['session_id'];
$slide_number = (int)$_POST['slide'];

// Update current slide for the session
$stmt = $conn->prepare("UPDATE presentation_sessions SET current_slide = ? WHERE id = ? AND is_active = 1");
$stmt->bind_param("ii", $slide_number, $session_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to update slide or session not active']);
}

$stmt->close();
?>
