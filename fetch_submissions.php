<?php
session_start();

// Bật debug để hiển thị lỗi (tắt trên production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', '/home/u459537937/domains/umters.club/public_html/error_log.txt');

// Kiểm tra tệp db_config.php
if (!file_exists('db_config.php') || !is_readable('db_config.php')) {
    error_log("File db_config.php not found or not readable");
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.']);
    exit;
}
include 'db_config.php';

// Kiểm tra kết nối database
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.']);
    exit;
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Bạn cần đăng nhập để truy cập dữ liệu.']);
    exit;
}

header('Content-Type: application/json');

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$status = isset($_GET['status']) && in_array($_GET['status'], ['unanswered', 'pending', 'answered', 'rejected']) ? $_GET['status'] : null;
$user_id = (int)$_SESSION['user_id'];
$submissions = [];

if ($room_id) {
    try {
        // Kiểm tra phòng thuộc user
        $stmt = $conn->prepare("SELECT id FROM qna_rooms WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn phòng: " . $conn->error);
        }
        $stmt->bind_param("ii", $room_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn phòng: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows == 0) {
            echo json_encode(['error' => 'Phòng không tồn tại hoặc bạn không có quyền truy cập.']);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // Truy vấn danh sách câu hỏi
        $sql = "SELECT id, full_name, email, phone, content, rating, status, submitted_at FROM qna_submissions WHERE room_id = ?" . ($status ? " AND status = ?" : "") . " ORDER BY submitted_at DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn danh sách: " . $conn->error);
        }
        if ($status) {
            $stmt->bind_param("is", $room_id, $status);
        } else {
            $stmt->bind_param("i", $room_id);
        }
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn danh sách: " . $stmt->error);
        }
        $result = $stmt->get_result();
        while ($submission = $result->fetch_assoc()) {
            $submissions[] = [
                'id' => (int)$submission['id'],
                'full_name' => htmlspecialchars($submission['full_name'] ?: ''),
                'email' => htmlspecialchars($submission['email'] ?: ''),
                'phone' => htmlspecialchars($submission['phone'] ?: ''),
                'content' => htmlspecialchars($submission['content']),
                'rating' => $submission['rating'] ? (int)$submission['rating'] : null,
                'status' => $submission['status'],
                'submitted_at' => date('d/m/Y H:i', strtotime($submission['submitted_at']))
            ];
        }
        $stmt->close();
        echo json_encode($submissions);
    } catch (Exception $e) {
        error_log("Fetch submissions error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        echo json_encode(['error' => 'Lỗi hệ thống: Không thể lấy danh sách câu hỏi.']);
    }
} else {
    echo json_encode(['error' => 'Thiếu room_id hợp lệ.']);
}

if (isset($conn) && $conn) {
    $conn->close();
}
?>