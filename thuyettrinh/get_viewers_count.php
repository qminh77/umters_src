<?php
require_once 'db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['session_id']) || !is_numeric($_GET['session_id'])) {
    echo json_encode(['error' => 'Invalid session ID']);
    exit();
}

$session_id = (int)$_GET['session_id'];

// Get count of active viewers (active in the last 2 minutes)
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM presentation_viewers 
                        WHERE session_id = ? 
                        AND last_active > DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode(['count' => (int)$row['count']]);

$stmt->close();
?>
