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
    die("Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.");
}
include 'db_config.php';

// Kiểm tra kết nối database
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.");
}

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

// Lấy thông tin user
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
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
            $user = ['username' => 'Unknown', 'full_name' => '', 'is_main_admin' => 0, 'is_super_admin' => 0];
        }
    }
    $stmt->close();
}

// Khởi tạo biến thông báo
$error_message = '';
$success_message = '';

// Tự động lấy tên miền
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

// Thư mục lưu file
$upload_dir = 'Uploads/documents/';
$upload_path = __DIR__ . '/' . $upload_dir;

// Đảm bảo thư mục tồn tại
if (!is_dir($upload_path)) {
    mkdir($upload_path, 0755, true);
    chmod($upload_path, 0755);
}

// Xử lý tải lên tài liệu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_document']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
        $major = filter_input(INPUT_POST, 'major', FILTER_SANITIZE_STRING);
        $lesson = filter_input(INPUT_POST, 'lesson', FILTER_SANITIZE_STRING);
        $project = filter_input(INPUT_POST, 'project', FILTER_SANITIZE_STRING);

        if (empty($subject) || empty($major)) {
            throw new Exception("Vui lòng nhập Môn học và Ngành.");
        }
        if (strlen($subject) > 255 || strlen($major) > 255 || strlen($lesson) > 255 || strlen($project) > 255) {
            throw new Exception("Thông tin nhập quá dài! Tối đa 255 ký tự.");
        }
        if (!isset($_FILES['document']) || $_FILES['document']['error'] == UPLOAD_ERR_NO_FILE) {
            throw new Exception("Vui lòng chọn file để tải lên.");
        }

        $file = $_FILES['document'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $max_size = 50 * 1024 * 1024; // 50MB
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types)) {
            throw new Exception("Định dạng file không được hỗ trợ! Chỉ hỗ trợ: doc, docx, pdf, ppt, pptx.");
        }
        if ($file_size > $max_size) {
            throw new Exception("File quá lớn! Kích thước tối đa là 50MB.");
        }

        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $new_file_name = uniqid() . '.' . $file_ext;
        $destination = $upload_path . $new_file_name;

        if (!move_uploaded_file($file_tmp, $destination)) {
            throw new Exception("Lỗi khi lưu file lên server.");
        }

        $stmt = $conn->prepare("INSERT INTO documents (user_id, file_name, file_path, file_type, subject, major, lesson, project, uploaded_at, download_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)");
        if (!$stmt) {
            unlink($destination);
            throw new Exception("Lỗi chuẩn bị truy vấn: " . $conn->error);
        }
        $stmt->bind_param("isssssss", $user_id, $file_name, $new_file_name, $file_ext, $subject, $major, $lesson, $project);
        if (!$stmt->execute()) {
            unlink($destination);
            throw new Exception("Lỗi lưu thông tin tài liệu: " . $stmt->error);
        }
        $stmt->close();
        $success_message = "Tải tài liệu lên thành công!";
    } catch (Exception $e) {
        error_log("Upload document error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $error_message = $e->getMessage();
    }
}

// Xử lý xóa tài liệu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_document']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $document_id = (int)$_POST['document_id'];
        $stmt = $conn->prepare("SELECT file_path FROM documents WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn xóa: " . $conn->error);
        }
        $stmt->bind_param("ii", $document_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn xóa: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $document = $result->fetch_assoc();
            $file_path = $upload_path . $document['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $stmt_delete = $conn->prepare("DELETE FROM documents WHERE id = ? AND user_id = ?");
            if (!$stmt_delete) {
                throw new Exception("Lỗi chuẩn bị xóa tài liệu: " . $conn->error);
            }
            $stmt_delete->bind_param("ii", $document_id, $user_id);
            if (!$stmt_delete->execute()) {
                throw new Exception("Lỗi xóa tài liệu: " . $stmt_delete->error);
            }
            $stmt_delete->close();
            $success_message = "Xóa tài liệu thành công!";
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Delete document error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $error_message = $e->getMessage();
    }
}

