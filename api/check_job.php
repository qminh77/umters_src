<?php
require_once '../db_config.php';

if (!isset($_GET['job_id'])) {
    echo json_encode(['error' => 'Missing job_id']);
    exit;
}

$job_id = mysqli_real_escape_string($conn, $_GET['job_id']);
$stmt = $conn->prepare("SELECT status, output_path FROM video_jobs WHERE job_id = ?");
$stmt->bind_param("s", $job_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

echo json_encode($result ?: ['error' => 'Job not found']);
$stmt->close();
mysqli_close($conn);
?>