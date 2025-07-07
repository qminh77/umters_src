<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$uploadDir = "uploads/user/$userId/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (isset($_POST['image'])) {
    $dataURL = $_POST['image'];
    $data = str_replace('data:image/png;base64,', '', $dataURL);
    $data = str_replace(' ', '+', $data);
    $imageData = base64_decode($data);

    $fileName = time() . '.png';
    $filePath = $uploadDir . $fileName;

    if (file_put_contents($filePath, $imageData)) {
        // Lưu thông tin ảnh vào database
        global $pdo;
        $stmt = $pdo->prepare("INSERT INTO photos (user_id, file_path, created_at) VALUES (?, ?, NOW())");
        if ($stmt->execute([$userId, $filePath])) {
            echo json_encode(['success' => true, 'file_path' => $filePath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi lưu thông tin ảnh vào database']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi khi lưu file ảnh']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Không có dữ liệu ảnh']);
}
?>