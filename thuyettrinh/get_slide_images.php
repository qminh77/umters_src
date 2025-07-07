<?php
require_once '../db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['slide_id']) || !is_numeric($_GET['slide_id'])) {
    echo json_encode(['error' => 'Invalid slide ID']);
    exit();
}

$slide_id = (int)$_GET['slide_id'];

// Get images for this slide
$stmt = $conn->prepare("SELECT * FROM slide_images WHERE slide_id = ?");
$stmt->bind_param("i", $slide_id);
$stmt->execute();
$result = $stmt->get_result();
$images = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode($images);

$stmt->close();
?>
