<?php
session_start();

// Bật debug để hiển thị lỗi (tạm thời)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u459537937/domains/umters.club/public_html/error_log.txt');

// Kiểm tra fileinfo extension
if (!extension_loaded('fileinfo')) {
    error_log("Extension fileinfo is not enabled");
    die("Lỗi hệ thống: Yêu cầu extension fileinfo.");
}

// Kiểm tra tệp db_config.php
if (!file_exists('../db_config.php') || !is_readable('../db_config.php')) {
    error_log("File db_config.php not found or not readable");
    die("Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.");
}
include '../db_config.php';

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

// Khởi tạo biến thông báo
$resource_message = isset($_SESSION['resource_message']) ? $_SESSION['resource_message'] : '';
$error_message = '';
$user_id = (int)$_SESSION['user_id'];
unset($_SESSION['resource_message']); // Xóa thông báo sau khi hiển thị

// Lấy thông tin user
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
            $user = [
                'username' => 'Unknown',
                'full_name' => '',
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

// Xử lý tạo/sửa tài nguyên
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_resource']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $resource_name = filter_var($_POST['resource_name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
        $new_category = filter_var($_POST['new_category'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $account_details = filter_var($_POST['account_details'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $link = filter_var($_POST['link'] ?? '', FILTER_SANITIZE_URL);
        $issue_date = filter_var($_POST['issue_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $expiry_date = filter_var($_POST['expiry_date'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
        $resource_id = isset($_POST['resource_id']) ? (int)$_POST['resource_id'] : 0;

        // Validate inputs
        if (empty($resource_name) || strlen($resource_name) > 255) {
            throw new Exception("Tên tài nguyên không hợp lệ hoặc quá dài!");
        }
        if (!$category_id && empty($new_category)) {
            throw new Exception("Vui lòng chọn hoặc nhập danh mục!");
        }
        if ($new_category && strlen($new_category) > 50) {
            throw new Exception("Tên danh mục quá dài!");
        }
        if ($link && (!filter_var($link, FILTER_VALIDATE_URL) || strlen($link) > 255)) {
            throw new Exception("Link không hợp lệ hoặc quá dài!");
        }
        if (empty($issue_date)) {
            throw new Exception("Vui lòng chọn ngày cấp!");
        }
        $issue_date = date('Y-m-d H:i:s', strtotime($issue_date));
        $expiry_date = $expiry_date ? date('Y-m-d H:i:s', strtotime($expiry_date)) : null;

        // Handle category
        if ($new_category) {
            $stmt = $conn->prepare("INSERT INTO resource_categories (user_id, category_name) VALUES (?, ?)");
            if (!$stmt) {
                throw new Exception("Lỗi chuẩn bị truy vấn danh mục: " . $conn->error);
            }
            $stmt->bind_param("is", $user_id, $new_category);
            if (!$stmt->execute()) {
                throw new Exception("Lỗi lưu danh mục: " . $stmt->error);
            }
            $category_id = $stmt->insert_id;
            $stmt->close();
        }

        // Handle resource file
        $file_path = null;
        if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES['resource_file'];
            $max_size = 5 * 1024 * 1024; // 5MB
            $allowed_types = ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) {
                throw new Exception("Không thể khởi tạo fileinfo.");
            }
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                throw new Exception("Định dạng file tài nguyên không được hỗ trợ! Hỗ trợ: txt, pdf, doc, docx, jpg, png.");
            }
            if ($file['size'] > $max_size) {
                throw new Exception("File tài nguyên quá lớn! Tối đa 5MB.");
            }

            $upload_dir = 'Uploads/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true) || !chmod($upload_dir, 0755)) {
                    throw new Exception("Không thể tạo thư mục Uploads. Vui lòng kiểm tra quyền ghi.");
                }
            }
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_file_name = time() . '_' . uniqid() . '_resource.' . $file_ext;
            $file_path = $upload_dir . $new_file_name;
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                throw new Exception("Lỗi khi tải file tài nguyên lên server!");
            }
        }

        // Handle cover image
        $cover_image = null;
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == UPLOAD_ERR_OK) {
            $cover = $_FILES['cover_image'];
            $max_size = 2 * 1024 * 1024; // 2MB for cover image
            $allowed_types = ['image/jpeg', 'image/png'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) {
                throw new Exception("Không thể khởi tạo fileinfo.");
            }
            $mime_type = finfo_file($finfo, $cover['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                throw new Exception("Định dạng hình ảnh bìa không được hỗ trợ! Hỗ trợ: jpg, png.");
            }
            if ($cover['size'] > $max_size) {
                throw new Exception("Hình ảnh bìa quá lớn! Tối đa 2MB.");
            }

            $upload_dir = 'Uploads/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true) || !chmod($upload_dir, 0755)) {
                    throw new Exception("Không thể tạo thư mục Uploads. Vui lòng kiểm tra quyền ghi.");
                }
            }
            $cover_ext = pathinfo($cover['name'], PATHINFO_EXTENSION);
            $new_cover_name = time() . '_' . uniqid() . '_cover.' . $cover_ext;
            $cover_image = $upload_dir . $new_cover_name;
            if (!move_uploaded_file($cover['tmp_name'], $cover_image)) {
                throw new Exception("Lỗi khi tải hình ảnh bìa lên server!");
            }
        }

        if ($resource_id > 0) {
            // Update existing resource
            $stmt = $conn->prepare("UPDATE resources SET resource_name = ?, category_id = ?, account_details = ?, link = ?, file_path = ?, cover_image = ?, issue_date = ?, expiry_date = ? WHERE id = ? AND user_id = ?");
            if (!$stmt) {
                throw new Exception("Lỗi chuẩn bị truy vấn cập nhật: " . $conn->error);
            }
            $stmt->bind_param("sissssssii", $resource_name, $category_id, $account_details, $link, $file_path, $cover_image, $issue_date, $expiry_date, $resource_id, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Lỗi cập nhật tài nguyên: " . $stmt->error);
            }
            $stmt->close();
            $_SESSION['resource_message'] = "Cập nhật tài nguyên thành công!";
        } else {
            // Create new resource
            $stmt = $conn->prepare("INSERT INTO resources (user_id, resource_name, category_id, account_details, link, file_path, cover_image, issue_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Lỗi chuẩn bị truy vấn: " . $conn->error);
            }
            $stmt->bind_param("isissssss", $user_id, $resource_name, $category_id, $account_details, $link, $file_path, $cover_image, $issue_date, $expiry_date);
            if (!$stmt->execute()) {
                throw new Exception("Lỗi lưu tài nguyên: " . $stmt->error);
            }
            $stmt->close();
            $_SESSION['resource_message'] = "Tạo tài nguyên thành công!";
        }

        // Redirect to prevent form resubmission
        header("Location: /tainguyen");
        exit;
    } catch (Exception $e) {
        error_log("Resource Creation/Update error: " . $e->getMessage());
        $resource_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Xử lý ẩn/hiện tài nguyên
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_hide']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $resource_id = (int)$_POST['resource_id'];
        $is_hidden = (int)$_POST['is_hidden'];
        $stmt = $conn->prepare("UPDATE resources SET is_hidden = ? WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn ẩn/hiện: " . $conn->error);
        }
        $stmt->bind_param("iii", $is_hidden, $resource_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi cập nhật trạng thái ẩn/hiện: " . $stmt->error);
        }
        $stmt->close();
        $_SESSION['resource_message'] = $is_hidden ? "Ẩn tài nguyên thành công!" : "Hiển thị tài nguyên thành công!";
        header("Location: /tainguyen");
        exit;
    } catch (Exception $e) {
        error_log("Toggle Hide error: " . $e->getMessage());
        $resource_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Xử lý xóa tài nguyên
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_resource']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $resource_id = (int)$_POST['resource_id'];
        $stmt = $conn->prepare("SELECT file_path, cover_image FROM resources WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("Lỗi chuẩn bị truy vấn xóa: " . $conn->error);
        }
        $stmt->bind_param("ii", $resource_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Lỗi truy vấn xóa: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $resource = $result->fetch_assoc();
            if ($resource['file_path'] && file_exists($resource['file_path'])) {
                if (!unlink($resource['file_path'])) {
                    error_log("Failed to delete file: " . $resource['file_path']);
                }
            }
            if ($resource['cover_image'] && file_exists($resource['cover_image'])) {
                if (!unlink($resource['cover_image'])) {
                    error_log("Failed to delete cover image: " . $resource['cover_image']);
                }
            }
            $stmt_delete = $conn->prepare("DELETE FROM resources WHERE id = ? AND user_id = ?");
            if (!$stmt_delete) {
                throw new Exception("Lỗi chuẩn bị xóa tài nguyên: " . $conn->error);
            }
            $stmt_delete->bind_param("ii", $resource_id, $user_id);
            if (!$stmt_delete->execute()) {
                throw new Exception("Lỗi xóa tài nguyên: " . $stmt_delete->error);
            }
            $stmt_delete->close();
            $_SESSION['resource_message'] = "Xóa tài nguyên thành công!";
        } else {
            $_SESSION['resource_message'] = "Tài nguyên không tồn tại hoặc bạn không có quyền xóa!";
        }
        $stmt->close();
        header("Location: /tainguyen");
        exit;
    } catch (Exception $e) {
        error_log("Delete Resource error: " . $e->getMessage());
        $resource_message = "Lỗi: " . htmlspecialchars($e->getMessage());
    }
}

