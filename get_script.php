<?php
session_start();
include 'db_config.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

if (!isset($_GET['id'])) {
    die(json_encode(['error' => 'No script ID provided']));
}

$script_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT script_content FROM teleprompter_scripts WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $script_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['content' => $row['script_content']]);
} else {
    echo json_encode(['error' => 'Script not found']);
}

$stmt->close();
?> 