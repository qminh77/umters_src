<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Kích hoạt hiển thị lỗi để debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Lấy user_id
$user_id = (int)$_SESSION['user_id'];

// Thư mục lưu file riêng cho từng tài khoản
$user_dir = "downloads/fileuser/$user_id/";
if (!file_exists($user_dir)) {
    mkdir($user_dir, 0777, true);
}

// Khởi tạo biến thông báo
$success_message = '';
$error_message = '';

// Xử lý tải lên file
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_file']) && isset($_FILES['user_file'])) {
    $file = $_FILES['user_file'];
    if ($file['error'] == UPLOAD_ERR_OK) {
        $filename = basename($file['name']);
        $target_file = $user_dir . $filename;

        // Kiểm tra file đã tồn tại
        if (file_exists($target_file)) {
            $error_message = "File đã tồn tại. Vui lòng chọn file khác hoặc đổi tên.";
        } else {
            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                // Lưu thông tin file vào database
                $filename = mysqli_real_escape_string($conn, $filename);
                $file_path = mysqli_real_escape_string($conn, $target_file);
                $sql = "INSERT INTO user_files (user_id, filename, file_path, upload_time) VALUES ($user_id, '$filename', '$file_path', NOW())";
                if (mysqli_query($conn, $sql)) {
                    $success_message = "Tải file lên thành công!";
                } else {
                    $error_message = "Lỗi khi lưu thông tin file vào database: " . mysqli_error($conn);
                    unlink($target_file); // Xóa file nếu lưu database thất bại
                }
            } else {
                $error_message = "Lỗi khi tải file lên server.";
            }
        }
    } else {
        $error_message = "Lỗi khi tải file: " . $file['error'];
    }
}

// Xử lý xóa file
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_file']) && isset($_POST['file_id'])) {
    $file_id = (int)$_POST['file_id'];
    $sql = "SELECT file_path FROM user_files WHERE id = $file_id AND user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $file = mysqli_fetch_assoc($result);
        $file_path = $file['file_path'];
        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                $sql_delete = "DELETE FROM user_files WHERE id = $file_id AND user_id = $user_id";
                if (mysqli_query($conn, $sql_delete)) {
                    $success_message = "Xóa file thành công!";
                } else {
                    $error_message = "Lỗi khi xóa file khỏi database: " . mysqli_error($conn);
                }
            } else {
                $error_message = "Lỗi khi xóa file trên server.";
            }
        } else {
            $error_message = "File không tồn tại trên server.";
        }
    } else {
        $error_message = "File không tồn tại hoặc bạn không có quyền xóa.";
    }
}

// Xử lý chỉnh sửa tên file
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_file']) && isset($_POST['file_id']) && isset($_POST['new_filename'])) {
    $file_id = (int)$_POST['file_id'];
    $new_filename = mysqli_real_escape_string($conn, trim($_POST['new_filename']));
    $sql = "SELECT file_path, filename FROM user_files WHERE id = $file_id AND user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $file = mysqli_fetch_assoc($result);
        $old_file_path = $file['file_path'];
        $file_ext = pathinfo($old_file_path, PATHINFO_EXTENSION);
        $new_file_path = $user_dir . $new_filename . '.' . $file_ext;

        if (file_exists($old_file_path)) {
            if ($old_file_path != $new_file_path) {
                if (!file_exists($new_file_path)) {
                    if (rename($old_file_path, $new_file_path)) {
                        $sql_update = "UPDATE user_files SET filename = '$new_filename', file_path = '$new_file_path' WHERE id = $file_id AND user_id = $user_id";
                        if (mysqli_query($conn, $sql_update)) {
                            $success_message = "Sửa tên file thành công!";
                        } else {
                            $error_message = "Lỗi khi cập nhật database: " . mysqli_error($conn);
                            rename($new_file_path, $old_file_path); // Hoàn tác nếu thất bại
                        }
                    } else {
                        $error_message = "Lỗi khi đổi tên file trên server.";
                    }
                } else {
                    $error_message = "Tên file mới đã tồn tại. Vui lòng chọn tên khác.";
                }
            } else {
                $error_message = "Tên mới trùng với tên cũ.";
            }
        } else {
            $error_message = "File không tồn tại trên server.";
        }
    } else {
        $error_message = "File không tồn tại hoặc bạn không có quyền chỉnh sửa.";
    }
}

