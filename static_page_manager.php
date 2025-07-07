<?php
// Bật hiển thị lỗi để dễ debug
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();
include 'db_config.php'; // Giả sử file cấu hình database của bạn

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Kiểm tra và tạo bảng static_pages nếu chưa tồn tại
$check_table_sql = "SHOW TABLES LIKE 'static_pages'";
$table_exists = mysqli_query($conn, $check_table_sql);

if (mysqli_num_rows($table_exists) == 0) {
    // Tạo bảng static_pages nếu chưa tồn tại
    $create_table_sql = "CREATE TABLE static_pages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        folder_name VARCHAR(255) NOT NULL,
        url VARCHAR(255) NOT NULL,
        user_id INT(11) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_folder_user (folder_name, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if (!mysqli_query($conn, $create_table_sql)) {
        die("Lỗi tạo bảng: " . mysqli_error($conn));
    }
}

// Lấy thông tin user
$user_id = (int)$_SESSION['user_id'];
$sql_user = "SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = $user_id";
$result_user = mysqli_query($conn, $sql_user);
$user = mysqli_fetch_assoc($result_user);
$is_main_admin = $user['is_main_admin'];
$is_super_admin = $user['is_super_admin'];
$username = $user['username'];
$full_name = $user['full_name'] ?: $user['username'];

// Phân cấp giới hạn
$max_pages = $is_super_admin ? 50 : ($is_main_admin ? 10 : 5);

// Đếm số trang tĩnh của user từ database
$sql_count = "SELECT COUNT(*) as total FROM static_pages WHERE user_id = $user_id";
$result_count = mysqli_query($conn, $sql_count);
$page_count = mysqli_fetch_assoc($result_count)['total'];

// Thư mục lưu trang tĩnh
$static_dir = "site_static/";

// Tạo thư mục nếu chưa tồn tại
if (!file_exists($static_dir)) {
    mkdir($static_dir, 0755, true);
}

// Lấy tên miền động
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$domain = $base_url . "/";

// Hàm ghi log
function writeLog($message) {
    $log_file = "static_page_log.txt";
    $timestamp = date("Y-m-d H:i:s");
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Hàm xóa thư mục và nội dung
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Hàm kiểm tra và làm sạch file trong ZIP
function isSafeFile($filename) {
    // Bỏ qua thư mục
    if (substr($filename, -1) == '/') return true;
    
    $allowed_extensions = ['html', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'ttf', 'woff', 'woff2', 'eot', 'txt', 'json'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $disallowed_extensions = ['php', 'exe', 'sh', 'bat', 'py', 'pl', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess'];
    
    // Kiểm tra extension hợp lệ
    if (!in_array($extension, $allowed_extensions)) {
        return false;
    }
    // Kiểm tra extension nguy hiểm
    if (in_array($extension, $disallowed_extensions)) {
        return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Xử lý tải lên file ZIP
    if (isset($_POST['upload_static_page']) && isset($_FILES['static_zip'])) {
        if ($page_count >= $max_pages) {
            $error = "Đã đạt giới hạn số trang tĩnh ($max_pages trang).";
        } else {
            // Kiểm tra lỗi upload
            if ($_FILES['static_zip']['error'] !== UPLOAD_ERR_OK) {
                switch ($_FILES['static_zip']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error = "File quá lớn. Giới hạn: " . ini_get('upload_max_filesize');
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error = "File chỉ được tải lên một phần.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error = "Không có file nào được tải lên.";
                        break;
                    default:
                        $error = "Lỗi tải lên không xác định.";
                }
                writeLog("Lỗi upload: " . $error);
            } else {
                // Kiểm tra MIME type của file ZIP
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $_FILES['static_zip']['tmp_name']);
                finfo_close($finfo);
                
                if ($mime_type !== 'application/zip' && $mime_type !== 'application/x-zip-compressed' && $mime_type !== 'application/x-zip') {
                    $error = "Chỉ hỗ trợ file ZIP. MIME type phát hiện: $mime_type";
                    writeLog("MIME type không hợp lệ: $mime_type");
                } else {
                    // Kiểm tra ZipArchive
                    if (!class_exists('ZipArchive')) {
                        $error = "Máy chủ không hỗ trợ giải nén file ZIP. Vui lòng liên hệ quản trị viên.";
                        writeLog("ZipArchive không được hỗ trợ");
                    } else {
                        $zip = new ZipArchive;
                        $zip_file = $_FILES['static_zip']['tmp_name'];
                        $custom_folder = isset($_POST['custom_folder']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_POST['custom_folder']) : '';
                        
                        // Đảm bảo tên thư mục không trống
                        if (empty($custom_folder)) {
                            $custom_folder = 'page_' . time();
                        }
                        
                        writeLog("Bắt đầu xử lý file ZIP cho thư mục: $custom_folder");
                        
                        if ($zip->open($zip_file) === TRUE) {
                            // Kiểm tra các file trong ZIP
                            $is_safe = true;
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $filename = $zip->getNameIndex($i);
                                if (!isSafeFile($filename)) {
                                    $is_safe = false;
                                    $error = "File không hợp lệ: $filename. Chỉ hỗ trợ HTML, CSS, JS và các file media.";
                                    writeLog("File không an toàn: $filename");
                                    break;
                                }
                            }

                            if ($is_safe) {
                                $extract_path = $static_dir . $custom_folder;
                                
                                // Kiểm tra xem thư mục đã tồn tại chưa
                                if (file_exists($extract_path)) {
                                    $error = "Thư mục '$custom_folder' đã tồn tại. Vui lòng chọn tên khác.";
                                    writeLog("Thư mục đã tồn tại: $extract_path");
                                } else {
                                    // Tạo thư mục và giải nén
                                    if (mkdir($extract_path, 0755, true)) {
                                        $extract_result = $zip->extractTo($extract_path);
                                        $zip->close();
                                        
                                        if ($extract_result) {
                                            writeLog("Giải nén thành công vào: $extract_path");
                                            
                                            // Tạo file index.html mặc định nếu không có
                                            if (!file_exists($extract_path . '/index.html')) {
                                                $default_index = '<!DOCTYPE html>
                                                <html>
                                                <head>
                                                    <meta charset="UTF-8">
                                                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                                    <title>Trang Tĩnh</title>
                                                    <style>
                                                        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                                                        h1 { color: #333; }
                                                    </style>
                                                </head>
                                                <body>
                                                    <h1>Trang tĩnh của bạn</h1>
                                                    <p>Đây là trang mặc định. Vui lòng thêm file index.html vào thư mục gốc của bạn.</p>
                                                </body>
                                                </html>';
                                                file_put_contents($extract_path . '/index.html', $default_index);
                                                writeLog("Đã tạo file index.html mặc định");
                                            }

                                            // Lưu vào database - SỬA PHẦN NÀY ĐỂ KHẮC PHỤC LỖI
                                            $folder_name_safe = mysqli_real_escape_string($conn, $custom_folder);
                                            $url_safe = mysqli_real_escape_string($conn, $domain . $custom_folder);
                                            
                                            // Kiểm tra xem folder_name đã tồn tại cho user này chưa
                                            $check_sql = "SELECT id FROM static_pages WHERE folder_name = '$folder_name_safe' AND user_id = $user_id";
                                            $check_result = mysqli_query($conn, $check_sql);
                                            
                                            if (mysqli_num_rows($check_result) > 0) {
                                                $error = "Bạn đã có một trang tĩnh với tên thư mục này. Vui lòng chọn tên khác.";
                                                deleteDirectory($extract_path);
                                                writeLog("Tên thư mục đã tồn tại trong database: $folder_name_safe");
                                            } else {
                                                try {
                                                    // Sử dụng câu lệnh INSERT không chỉ định ID
                                                    $sql_insert = "INSERT INTO static_pages (folder_name, url, user_id) 
                                                                  VALUES ('$folder_name_safe', '$url_safe', $user_id)";
                                                    
                                                    if (mysqli_query($conn, $sql_insert)) {
                                                        $success = "Tải lên và tạo trang tĩnh thành công! Đường dẫn: $url_safe";
                                                        writeLog("Lưu database thành công: $sql_insert");
                                                    } else {
                                                        $error = "Lỗi khi lưu vào database: " . mysqli_error($conn);
                                                        // Xóa thư mục nếu lưu database thất bại
                                                        deleteDirectory($extract_path);
                                                        writeLog("Lỗi database: " . mysqli_error($conn));
                                                    }
                                                } catch (Exception $e) {
                                                    $error = "Lỗi ngoại lệ: " . $e->getMessage();
                                                    deleteDirectory($extract_path);
                                                    writeLog("Lỗi ngoại lệ: " . $e->getMessage());
                                                }
                                            }
                                        } else {
                                            $error = "Không thể giải nén file ZIP. Vui lòng kiểm tra nội dung file.";
                                            deleteDirectory($extract_path);
                                            writeLog("Không thể giải nén file ZIP");
                                        }
                                    } else {
                                        $error = "Không thể tạo thư mục. Vui lòng kiểm tra quyền truy cập.";
                                        writeLog("Không thể tạo thư mục: $extract_path");
                                    }
                                }
                            }
                        } else {
                            $error = "Không thể mở file ZIP.";
                            writeLog("Không thể mở file ZIP");
                        }
                    }
                }
            }
        }
    }

    // Xử lý đổi tên folder
    if (isset($_POST['rename_static_page']) && isset($_POST['old_folder']) && isset($_POST['new_folder'])) {
        $old_folder = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['old_folder']);
        $new_folder = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['new_folder']);
        $old_path = $static_dir . $old_folder;
        $new_path = $static_dir . $new_folder;

        writeLog("Yêu cầu đổi tên từ $old_folder thành $new_folder");

        if (file_exists($old_path) && !file_exists($new_path)) {
            if (rename($old_path, $new_path)) {
                // Cập nhật database
                $new_url = $domain . $static_dir . $new_folder;
                $old_folder_safe = mysqli_real_escape_string($conn, $old_folder);
                $new_folder_safe = mysqli_real_escape_string($conn, $new_folder);
                $new_url_safe = mysqli_real_escape_string($conn, $new_url);
                
                $sql_update = "UPDATE static_pages SET folder_name = '$new_folder_safe', url = '$new_url_safe' 
                              WHERE folder_name = '$old_folder_safe' AND user_id = $user_id";
                
                if (mysqli_query($conn, $sql_update)) {
                    $success = "Đổi tên thư mục thành công!";
                    writeLog("Đổi tên thành công: $old_folder -> $new_folder");
                } else {
                    $error = "Lỗi khi cập nhật database: " . mysqli_error($conn);
                    // Hoàn tác đổi tên nếu database thất bại
                    rename($new_path, $old_path);
                    writeLog("Lỗi cập nhật database khi đổi tên: " . mysqli_error($conn));
                }
            } else {
                $error = "Không thể đổi tên thư mục.";
                writeLog("Không thể đổi tên thư mục từ $old_path thành $new_path");
            }
        } else {
            $error = "Thư mục cũ không tồn tại hoặc thư mục mới đã tồn tại.";
            writeLog("Lỗi: Thư mục cũ không tồn tại hoặc thư mục mới đã tồn tại");
        }
    }

    // Xử lý xóa trang tĩnh
    if (isset($_POST['delete_static_page']) && isset($_POST['folder_to_delete'])) {
        $folder_to_delete = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['folder_to_delete']);
        $folder_path = $static_dir . $folder_to_delete;

        writeLog("Yêu cầu xóa thư mục: $folder_to_delete");

        if (file_exists($folder_path)) {
            if (deleteDirectory($folder_path)) {
                // Xóa khỏi database
                $folder_safe = mysqli_real_escape_string($conn, $folder_to_delete);
                $sql_delete = "DELETE FROM static_pages WHERE folder_name = '$folder_safe' AND user_id = $user_id";
                
                if (mysqli_query($conn, $sql_delete)) {
                    $success = "Xóa trang tĩnh thành công!";
                    writeLog("Xóa thành công: $folder_to_delete");
                } else {
                    $error = "Lỗi khi xóa khỏi database: " . mysqli_error($conn);
                    writeLog("Lỗi xóa khỏi database: " . mysqli_error($conn));
                }
            } else {
                $error = "Không thể xóa thư mục.";
                writeLog("Không thể xóa thư mục: $folder_path");
            }
        } else {
            $error = "Thư mục không tồn tại.";
            writeLog("Thư mục không tồn tại: $folder_path");
        }
    }
}

// Lấy danh sách trang tĩnh từ database
$sql_pages = "SELECT folder_name, url FROM static_pages WHERE user_id = $user_id";
$result_pages = mysqli_query($conn, $sql_pages);
$static_pages = [];
if ($result_pages) {
    while ($row = mysqli_fetch_assoc($result_pages)) {
        $static_pages[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS - Quản lý Trang Tĩnh</title>
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

        /* Page title */
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
            background: linear-gradient(to right, var(--primary-light), var(--secondary-light), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .page-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent));
            border-radius: var(--radius-full);
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
            color: #00E0FF;
            border: 1px solid rgba(0, 224, 255, 0.3);
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

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Form cards */
        .form-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .form-card::before {
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
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }

        .form-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .form-card:hover::before {
            opacity: 1;
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--foreground);
        }

        .form-title i {
            color: var(--accent);
            font-size: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            font-weight: 500;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: #00e2ff;
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.25);
            background: rgba(255, 255, 255, 0.1);
        }

        .form-input::placeholder {
            color: var(--foreground-subtle);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23FFFFFF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-input[type="file"] {
            padding: 0.5rem;
            cursor: pointer;
            border-style: dashed;
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
            width: 100%;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: var(--glow);
        }

        .form-button:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-light));
            transform: translateY(-2px);
            box-shadow: var(--glow-accent);
        }

        .form-button.delete-button {
            background: linear-gradient(135deg, #FF3D57, #FF5757);
            box-shadow: 0 0 20px rgba(255, 61, 87, 0.3);
        }

        .form-button.delete-button:hover {
            background: linear-gradient(135deg, #FF5757, #FF3D57);
            box-shadow: 0 0 20px rgba(255, 61, 87, 0.5);
        }

        .form-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            font-size: 0.75rem;
            color: var(--foreground-muted);
        }

        .form-info i {
            color: var(--secondary);
        }

        .limit-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .limit-text {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .limit-progress {
            flex: 1;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-full);
            overflow: hidden;
        }

        .limit-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            width: <?php echo ($page_count / $max_pages) * 100; ?>%;
            transition: width 0.3s ease;
        }

        /* Pages list */
        .pages-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-top: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .pages-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 30% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 70% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            opacity: 0.5;
        }

        .pages-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-align: center;
            color: var(--foreground);
            position: relative;
            padding-bottom: 0.5rem;
        }

        .pages-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, var(--secondary), var(--accent));
            border-radius: var(--radius-full);
        }

        .pages-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            list-style: none;
            padding: 0;
            margin-top: 1.5rem;
        }

        .page-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1rem;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .page-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
            background: rgba(255, 255, 255, 0.08);
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .page-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
            word-break: break-all;
            flex: 1;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 224, 255, 0.1);
            border: 1px solid rgba(0, 224, 255, 0.2);
            border-radius: var(--radius-sm);
            color: var(--secondary);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: rgba(0, 224, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .no-pages {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--foreground-muted);
            gap: 1rem;
        }

        .no-pages i {
            font-size: 3rem;
            color: var(--foreground-subtle);
        }

        .no-pages-text {
            font-size: 1rem;
            max-width: 400px;
        }

        /* Debug info */
        .debug-info {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            margin-top: 2rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
        }

        .debug-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .debug-title i {
            color: var(--secondary);
        }

        .debug-section {
            margin-bottom: 1.5rem;
        }

        .debug-section h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .debug-section h4 i {
            color: var(--accent);
            font-size: 0.875rem;
        }

        .debug-item {
            display: flex;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
        }

        .debug-label {
            font-weight: 500;
            margin-right: 0.5rem;
            color: var(--foreground);
        }

        .debug-value {
            color: var(--foreground-muted);
        }

        .debug-value.positive {
            color: var(--secondary);
        }

        .debug-value.negative {
            color: #FF3D57;
        }

        .debug-pre {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: var(--radius-sm);
            overflow-x: auto;
            color: var(--foreground-muted);
            margin-top: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
        }

        /* Responsive styles */
        @media (max-width: 1024px) {
            .form-container {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            
            .pages-list {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
                justify-content: space-between;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .form-container {
                grid-template-columns: 1fr;
            }
            
            .pages-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header {
                padding: 0.75rem;
            }
            
            .header-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            
            .back-to-home, .user-profile {
                width: 100%;
                justify-content: center;
            }
            
            .page-title {
                font-size: 1.25rem;
            }
            
            .form-title {
                font-size: 1.1rem;
            }
            
            .pages-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon"><i class="fas fa-file-code"></i></div>
                <div class="logo-text">UMTERS</div>
            </div>
            
            <div class="header-actions">
                <a href="dashboard.php" class="back-to-home">
                    <i class="fas fa-arrow-left"></i> Trở về trang chủ
                </a>
                
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                    <div class="user-info">
                        <p class="user-name"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="user-role">
                            <?php echo $is_super_admin ? 'Super Admin' : ($is_main_admin ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <h1 class="page-title">Quản lý Trang Tĩnh</h1>
            
            <!-- Messages -->
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="success-message"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Form Container -->
            <div class="form-container">
                <!-- Upload Static Page Form -->
                <div class="form-card">
                    <div class="form-title">
                        <i class="fas fa-upload"></i> Tải lên trang tĩnh
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label" for="static_zip">Chọn file ZIP</label>
                            <input type="file" id="static_zip" name="static_zip" class="form-input" accept=".zip" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="custom_folder">Tên thư mục</label>
                            <input type="text" id="custom_folder" name="custom_folder" class="form-input" placeholder="Chỉ chữ và số, không dấu" value="page_<?php echo time(); ?>">
                        </div>
                        
                        <button type="submit" name="upload_static_page" class="form-button">
                            <i class="fas fa-cloud-upload-alt"></i> Tải lên trang tĩnh
                        </button>
                        
                        <div class="limit-info">
                            <div class="limit-text">
                                <i class="fas fa-info-circle"></i> Giới hạn: <?php echo $page_count; ?> / <?php echo $max_pages; ?> trang
                            </div>
                            <div class="limit-progress">
                                <div class="limit-bar"></div>
                            </div>
                        </div>
                        
                        <div class="form-info">
                            <i class="fas fa-info-circle"></i> Chỉ hỗ trợ file ZIP chứa HTML, CSS, JS và các file media
                        </div>
                    </form>
                </div>
                
                <!-- Rename Static Page Form -->
                <div class="form-card">
                    <div class="form-title">
                        <i class="fas fa-edit"></i> Đổi tên trang tĩnh
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label" for="old_folder">Chọn trang để đổi tên</label>
                            <select id="old_folder" name="old_folder" class="form-select" required>
                                <option value="">-- Chọn trang --</option>
                                <?php foreach ($static_pages as $page): ?>
                                    <option value="<?php echo htmlspecialchars($page['folder_name']); ?>"><?php echo htmlspecialchars($page['folder_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="new_folder">Tên mới</label>
                            <input type="text" id="new_folder" name="new_folder" class="form-input" placeholder="Chỉ chữ và số, không dấu" required>
                        </div>
                        
                        <button type="submit" name="rename_static_page" class="form-button">
                            <i class="fas fa-check-circle"></i> Đổi tên
                        </button>
                        
                        <div class="form-info">
                            <i class="fas fa-exclamation-circle"></i> Đổi tên sẽ thay đổi URL của trang
                        </div>
                    </form>
                </div>
                
                <!-- Delete Static Page Form -->
                <div class="form-card">
                    <div class="form-title">
                        <i class="fas fa-trash-alt"></i> Xóa trang tĩnh
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label" for="folder_to_delete">Chọn trang để xóa</label>
                            <select id="folder_to_delete" name="folder_to_delete" class="form-select" required>
                                <option value="">-- Chọn trang --</option>
                                <?php foreach ($static_pages as $page): ?>
                                    <option value="<?php echo htmlspecialchars($page['folder_name']); ?>"><?php echo htmlspecialchars($page['folder_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="delete_static_page" class="form-button delete-button" onclick="return confirm('Bạn có chắc muốn xóa trang này? Hành động này không thể hoàn tác.');">
                            <i class="fas fa-trash-alt"></i> Xóa trang
                        </button>
                        
                        <div class="form-info">
                            <i class="fas fa-exclamation-triangle"></i> Cảnh báo: Hành động này không thể hoàn tác
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Pages List -->
            <div class="pages-container">
                <h2 class="pages-title">Danh sách trang tĩnh</h2>
                
                <?php if (count($static_pages) > 0): ?>
                    <ul class="pages-list">
                        <?php foreach ($static_pages as $page): ?>
                            <li class="page-item">
                                <div class="page-header">
                                    <div class="page-icon">
                                        <i class="fas fa-file-code"></i>
                                    </div>
                                    <div class="page-name"><?php echo htmlspecialchars($page['folder_name']); ?></div>
                                </div>
                                <a href="<?php echo htmlspecialchars($page['url']); ?>" target="_blank" class="page-link">
                                    <i class="fas fa-external-link-alt"></i> Xem trang
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-pages">
                        <i class="fas fa-folder-open"></i>
                        <p class="no-pages-text">Bạn chưa có trang tĩnh nào. Hãy tải lên trang đầu tiên!</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_super_admin): ?>
            <!-- Debug Info - Chỉ hiển thị cho super admin -->
            <div class="debug-info">
                <div class="debug-title">
                    <i class="fas fa-bug"></i> Thông tin Debug
                </div>
                
                <div class="debug-section">
                    <h4><i class="fas fa-server"></i> Thông tin hệ thống</h4>
                    <div class="debug-item">
                        <span class="debug-label">PHP Version:</span>
                        <span class="debug-value"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">ZipArchive:</span>
                        <span class="debug-value <?php echo class_exists('ZipArchive') ? 'positive' : 'negative'; ?>">
                            <?php echo class_exists('ZipArchive') ? 'Có hỗ trợ' : 'Không hỗ trợ'; ?>
                        </span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Upload Max Filesize:</span>
                        <span class="debug-value"><?php echo ini_get('upload_max_filesize'); ?></span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Post Max Size:</span>
                        <span class="debug-value"><?php echo ini_get('post_max_size'); ?></span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Memory Limit:</span>
                        <span class="debug-value"><?php echo ini_get('memory_limit'); ?></span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Max Execution Time:</span>
                        <span class="debug-value"><?php echo ini_get('max_execution_time'); ?> seconds</span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Static Directory:</span>
                        <span class="debug-value <?php echo file_exists($static_dir) ? 'positive' : 'negative'; ?>">
                            <?php echo $static_dir; ?> (<?php echo file_exists($static_dir) ? 'Tồn tại' : 'Không tồn tại'; ?>)
                        </span>
                    </div>
                    <div class="debug-item">
                        <span class="debug-label">Static Directory Writable:</span>
                        <span class="debug-value <?php echo is_writable($static_dir) ? 'positive' : 'negative'; ?>">
                            <?php echo is_writable($static_dir) ? 'Có' : 'Không'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="debug-section">
                    <h4><i class="fas fa-database"></i> Cấu trúc bảng static_pages</h4>
                    <div class="debug-pre">
<?php
$table_structure = mysqli_query($conn, "DESCRIBE static_pages");
if ($table_structure) {
    while ($row = mysqli_fetch_assoc($table_structure)) {
        echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . ($row['Key'] == 'PRI' ? 'PRIMARY KEY' : '') . "\n";
    }
} else {
    echo "Không thể lấy cấu trúc bảng: " . mysqli_error($conn);
}
?>
                    </div>
                </div>
                
                <div class="debug-section">
                    <h4><i class="fas fa-history"></i> Log gần đây</h4>
                    <div class="debug-pre">
<?php
if (file_exists('static_page_log.txt')) {
    $log_content = file_get_contents('static_page_log.txt');
    if (strlen($log_content) > 2000) {
        echo htmlspecialchars(substr($log_content, -2000));
    } else {
        echo htmlspecialchars($log_content);
    }
} else {
    echo "Chưa có file log.";
}
?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.error-message, .success-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.parentNode.removeChild(message);
                        }
                    }, 500);
                });
            }, 5000);
            
            // Add hover effects for form cards
            const formCards = document.querySelectorAll('.form-card');
            formCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = 'var(--shadow-lg)';
                    this.style.borderColor = 'var(--primary-light)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                    this.style.borderColor = '';
                });
            });
            
            // Add hover effects for page items
            const pageItems = document.querySelectorAll('.page-item');
            pageItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = 'var(--shadow)';
                    this.style.borderColor = 'var(--primary-light)';
                    this.style.background = 'rgba(255, 255, 255, 0.08)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                    this.style.borderColor = '';
                    this.style.background = '';
                });
            });
            
            // Create floating particles
            createParticles();
        });
        
        // Function to create floating particles
        function createParticles() {
            const container = document.createElement('div');
            container.style.position = 'fixed';
            container.style.top = '0';
            container.style.left = '0';
            container.style.width = '100%';
            container.style.height = '100%';
            container.style.pointerEvents = 'none';
            container.style.zIndex = '0';
            document.body.appendChild(container);
            
            const particleCount = 20;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                
                // Random size
                const size = Math.random() * 5 + 2;
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
                
                // Style
                particle.style.position = 'absolute';
                particle.style.borderRadius = '50%';
                particle.style.background = color;
                particle.style.opacity = '0.3';
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Animation
                const duration = Math.random() * 60 + 30;
                const delay = Math.random() * 10;
                particle.style.animation = `floatParticle ${duration}s ease-in-out ${delay}s infinite`;
                
                container.appendChild(particle);
            }
            
            // Add keyframes
            const style = document.createElement('style');
            style.textContent = `
                @keyframes floatParticle {
                    0%, 100% { transform: translate(0, 0); }
                    25% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                    50% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                    75% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                }
            `;
            document.head.appendChild(style);
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
