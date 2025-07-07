<?php
session_start();
include 'db_config.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

if (!isset($_POST['id'])) {
    die(json_encode(['success' => false, 'error' => 'No script ID provided']));
}

$script_id = intval($_POST['id']);
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("DELETE FROM teleprompter_scripts WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $script_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
?> 