// Lấy danh sách danh mục
$categories = [];
$stmt = $conn->prepare("SELECT id, category_name FROM resource_categories WHERE user_id = ? ORDER BY category_name");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log("Execute failed for categories list: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy danh sách danh mục.";
    }
    $stmt->close();
}

// Phân trang cho danh sách tài nguyên
$resources_per_page = 12;
$page_resources = isset($_GET['page_resources']) ? (int)$_GET['page_resources'] : 1;
if ($page_resources < 1) $page_resources = 1;
$offset_resources = ($page_resources - 1) * $resources_per_page;

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM resources WHERE is_hidden = 0");
if (!$stmt) {
    error_log("Prepare failed for total resources count: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể lấy tổng số tài nguyên.";
    $total_resources = 0;
    $total_pages_resources = 1;
} else {
    if ($stmt->execute()) {
        $total_resources = $stmt->get_result()->fetch_assoc()['total'];
        $total_pages_resources = ceil($total_resources / $resources_per_page);
    } else {
        error_log("Execute failed for total resources count: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy tổng số tài nguyên.";
        $total_resources = 0;
        $total_pages_resources = 1;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT r.*, u.username, c.category_name FROM resources r JOIN users u ON r.user_id = u.id JOIN resource_categories c ON r.category_id = c.id WHERE r.is_hidden = 0 ORDER BY r.created_at DESC LIMIT ?, ?");
if (!$stmt) {
    error_log("Prepare failed for resources list: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể lấy danh sách tài nguyên.";
    $resources = [];
} else {
    $stmt->bind_param("ii", $offset_resources, $resources_per_page);
    if ($stmt->execute()) {
        $resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log("Execute failed for resources list: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể lấy danh sách tài nguyên.";
        $resources = [];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS Tài Nguyên - Quản Lý Hiện Đại</title>
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
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            padding: 1.5rem;
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header section */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
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

        .header-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
            position: relative;
            overflow: hidden;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 0.3; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            position: relative;
        }

        .logo-text::after {
            content: 'UMTERS';
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0;
            animation: textFlicker 8s linear infinite;
        }

        @keyframes textFlicker {
            0%, 92%, 100% { opacity: 0; }
            94%, 96% { opacity: 1; }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-to-home {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            color: var(--foreground);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .back-to-home:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem 0.5rem 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .user-avatar::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.3));
            top: 0;
            left: 0;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Main content */
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        /* Error and success messages */
        .error-message,
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: #00E0FF;
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Resource Panel */
        .resource-panel {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .resource-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .panel-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .panel-title i {
            color: var(--accent);
            font-size: 1.5rem;
        }

        .create-resource-btn {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--glow-accent);
            position: relative;
            overflow: hidden;
        }

        .create-resource-btn::before {
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

        .create-resource-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 61, 255, 0.4);
        }

        .create-resource-btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        /* Resource Form */
        .resource-form-section {
            background: rgba(30, 30, 60, 0.3);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .resource-form-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .resource-form-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .resource-form-title i {
            color: var(--accent);
        }

        .resource-form-title::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--accent), transparent);
            border-radius: var(--radius-full);
        }

        .resource-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--foreground-muted);
        }

        .form-select,
        .form-input,
        .form-textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23FFFFFF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-select:focus,
        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 61, 255, 0.25);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-input[type="file"] {
            padding: 0.5rem;
            cursor: pointer;
        }

        .form-input[type="file"]::file-selector-button {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 1rem;
        }

        .form-input[type="file"]::file-selector-button:hover {
            background: linear-gradient(135deg, var(--accent), var(--primary));
            transform: translateY(-2px);
        }

        .form-button {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            box-shadow: var(--glow-accent);
            margin-top: 0.5rem;
            position: relative;
            overflow: hidden;
            grid-column: span 2;
            justify-self: center;
            width: auto;
        }

        .form-button::before {
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

        .form-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 61, 255, 0.4);
        }

        .form-button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        /* Resource List */
        .resource-list-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 1.5rem 0;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .resource-list-title i {
            color: var(--secondary);
        }

        .resource-list-title::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--secondary), transparent);
            border-radius: var(--radius-full);
        }

        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .resource-card {
            background: rgba(30, 30, 60, 0.3);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .resource-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .resource-cover {
            height: 160px;
            background: rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .resource-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.3s ease;
        }

        .resource-card:hover .resource-cover img {
            transform: scale(1.05);
        }

        .resource-category {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: rgba(255, 61, 255, 0.2);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--accent-light);
        }

        .resource-content {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .resource-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .resource-details {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-bottom: 0.75rem;
        }

        .resource-details p {
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .resource-details i {
            color: var(--secondary);
            width: 16px;
            text-align: center;
        }

        .resource-account {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-top: auto;
            margin-bottom: 0.75rem;
            max-height: 100px;
            overflow-y: auto;
            white-space: pre-line;
        }

        .resource-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border-top: 1px solid var(--border);
        }

        .resource-btn {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }

        .resource-download {
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: white;
        }

        .resource-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .resource-edit {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .resource-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .resource-toggle {
            background: linear-gradient(135deg, #64748b, #475569);
            color: white;
        }

        .resource-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        .resource-delete {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
        }

        .resource-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 255, 0.3);
        }

        .no-resources {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 3rem 1.5rem;
            text-align: center;
            background: rgba(30, 30, 60, 0.3);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .no-resources i {
            font-size: 3rem;
            color: var(--foreground-subtle);
            opacity: 0.5;
        }

        .no-resources p {
            color: var(--foreground-muted);
            font-size: 0.875rem;
            max-width: 400px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination-link {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.5rem 1rem;
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .pagination-link:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            border-color: var(--primary-light);
        }

        .pagination-info {
            font-size: 0.875rem;
            color: var(--foreground-muted);
        }

        /* Animations */
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .resource-form {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .form-button {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
                gap: 1rem;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
                gap: 0.75rem;
            }

            .back-to-home, .user-profile {
                width: 100%;
            }

            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .create-resource-btn {
                width: 100%;
            }

            .resource-grid {
                grid-template-columns: 1fr;
            }

            .resource-actions {
                flex-wrap: wrap;
            }

            .resource-btn {
                min-width: calc(50% - 0.25rem);
            }

            .pagination {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .resource-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .resource-btn {
                width: 100%;
            }
        }
    </style>
    <script>
        function toggleForm() {
            const formSection = document.querySelector('.resource-form-section');
            const formTitle = document.querySelector('.resource-form-title');
            formSection.classList.toggle('active');
            formTitle.textContent = formSection.classList.contains('active') ? 'Tạo Tài Nguyên Mới' : 'Tạo Tài Nguyên';
            document.querySelector('input[name="resource_id"]').value = '0';
            document.querySelector('input[name="resource_name"]').value = '';
            document.querySelector('select[name="category_id"]').value = '';
            document.querySelector('input[name="new_category"]').value = '';
            document.querySelector('textarea[name="account_details"]').value = '';
            document.querySelector('input[name="link"]').value = '';
            document.querySelector('input[name="issue_date"]').value = '';
            document.querySelector('input[name="expiry_date"]').value = '';
            document.querySelector('input[name="resource_file"]').value = '';
            document.querySelector('input[name="cover_image"]').value = '';
            toggleCategoryInput();
            
            // Scroll to form if opening
            if (formSection.classList.contains('active')) {
                formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function editResource(resourceId, resourceName, categoryId, categoryName, accountDetails, link, issueDate, expiryDate) {
            const formSection = document.querySelector('.resource-form-section');
            const formTitle = document.querySelector('.resource-form-title');
            formSection.classList.add('active');
            formTitle.innerHTML = '<i class="fas fa-edit"></i> Sửa Tài Nguyên';
            document.querySelector('input[name="resource_id"]').value = resourceId;
            document.querySelector('input[name="resource_name"]').value = resourceName;
            document.querySelector('select[name="category_id"]').value = categoryId || '';
            document.querySelector('input[name="new_category"]').value = categoryId ? '' : categoryName || '';
            document.querySelector('textarea[name="account_details"]').value = accountDetails || '';
            document.querySelector('input[name="link"]').value = link || '';
            document.querySelector('input[name="issue_date"]').value = issueDate || '';
            document.querySelector('input[name="expiry_date"]').value = expiryDate || '';
            document.querySelector('input[name="resource_file"]').value = '';
            document.querySelector('input[name="cover_image"]').value = '';
            toggleCategoryInput();
            
            // Scroll to form
            formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function toggleCategoryInput() {
            const categorySelect = document.getElementById('category_id');
            const newCategoryInput = document.getElementById('new_category_container');
            newCategoryInput.style.display = categorySelect.value === 'new' ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleCategoryInput();
            
            // Animate elements on page load
            const elements = document.querySelectorAll('.resource-panel, .resource-form-section, .resource-card');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Auto-hide messages after 5 seconds
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
            
            // Create particle effect
            createParticles();
        });
        
        // Function to create particle effect
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.className = 'particles';
            particlesContainer.style.position = 'fixed';
            particlesContainer.style.top = '0';
            particlesContainer.style.left = '0';
            particlesContainer.style.width = '100%';
            particlesContainer.style.height = '100%';
            particlesContainer.style.pointerEvents = 'none';
            particlesContainer.style.zIndex = '0';
            document.body.appendChild(particlesContainer);
            
            const particleCount = 30;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
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
                
                // Style
                particle.style.position = 'absolute';
                particle.style.borderRadius = '50%';
                particle.style.opacity = '0.3';
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon floating"><i class="fas fa-folder-open"></i></div>
                <div class="logo-text">UMTERS Tài Nguyên</div>
            </div>
            
            <div class="header-actions">
                <a href="../dashboard.php" class="back-to-home">
                    <i class="fas fa-arrow-left"></i> Trở về trang chủ
                </a>
                
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                    <div class="user-info">
                        <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                        <p class="user-role">
                            <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php elseif (!empty($resource_message)): ?>
                <div class="<?php echo strpos($resource_message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>">
                    <?php echo htmlspecialchars($resource_message); ?>
                </div>
            <?php endif; ?>

            <!-- Resource Panel -->
            <div class="resource-panel">
                <div class="panel-header">
                    <h2 class="panel-title"><i class="fas fa-folder-open"></i> Quản Lý Tài Nguyên</h2>
                    <button class="create-resource-btn" onclick="toggleForm()">
                        <i class="fas fa-plus"></i> Tạo Tài Nguyên Mới
                    </button>
                </div>
                
                <!-- Resource Form Section -->
                <div class="resource-form-section">
                    <div class="resource-form-title">
                        <i class="fas fa-folder-plus"></i> Tạo Tài Nguyên Mới
                    </div>
                    <form class="resource-form" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="resource_id" value="0">
                        
                        <div class="form-group">
                            <label class="form-label">Tên tài nguyên</label>
                            <input type="text" name="resource_name" placeholder="Nhập tên tài nguyên" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Danh mục</label>
                            <select name="category_id" id="category_id" class="form-select" onchange="toggleCategoryInput()">
                                <option value="">Chọn danh mục</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                <?php endforeach; ?>
                                <option value="new">Thêm danh mục mới</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="new_category_container" style="display: none;">
                            <label class="form-label">Danh mục mới</label>
                            <input type="text" name="new_category" id="new_category" placeholder="Nhập danh mục mới" class="form-input">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Thông tin tài khoản</label>
                            <textarea name="account_details" placeholder="Nhập thông tin tài khoản (tài khoản, mật khẩu,...)" class="form-textarea"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Link (nếu có)</label>
                            <input type="url" name="link" placeholder="Nhập URL (http://...)" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Ngày cấp</label>
                            <input type="datetime-local" name="issue_date" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Ngày hết hạn (tùy chọn)</label>
                            <input type="datetime-local" name="expiry_date" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">File tài nguyên (hỗ trợ: txt, pdf, doc, docx, jpg, png)</label>
                            <input type="file" name="resource_file" accept=".txt,.pdf,.doc,.docx,.jpg,.png" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Hình ảnh bìa (hỗ trợ: jpg, png)</label>
                            <input type="file" name="cover_image" accept=".jpg,.jpeg,.png" class="form-input">
                        </div>
                        
                        <button type="submit" name="save_resource" class="form-button">
                            <i class="fas fa-save"></i> Lưu Tài Nguyên
                        </button>
                    </form>
                </div>
                
                <!-- Resource List -->
                <div class="resource-list-title">
                    <i class="fas fa-list"></i> Danh Sách Tài Nguyên
                </div>
                
                <?php if (empty($resources)): ?>
                    <div class="no-resources">
                        <i class="fas fa-folder-open"></i>
                        <p>Chưa có tài nguyên nào được tạo. Hãy tạo tài nguyên đầu tiên của bạn!</p>
                    </div>
                <?php else: ?>
                    <div class="resource-grid">
                        <?php foreach ($resources as $resource): ?>
                            <div class="resource-card">
                                <?php if ($resource['cover_image']): ?>
                                    <div class="resource-cover">
                                        <img src="<?php echo htmlspecialchars($resource['cover_image']); ?>" alt="Cover Image">
                                        <div class="resource-category"><?php echo htmlspecialchars($resource['category_name']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="resource-cover" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, rgba(112, 0, 255, 0.1), rgba(0, 224, 255, 0.1));">
                                        <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--primary-light);"></i>
                                        <div class="resource-category"><?php echo htmlspecialchars($resource['category_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="resource-content">
                                    <h3 class="resource-name"><?php echo htmlspecialchars($resource['resource_name']); ?></h3>
                                    
                                    <div class="resource-details">
                                        <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($resource['username']); ?></p>
                                        <p><i class="far fa-calendar-plus"></i> Cấp: <?php echo date('d/m/Y H:i', strtotime($resource['issue_date'])); ?></p>
                                        <?php if ($resource['expiry_date']): ?>
                                            <p><i class="far fa-calendar-times"></i> Hết hạn: <?php echo date('d/m/Y H:i', strtotime($resource['expiry_date'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($resource['account_details']): ?>
                                        <div class="resource-account"><?php echo nl2br(htmlspecialchars($resource['account_details'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="resource-actions">
                                    <?php if ($resource['file_path']): ?>
                                        <a href="<?php echo htmlspecialchars($resource['file_path']); ?>" download class="resource-btn resource-download">
                                            <i class="fas fa-download"></i> Tải về
                                        </a>
                                    <?php elseif ($resource['link']): ?>
                                        <a href="<?php echo htmlspecialchars($resource['link']); ?>" target="_blank" class="resource-btn resource-download">
                                            <i class="fas fa-link"></i> Truy cập
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($resource['user_id'] == $user_id): ?>
                                        <a href="javascript:void(0);" onclick="editResource(<?php echo $resource['id']; ?>, '<?php echo addslashes($resource['resource_name']); ?>', '<?php echo $resource['category_id']; ?>', '<?php echo addslashes($resource['category_name']); ?>', '<?php echo addslashes($resource['account_details']); ?>', '<?php echo addslashes($resource['link']); ?>', '<?php echo date('Y-m-d\TH:i', strtotime($resource['issue_date'])); ?>', '<?php echo $resource['expiry_date'] ? date('Y-m-d\TH:i', strtotime($resource['expiry_date'])) : ''; ?>')" class="resource-btn resource-edit">
                                            <i class="fas fa-edit"></i> Sửa
                                        </a>
                                        
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                                            <input type="hidden" name="is_hidden" value="<?php echo $resource['is_hidden'] ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_hide" class="resource-btn resource-toggle">
                                                <i class="fas <?php echo $resource['is_hidden'] ? 'fa-eye' : 'fa-eye-slash'; ?>"></i>
                                                <?php echo $resource['is_hidden'] ? 'Hiển thị' : 'Ẩn'; ?>
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                                            <button type="submit" name="delete_resource" class="resource-btn resource-delete" onclick="return confirm('Bạn có chắc muốn xóa tài nguyên này? Hành động này không thể hoàn tác.');">
                                                <i class="fas fa-trash-alt"></i> Xóa
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Phân trang -->
                    <?php if ($total_pages_resources > 1): ?>
                        <div class="pagination">
                            <?php if ($page_resources > 1): ?>
                                <a href="/tainguyen?page_resources=<?php echo $page_resources - 1; ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i> Trang trước
                                </a>
                            <?php endif; ?>
                            
                            <span class="pagination-info">Trang <?php echo $page_resources; ?> / <?php echo $total_pages_resources; ?></span>
                            
                            <?php if ($page_resources < $total_pages_resources): ?>
                                <a href="/tainguyen?page_resources=<?php echo $page_resources + 1; ?>" class="pagination-link">
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
