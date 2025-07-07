<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lấy thông tin user
$user_id = (int)$_SESSION['user_id'];
$sql_user = "SELECT is_super_admin FROM users WHERE id = $user_id";
$result_user = mysqli_query($conn, $sql_user);
$user = mysqli_fetch_assoc($result_user);
$is_super_admin = $user['is_super_admin'];

// Tạo thư mục lưu trữ file nếu chưa tồn tại
$upload_dir = "uploads/automation/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Khởi tạo biến thông báo
$error = "";
$success = "";

// Xử lý tạo công cụ mới
if (isset($_POST['create_tool'])) {
    if (!$is_super_admin) {
        $error = "Bạn không có quyền thực hiện hành động này!";
    } else {
        $tool_name = mysqli_real_escape_string($conn, $_POST['tool_name']);
        $tool_description = mysqli_real_escape_string($conn, $_POST['tool_description']);
        $sql = "INSERT INTO automation_tools (name, description, created_by) VALUES ('$tool_name', '$tool_description', $user_id)";
        if (mysqli_query($conn, $sql)) {
            $success = "Tạo công cụ thành công!";
        } else {
            $error = "Lỗi: " . mysqli_error($conn);
        }
    }
}

// Xử lý cập nhật công cụ
if (isset($_POST['update_tool'])) {
    if (!$is_super_admin) {
        $error = "Bạn không có quyền thực hiện hành động này!";
    } else {
        $tool_id = (int)$_POST['tool_id'];
        $tool_name = mysqli_real_escape_string($conn, $_POST['tool_name']);
        $tool_description = mysqli_real_escape_string($conn, $_POST['tool_description']);
        $sql = "UPDATE automation_tools SET name = '$tool_name', description = '$tool_description' WHERE id = $tool_id";
        if (mysqli_query($conn, $sql)) {
            $success = "Cập nhật công cụ thành công!";
        } else {
            $error = "Lỗi: " . mysqli_error($conn);
        }
    }
}

// Xử lý xóa công cụ
if (isset($_POST['delete_tool'])) {
    if (!$is_super_admin) {
        $error = "Bạn không có quyền thực hiện hành động này!";
    } else {
        $tool_id = (int)$_POST['tool_id'];
        $sql_files = "SELECT file_path FROM automation_files WHERE tool_id = $tool_id";
        $result_files = mysqli_query($conn, $sql_files);
        while ($file = mysqli_fetch_assoc($result_files)) {
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
        }
        $sql = "DELETE FROM automation_tools WHERE id = $tool_id";
        if (mysqli_query($conn, $sql)) {
            $success = "Xóa công cụ thành công!";
        } else {
            $error = "Lỗi: " . mysqli_error($conn);
        }
    }
}