// Lấy danh sách file của tài khoản
$sql_files = "SELECT id, filename, file_path, upload_time FROM user_files WHERE user_id = $user_id ORDER BY upload_time DESC";
$result_files = mysqli_query($conn, $sql_files);
if ($result_files) {
    $files = mysqli_fetch_all($result_files, MYSQLI_ASSOC);
} else {
    $error_message = "Lỗi khi lấy danh sách file: " . mysqli_error($conn);
    $files = [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS File Manager - Quản Lý File </title>
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
        .file-manager-container {
            max-width: 1400px;
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

        /* Main content grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 3fr;
            gap: 1.5rem;
        }

        /* Upload section */
        .upload-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            height: fit-content;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .upload-section::before {
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

        .upload-section:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
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
        }

        .file-drop-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.03);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            margin-bottom: 1.5rem;
        }

        .file-drop-area::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(112, 0, 255, 0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .file-drop-area:hover {
            border-color: var(--primary-light);
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
        }

        .file-drop-area.highlight {
            border-color: var(--primary);
            background: rgba(112, 0, 255, 0.1);
            box-shadow: 0 0 15px rgba(112, 0, 255, 0.3);
        }

        .file-icon {
            font-size: 2.5rem;
            color: var(--primary-light);
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }

        .file-drop-area:hover .file-icon {
            transform: scale(1.1);
            color: var(--primary);
        }

        .file-message {
            font-size: 1rem;
            color: var(--foreground-muted);
            margin-bottom: 0.5rem;
        }

        .file-submessage {
            font-size: 0.875rem;
            color: var(--foreground-subtle);
        }

        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-name-display {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            font-size: 0.875rem;
            color: var(--foreground-muted);
            word-break: break-all;
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .upload-btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
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
        }

        .upload-btn::before {
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

        .upload-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .upload-btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .upload-btn:active {
            transform: translateY(0);
        }

        .upload-btn i {
            font-size: 1.125rem;
        }

        /* Stats section */
        .stats-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-3px);
            border-color: var(--border);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .stat-card:nth-child(1) .stat-icon {
            color: var(--primary-light);
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.3);
        }

        .stat-card:nth-child(2) .stat-icon {
            color: var(--secondary);
            box-shadow: 0 0 10px rgba(0, 224, 255, 0.3);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            text-align: center;
        }

        /* Files section */
        .files-section {
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

        .files-section::before {
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

        .files-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .view-options {
            display: flex;
            gap: 0.5rem;
        }

        .view-option {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--foreground-muted);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-option:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
        }

        .view-option.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary-light);
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.3);
        }

        /* File grid view */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .file-card {
            background: rgba(30, 30, 60, 0.7);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .file-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
            background: var(--card-hover);
        }

        .file-preview {
            height: 160px;
            background: rgba(255, 255, 255, 0.03);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .file-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .file-card:hover .file-preview img {
            transform: scale(1.05);
        }

        .file-preview-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 8px rgba(112, 0, 255, 0.5));
        }

        .file-extension {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.25rem 0.5rem;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .file-info {
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .file-name {
            font-weight: 600;
            color: var(--foreground);
            font-size: 1rem;
            word-break: break-all;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .file-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .file-size {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .file-date {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .file-actions {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid var(--border);
        }

        .file-action {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            color: white;
        }

        .action-download {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }

        .action-download:hover {
            background: linear-gradient(135deg, #16a34a, #15803d);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(34, 197, 94, 0.3);
        }

        .action-edit {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
        }

        .action-edit:hover {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(14, 165, 233, 0.3);
        }

        .action-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .action-delete:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }

        /* Empty state */
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--foreground-subtle);
            opacity: 0.5;
        }

        .empty-text {
            font-size: 1.25rem;
            color: var(--foreground-muted);
            max-width: 500px;
            margin: 0 auto;
        }

        .empty-subtext {
            font-size: 0.875rem;
            color: var(--foreground-subtle);
        }

        /* Edit form */
        .edit-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        .edit-modal {
            background: var(--surface);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            animation: scaleIn 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }

        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .edit-modal::before {
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

        .edit-header {
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .edit-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
        }

        .edit-subtitle {
            font-size: 0.875rem;
            color: var(--foreground-subtle);
        }

        .edit-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--foreground-muted);
        }

        .form-input {
            padding: 0.875rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .form-submit {
            flex: 1;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .form-submit:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .form-cancel {
            flex: 1;
            padding: 0.875rem 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .form-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr 2fr;
            }
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .file-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .file-manager-container {
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

            .file-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 1rem;
            }

            .file-preview {
                height: 140px;
            }
        }

        @media (max-width: 480px) {
            .file-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .file-actions {
                flex-direction: column;
            }

            .form-actions {
                flex-direction: column;
            }
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .floating {
            animation: float 6s ease-in-out infinite;
        }

        .floating-slow {
            animation: float 8s ease-in-out infinite;
        }

        .floating-fast {
            animation: float 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.5; transform: scale(0.95); }
            50% { opacity: 1; transform: scale(1); }
            100% { opacity: 0.5; transform: scale(0.95); }
        }

        .pulsing {
            animation: pulse 3s ease-in-out infinite;
        }

        /* Particle background */
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
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="file-manager-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-folder-open"></i> UMTERS File Manager</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="content-grid">
            <div class="sidebar-column">
                <!-- Upload Section -->
                <div class="upload-section">
                    <h2 class="section-title"><i class="fas fa-cloud-upload-alt"></i> Tải lên file</h2>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="file-drop-area" id="drop-area">
                            <i class="fas fa-file-upload file-icon"></i>
                            <p class="file-message">Kéo thả file vào đây</p>
                            <p class="file-submessage">hoặc nhấp để chọn file</p>
                            <input type="file" name="user_file" id="user_file" class="file-input" required>
                        </div>
                        <div class="file-name-display" id="file-name"></div>
                        <button type="submit" name="upload_file" class="upload-btn">
                            <i class="fas fa-upload"></i> Tải lên file
                        </button>
                    </form>

                    <!-- Stats Section -->
                    <div class="stats-section">
                        <h2 class="section-title"><i class="fas fa-chart-pie"></i> Thống kê</h2>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-file"></i>
                                </div>
                                <div class="stat-value"><?php echo count($files); ?></div>
                                <div class="stat-label">Tổng số file</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-hdd"></i>
                                </div>
                                <div class="stat-value" id="total-size">0 KB</div>
                                <div class="stat-label">Dung lượng</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-column">
                <!-- Files Section -->
                <div class="files-section">
                    <div class="files-header">
                        <h2 class="section-title"><i class="fas fa-folder-open"></i> Danh sách file của bạn</h2>
                        <div class="view-options">
                            <div class="view-option active" data-view="grid">
                                <i class="fas fa-th"></i>
                            </div>
                            <div class="view-option" data-view="list">
                                <i class="fas fa-list"></i>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($files)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open empty-icon"></i>
                            <h3 class="empty-text">Chưa có file nào được tải lên</h3>
                            <p class="empty-subtext">Hãy tải lên file đầu tiên của bạn bằng cách sử dụng form bên trái</p>
                        </div>
                    <?php else: ?>
                        <div class="file-grid" id="file-container">
                            <?php 
                            $total_size = 0;
                            foreach ($files as $file): 
                                $file_ext = strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION));
                                $file_size = file_exists($file['file_path']) ? filesize($file['file_path']) : 0;
                                $total_size += $file_size;
                            ?>
                                <div class="file-card">
                                    <div class="file-preview">
                                        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])): ?>
                                            <img src="<?php echo htmlspecialchars($file['file_path']); ?>" alt="<?php echo htmlspecialchars($file['filename']); ?>">
                                        <?php else: ?>
                                            <?php 
                                            $icon_class = 'fa-file';
                                            if (in_array($file_ext, ['pdf'])) $icon_class = 'fa-file-pdf';
                                            elseif (in_array($file_ext, ['doc', 'docx'])) $icon_class = 'fa-file-word';
                                            elseif (in_array($file_ext, ['xls', 'xlsx'])) $icon_class = 'fa-file-excel';
                                            elseif (in_array($file_ext, ['ppt', 'pptx'])) $icon_class = 'fa-file-powerpoint';
                                            elseif (in_array($file_ext, ['zip', 'rar', '7z'])) $icon_class = 'fa-file-archive';
                                            elseif (in_array($file_ext, ['mp3', 'wav', 'ogg'])) $icon_class = 'fa-file-audio';
                                            elseif (in_array($file_ext, ['mp4', 'avi', 'mov'])) $icon_class = 'fa-file-video';
                                            elseif (in_array($file_ext, ['txt', 'md'])) $icon_class = 'fa-file-alt';
                                            elseif (in_array($file_ext, ['html', 'css', 'js', 'php'])) $icon_class = 'fa-file-code';
                                            ?>
                                            <i class="fas <?php echo $icon_class; ?> file-preview-icon"></i>
                                        <?php endif; ?>
                                        <span class="file-extension"><?php echo $file_ext; ?></span>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name"><?php echo htmlspecialchars($file['filename']); ?></div>
                                        <div class="file-meta">
                                            <span class="file-size">
                                                <i class="fas fa-weight-hanging"></i> 
                                                <?php echo formatFileSize($file_size); ?>
                                            </span>
                                            <span class="file-date">
                                                <i class="far fa-calendar-alt"></i> 
                                                <?php echo date('d/m/Y', strtotime($file['upload_time'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="file-actions">
                                        <a href="<?php echo htmlspecialchars($file['file_path']); ?>" download class="file-action action-download">
                                            <i class="fas fa-download"></i> Tải xuống
                                        </a>
                                        <a href="javascript:void(0)" class="file-action action-edit" onclick="showEditForm(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(pathinfo($file['filename'], PATHINFO_FILENAME)); ?>')">
                                            <i class="fas fa-edit"></i> Sửa
                                        </a>
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                            <button type="submit" name="delete_file" class="file-action action-delete" onclick="return confirm('Bạn có chắc muốn xóa file này? Hành động này không thể hoàn tác.');">
                                                <i class="fas fa-trash-alt"></i> Xóa
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Form Modal -->
    <div class="edit-overlay" id="edit-overlay" style="display: none;">
        <div class="edit-modal">
            <div class="edit-header">
                <h2 class="edit-title">Chỉnh sửa tên file</h2>
                <p class="edit-subtitle">Nhập tên mới cho file của bạn</p>
            </div>
            <form class="edit-form" method="POST">
                <div class="form-group">
                    <label for="new_filename" class="form-label">Tên file mới</label>
                    <input type="text" name="new_filename" id="new_filename" class="form-input" required>
                </div>
                <input type="hidden" name="file_id" id="edit_file_id">
                <div class="form-actions">
                    <button type="submit" name="edit_file" class="form-submit">
                        <i class="fas fa-save"></i> Lưu thay đổi
                    </button>
                    <button type="button" class="form-cancel" onclick="hideEditForm()">
                        <i class="fas fa-times"></i> Hủy bỏ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Hiển thị tên file khi chọn file
            const fileInput = document.getElementById('user_file');
            const fileNameDisplay = document.getElementById('file-name');
            
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    fileNameDisplay.textContent = this.files[0].name;
                    fileNameDisplay.style.display = 'block';
                } else {
                    fileNameDisplay.style.display = 'none';
                }
            });
            
            // Hiệu ứng kéo thả file
            const dropArea = document.getElementById('drop-area');
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight() {
                dropArea.classList.add('highlight');
            }
            
            function unhighlight() {
                dropArea.classList.remove('highlight');
            }
            
            dropArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;
                
                if (files && files[0]) {
                    fileNameDisplay.textContent = files[0].name;
                    fileNameDisplay.style.display = 'block';
                }
            }
            
            // Hiệu ứng hiển thị thông báo
            const messages = document.querySelectorAll('.error-message, .success-message');
            
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                }, 5000);
            });
            
            // Chuyển đổi chế độ xem
            const viewOptions = document.querySelectorAll('.view-option');
            const fileContainer = document.getElementById('file-container');
            
            viewOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    
                    // Xóa active class từ tất cả options
                    viewOptions.forEach(opt => opt.classList.remove('active'));
                    
                    // Thêm active class cho option được chọn
                    this.classList.add('active');
                    
                    // Thay đổi layout
                    if (view === 'grid') {
                        fileContainer.classList.remove('file-list');
                        fileContainer.classList.add('file-grid');
                    } else {
                        fileContainer.classList.remove('file-grid');
                        fileContainer.classList.add('file-list');
                    }
                });
            });
            
            // Hiển thị tổng dung lượng
            const totalSizeElement = document.getElementById('total-size');
            totalSizeElement.textContent = '<?php echo formatFileSize($total_size); ?>';
            
            // Animation cho các phần tử
            animateElements('.file-card', 100);
            animateElements('.stat-card', 200);
        });
        
        // Hàm tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
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
        
        // Hiển thị form chỉnh sửa
        function showEditForm(fileId, fileName) {
            document.getElementById('edit_file_id').value = fileId;
            document.getElementById('new_filename').value = fileName;
            document.getElementById('edit-overlay').style.display = 'flex';
        }
        
        // Ẩn form chỉnh sửa
        function hideEditForm() {
            document.getElementById('edit-overlay').style.display = 'none';
        }
        
        <?php
        // Hàm định dạng kích thước file
        function formatFileSize($size) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            while ($size >= 1024 && $i < count($units) - 1) {
                $size /= 1024;
                $i++;
            }
            return round($size, 2) . ' ' . $units[$i];
        }
        ?>
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
