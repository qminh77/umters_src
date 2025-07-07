<?php
session_start();
require_once '../db_config.php';
require '../vendor/autoload.php';
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

// Cấu hình
define('UPLOAD_DIR', 'uploads/');
define('CONVERTED_DIR', 'converted/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024);
$allowed_input_formats = ['mp4', 'avi', 'mov', 'wmv'];
$allowed_output_formats = ['mp4', 'avi', 'mov'];

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Chưa đăng nhập']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Hàm làm sạch tên file
function sanitize_filename($filename) {
    return preg_replace('/[^a-zA-Z0-9-_\.]/', '', basename($filename));
}

// Hàm kiểm tra định dạng
function is_allowed_format($extension, $allowed_formats) {
    return in_array(strtolower($extension), $allowed_formats);
}

// Hàm ghi log lỗi
function log_error($conn, $user_id, $error_message) {
    $stmt = $conn->prepare("INSERT INTO error_logs (user_id, error_message, log_time) VALUES (?, ?, NOW())");
    $stmt->bind_param("is", $user_id, $error_message);
    $stmt->execute();
    $stmt->close();
}

// Tạo bảng error_logs và video_jobs
$sql_error_logs = "CREATE TABLE IF NOT EXISTS error_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    error_message TEXT NOT NULL,
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_error_logs);

$sql_video_jobs = "CREATE TABLE IF NOT EXISTS video_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    job_id VARCHAR(50) NOT NULL,
    input_path VARCHAR(255) NOT NULL,
    output_path VARCHAR(255) NOT NULL,
    output_format VARCHAR(10) NOT NULL,
    status VARCHAR(20) DEFAULT 'processing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_video_jobs);

// Xử lý upload và chuyển đổi
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['video_file']) || !isset($_POST['output_format'])) {
        throw new Exception("Yêu cầu không hợp lệ");
    }

    // Kiểm tra quyền
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception("Không có quyền truy cập");
    }
    $stmt->close();

    // Kiểm tra file
    if ($_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Lỗi khi tải file lên: " . $_FILES['video_file']['error']);
    }

    if ($_FILES['video_file']['size'] > MAX_FILE_SIZE) {
        throw new Exception("Kích thước file vượt quá giới hạn (100MB)");
    }

    $input_ext = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
    if (!is_allowed_format($input_ext, $allowed_input_formats)) {
        throw new Exception("Định dạng file không được hỗ trợ: $input_ext");
    }

    $output_format = $_POST['output_format'];
    if (!is_allowed_format($output_format, $allowed_output_formats)) {
        throw new Exception("Định dạng đầu ra không được hỗ trợ: $output_format");
    }

    // Tạo thư mục
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
        throw new Exception("Không thể tạo thư mục uploads");
    }
    if (!is_dir(CONVERTED_DIR) && !mkdir(CONVERTED_DIR, 0755, true)) {
        throw new Exception("Không thể tạo thư mục converted");
    }

    // Tạo đường dẫn
    $original_filename = sanitize_filename($_FILES['video_file']['name']);
    $unique_id = uniqid();
    $input_path = UPLOAD_DIR . $unique_id . '_' . $original_filename;
    $output_filename = pathinfo($original_filename, PATHINFO_FILENAME) . '.' . $output_format;
    $output_path = CONVERTED_DIR . $unique_id . '_' . $output_filename;

    // Lưu file
    if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $input_path)) {
        throw new Exception("Lỗi khi lưu file");
    }

    // Tạo job
    $job_id = uniqid('video_');
    $stmt = $conn->prepare("INSERT INTO video_jobs (user_id, job_id, input_path, output_path, output_format, status) VALUES (?, ?, ?, ?, ?, 'processing')");
    $stmt->bind_param("issss", $user_id, $job_id, $input_path, $output_path, $output_format);
    if (!$stmt->execute()) {
        unlink($input_path);
        throw new Exception("Lỗi khi tạo tác vụ chuyển đổi");
    }
    $stmt->close();

    // Chuyển đổi trực tiếp
    try {
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($input_path);
        $format = new X264();
        $format->setAudioCodec('aac');
        $video->save($format, $output_path);

        $stmt = $conn->prepare("INSERT INTO user_files (user_id, filename, file_path, upload_time) VALUES (?, ?, ?, NOW())");
        $filename = basename($output_path);
        $stmt->bind_param("iss", $user_id, $filename, $output_path);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE video_jobs SET status = 'completed' WHERE job_id = ?");
        $stmt->bind_param("s", $job_id);
        $stmt->execute();
        $stmt->close();

        if (file_exists($input_path)) {
            unlink($input_path);
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Chuyển đổi video thành công! Kiểm tra lịch sử để tải file.'
        ]);
    } catch (Exception $e) {
        log_error($conn, $user_id, "Conversion failed: " . $e->getMessage());
        $stmt = $conn->prepare("UPDATE video_jobs SET status = 'failed' WHERE job_id = ?");
        $stmt->bind_param("s", $job_id);
        $stmt->execute();
        $stmt->close();
        if (file_exists($input_path)) unlink($input_path);
        throw new Exception("Lỗi khi chuyển đổi video: " . $e->getMessage());
    }
} catch (Exception $e) {
    log_error($conn, $user_id, $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
mysqli_close($conn);
?>