// Xử lý tải lên file
if (isset($_POST['upload_file'])) {
    if (!$is_super_admin) {
        $error = "Bạn không có quyền thực hiện hành động này!";
    } else {
        $tool_id = (int)$_POST['tool_id'];
        $version = mysqli_real_escape_string($conn, $_POST['version']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $check_tool = mysqli_query($conn, "SELECT id FROM automation_tools WHERE id = $tool_id");
        if (mysqli_num_rows($check_tool) == 0) {
            $error = "Công cụ không tồn tại!";
        } else if (!isset($_FILES['file']) || $_FILES['file']['error'] != 0) {
            $error = "Vui lòng chọn file để tải lên!";
        } else {
            $file = $_FILES['file'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_size = $file['size'];
            $file_type = $file['type'];
            $unique_name = time() . '_' . $file_name;
            $file_path = $upload_dir . $unique_name;
            if (move_uploaded_file($file_tmp, $file_path)) {
                $sql = "INSERT INTO automation_files (tool_id, file_name, file_path, file_type, file_size, version, description, uploaded_by) 
                        VALUES ($tool_id, '$file_name', '$file_path', '$file_type', $file_size, '$version', '$description', $user_id)";
                if (mysqli_query($conn, $sql)) {
                    $success = "Tải lên file thành công!";
                } else {
                    $error = "Lỗi khi lưu thông tin file: " . mysqli_error($conn);
                    unlink($file_path);
                }
            } else {
                $error = "Lỗi khi tải lên file!";
            }
        }
    }
}

// Xử lý xóa file
if (isset($_POST['delete_file'])) {
    if (!$is_super_admin) {
        $error = "Bạn không có quyền thực hiện hành động này!";
    } else {
        $file_id = (int)$_POST['file_id'];
        $sql_file = "SELECT file_path FROM automation_files WHERE id = $file_id";
        $result_file = mysqli_query($conn, $sql_file);
        if ($file = mysqli_fetch_assoc($result_file)) {
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
            $sql = "DELETE FROM automation_files WHERE id = $file_id";
            if (mysqli_query($conn, $sql)) {
                $success = "Xóa file thành công!";
            } else {
                $error = "Lỗi khi xóa file: " . mysqli_error($conn);
            }
        } else {
            $error = "File không tồn tại!";
        }
    }
}

// Lấy danh sách công cụ
$sql_tools = "SELECT t.*, u.username as creator_name, 
              (SELECT COUNT(*) FROM automation_files WHERE tool_id = t.id) as file_count 
              FROM automation_tools t 
              JOIN users u ON t.created_by = u.id 
              ORDER BY t.created_at DESC";
$result_tools = mysqli_query($conn, $sql_tools);

// Lấy thông tin công cụ cụ thể nếu có
$selected_tool = null;
$tool_files = [];
if (isset($_GET['tool_id'])) {
    $tool_id = (int)$_GET['tool_id'];
    $sql_tool = "SELECT t.*, u.username as creator_name 
                 FROM automation_tools t 
                 JOIN users u ON t.created_by = u.id 
                 WHERE t.id = $tool_id";
    $result_tool = mysqli_query($conn, $sql_tool);
    if ($result_tool && mysqli_num_rows($result_tool) > 0) {
        $selected_tool = mysqli_fetch_assoc($result_tool);
        $sql_files = "SELECT f.*, u.username as uploader_name 
                      FROM automation_files f 
                      JOIN users u ON f.uploaded_by = u.id 
                      WHERE f.tool_id = $tool_id 
                      ORDER BY f.version DESC, f.uploaded_at DESC";
        $result_files = mysqli_query($conn, $sql_files);
        while ($file = mysqli_fetch_assoc($result_files)) {
            $tool_files[] = $file;
        }
    }
}

// Hàm định dạng kích thước file
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    else return $bytes . ' bytes';
}

// Hàm lấy icon cho loại file
function getFileIcon($fileType) {
    $iconClass = 'fa-file';
    if (strpos($fileType, 'image/') !== false) $iconClass = 'fa-file-image';
    elseif (strpos($fileType, 'video/') !== false) $iconClass = 'fa-file-video';
    elseif (strpos($fileType, 'audio/') !== false) $iconClass = 'fa-file-audio';
    elseif (strpos($fileType, 'text/') !== false) $iconClass = 'fa-file-alt';
    elseif (strpos($fileType, 'application/pdf') !== false) $iconClass = 'fa-file-pdf';
    elseif (strpos($fileType, 'application/json') !== false) $iconClass = 'fa-file-code';
    return $iconClass;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VinPhim - Quản lý Tự động hóa</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --danger: #FF3D57;
            --danger-light: #FF5D77;
            --danger-dark: #E01F3D;
            --success: #00FF85;
            --success-light: #4DFFAA;
            --success-dark: #00CC6A;
            --warning: #FFB800;
            --warning-light: #FFD155;
            --warning-dark: #E6A600;
            
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
            --glow-danger: 0 0 20px rgba(255, 61, 87, 0.5);
            --glow-success: 0 0 20px rgba(0, 255, 133, 0.5);
            
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
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
            min-height: 100vh;
        }

        /* Particles */
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

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
            100% { transform: translateY(0px); }
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes zoomIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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

        /* Container */
        .auto-dashboard-container {
            width: 100%;
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header */
        .auto-header {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            animation: slideUp 0.6s ease-out;
        }

        .auto-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .auto-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--foreground), var(--primary-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auto-subtitle {
            font-size: 1.1rem;
            color: var(--foreground-muted);
            font-weight: 500;
        }

        /* Messages */
        .auto-message-container {
            margin-bottom: 2rem;
        }

        .auto-error-message,
        .auto-success-message {
            padding: 1.25rem 1.75rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            position: relative;
            padding-left: 3.5rem;
            animation: slideUp 0.5s ease-out;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            font-weight: 500;
        }

        .auto-error-message {
            background: rgba(255, 61, 87, 0.1);
            border-color: var(--danger-dark);
            color: var(--danger);
            box-shadow: 0 0 20px rgba(255, 61, 87, 0.2);
        }

        .auto-success-message {
            background: rgba(0, 255, 133, 0.1);
            border-color: var(--success-dark);
            color: var(--success);
            box-shadow: 0 0 20px rgba(0, 255, 133, 0.2);
        }

        .auto-error-message::before,
        .auto-success-message::before {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        .auto-error-message::before {
            content: "\f071";
            color: var(--danger);
        }

        .auto-success-message::before {
            content: "\f00c";
            color: var(--success);
        }

        /* Layout */
        .auto-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 2rem;
            animation: slideUp 0.8s ease-out;
        }

        /* Sidebar */
        .auto-sidebar {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            height: fit-content;
            position: sticky;
            top: 2rem;
        }

        .auto-sidebar-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .auto-sidebar-title i {
            color: var(--primary-light);
            font-size: 1.75rem;
        }

        .auto-tool-list {
            list-style: none;
            margin-bottom: 2rem;
        }

        .auto-tool-item {
            margin-bottom: 0.75rem;
        }

        .auto-tool-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--foreground-muted);
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-light);
        }

        .auto-tool-link:hover {
            background: rgba(112, 0, 255, 0.1);
            color: var(--foreground);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .auto-tool-link.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: var(--foreground);
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .auto-tool-link i {
            margin-right: 0.75rem;
            font-size: 1.1rem;
            color: var(--primary-light);
        }

        .auto-tool-count {
            margin-left: auto;
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            color: var(--foreground);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.8rem;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }

        .auto-add-tool-btn {
            width: 100%;
            padding: 1rem 1.5rem;
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
            color: var(--foreground);
            border: none;
            border-radius: var(--radius-full);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .auto-add-tool-btn:hover {
            background: linear-gradient(90deg, var(--secondary-light), var(--secondary));
            transform: translateY(-3px);
            box-shadow: var(--glow-secondary);
        }

        /* Main Content */
        .auto-main-content {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
        }

        .auto-tool-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .auto-tool-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--foreground), var(--primary-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auto-tool-actions {
            display: flex;
            gap: 1rem;
        }

        .auto-btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-full);
            cursor: pointer;
            border: none;
            color: var(--foreground);
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .auto-btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .auto-btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-3px);
            box-shadow: var(--glow);
        }

        .auto-btn-danger {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
        }

        .auto-btn-danger:hover {
            background: linear-gradient(90deg, var(--danger-light), var(--danger));
            transform: translateY(-3px);
            box-shadow: var(--glow-danger);
        }

        /* Tool Info */
        .auto-tool-info {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            animation: zoomIn 0.6s ease-out;
        }

        .auto-info-item {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: start;
        }

        .auto-info-item:last-child {
            margin-bottom: 0;
        }

        .auto-info-label {
            font-weight: 600;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .auto-info-label i {
            color: var(--primary-light);
        }

        .auto-info-value {
            color: var(--foreground);
            line-height: 1.6;
        }

        /* Form Container */
        .auto-form-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            padding: 2rem;
            animation: slideUp 0.8s ease-out;
        }

        .auto-form-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .auto-form-title i {
            color: var(--secondary);
            font-size: 1.75rem;
        }

        .auto-form-group {
            margin-bottom: 1.5rem;
        }

        .auto-form-label {
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--foreground);
            display: block;
            font-size: 0.95rem;
        }

        .auto-form-input, .auto-form-textarea {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            font-size: 1rem;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }

        .auto-form-input:focus, .auto-form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }

        .auto-form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .auto-form-submit {
            padding: 1rem 2rem;
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            color: var(--foreground);
            border: none;
            border-radius: var(--radius-full);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .auto-form-submit:hover {
            background: linear-gradient(90deg, var(--accent-light), var(--accent));
            transform: translateY(-3px);
            box-shadow: var(--glow-accent);
        }

        /* File List */
        .auto-file-list {
            animation: slideUp 1s ease-out;
        }

        .auto-file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
        }

        .auto-file-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .auto-file-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .auto-file-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .auto-file-card:hover::before {
            transform: scaleX(1);
        }

        .auto-file-icon {
            font-size: 2.5rem;
            color: var(--primary-light);
            margin-bottom: 1rem;
            text-align: center;
            display: block;
        }

        .auto-file-name {
            font-weight: 600;
            margin-bottom: 1rem;
            word-break: break-all;
            color: var(--foreground);
            font-size: 1.1rem;
            line-height: 1.4;
        }

        .auto-file-version {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.85rem;
            color: var(--foreground);
            font-weight: 600;
            margin-bottom: 1rem;
            display: inline-block;
            box-shadow: var(--shadow-sm);
        }

        .auto-file-meta {
            margin-bottom: 1rem;
        }

        .auto-file-meta span {
            display: block;
            font-size: 0.9rem;
            color: var(--foreground-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .auto-file-meta i {
            color: var(--primary-light);
            width: 16px;
        }

        .auto-file-description {
            font-size: 0.95rem;
            color: var(--foreground-muted);
            margin-bottom: 1.5rem;
            line-height: 1.6;
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
        }

        .auto-file-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .auto-file-download {
            text-decoration: none;
            color: var(--foreground);
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
        }

        .auto-file-download:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .auto-file-delete {
            color: var(--danger);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .auto-file-delete:hover {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
            color: var(--foreground);
            transform: translateY(-2px);
            box-shadow: var(--glow-danger);
        }

        /* Empty State */
        .auto-empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--foreground-muted);
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            animation: zoomIn 0.6s ease-out;
        }

        .auto-empty-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .auto-empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        .auto-empty-description {
            font-size: 1rem;
            margin-bottom: 2rem;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: rgba(30, 30, 60, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            max-width: 500px;
            width: 90%;
            padding: 2rem;
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--foreground);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--foreground-muted);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-full);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }

        .modal-btn-cancel {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
        }

        .modal-btn-cancel:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-btn-danger {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
            color: var(--foreground);
        }

        .modal-btn-danger:hover {
            background: linear-gradient(90deg, var(--danger-light), var(--danger));
            transform: translateY(-2px);
            box-shadow: var(--glow-danger);
        }

        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .auto-layout {
                grid-template-columns: 280px 1fr;
            }
        }

        @media (max-width: 992px) {
            .auto-layout {
                grid-template-columns: 1fr;
            }
            
            .auto-sidebar {
                position: static;
                margin-bottom: 2rem;
            }
        }

        @media (max-width: 768px) {
            .auto-dashboard-container {
                padding: 0 1rem;
                margin: 1rem auto;
            }

            .auto-title {
                font-size: 2rem;
            }

            .auto-tool-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .auto-tool-actions {
                width: 100%;
                justify-content: space-between;
            }

            .auto-info-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .auto-file-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .auto-title {
                font-size: 1.75rem;
            }

            .auto-header,
            .auto-sidebar,
            .auto-main-content,
            .auto-form-container {
                padding: 1.5rem;
            }

            .auto-file-card {
                padding: 1.25rem;
            }

            .auto-btn,
            .auto-form-submit {
                padding: 0.75rem 1.25rem;
                font-size: 0.95rem;
            }
        }

        /* Animation delays for staggered effects */
        .auto-file-card:nth-child(1) { animation-delay: 0.1s; }
        .auto-file-card:nth-child(2) { animation-delay: 0.2s; }
        .auto-file-card:nth-child(3) { animation-delay: 0.3s; }
        .auto-file-card:nth-child(4) { animation-delay: 0.4s; }
        .auto-file-card:nth-child(5) { animation-delay: 0.5s; }
        .auto-file-card:nth-child(6) { animation-delay: 0.6s; }
        .auto-file-card:nth-child(n+7) { animation-delay: 0.7s; }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="auto-dashboard-container">
        <!-- Header -->
        <div class="auto-header">
            <h1 class="auto-title">
                <i class="fas fa-robot"></i> Quản lý Tự động hóa
            </h1>
            <p class="auto-subtitle">Quản lý công cụ và file tự động hóa một cách hiệu quả</p>
        </div>

        <?php include 'taskbar.php'; ?>

        <!-- Messages -->
        <div class="auto-message-container">
            <?php if (!empty($error)) echo "<div class='auto-error-message'>$error</div>"; ?>
            <?php if (!empty($success)) echo "<div class='auto-success-message'>$success</div>"; ?>
        </div>

        <div class="auto-layout">
            <!-- Sidebar -->
            <div class="auto-sidebar">
                <div class="auto-sidebar-title">
                    <i class="fas fa-tools"></i>
                    Danh sách công cụ
                </div>
                <ul class="auto-tool-list">
                    <?php if (mysqli_num_rows($result_tools) > 0): ?>
                        <?php while ($tool = mysqli_fetch_assoc($result_tools)): ?>
                            <li class="auto-tool-item">
                                <a href="?tool_id=<?php echo $tool['id']; ?>" class="auto-tool-link <?php echo (isset($_GET['tool_id']) && $_GET['tool_id'] == $tool['id']) ? 'active' : ''; ?>">
                                    <i class="fas fa-cog"></i>
                                    <?php echo htmlspecialchars($tool['name']); ?>
                                    <span class="auto-tool-count"><?php echo $tool['file_count']; ?></span>
                                </a>
                            </li>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <li class="auto-empty-state" style="padding: 2rem 1rem;">
                            <div class="auto-empty-icon"><i class="fas fa-tools"></i></div>
                            <div class="auto-empty-title">Chưa có công cụ nào</div>
                            <div class="auto-empty-description">Hãy tạo công cụ đầu tiên</div>
                        </li>
                    <?php endif; ?>
                </ul>
                <?php if ($is_super_admin): ?>
                    <button class="auto-add-tool-btn" onclick="showAddToolForm()">
                        <i class="fas fa-plus"></i>
                        Thêm công cụ mới
                    </button>
                <?php endif; ?>
            </div>

            <!-- Main Content -->
            <div class="auto-main-content">
                <?php if ($selected_tool): ?>
                    <div class="auto-tool-header">
                        <div class="auto-tool-title">
                            <i class="fas fa-cog"></i>
                            <?php echo htmlspecialchars($selected_tool['name']); ?>
                        </div>
                        <?php if ($is_super_admin): ?>
                            <div class="auto-tool-actions">
                                <button class="auto-btn auto-btn-primary" onclick="showEditToolForm(<?php echo $selected_tool['id']; ?>, '<?php echo addslashes($selected_tool['name']); ?>', '<?php echo addslashes($selected_tool['description']); ?>')">
                                    <i class="fas fa-edit"></i> Sửa
                                </button>
                                <button class="auto-btn auto-btn-danger" onclick="confirmDeleteTool(<?php echo $selected_tool['id']; ?>, '<?php echo addslashes($selected_tool['name']); ?>')">
                                    <i class="fas fa-trash"></i> Xóa
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="auto-tool-info">
                        <div class="auto-info-item">
                            <div class="auto-info-label">
                                <i class="fas fa-align-left"></i> Mô tả:
                            </div>
                            <div class="auto-info-value"><?php echo nl2br(htmlspecialchars($selected_tool['description'])); ?></div>
                        </div>
                        <div class="auto-info-item">
                            <div class="auto-info-label">
                                <i class="fas fa-user"></i> Người tạo:
                            </div>
                            <div class="auto-info-value"><?php echo htmlspecialchars($selected_tool['creator_name']); ?></div>
                        </div>
                        <div class="auto-info-item">
                            <div class="auto-info-label">
                                <i class="fas fa-calendar-plus"></i> Ngày tạo:
                            </div>
                            <div class="auto-info-value"><?php echo date('d/m/Y H:i', strtotime($selected_tool['created_at'])); ?></div>
                        </div>
                        <div class="auto-info-item">
                            <div class="auto-info-label">
                                <i class="fas fa-clock"></i> Cập nhật:
                            </div>
                            <div class="auto-info-value"><?php echo date('d/m/Y H:i', strtotime($selected_tool['updated_at'])); ?></div>
                        </div>
                    </div>

                    <?php if ($is_super_admin): ?>
                        <div class="auto-form-container">
                            <div class="auto-form-title">
                                <i class="fas fa-cloud-upload-alt"></i>
                                Tải lên phiên bản mới
                            </div>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="tool_id" value="<?php echo $selected_tool['id']; ?>">
                                <div class="auto-form-group">
                                    <label class="auto-form-label">Chọn file:</label>
                                    <input type="file" name="file" class="auto-form-input" required>
                                </div>
                                <div class="auto-form-group">
                                    <label class="auto-form-label">Phiên bản:</label>
                                    <input type="text" name="version" class="auto-form-input" placeholder="Ví dụ: 1.0.0" required>
                                </div>
                                <div class="auto-form-group">
                                    <label class="auto-form-label">Mô tả:</label>
                                    <textarea name="description" class="auto-form-textarea" placeholder="Mô tả phiên bản"></textarea>
                                </div>
                                <button type="submit" name="upload_file" class="auto-form-submit">
                                    <i class="fas fa-upload"></i>
                                    Tải lên
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="auto-file-list">
                        <?php if (count($tool_files) > 0): ?>
                            <div class="auto-file-grid">
                                <?php foreach ($tool_files as $file): ?>
                                    <div class="auto-file-card">
                                        <div class="auto-file-icon">
                                            <i class="fas <?php echo getFileIcon($file['file_type']); ?>"></i>
                                        </div>
                                        <div class="auto-file-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                        <div class="auto-file-version">v<?php echo htmlspecialchars($file['version']); ?></div>
                                        <div class="auto-file-meta">
                                            <span>
                                                <i class="fas fa-user"></i>
                                                <?php echo htmlspecialchars($file['uploader_name']); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($file['uploaded_at'])); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-hdd"></i>
                                                <?php echo formatFileSize($file['file_size']); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($file['description'])): ?>
                                            <div class="auto-file-description"><?php echo nl2br(htmlspecialchars($file['description'])); ?></div>
                                        <?php endif; ?>
                                        <div class="auto-file-actions">
                                            <a href="<?php echo $file['file_path']; ?>" class="auto-file-download" download>
                                                <i class="fas fa-download"></i>
                                                Tải xuống
                                            </a>
                                            <?php if ($is_super_admin): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xác nhận xóa file?');">
                                                    <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                                    <button type="submit" name="delete_file" class="auto-file-delete">
                                                        <i class="fas fa-trash"></i>
                                                        Xóa
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="auto-empty-state">
                                <div class="auto-empty-icon"><i class="fas fa-file-upload"></i></div>
                                <div class="auto-empty-title">Chưa có file nào</div>
                                <div class="auto-empty-description">Hãy tải lên file đầu tiên cho công cụ này</div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="auto-empty-state">
                        <div class="auto-empty-icon"><i class="fas fa-tools"></i></div>
                        <div class="auto-empty-title">Chưa chọn công cụ nào</div>
                        <div class="auto-empty-description">Vui lòng chọn một công cụ từ danh sách bên trái</div>
                        <?php if ($is_super_admin): ?>
                            <button class="auto-btn auto-btn-primary" onclick="showAddToolForm()">
                                <i class="fas fa-plus"></i>
                                Tạo công cụ mới
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Tool Modal -->
    <div id="addToolModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-plus"></i>
                    Thêm công cụ mới
                </h3>
                <button class="modal-close" onclick="hideAddToolForm()">&times;</button>
            </div>
            <form method="POST">
                <div class="auto-form-group">
                    <label class="auto-form-label">Tên công cụ:</label>
                    <input type="text" name="tool_name" class="auto-form-input" required>
                </div>
                <div class="auto-form-group">
                    <label class="auto-form-label">Mô tả:</label>
                    <textarea name="tool_description" class="auto-form-textarea"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="hideAddToolForm()">Hủy</button>
                    <button type="submit" name="create_tool" class="auto-btn auto-btn-primary">
                        <i class="fas fa-plus"></i>
                        Tạo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Tool Modal -->
    <div id="editToolModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-edit"></i>
                    Sửa công cụ
                </h3>
                <button class="modal-close" onclick="hideEditToolForm()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" id="edit_tool_id" name="tool_id">
                <div class="auto-form-group">
                    <label class="auto-form-label">Tên công cụ:</label>
                    <input type="text" id="edit_tool_name" name="tool_name" class="auto-form-input" required>
                </div>
                <div class="auto-form-group">
                    <label class="auto-form-label">Mô tả:</label>
                    <textarea id="edit_tool_description" name="tool_description" class="auto-form-textarea"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="hideEditToolForm()">Hủy</button>
                    <button type="submit" name="update_tool" class="auto-btn auto-btn-primary">
                        <i class="fas fa-save"></i>
                        Cập nhật
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Tool Modal -->
    <div id="deleteToolModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Xác nhận xóa
                </h3>
                <button class="modal-close" onclick="hideDeleteToolForm()">&times;</button>
            </div>
            <p style="margin-bottom: 1.5rem; color: var(--foreground-muted);">
                Bạn có chắc muốn xóa công cụ "<span id="delete_tool_name" style="color: var(--foreground); font-weight: 600;"></span>"?
                <br><small style="color: var(--danger);">Hành động này không thể hoàn tác!</small>
            </p>
            <form method="POST">
                <input type="hidden" id="delete_tool_id" name="tool_id">
                <div class="modal-footer">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="hideDeleteToolForm()">Hủy</button>
                    <button type="submit" name="delete_tool" class="modal-btn modal-btn-danger">
                        <i class="fas fa-trash"></i>
                        Xóa
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Create particles background
            createParticles();
            
            // Auto-hide messages
            autoHideMessages();
            
            // Handle form submissions with loading states
            enhanceFormSubmissions();
        });

        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                particle.style.opacity = 0;
                
                particlesContainer.appendChild(particle);
                
                setTimeout(() => {
                    particle.style.transition = 'opacity 1s ease';
                    particle.style.opacity = 0.3;
                    
                    setInterval(() => {
                        const newPosX = parseFloat(particle.style.left) + (Math.random() - 0.5) * 0.2;
                        const newPosY = parseFloat(particle.style.top) + (Math.random() - 0.5) * 0.2;
                        
                        if (newPosX >= 0 && newPosX <= 100) particle.style.left = `${newPosX}%`;
                        if (newPosY >= 0 && newPosY <= 100) particle.style.top = `${newPosY}%`;
                    }, 2000);
                }, delay * 1000);
            }
        }

        function autoHideMessages() {
            setTimeout(() => {
                const messages = document.querySelectorAll('.auto-error-message, .auto-success-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.remove();
                        }
                    }, 500);
                });
            }, 5000);
        }

        function enhanceFormSubmissions() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"], input[type="submit"]');
                    if (submitBtn) {
                        const originalContent = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<span class="loading-spinner"></span> Đang xử lý...';
                        submitBtn.disabled = true;
                        
                        // Re-enable after 10 seconds as fallback
                        setTimeout(() => {
                            if (submitBtn.disabled) {
                                submitBtn.innerHTML = originalContent;
                                submitBtn.disabled = false;
                            }
                        }, 10000);
                    }
                });
            });
        }

        function showAddToolForm() {
            const modal = document.getElementById('addToolModal');
            modal.classList.add('show');
        }

        function hideAddToolForm() {
            const modal = document.getElementById('addToolModal');
            modal.classList.remove('show');
        }

        function showEditToolForm(id, name, description) {
            document.getElementById('edit_tool_id').value = id;
            document.getElementById('edit_tool_name').value = name;
            document.getElementById('edit_tool_description').value = description;
            const modal = document.getElementById('editToolModal');
            modal.classList.add('show');
        }

        function hideEditToolForm() {
            const modal = document.getElementById('editToolModal');
            modal.classList.remove('show');
        }

        function confirmDeleteTool(id, name) {
            document.getElementById('delete_tool_id').value = id;
            document.getElementById('delete_tool_name').textContent = name;
            const modal = document.getElementById('deleteToolModal');
            modal.classList.add('show');
        }

        function hideDeleteToolForm() {
            const modal = document.getElementById('deleteToolModal');
            modal.classList.remove('show');
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>