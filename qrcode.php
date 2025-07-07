<?php
session_start();

// Kiểm tra tệp db_config.php
if (!file_exists('db_config.php') || !is_readable('db_config.php')) {
    error_log("File db_config.php not found or not readable");
    die("Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.");
}
include 'db_config.php';

// Kiểm tra kết nối database
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.");
}

// Kiểm tra vendor/autoload.php
if (!file_exists('vendor/autoload.php')) {
    error_log("File vendor/autoload.php not found");
    die("Lỗi hệ thống: Thiếu thư viện Composer. Vui lòng cài đặt Composer và chạy 'composer require endroid/qr-code'.");
}
require 'vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        error_log("Lỗi khi tạo CSRF token: " . $e->getMessage());
        die("Lỗi hệ thống: Không thể tạo mã bảo mật.");
    }
}

// Khởi tạo biến thông báo
$qr_message = '';
$qr_image = '';
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, phone, email, full_name, class, address, is_main_admin, is_super_admin FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể truy vấn thông tin người dùng.";
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể truy vấn thông tin người dùng.";
    } else {
        $result_user = $stmt->get_result();
        if ($result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
        } else {
            $error_message = "Lỗi: Người dùng không tồn tại.";
            $user = [
                'username' => 'Unknown',
                'phone' => '',
                'email' => '',
                'full_name' => '',
                'class' => '',
                'address' => '',
                'is_main_admin' => 0,
                'is_super_admin' => 0
            ];
        }
    }
    $stmt->close();
}

// Tự động lấy tên miền
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

// Mặc định thời gian xóa QR Code là 30 ngày
$default_expiry = 2592000; // 30 ngày
$expiry_config = isset($_SESSION['qr_expiry']) ? $_SESSION['qr_expiry'] : $default_expiry;

