<?php
session_start();
require_once 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Lấy thời gian cập nhật cuối cùng từ session hoặc thiết lập mặc định
$last_check_time = isset($_SESSION['last_document_check_time']) ? $_SESSION['last_document_check_time'] : date('Y-m-d H:i:s', strtotime('-1 minute'));

// Kiểm tra xem có cập nhật nào cho tài liệu của người dùng không
$sql = "SELECT COUNT(*) as update_count 
        FROM document_uploads d
        LEFT JOIN document_reviews r ON d.id = r.document_id
        WHERE d.user_id = ? AND (
            d.status != 'pending' AND d.status_updated_at > ? 
            OR r.review_time > ?
            OR EXISTS (SELECT 1 FROM document_results WHERE document_id = d.id AND upload_time > ?)
        )";

// Thêm cột status_updated_at nếu chưa có
$alter_sql = "SHOW COLUMNS FROM document_uploads LIKE 'status_updated_at'";
$result = $conn->query($alter_sql);
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE document_uploads ADD COLUMN status_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("isss", $user_id, $last_check_time, $last_check_time, $last_check_time);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// Cập nhật thời gian kiểm tra cuối cùng
$_SESSION['last_document_check_time'] = date('Y-m-d H:i:s');

// Trả về kết quả dưới dạng JSON
header('Content-Type: application/json');
echo json_encode([
    'hasUpdates' => ($row['update_count'] > 0),
    'updateCount' => $row['update_count'],
    'lastCheck' => $last_check_time,
    'currentTime' => date('Y-m-d H:i:s')
]);
?>