// Xử lý sửa tài liệu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_document']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $document_id = (int)$_POST['document_id'];
        $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
        $major = filter_input(INPUT_POST, 'major', FILTER_SANITIZE_STRING);
        $lesson = filter_input(INPUT_POST, 'lesson', FILTER_SANITIZE_STRING);
        $project = filter_input(INPUT_POST, 'project', FILTER_SANITIZE_STRING);

        if (empty($subject) || empty($major)) {
            throw new Exception("Vui lòng nhập Môn học và Ngành.");
        }
        if (strlen($subject) > 255 || strlen($major) > 255 || strlen($lesson) > 255 || strlen($project) > 255) {
            throw new Exception("Thông tin nhập quá dài! Tối đa 255 ký tự.");
        }

        $stmt = $conn->prepare("UPDATE documents SET subject = ?, major = ?, lesson = ?, project = ? WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn cập nhật: " . $conn->error);
        }
        $stmt->bind_param("ssssii", $subject, $major, $lesson, $project, $document_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi cập nhật tài liệu: " . $stmt->error);
        }
        $stmt->close();
        $success_message = "Cập nhật thông tin tài liệu thành công!";
    } catch (Exception $e) {
        error_log("Edit document error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $error_message = $e->getMessage();
    }
}

// Xử lý tải xuống (tăng đếm lượt tải)
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    try {
        $doc_id = (int)$_GET['download'];
        $stmt = $conn->prepare("SELECT file_name, file_path FROM documents WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn tải: " . $conn->error);
        }
        $stmt->bind_param("i", $doc_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn tải: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $doc = $result->fetch_assoc();
            $file_path = $upload_path . $doc['file_path'];
            if (!file_exists($file_path)) {
                throw new Exception("File không tồn tại trên server.");
            }

            // Tăng download_count
            $stmt_update = $conn->prepare("UPDATE documents SET download_count = download_count + 1 WHERE id = ?");
            if (!$stmt_update) {
                throw new Exception("Lỗi chuẩn bị truy vấn cập nhật lượt tải: " . $conn->error);
            }
            $stmt_update->bind_param("i", $doc_id);
            if (!$stmt_update->execute()) {
                throw new Exception("Lỗi cập nhật lượt tải: " . $stmt_update->error);
            }
            $stmt_update->close();

            // Tải file
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $doc['file_name'] . '"');
            readfile($file_path);
            $stmt->close();
            exit;
        } else {
            throw new Exception("Tài liệu không tồn tại.");
        }
    } catch (Exception $e) {
        error_log("Download document error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        $error_message = $e->getMessage();
    }
    if (isset($stmt)) {
        $stmt->close();
    }
}

// Xử lý tìm kiếm, lọc và phân trang
$search_query = trim($_GET['search'] ?? '');
$file_type_filter = $_GET['file_type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where_clause = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($search_query)) {
    $where_clause .= " AND (LOWER(subject) LIKE ? OR LOWER(major) LIKE ?)";
    $search_param = "%" . strtolower($search_query) . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($file_type_filter)) {
    $where_clause .= " AND file_type = ?";
    $params[] = $file_type_filter;
    $types .= "s";
}

// Đếm tổng số tài liệu
$count_sql = "SELECT COUNT(*) as total FROM documents $where_clause";
$stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt->execute()) {
    error_log("Execute failed for count documents: " . $stmt->error);
    $error_message = "Lỗi hệ thống: Không thể lấy tổng số tài liệu.";
    $total = 0;
    $total_pages = 1;
} else {
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total / $per_page);
}
$stmt->close();