// Cập nhật thời gian xóa (chỉ Super Admin)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_expiry']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']) && $user['is_super_admin']) {
    try {
        $new_expiry = (int)$_POST['new_expiry'] * 86400; // Chuyển từ ngày sang giây
        if ($new_expiry <= 0) {
            throw new Exception("Thời gian xóa phải lớn hơn 0!");
        }
        $_SESSION['qr_expiry'] = $new_expiry;
        $qr_message = "Cập nhật thời gian xóa QR Code thành công! (Mỗi QR sẽ tự xóa sau " . ((int)$_POST['new_expiry']) . " ngày)";
    } catch (Exception $e) {
        error_log("Update expiry error: " . $e->getMessage());
        $qr_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Khởi tạo biến để lưu dữ liệu QR chỉnh sửa
$edit_qr_data = [];
$edit_qr_type = '';
if (isset($_POST['edit_qr']) && isset($_POST['qr_data']) && isset($_POST['qr_path']) && isset($_POST['qr_type'])) {
    $edit_qr_data = [
        'qr_data' => $_POST['qr_data'],
        'qr_path' => $_POST['qr_path'],
        'qr_type' => $_POST['qr_type']
    ];
    $edit_qr_type = $_POST['qr_type'];
    $qr_image = $_POST['qr_path'];

    // Tách dữ liệu QR để điền vào form
    switch ($edit_qr_type) {
        case 'text':
            $edit_qr_data['qr_text'] = $edit_qr_data['qr_data'];
            break;
        case 'link':
            $edit_qr_data['qr_link'] = $edit_qr_data['qr_data'];
            break;
        case 'email':
            $parsed = parse_url($edit_qr_data['qr_data']);
            $edit_qr_data['qr_email'] = str_replace('mailto:', '', $parsed['path']);
            parse_str($parsed['query'], $query);
            $edit_qr_data['qr_email_subject'] = isset($query['subject']) ? urldecode($query['subject']) : '';
            $edit_qr_data['qr_email_body'] = isset($query['body']) ? urldecode($query['body']) : '';
            break;
        case 'event':
            preg_match("/SUMMARY:(.*?)\n/", $edit_qr_data['qr_data'], $title_match);
            preg_match("/LOCATION:(.*?)\n/", $edit_qr_data['qr_data'], $location_match);
            preg_match("/DTSTART:(.*?)\n/", $edit_qr_data['qr_data'], $start_match);
            preg_match("/DTEND:(.*?)\n/", $edit_qr_data['qr_data'], $end_match);
            $edit_qr_data['qr_event_title'] = isset($title_match[1]) ? $title_match[1] : '';
            $edit_qr_data['qr_event_location'] = isset($location_match[1]) ? $location_match[1] : '';
            $edit_qr_data['qr_event_start'] = isset($start_match[1]) ? date('Y-m-d\TH:i', strtotime($start_match[1])) : '';
            $edit_qr_data['qr_event_end'] = isset($end_match[1]) ? date('Y-m-d\TH:i', strtotime($end_match[1])) : '';
            break;
        case 'file':
            // File không cần điền lại, chỉ hiển thị đường dẫn
            break;
    }
}

// Xử lý tạo QR Code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_qr']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $qr_type = filter_input(INPUT_POST, 'qr_type', FILTER_SANITIZE_STRING);
        $valid_types = ['text', 'link', 'email', 'file', 'event'];
        if (!in_array($qr_type, $valid_types)) {
            throw new Exception("Loại QR Code không hợp lệ!");
        }

        $qr_data = '';
        switch ($qr_type) {
            case 'text':
                $qr_data = filter_input(INPUT_POST, 'qr_text', FILTER_SANITIZE_STRING);
                if (empty($qr_data)) {
                    throw new Exception("Vui lòng nhập nội dung văn bản!");
                }
                if (strlen($qr_data) > 255) {
                    throw new Exception("Nội dung văn bản quá dài! Tối đa 255 ký tự.");
                }
                break;
            case 'link':
                $qr_data = filter_input(INPUT_POST, 'qr_link', FILTER_SANITIZE_URL);
                if (!filter_var($qr_data, FILTER_VALIDATE_URL) || strlen($qr_data) > 255) {
                    throw new Exception("URL không hợp lệ hoặc quá dài! Tối đa 255 ký tự.");
                }
                break;
            case 'email':
                $email = filter_input(INPUT_POST, 'qr_email', FILTER_SANITIZE_EMAIL);
                $subject = filter_input(INPUT_POST, 'qr_email_subject', FILTER_SANITIZE_STRING);
                $body = filter_input(INPUT_POST, 'qr_email_body', FILTER_SANITIZE_STRING);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($subject) > 255 || strlen($body) > 255) {
                    throw new Exception("Email hoặc nội dung không hợp lệ! Tối đa 255 ký tự.");
                }
                $qr_data = "mailto:$email?subject=" . urlencode($subject) . "&body=" . urlencode($body);
                break;
            case 'file':
                if (!isset($_FILES['qr_file']) || $_FILES['qr_file']['error'] != UPLOAD_ERR_OK) {
                    throw new Exception("Vui lòng chọn file để tải lên!");
                }
                $file = $_FILES['qr_file'];
                $max_size = 5 * 1024 * 1024; // 5MB
                $allowed_types = ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo === false) {
                    throw new Exception("Lỗi hệ thống: Không thể kiểm tra định dạng file. Vui lòng liên hệ quản trị viên.");
                }
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime_type, $allowed_types)) {
                    throw new Exception("Định dạng file không được hỗ trợ! Hỗ trợ: txt, pdf, doc, docx, jpg, png.");
                }
                if ($file['size'] > $max_size) {
                    throw new Exception("File quá lớn! Tối đa 5MB.");
                }

                $upload_dir = 'Uploads/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true) || !chmod($upload_dir, 0755)) {
                        throw new Exception("Không thể tạo thư mục Uploads! Vui lòng kiểm tra quyền thư mục.");
                    }
                }
                $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_file_name = time() . '_' . uniqid() . '.' . $file_ext;
                $file_path = $upload_dir . $new_file_name;
                if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                    throw new Exception("Lỗi khi tải file lên server!");
                }
                $qr_data = $base_url . '/' . $file_path;

                // Lưu thông tin file vào bảng files
                $stmt = $conn->prepare("INSERT INTO files (filename, file_path) VALUES (?, ?)");
                if (!$stmt) {
                    throw new Exception("Lỗi chuẩn bị truy vấn file: " . $conn->error);
                }
                $stmt->bind_param("ss", $file['name'], $file_path);
                if (!$stmt->execute()) {
                    throw new Exception("Lỗi lưu thông tin file: " . $stmt->error);
                }
                $stmt->close();
                break;
            case 'event':
                $event_title = filter_input(INPUT_POST, 'qr_event_title', FILTER_SANITIZE_STRING);
                $event_location = filter_input(INPUT_POST, 'qr_event_location', FILTER_SANITIZE_STRING);
                $event_start = filter_input(INPUT_POST, 'qr_event_start', FILTER_SANITIZE_STRING);
                $event_end = filter_input(INPUT_POST, 'qr_event_end', FILTER_SANITIZE_STRING);
                if (empty($event_title) || empty($event_start) || empty($event_end) || strlen($event_title) > 255 || strlen($event_location) > 255) {
                    throw new Exception("Thông tin sự kiện không hợp lệ hoặc quá dài! Tối đa 255 ký tự.");
                }
                $event_start_formatted = date('Ymd\THis', strtotime($event_start));
                $event_end_formatted = date('Ymd\THis', strtotime($event_end));
                $qr_data = "BEGIN:VEVENT\nSUMMARY:" . $event_title . "\nLOCATION:" . $event_location . "\nDTSTART:" . $event_start_formatted . "\nDTEND:" . $event_end_formatted . "\nEND:VEVENT";
                break;
        }

        if ($qr_data) {
            $qrCode = QrCode::create($qr_data)->setSize(300)->setMargin(10);
            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            // Lưu QR Code vào thư mục tạm
            $qr_dir = 'qrcodes/';
            if (!is_dir($qr_dir)) {
                if (!mkdir($qr_dir, 0755, true) || !chmod($qr_dir, 0755)) {
                    throw new Exception("Không thể tạo thư mục qrcodes! Vui lòng kiểm tra quyền thư mục.");
                }
            }
            $qr_path = $qr_dir . 'qr-' . time() . '.png';
            $result->saveToFile($qr_path);
            $qr_image = $qr_path;

            // Lưu lịch sử QR Code (không bao gồm cột id để MySQL tự động tăng)
            $expiry_time = date('Y-m-d H:i:s', time() + $expiry_config);
            $stmt = $conn->prepare("INSERT INTO qr_codes (user_id, qr_type, qr_data, qr_path, expiry_time) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Lỗi chuẩn bị truy vấn QR: " . $conn->error);
            }
            $stmt->bind_param("issss", $user_id, $qr_type, $qr_data, $qr_path, $expiry_time);
            if (!$stmt->execute()) {
                throw new Exception("Lỗi lưu lịch sử QR: " . $stmt->error);
            }
            $stmt->close();
            $qr_message = "Tạo QR Code thành công!";

            // Lưu thông tin để chỉnh sửa
            $_SESSION['qr_data'] = $qr_data;
            $_SESSION['qr_path'] = $qr_path;
            $_SESSION['qr_type'] = $qr_type;

            // Xóa dữ liệu chỉnh sửa sau khi tạo mới
            $edit_qr_data = [];
            $edit_qr_type = '';
        } else {
            throw new Exception("Vui lòng nhập dữ liệu để tạo QR Code!");
        }
    } catch (Exception $e) {
        error_log("QR Creation error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $qr_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Xử lý xóa QR Code khỏi lịch sử
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_qr']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $qr_id = (int)$_POST['qr_id'];
        $stmt = $conn->prepare("SELECT qr_path FROM qr_codes WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn xóa: " . $conn->error);
        }
        $stmt->bind_param("ii", $qr_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn xóa: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $qr = $result->fetch_assoc();
            $qr_path = $qr['qr_path'];
            if (file_exists($qr_path)) {
                unlink($qr_path);
            }
            $stmt_delete = $conn->prepare("DELETE FROM qr_codes WHERE id = ? AND user_id = ?");
            if (!$stmt_delete) {
                throw new Exception("Lỗi chuẩn bị xóa QR: " . $conn->error);
            }
            $stmt_delete->bind_param("ii", $qr_id, $user_id);
            if (!$stmt_delete->execute()) {
                throw new Exception("Lỗi xóa QR: " . $stmt_delete->error);
            }
            $stmt_delete->close();
            $qr_message = "Xóa QR Code thành công!";
        } else {
            $qr_message = "QR Code không tồn tại hoặc bạn không có quyền xóa!";
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Delete QR error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $qr_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Phân trang cho lịch sử QR Code
$qr_per_page = 10;
$page_qr = isset($_GET['page_qr']) ? (int)$_GET['page_qr'] : 1;
if ($page_qr < 1) $page_qr = 1;
$offset_qr = ($page_qr - 1) * $qr_per_page;

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM qr_codes WHERE user_id = ?");
if (!$stmt) {
    error_log("Prepare failed for total QR count: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể lấy tổng số QR Code.";
    $total_qr = 0;
    $total_pages_qr = 1;
} else {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $total_qr = $stmt->get_result()->fetch_assoc()['total'];
        $total_pages_qr = ceil($total_qr / $qr_per_page);
    } else {
        error_log("Execute failed for total QR count: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy tổng số QR Code.";
        $total_qr = 0;
        $total_pages_qr = 1;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT * FROM qr_codes WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?");
if (!$stmt) {
    error_log("Prepare failed for QR list: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể lấy danh sách QR Code.";
    $qr_codes = [];
} else {
    $stmt->bind_param("iii", $user_id, $offset_qr, $qr_per_page);
    if ($stmt->execute()) {
        $qr_codes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log("Execute failed for QR list: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy danh sách QR Code.";
        $qr_codes = [];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo QR Code - Quản Lý Hiện Đại</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .dashboard-container {
            background: var(--card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            backdrop-filter: blur(20px);
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .dashboard-title {
            font-size: 1.875rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: var(--glow);
            transition: transform 0.3s ease;
        }

        .user-avatar:hover {
            transform: rotate(360deg);
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--foreground);
        }

        .user-role {
            font-size: 0.875rem;
            color: var(--foreground-muted);
        }

        .content-section {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--foreground);
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--secondary);
            font-size: 1.25rem;
        }

        .qr-form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2rem;
            justify-content: space-between;
        }

        .qr-form-section, .qr-result-section, .expiry-config, .qr-history {
            flex: 1;
            min-width: 300px;
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .qr-form-section:hover, .qr-result-section:hover, .qr-history:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .qr-form-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--foreground);
        }

        .qr-form-title i {
            color: var(--secondary);
        }

        .qr-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .qr-select, .qr-input, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .qr-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2300E0FF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        .qr-input[type="file"] {
            border-style: dashed;
            cursor: pointer;
        }

        .qr-select:focus, .qr-input:focus, textarea:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            outline: none;
        }

        .field-group {
            display: none;
            animation: slideIn 0.5s ease;
        }

        .field-group.active {
            display: block;
        }

        .field-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
            color: var(--foreground);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .qr-button {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .qr-button:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .qr-result-section {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .qr-image {
            max-width: 300px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }

        .qr-download-btn, .qr-edit-btn {
            background: linear-gradient(to right, var(--secondary), var(--secondary-dark));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            margin: 0.5rem;
        }

        .qr-download-btn:hover, .qr-edit-btn:hover {
            background: linear-gradient(to right, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .qr-edit-btn {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
        }

        .qr-edit-btn:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .instructions {
            padding: 1rem;
            text-align: left;
        }

        .instructions p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
        }

        .instructions p i {
            color: var(--secondary);
        }

        .expiry-config {
            display: <?php echo $user['is_super_admin'] ? 'block' : 'none'; ?>;
            margin-top: 1.5rem;
        }

        .expiry-form {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .expiry-input {
            width: 100px;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .expiry-input:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            outline: none;
        }

        .expiry-info {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .expiry-info i {
            color: var(--secondary);
        }

        .qr-history {
            grid-column: 1 / -1;
            margin-top: 1.5rem;
        }

        .qr-history-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .qr-history-card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }

        .qr-history-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .qr-history-preview {
            padding: 1rem;
            display: flex;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid var(--border);
        }

        .qr-history-preview img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            border-radius: var(--radius-sm);
        }

        .qr-history-info {
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .qr-history-type {
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--foreground);
        }

        .qr-history-type i {
            color: var(--secondary);
        }

        .qr-history-data {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            word-break: break-all;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .qr-history-dates {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-top: auto;
        }

        .qr-history-dates i {
            color: var(--secondary);
            margin-right: 0.25rem;
        }

        .qr-history-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-top: 1px solid var(--border);
        }

        .qr-history-btn {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }

        .qr-download-history-btn {
            background: linear-gradient(to right, var(--secondary), var(--secondary-dark));
            color: white;
        }

        .qr-download-history-btn:hover {
            background: linear-gradient(to right, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .qr-edit-history-btn {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
        }

        .qr-edit-history-btn:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .qr-delete-btn {
            background: linear-gradient(to right, #FF3D57, #C70039);
            color: white;
        }

        .qr-delete-btn:hover {
            background: linear-gradient(to right, #FF5069, #DC143C);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.3);
        }

        .no-qr-history {
            text-align: center;
            padding: 1.5rem;
            color: var(--foreground-muted);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .no-qr-history i {
            font-size: 2rem;
            color: var(--secondary);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
            align-items: center;
        }

        .pagination-link {
            padding: 0.5rem 1rem;
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pagination-link:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .pagination-info {
            font-size: 0.75rem;
            color: var(--foreground-muted);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin: 1rem 0;
            font-weight: 500;
            backdrop-filter: blur(10px);
            animation: slideIn 0.5s ease;
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--surface);
            color: var(--foreground);
            border-radius: 21px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            margin-top: 2rem;
        }

        .back-button:hover {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .dashboard-container {
                margin: 1rem;
                padding: 1rem;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .qr-form-container {
                flex-direction: column;
            }

            .qr-history-list {
                grid-template-columns: 1fr;
            }

            .qr-button, .qr-download-btn, .qr-edit-btn, .back-button {
                width: 100%;
            }

            .pagination {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            .qr-form-title {
                font-size: 1rem;
            }

            .qr-select, .qr-input, textarea, .expiry-input {
                font-size: 0.75rem;
            }

            .qr-button, .qr-download-btn, .qr-edit-btn, .back-button {
                font-size: 0.75rem;
            }

            .qr-image {
                max-width: 200px;
            }

            .qr-history-preview img {
                width: 80px;
                height: 80px;
            }

            .qr-history-btn {
                font-size: 0.7rem;
            }
        }
    </style>
    <script>
        function toggleQrFields() {
            const qrType = document.getElementById('qr_type').value;
            document.querySelectorAll('.field-group').forEach(group => {
                group.classList.remove('active');
            });
            if (qrType) {
                document.getElementById(qrType + '_fields').classList.add('active');
            }
        }

        function editQr(qrData, qrPath, qrType) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/qrcode.php';
            const dataInput = document.createElement('input');
            dataInput.type = 'hidden';
            dataInput.name = 'qr_data';
            dataInput.value = qrData;
            const pathInput = document.createElement('input');
            pathInput.type = 'hidden';
            pathInput.name = 'qr_path';
            pathInput.value = qrPath;
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'qr_type';
            typeInput.value = qrType;
            const editInput = document.createElement('input');
            editInput.type = 'hidden';
            editInput.name = 'edit_qr';
            editInput.value = '1';
            form.appendChild(dataInput);
            form.appendChild(pathInput);
            form.appendChild(typeInput);
            form.appendChild(editInput);
            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleQrFields();
            const elements = document.querySelectorAll('.content-section, .qr-form-section, .qr-result-section, .expiry-config, .qr-history, .qr-history-card');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0, 0, 0.2, 1), transform 0.5s cubic-bezier(0, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            setTimeout(() => {
                const messages = document.querySelectorAll('.alert');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        });
    </script>
</head>
<body>
    <div class="container">
        <div class="dashboard-container">
            <div class="dashboard-header">
                <h1 class="dashboard-title">Tạo QR Code</h1>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                    <div class="user-details">
                        <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                        <p class="user-role">
                            <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php if (!empty($error_message)): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></p>
            <?php elseif (!empty($qr_message)): ?>
                <p class="alert <?php echo strpos($qr_message, 'thành công') !== false ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($qr_message); ?>
                </p>
            <?php endif; ?>

            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-qrcode"></i> Tạo QR Code</h2>
                <div class="qr-form-container">
                    <!-- Form tạo QR Code -->
                    <div class="qr-form-section">
                        <div class="qr-form-title">
                            <i class="fas fa-qrcode"></i> Tạo QR Code mới
                        </div>
                        <form class="qr-form" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <select name="qr_type" id="qr_type" onchange="toggleQrFields()" class="qr-select" required>
                                <option value="">Chọn loại QR Code</option>
                                <option value="text" <?php echo (isset($_POST['qr_type']) && $_POST['qr_type'] === 'text') || $edit_qr_type === 'text' ? 'selected' : ''; ?>>Text</option>
                                <option value="link" <?php echo (isset($_POST['qr_type']) && $_POST['qr_type'] === 'link') || $edit_qr_type === 'link' ? 'selected' : ''; ?>>Link</option>
                                <option value="email" <?php echo (isset($_POST['qr_type']) && $_POST['qr_type'] === 'email') || $edit_qr_type === 'email' ? 'selected' : ''; ?>>Email</option>
                                <option value="file" <?php echo (isset($_POST['qr_type']) && $_POST['qr_type'] === 'file') || $edit_qr_type === 'file' ? 'selected' : ''; ?>>File</option>
                                <option value="event" <?php echo (isset($_POST['qr_type']) && $_POST['qr_type'] === 'event') || $edit_qr_type === 'event' ? 'selected' : ''; ?>>Thông tin Event</option>
                            </select>

                            <div id="text_fields" class="field-group">
                                <label class="field-label">Nội dung văn bản</label>
                                <input type="text" name="qr_text" placeholder="Nhập nội dung text" class="qr-input" value="<?php echo isset($edit_qr_data['qr_text']) ? htmlspecialchars($edit_qr_data['qr_text']) : (isset($_POST['qr_text']) ? htmlspecialchars($_POST['qr_text']) : ''); ?>">
                            </div>

                            <div id="link_fields" class="field-group">
                                <label class="field-label">Đường dẫn URL</label>
                                <input type="url" name="qr_link" placeholder="Nhập URL (http://...)" class="qr-input" value="<?php echo isset($edit_qr_data['qr_link']) ? htmlspecialchars($edit_qr_data['qr_link']) : (isset($_POST['qr_link']) ? htmlspecialchars($_POST['qr_link']) : ''); ?>">
                            </div>

                            <div id="email_fields" class="field-group">
                                <label class="field-label">Địa chỉ email</label>
                                <input type="email" name="qr_email" placeholder="Địa chỉ email" class="qr-input" value="<?php echo isset($edit_qr_data['qr_email']) ? htmlspecialchars($edit_qr_data['qr_email']) : (isset($_POST['qr_email']) ? htmlspecialchars($_POST['qr_email']) : ''); ?>">
                                <label class="field-label">Tiêu đề email</label>
                                <input type="text" name="qr_email_subject" placeholder="Tiêu đề email" class="qr-input" value="<?php echo isset($edit_qr_data['qr_email_subject']) ? htmlspecialchars($edit_qr_data['qr_email_subject']) : (isset($_POST['qr_email_subject']) ? htmlspecialchars($_POST['qr_email_subject']) : ''); ?>">
                                <label class="field-label">Nội dung email</label>
                                <textarea name="qr_email_body" placeholder="Nội dung email" class="qr-input"><?php echo isset($edit_qr_data['qr_email_body']) ? htmlspecialchars($edit_qr_data['qr_email_body']) : (isset($_POST['qr_email_body']) ? htmlspecialchars($_POST['qr_email_body']) : ''); ?></textarea>
                            </div>

                            <div id="file_fields" class="field-group">
                                <label class="field-label">Chọn file (hỗ trợ: txt, pdf, doc, docx, jpg, png)</label>
                                <input type="file" name="qr_file" accept=".txt,.pdf,.doc,.docx,.jpg,.png" class="qr-input">
                                <?php if (isset($edit_qr_data['qr_data']) && $edit_qr_type === 'file'): ?>
                                    <p class="field-label">File hiện tại: <a href="<?php echo htmlspecialchars($edit_qr_data['qr_data']); ?>" target="_blank"><?php echo htmlspecialchars($edit_qr_data['qr_data']); ?></a></p>
                                <?php endif; ?>
                            </div>

                            <div id="event_fields" class="field-group">
                                <label class="field-label">Tiêu đề sự kiện</label>
                                <input type="text" name="qr_event_title" placeholder="Tiêu đề sự kiện" class="qr-input" value="<?php echo isset($edit_qr_data['qr_event_title']) ? htmlspecialchars($edit_qr_data['qr_event_title']) : (isset($_POST['qr_event_title']) ? htmlspecialchars($_POST['qr_event_title']) : ''); ?>">
                                <label class="field-label">Địa điểm</label>
                                <input type="text" name="qr_event_location" placeholder="Địa điểm" class="qr-input" value="<?php echo isset($edit_qr_data['qr_event_location']) ? htmlspecialchars($edit_qr_data['qr_event_location']) : (isset($_POST['qr_event_location']) ? htmlspecialchars($_POST['qr_event_location']) : ''); ?>">
                                <label class="field-label">Thời gian bắt đầu</label>
                                <input type="datetime-local" name="qr_event_start" class="qr-input" value="<?php echo isset($edit_qr_data['qr_event_start']) ? htmlspecialchars($edit_qr_data['qr_event_start']) : (isset($_POST['qr_event_start']) ? htmlspecialchars($_POST['qr_event_start']) : ''); ?>">
                                <label class="field-label">Thời gian kết thúc</label>
                                <input type="datetime-local" name="qr_event_end" class="qr-input" value="<?php echo isset($edit_qr_data['qr_event_end']) ? htmlspecialchars($edit_qr_data['qr_event_end']) : (isset($_POST['qr_event_end']) ? htmlspecialchars($_POST['qr_event_end']) : ''); ?>">
                            </div>

                            <button type="submit" name="generate_qr" class="qr-button">
                                <i class="fas fa-qrcode"></i> Tạo QR Code
                            </button>
                        </form>
                    </div>

                    <!-- Hiển thị QR Code mới tạo -->
                    <?php if ($qr_image): ?>
                        <div class="qr-result-section">
                            <div class="qr-form-title">
                                <i class="fas fa-check-circle"></i> QR Code đã tạo
                            </div>
                            <img src="<?php echo htmlspecialchars($qr_image); ?>" alt="QR Code" class="qr-image">
                            <a href="<?php echo htmlspecialchars($qr_image); ?>" download class="qr-download-btn">
                                <i class="fas fa-download"></i> Tải xuống QR Code
                            </a>
                            <button onclick="editQr('<?php echo addslashes(htmlspecialchars($_SESSION['qr_data'])); ?>', '<?php echo addslashes(htmlspecialchars($_SESSION['qr_path'])); ?>', '<?php echo addslashes(htmlspecialchars($_SESSION['qr_type'])); ?>')" class="qr-edit-btn">
                                <i class="fas fa-edit"></i> Chỉnh sửa QR Code
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="qr-result-section">
                            <div class="qr-form-title">
                                <i class="fas fa-info-circle"></i> Hướng dẫn
                            </div>
                            <div class="instructions">
                                <p><i class="fas fa-check-circle"></i> Chọn loại QR Code bạn muốn tạo</p>
                                <p><i class="fas fa-check-circle"></i> Nhập thông tin cần thiết</p>
                                <p><i class="fas fa-check-circle"></i> Nhấn nút "Tạo QR Code"</p>
                                <p><i class="fas fa-check-circle"></i> QR Code sẽ hiển thị tại đây</p>
                                <p><i class="fas fa-check-circle"></i> Tải xuống hoặc chỉnh sửa để tùy chỉnh thiết kế</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Cấu hình thời gian xóa QR Code (chỉ Super Admin) -->
                    <?php if ($user['is_super_admin']): ?>
                        <div class="expiry-config">
                            <div class="qr-form-title">
                                <i class="fas fa-cog"></i> Cấu hình thời gian xóa QR Code
                            </div>
                            <form class="expiry-form" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="number" name="new_expiry" min="1" value="<?php echo isset($_SESSION['qr_expiry']) ? ($_SESSION['qr_expiry'] / 86400) : 30; ?>" class="expiry-input" required>
                                <span>ngày</span>
                                <button type="submit" name="update_expiry" class="qr-button">
                                    <i class="fas fa-save"></i> Cập nhật
                                </button>
                            </form>
                            <p class="expiry-info">
                                <i class="fas fa-info-circle"></i> Thời gian hiện tại: Mỗi QR sẽ tự xóa sau <?php echo isset($_SESSION['qr_expiry']) ? ($_SESSION['qr_expiry'] / 86400) : 30; ?> ngày.
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- Lịch sử QR Code -->
                    <div class="qr-history">
                        <div class="qr-form-title">
                            <i class="fas fa-history"></i> Lịch sử tạo QR Code
                        </div>
                        <?php if (empty($qr_codes)): ?>
                            <div class="no-qr-history">
                                <i class="fas fa-qrcode"></i>
                                <p>Chưa có QR Code nào được tạo. Hãy tạo QR Code đầu tiên của bạn!</p>
                            </div>
                        <?php else: ?>
                            <div class="qr-history-list">
                                <?php foreach ($qr_codes as $qr): ?>
                                    <div class="qr-history-card">
                                        <div class="qr-history-preview">
                                            <img src="<?php echo htmlspecialchars($qr['qr_path']); ?>" alt="QR Code">
                                        </div>
                                        <div class="qr-history-info">
                                            <div class="qr-history-type">
                                                <?php 
                                                $icon_class = 'fa-qrcode';
                                                switch($qr['qr_type']) {
                                                    case 'text': $icon_class = 'fa-font'; break;
                                                    case 'link': $icon_class = 'fa-link'; break;
                                                    case 'email': $icon_class = 'fa-envelope'; break;
                                                    case 'file': $icon_class = 'fa-file'; break;
                                                    case 'event': $icon_class = 'fa-calendar'; break;
                                                }
                                                ?>
                                                <i class="fas <?php echo $icon_class; ?>"></i>
                                                <?php echo ucfirst(htmlspecialchars($qr['qr_type'])); ?>
                                            </div>
                                            <div class="qr-history-data" title="<?php echo htmlspecialchars($qr['qr_data']); ?>">
                                                <?php echo htmlspecialchars(substr($qr['qr_data'], 0, 100)) . (strlen($qr['qr_data']) > 100 ? '...' : ''); ?>
                                            </div>
                                            <div class="qr-history-dates">
                                                <span><i class="far fa-calendar-plus"></i> Tạo: <?php echo date('d/m/Y H:i', strtotime($qr['created_at'])); ?></span>
                                                <span><i class="far fa-calendar-times"></i> Hết hạn: <?php echo date('d/m/Y H:i', strtotime($qr['expiry_time'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="qr-history-actions">
                                            <a href="<?php echo htmlspecialchars($qr['qr_path']); ?>" download class="qr-history-btn qr-download-history-btn">
                                                <i class="fas fa-download"></i> Tải xuống
                                            </a>
                                            <button onclick="editQr('<?php echo addslashes(htmlspecialchars($qr['qr_data'])); ?>', '<?php echo addslashes(htmlspecialchars($qr['qr_path'])); ?>', '<?php echo addslashes(htmlspecialchars($qr['qr_type'])); ?>')" class="qr-history-btn qr-edit-history-btn">
                                                <i class="fas fa-edit"></i> Chỉnh sửa
                                            </button>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="qr_id" value="<?php echo $qr['id']; ?>">
                                                <button type="submit" name="delete_qr" class="qr-history-btn qr-delete-btn" onclick="return confirm('Bạn có chắc muốn xóa QR Code này? Hành động này không thể hoàn tác.');">
                                                    <i class="fas fa-trash-alt"></i> Xóa
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Phân trang -->
                            <?php if ($total_pages_qr > 1): ?>
                                <div class="pagination">
                                    <?php if ($page_qr > 1): ?>
                                        <a href="/qrcode.php?page_qr=<?php echo $page_qr - 1; ?>" class="pagination-link">
                                            <i class="fas fa-chevron-left"></i> Trang trước
                                        </a>
                                    <?php endif; ?>
                                    <span class="pagination-info">Trang <?php echo $page_qr; ?> / <?php echo $total_pages_qr; ?></span>
                                    <?php if ($page_qr < $total_pages_qr): ?>
                                        <a href="/qrcode.php?page_qr=<?php echo $page_qr + 1; ?>" class="pagination-link">
                                            Trang sau <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>
    </div>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>