// Lấy danh sách tài liệu
$sql = "SELECT d.*, u.username FROM documents d JOIN users u ON d.user_id = u.id $where_clause ORDER BY d.uploaded_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed for document list: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể lấy danh sách tài liệu.";
    $documents = [];
} else {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log("Execute failed for document list: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy danh sách tài liệu.";
        $documents = [];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Tài Liệu - Quản Lý</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Màu chính */
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            
            /* Màu nền và text */
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            
            /* Màu card */
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            
            /* Border và shadow */
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.4);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --glow-accent: 0 0 20px rgba(255, 61, 255, 0.5);
            --glow-secondary: 0 0 20px rgba(0, 224, 255, 0.5);
            
            /* Border radius */
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
            --radius-xl: 3rem;
            --radius-full: 9999px;
            
            /* Màu chức năng */
            --delete-color: #FF3D57;
            --delete-hover-color: #FF1A3A;
            --edit-color: var(--secondary);
            --edit-hover-color: var(--secondary-light);
            --download-color: #22c55e;
            --download-hover-color: #16a34a;
            --view-color: var(--accent);
            --view-hover-color: var(--accent-light);
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

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-light), var(--secondary-light));
        }

        /* Main layout */
        .dashboard-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-to-dashboard {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-dashboard:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Message styles */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border-left: 4px solid #FF3D57;
        }

        .error-message::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border-left: 4px solid var(--secondary);
        }

        .success-message::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Content section */
        .content-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 80% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .section-title i {
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Form Container */
        .form-container {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-container:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--foreground);
        }

        .form-title i {
            color: var(--secondary);
        }

        .form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .field-group {
            margin-bottom: 1rem;
        }

        .field-label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--foreground-muted);
            font-size: 0.875rem;
        }

        .input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }

        .input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .input[type="file"] {
            border-style: dashed;
            cursor: pointer;
            padding: 1rem;
            position: relative;
        }

        .button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
            width: 100%;
        }

        .button::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .button:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .button:active {
            transform: translateY(0);
        }

        .button i {
            font-size: 1.125rem;
        }

        /* Filter Container */
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            background: var(--card);
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .filter-select {
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--foreground);
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .filter-select option {
            background-color: var(--surface);
            color: var(--foreground);
        }

        /* Document List */
        .document-history {
            margin-top: 2rem;
        }

        .document-history-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-top: 1.5rem;
        }

        .document-history-card {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .document-history-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--border);
            background: var(--card-hover);
        }

        .document-history-info {
            padding: 1.25rem;
            flex: 1;
        }

        .document-history-type {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 0.75rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .document-history-type i {
            font-size: 1.25rem;
        }

        .document-history-data {
            color: var(--foreground-muted);
            font-size: 0.875rem;
            display: -webkit-box;
            -webkit-line-clamp: 6;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: auto;
            margin-bottom: 1rem;
        }

        .document-history-data strong {
            color: var(--foreground);
            font-weight: 600;
        }

        .document-history-dates {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding-top: 0.75rem;
        }

        .document-history-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 0.5rem;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid var(--border);
        }

        .document-history-btn {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }

        .document-download-btn {
            background: var(--download-color);
        }

        .document-download-btn:hover {
            background: var(--download-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.4);
        }

        .document-view-btn {
            background: var(--view-color);
        }

        .document-view-btn:hover {
            background: var(--view-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 255, 0.4);
        }

        .document-edit-btn {
            background: var(--edit-color);
        }

        .document-edit-btn:hover {
            background: var(--edit-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.4);
        }

        .document-delete-btn {
            background: var(--delete-color);
        }

        .document-delete-btn:hover {
            background: var(--delete-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.4);
        }

        .no-document-history {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--foreground-muted);
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .no-document-history i {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Edit Form */
        .edit-form {
            margin-top: 0.5rem;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .edit-form .form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .edit-form .input {
            font-size: 0.75rem;
            padding: 0.6rem 0.75rem;
        }

        .edit-form .button {
            padding: 0.6rem 0.75rem;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            background: var(--card);
            color: var(--foreground);
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.875rem;
            border: 1px solid var(--border);
        }

        .pagination a:hover {
            background: var(--card-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .pagination a.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: transparent;
            box-shadow: var(--glow);
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin: 1rem auto;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }
            
            .back-to-dashboard {
                width: 100%;
                justify-content: center;
            }
            
            .form {
                grid-template-columns: 1fr;
            }
            
            .filter-container {
                flex-direction: column;
            }
            
            .document-history-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
            
            .document-history-actions {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Particle animation */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            pointer-events: none;
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }
    </style>
    <script>
        function toggleEditForm(id) {
            const form = document.getElementById('edit-form-' + id);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.content-section', 100);
            animateElements('.form-container', 150);
            animateElements('.document-history-card', 50);
            
            // Hiệu ứng hiển thị thông báo
            setTimeout(() => {
                const messages = document.querySelectorAll('.success-message, .error-message');
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
        
        // Hàm tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.classList.add('particles');
            document.body.appendChild(particlesContainer);
            
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                // Random color
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Hàm animation cho các phần tử
        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * delay);
            });
        }
    </script>
</head>
<body>
    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-file-alt"></i> Quản Lý Tài Liệu</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php elseif (!empty($success_message)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-file-upload"></i> Quản Lý Tài Liệu</h2>

            <!-- Form tải lên tài liệu -->
            <div class="form-container">
                <h3 class="form-title"><i class="fas fa-upload"></i> Tải Lên Tài Liệu</h3>
                <form method="POST" enctype="multipart/form-data" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="field-group">
                        <label class="field-label" for="subject">Môn học</label>
                        <input type="text" id="subject" name="subject" class="input" placeholder="Nhập môn học" value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="major">Ngành</label>
                        <input type="text" id="major" name="major" class="input" placeholder="Nhập ngành" value="<?php echo isset($_POST['major']) ? htmlspecialchars($_POST['major']) : ''; ?>" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="lesson">Bài học (tùy chọn)</label>
                        <input type="text" id="lesson" name="lesson" class="input" placeholder="Nhập bài học" value="<?php echo isset($_POST['lesson']) ? htmlspecialchars($_POST['lesson']) : ''; ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="project">Đồ án (tùy chọn)</label>
                        <input type="text" id="project" name="project" class="input" placeholder="Nhập đồ án" value="<?php echo isset($_POST['project']) ? htmlspecialchars($_POST['project']) : ''; ?>">
                    </div>
                    <div class="field-group" style="grid-column: 1 / -1;">
                        <label class="field-label" for="document">Chọn file (Tối đa 50MB, hỗ trợ: doc, docx, pdf, ppt, pptx)</label>
                        <input type="file" id="document" name="document" class="input" accept=".doc,.docx,.pdf,.ppt,.pptx" required>
                    </div>
                    <div class="field-group" style="grid-column: 1 / -1;">
                        <button type="submit" name="upload_document" class="button"><i class="fas fa-upload"></i> Tải Lên</button>
                    </div>
                </form>
            </div>

            <!-- Tìm kiếm và lọc -->
            <div class="filter-container">
                <form method="GET" style="display: flex; gap: 1rem; width: 100%; flex-wrap: wrap;">
                    <input type="text" name="search" class="input" style="flex: 1;" placeholder="Tìm kiếm theo môn học hoặc ngành..." value="<?php echo htmlspecialchars($search_query); ?>">
                    <select name="file_type" class="filter-select" onchange="this.form.submit()" style="width: auto;">
                        <option value="">Tất cả định dạng</option>
                        <option value="pdf" <?php echo $file_type_filter == 'pdf' ? 'selected' : ''; ?>>PDF</option>
                        <option value="doc" <?php echo $file_type_filter == 'doc' ? 'selected' : ''; ?>>DOC</option>
                        <option value="docx" <?php echo $file_type_filter == 'docx' ? 'selected' : ''; ?>>DOCX</option>
                        <option value="ppt" <?php echo $file_type_filter == 'ppt' ? 'selected' : ''; ?>>PPT</option>
                        <option value="pptx" <?php echo $file_type_filter == 'pptx' ? 'selected' : ''; ?>>PPTX</option>
                    </select>
                    <button type="submit" class="button" style="width: auto; padding: 0.75rem 1.25rem;"><i class="fas fa-search"></i> Tìm</button>
                </form>
            </div>

            <!-- Danh sách tài liệu -->
            <div class="document-history">
                <h3 class="section-title"><i class="fas fa-file-alt"></i> Danh Sách Tài Liệu</h3>
                <?php if (empty($documents)): ?>
                    <div class="no-document-history">
                        <i class="fas fa-file-alt"></i>
                        <p>Chưa có tài liệu nào được tải lên.</p>
                    </div>
                <?php else: ?>
                    <div class="document-history-list">
                        <?php foreach ($documents as $doc): ?>
                            <div class="document-history-card">
                                <div class="document-history-info">
                                    <div class="document-history-type">
                                        <?php
                                        $icon_class = 'fas fa-file';
                                        if ($doc['file_type'] === 'pdf') $icon_class = 'fas fa-file-pdf';
                                        elseif ($doc['file_type'] === 'doc' || $doc['file_type'] === 'docx') $icon_class = 'fas fa-file-word';
                                        elseif ($doc['file_type'] === 'ppt' || $doc['file_type'] === 'pptx') $icon_class = 'fas fa-file-powerpoint';
                                        ?>
                                        <i class="<?php echo $icon_class; ?>"></i> <?php echo htmlspecialchars($doc['file_name']); ?>
                                    </div>
                                    <div class="document-history-data">
                                        <strong>Người đăng:</strong> <?php echo htmlspecialchars($doc['username']); ?><br>
                                        <strong>Định dạng:</strong> <?php echo strtoupper(htmlspecialchars($doc['file_type'])); ?><br>
                                        <strong>Môn học:</strong> <?php echo htmlspecialchars($doc['subject']); ?><br>
                                        <strong>Ngành:</strong> <?php echo htmlspecialchars($doc['major']); ?><br>
                                        <?php if ($doc['lesson']): ?>
                                            <strong>Bài học:</strong> <?php echo htmlspecialchars($doc['lesson']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($doc['project']): ?>
                                            <strong>Đồ án:</strong> <?php echo htmlspecialchars($doc['project']); ?><br>
                                        <?php endif; ?>
                                        <strong>Lượt tải:</strong> <?php echo (int)$doc['download_count']; ?>
                                    </div>
                                    <div class="document-history-dates">
                                        <i class="far fa-clock"></i> <?php echo htmlspecialchars($doc['uploaded_at']); ?>
                                    </div>
                                </div>
                                <div class="document-history-actions">
                                    <?php if ($doc['file_type'] == 'pdf'): ?>
                                        <a href="<?php echo $base_url . '/' . $upload_dir . htmlspecialchars($doc['file_path']); ?>" target="_blank" class="document-history-btn document-view-btn"><i class="fas fa-eye"></i> Xem</a>
                                    <?php endif; ?>
                                    <a href="?download=<?php echo $doc['id']; ?>" class="document-history-btn document-download-btn"><i class="fas fa-download"></i> Tải Xuống</a>
                                    <?php if ($doc['user_id'] == $user_id): ?>
                                        <button onclick="toggleEditForm(<?php echo $doc['id']; ?>)" class="document-history-btn document-edit-btn"><i class="fas fa-edit"></i> Sửa</button>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                            <button type="submit" name="delete_document" class="document-history-btn document-delete-btn" onclick="return confirm('Bạn có chắc muốn xóa tài liệu này?');"><i class="fas fa-trash"></i> Xóa</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if ($doc['user_id'] == $user_id): ?>
                                    <div id="edit-form-<?php echo $doc['id']; ?>" class="edit-form">
                                        <form method="POST" class="form">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                            <div class="field-group">
                                                <label class="field-label" for="subject-<?php echo $doc['id']; ?>">Môn học</label>
                                                <input type="text" id="subject-<?php echo $doc['id']; ?>" name="subject" class="input" value="<?php echo htmlspecialchars($doc['subject']); ?>" required>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label" for="major-<?php echo $doc['id']; ?>">Ngành</label>
                                                <input type="text" id="major-<?php echo $doc['id']; ?>" name="major" class="input" value="<?php echo htmlspecialchars($doc['major']); ?>" required>
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label" for="lesson-<?php echo $doc['id']; ?>">Bài học (tùy chọn)</label>
                                                <input type="text" id="lesson-<?php echo $doc['id']; ?>" name="lesson" class="input" value="<?php echo htmlspecialchars($doc['lesson']); ?>">
                                            </div>
                                            <div class="field-group">
                                                <label class="field-label" for="project-<?php echo $doc['id']; ?>">Đồ án (tùy chọn)</label>
                                                <input type="text" id="project-<?php echo $doc['id']; ?>" name="project" class="input" value="<?php echo htmlspecialchars($doc['project']); ?>">
                                            </div>
                                            <button type="submit" name="edit_document" class="button"><i class="fas fa-save"></i> Lưu</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Phân trang -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_query); ?>&file_type=<?php echo urlencode($file_type_filter); ?>">
                                    <i class="fas fa-chevron-left"></i> Trang trước
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&file_type=<?php echo urlencode($file_type_filter); ?>" class="<?php echo $page == $i ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_query); ?>&file_type=<?php echo urlencode($file_type_filter); ?>">
                                    Trang sau <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>