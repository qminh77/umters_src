<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$is_super_admin = isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'];

// Tạo bảng checkdocuments nếu chưa có
$sql_checkdocuments = "CREATE TABLE IF NOT EXISTS checkdocuments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!mysqli_query($conn, $sql_checkdocuments)) {
    error_log("Lỗi tạo bảng checkdocuments: " . mysqli_error($conn));
    die("Lỗi hệ thống, vui lòng thử lại sau!");
}

// Tạo bảng checkdocument_reports
$sql_reports = "CREATE TABLE IF NOT EXISTS checkdocument_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checkdocument_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (checkdocument_id) REFERENCES checkdocuments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!mysqli_query($conn, $sql_reports)) {
    error_log("Lỗi tạo bảng checkdocument_reports: " . mysqli_error($conn));
    die("Lỗi hệ thống, vui lòng thử lại sau!");
}

// Kiểm tra và thêm cột created_at nếu thiếu
$sql_check_column = "SHOW COLUMNS FROM checkdocuments LIKE 'created_at'";
$result_check = mysqli_query($conn, $sql_check_column);
if (mysqli_num_rows($result_check) == 0) {
    $sql_add_column = "ALTER TABLE checkdocuments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
    if (!mysqli_query($conn, $sql_add_column)) {
        error_log("Lỗi thêm cột created_at: " . mysqli_error($conn));
        die("Lỗi hệ thống, vui lòng thử lại sau!");
    }
}

// Thêm cài đặt giới hạn tải lên nếu chưa có
$check_setting = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key='upload_limit_per_month'");
if (mysqli_num_rows($check_setting) == 0) {
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('upload_limit_per_month', '3')")
        or die("Lỗi thêm cài đặt upload_limit_per_month: " . mysqli_error($conn));
}

// Khởi tạo thông báo
$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Lấy giới hạn tải lên
$upload_limit_sql = "SELECT setting_value FROM settings WHERE setting_key = 'upload_limit_per_month'";
$upload_limit_result = mysqli_query($conn, $upload_limit_sql);
$upload_limit = $upload_limit_result ? (int)mysqli_fetch_assoc($upload_limit_result)['setting_value'] : 3;

// Gửi email thông báo
function sendNotificationEmail($conn, $smtp_config, $user_id, $filename) {
    // Kiểm tra sự tồn tại của PHPMailer
    $phpmailer_path = 'lib/PHPMailer/src/PHPMailer.php';
    if (!file_exists($phpmailer_path)) {
        error_log("Lỗi: Không tìm thấy PHPMailer tại $phpmailer_path");
        return false;
    }

    require_once 'lib/PHPMailer/src/PHPMailer.php';
    require_once 'lib/PHPMailer/src/SMTP.php';
    require_once 'lib/PHPMailer/src/Exception.php';
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtp_config['host'];
        $mail->SMTPAuth = $smtp_config['smtp_auth'];
        $mail->Username = $smtp_config['username'];
        $mail->Password = $smtp_config['password'];
        $mail->SMTPSecure = $smtp_config['smtp_secure'];
        $mail->Port = $smtp_config['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
        $mail->addAddress('minhminh3456minh@gmail.com');

        // Lấy thông tin người dùng
        $sql_user = "SELECT username FROM users WHERE id = ?";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        $username = $user_result->fetch_assoc()['username'] ?? 'Không xác định';
        $stmt_user->close();

        $mail->isHTML(true);
        $mail->Subject = 'Thông báo: Tài liệu mới cần duyệt';
        $mail->Body = "
            <h2>Tài liệu mới cần duyệt</h2>
            <p><strong>Người dùng:</strong> $username</p>
            <p><strong>Tên file:</strong> $filename</p>
            <p><strong>Thời gian tải lên:</strong> " . date('d/m/Y H:i:s') . "</p>
            <p>Vui lòng kiểm tra hệ thống để duyệt tài liệu.</p>
        ";

        $mail->send();
        error_log("Email thông báo gửi thành công đến minhminh3456minh@gmail.com, tài liệu: $filename");
        return true;
    } catch (Exception $e) {
        error_log("Lỗi gửi email thông báo đến minhminh3456minh@gmail.com: " . $mail->ErrorInfo);
        return false;
    }
}

// Kiểm tra giới hạn tải lên
function checkUploadLimit($conn, $user_id, $limit) {
    $start_of_month = date('Y-m-01 00:00:00');
    $sql = "SELECT COUNT(*) as count FROM checkdocuments WHERE user_id = ? AND created_at >= ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Lỗi chuẩn bị truy vấn checkUploadLimit: " . mysqli_error($conn));
        return false;
    }
    $stmt->bind_param("is", $user_id, $start_of_month);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    error_log("Kiểm tra giới hạn tải lên: user_id=$user_id, count={$row['count']}, limit=$limit");
    return $row['count'] < $limit;
}

// Xử lý cập nhật giới hạn tải lên
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_upload_limit']) && $is_super_admin) {
    $new_limit = (int)$_POST['upload_limit'];
    if ($new_limit < 1) {
        $_SESSION['error_message'] = "Giới hạn phải lớn hơn 0!";
        error_log("Giới hạn tải lên không hợp lệ: $new_limit");
    } else {
        $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = 'upload_limit_per_month'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $new_limit);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Cập nhật giới hạn tải lên thành công!";
                $upload_limit = $new_limit;
                error_log("Cập nhật giới hạn tải lên thành công: $new_limit");
            } else {
                $_SESSION['error_message'] = "Lỗi khi cập nhật giới hạn tải lên!";
                error_log("Lỗi cập nhật upload_limit: " . mysqli_error($conn));
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "Lỗi khi chuẩn bị truy vấn cơ sở dữ liệu!";
            error_log("Lỗi chuẩn bị truy vấn update_upload_limit: " . mysqli_error($conn));
        }
    }
    header("Location: checkdocument.php");
    exit;
}

// Xử lý tải lên tài liệu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document']) && !$is_super_admin) {
    error_log("Bắt đầu xử lý tải lên tài liệu cho user_id: $user_id");
    if (!checkUploadLimit($conn, $user_id, $upload_limit)) {
        $_SESSION['error_message'] = "Bạn đã đạt giới hạn $upload_limit tài liệu mỗi tháng!";
        error_log("Đạt giới hạn tải lên: $upload_limit cho user_id: $user_id");
        header("Location: checkdocument.php");
        exit;
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = "Vui lòng chọn một file để tải lên hoặc lỗi khi tải file!";
        error_log("Lỗi file upload: " . ($_FILES['document']['error'] ?? 'Không có file'));
        header("Location: checkdocument.php");
        exit;
    }

    $file = $_FILES['document'];
    $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_size = 20 * 1024 * 1024; // 20MB

    error_log("File nhận được: name={$file['name']}, type={$file['type']}, size={$file['size']}");

    if (!in_array($file['type'], $allowed_types)) {
        $_SESSION['error_message'] = "Chỉ chấp nhận file PDF, DOC, hoặc DOCX!";
        error_log("Loại file không hợp lệ: {$file['type']}");
        header("Location: checkdocument.php");
        exit;
    }

    if ($file['size'] > $max_size) {
        $_SESSION['error_message'] = "File quá lớn! Kích thước tối đa là 20MB.";
        error_log("File quá lớn: {$file['size']} bytes");
        header("Location: checkdocument.php");
        exit;
    }

    $upload_dir = 'uploads/checkdocuments/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $_SESSION['error_message'] = "Lỗi khi tạo thư mục uploads!";
            error_log("Lỗi tạo thư mục: $upload_dir");
            header("Location: checkdocument.php");
            exit;
        }
    }

    $filename = time() . '_' . preg_replace("/[^A-Za-z0-9_\-\.]/", '', $file['name']);
    $file_path = $upload_dir . $filename;

    error_log("Chuẩn bị di chuyển file đến: $file_path");
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        error_log("File di chuyển thành công: $file_path");
        $sql = "INSERT INTO checkdocuments (user_id, filename, file_path, file_size, status) VALUES (?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("issi", $user_id, $file['name'], $file_path, $file['size']);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Tài liệu đã được tải lên thành công và đang chờ duyệt!";
                error_log("Lưu tài liệu vào DB thành công: {$file['name']}, user_id: $user_id");
                // Gửi email thông báo
                sendNotificationEmail($conn, $smtp_config, $user_id, $file['name']);
            } else {
                $_SESSION['error_message'] = "Lỗi khi lưu thông tin tài liệu vào cơ sở dữ liệu!";
                error_log("Lỗi lưu vào DB: " . mysqli_error($conn));
                unlink($file_path);
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "Lỗi khi chuẩn bị truy vấn cơ sở dữ liệu!";
            error_log("Lỗi chuẩn bị truy vấn INSERT: " . mysqli_error($conn));
            unlink($file_path);
        }
    } else {
        $_SESSION['error_message'] = "Lỗi khi di chuyển file tải lên! Vui lòng thử lại.";
        error_log("Lỗi di chuyển file: {$file['tmp_name']} -> $file_path");
    }
    header("Location: checkdocument.php");
    exit;
}

// Xử lý hành động admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action']) && $is_super_admin) {
    $document_id = (int)$_POST['document_id'];
    $action = $_POST['action'];
    $admin_note = trim($_POST['admin_note'] ?? '');

    $admin_note = htmlspecialchars($admin_note, ENT_QUOTES, 'UTF-8');

    $sql = "UPDATE checkdocuments SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ssi", $action, $admin_note, $document_id);
        if (!$stmt->execute()) {
            $_SESSION['error_message'] = "Lỗi khi cập nhật trạng thái tài liệu!";
            error_log("Lỗi cập nhật trạng thái tài liệu: " . mysqli_error($conn));
            $stmt->close();
            header("Location: checkdocument.php");
            exit;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Lỗi khi chuẩn bị truy vấn cơ sở dữ liệu!";
        error_log("Lỗi chuẩn bị truy vấn UPDATE: " . mysqli_error($conn));
        header("Location: checkdocument.php");
        exit;
    }

    if ($action === 'approved' && isset($_FILES['report_files']) && !empty($_FILES['report_files']['name'][0])) {
        $upload_dir = 'uploads/checkreports/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $files = $_FILES['report_files'];

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                if (!in_array($files['type'][$i], $allowed_types)) {
                    $_SESSION['error_message'] = "File báo cáo {$files['name'][$i]} không đúng định dạng (chỉ chấp nhận PDF, DOC, DOCX)!";
                    error_log("File báo cáo không hợp lệ: {$files['name'][$i]}, type: {$files['type'][$i]}");
                    continue;
                }

                $report_filename = time() . '_' . preg_replace("/[^A-Za-z0-9_\-\.]/", '', $files['name'][$i]);
                $report_path = $upload_dir . $report_filename;

                if (move_uploaded_file($files['tmp_name'][$i], $report_path)) {
                    $sql = "INSERT INTO checkdocument_reports (checkdocument_id, filename, file_path, file_size) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("issi", $document_id, $files['name'][$i], $report_path, $files['size'][$i]);
                        if (!$stmt->execute()) {
                            $_SESSION['error_message'] = "Lỗi khi lưu file báo cáo {$files['name'][$i]}!";
                            error_log("Lỗi lưu file báo cáo: {$files['name'][$i]}, lỗi: " . mysqli_error($conn));
                            unlink($report_path);
                        }
                        $stmt->close();
                    } else {
                        $_SESSION['error_message'] = "Lỗi khi chuẩn bị truy vấn cơ sở dữ liệu!";
                        error_log("Lỗi chuẩn bị truy vấn INSERT report: " . mysqli_error($conn));
                        unlink($report_path);
                    }
                } else {
                    $_SESSION['error_message'] = "Lỗi khi tải lên file báo cáo {$files['name'][$i]}!";
                    error_log("Lỗi di chuyển file báo cáo: {$files['tmp_name'][$i]} -> $report_path");
                }
            }
        }
    }

    if (!isset($_SESSION['error_message'])) {
        $_SESSION['success_message'] = "Cập nhật trạng thái tài liệu thành công!";
    }
    header("Location: checkdocument.php");
    exit;
}

// Lấy danh sách tài liệu và file báo cáo
$checkdocuments = [];
$reports = [];
if ($is_super_admin) {
    $sql = "SELECT d.*, u.username FROM checkdocuments d JOIN users u ON d.user_id = u.id ORDER BY d.created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $checkdocuments[] = $row;
            $count++;
        }
        error_log("Lấy danh sách tài liệu (superadmin): $count tài liệu tìm thấy");
        $stmt->close();
    } else {
        $error_message = "Lỗi khi truy vấn danh sách tài liệu!";
        error_log("Lỗi chuẩn bị truy vấn danh sách tài liệu (superadmin): " . mysqli_error($conn));
    }
} else {
    $sql = "SELECT * FROM checkdocuments WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $checkdocuments[] = $row;
            $count++;
        }
        error_log("Lấy danh sách tài liệu (user_id=$user_id): $count tài liệu tìm thấy");
        $stmt->close();
    } else {
        $error_message = "Lỗi khi truy vấn danh sách tài liệu!";
        error_log("Lỗi chuẩn bị truy vấn danh sách tài liệu (user): " . mysqli_error($conn));
    }
}

// Lấy danh sách file báo cáo
$sql_reports = "SELECT * FROM checkdocument_reports";
$result_reports = mysqli_query($conn, $sql_reports);
if ($result_reports) {
    while ($row = mysqli_fetch_assoc($result_reports)) {
        $reports[$row['checkdocument_id']][] = $row;
    }
    error_log("Lấy danh sách file báo cáo: " . mysqli_num_rows($result_reports) . " file tìm thấy");
} else {
    error_log("Lỗi truy vấn file báo cáo: " . mysqli_error($conn));
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiểm Tra Tài Liệu</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-gradient-start: #4facfe;
            --primary-gradient-end: #00f2fe;
            --secondary-gradient-start: #00f2fe;
            --secondary-gradient-end: #4facfe;
            --background-gradient: linear-gradient(135deg, #e0e7ff, #a5f3fc);
            --container-bg: rgba(255, 255, 255, 0.95);
            --card-bg: #ffffff;
            --form-bg: #f8fafc;
            --hover-bg: #eff6ff;
            --text-color: #1e293b;
            --text-secondary: #64748b;
            --link-color: #0284c7;
            --link-hover-color: #0369a1;
            --error-color: #dc2626;
            --success-color: #16a34a;
            --warning-color: #d97706;
            --info-color: #2563eb;
            --approve-color: #16a34a;
            --approve-hover-color: #15803d;
            --reject-color: #dc2626;
            --reject-hover-color: #b91c1c;
            --shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 12px 32px rgba(0, 0, 0, 0.15);
            --border-radius: 1.25rem;
            --small-radius: 0.75rem;
            --button-radius: 2rem;
            --padding: 2rem;
            --small-padding: 1rem;
            --transition-speed: 0.3s;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
            line-height: 1.6;
        }
        .document-container {
            background: var(--container-bg);
            padding: var(--padding);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }
        .document-container:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        h2 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            padding-bottom: 0.5rem;
        }
        h2:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 120px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 2px;
        }
        h3 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        h3:before {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            color: var(--primary-gradient-start);
            content: "\f15c";
        }
        .error-message, .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--small-radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .error-message {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        .error-message:before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }
        .success-message {
            background-color: rgba(22, 163, 74, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        .success-message:before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }
        .upload-form, .settings-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            background: var(--form-bg);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            position: relative;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .form-group label {
            font-weight: 500;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-group label i {
            color: var(--primary-gradient-start);
        }
        .upload-form input[type="file"],
        .settings-form input[type="number"] {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid rgba(79, 172, 254, 0.5);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            transition: all var(--transition-speed) ease;
            color: var(--text-color);
            box-shadow: var(--shadow-sm);
        }
        .upload-form button,
        .settings-form button {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-speed) cubic-bezier(0.22, 1, 0.36, 1);
            box-shadow: 0 4px 10px rgba(79, 172, 254, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            align-self: center;
        }
        .upload-form button:before {
            content: "\f093";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        .settings-form button:before {
            content: "\f0c7";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        .upload-form button:hover,
        .settings-form button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(79, 172, 254, 0.4);
        }
        .document-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .document-card {
            background: var(--card-bg);
            border-radius: var(--small-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }
        .document-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }
        .document-info {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            background: linear-gradient(180deg, #ffffff, #f8fafc);
        }
        .document-info p {
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .document-info strong {
            color: var(--text-color);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .document-info strong i {
            color: var(--primary-gradient-start);
            width: 20px;
            text-align: center;
        }
        .document-info a {
            color: var(--link-color);
            text-decoration: none;
            transition: color var(--transition-speed) ease;
        }
        .document-info a:hover {
            color: var(--link-hover-color);
            text-decoration: underline;
        }
        .status-pending { color: var(--warning-color); }
        .status-approved { color: var(--success-color); }
        .status-rejected { color: var(--error-color); }
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }
        .approve-btn, .reject-btn {
            flex: 1;
            padding: 0.5rem 0.75rem;
            border-radius: var(--small-radius);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }
        .approve-btn {
            background: var(--approve-color);
            color: white;
        }
        .approve-btn:before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        .approve-btn:hover {
            background: var(--approve-hover-color);
            transform: translateY(-2px);
        }
        .reject-btn {
            background: var(--reject-color);
            color: white;
        }
        .reject-btn:before {
            content: "\f00d";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        .reject-btn:hover {
            background: var(--reject-hover-color);
            transform: translateY(-2px);
        }
        .admin-form {
            margin-top: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .admin-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 0.75rem;
            border: 2px solid rgba(79, 172, 254, 0.5);
            border-radius: var(--small-radius);
            resize: vertical;
            transition: border-color var(--transition-speed) ease;
        }
        .admin-form textarea:focus {
            border-color: var(--primary-gradient-start);
            outline: none;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--primary-gradient-start);
            opacity: 0.7;
            animation: pulse 2s infinite;
        }
        .empty-state p {
            font-size: 1.1rem;
            max-width: 500px;
            margin: 0 auto;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        @media (max-width: 768px) {
            :root { --padding: 1.5rem; --small-padding: 0.75rem; }
            .document-container { padding: var(--padding); margin: 1rem; }
            h2 { font-size: 1.8rem; }
            h3 { font-size: 1.5rem; }
            .document-list { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; gap: 0.5rem; }
            .approve-btn, .reject-btn { width: 100%; }
            .upload-form button, .settings-form button { width: 100%; font-size: 1rem; padding: 0.75rem 1rem; }
        }
        @media (max-width: 480px) {
            :root { --padding: 1rem; --small-padding: 0.5rem; }
            .document-container { padding: var(--padding); margin: 0.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
            .upload-form input[type="file"],
            .settings-form input[type="number"] { font-size: 0.9rem; padding: 0.6rem 0.75rem; }
            .document-info { padding: 1rem; gap: 0.5rem; }
            .document-info p { font-size: 0.85rem; }
            .approve-btn, .reject-btn { font-size: 0.8rem; padding: 0.4rem 0.6rem; }
            .action-buttons { padding: 0.75rem 1rem; }
        }
    </style>
</head>
<body>
    <div class="document-container">
        <h2>Kiểm Tra Tài Liệu</h2>

        <?php if (file_exists('taskbar.php')) include 'taskbar.php'; ?>

        <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($is_super_admin): ?>
            <div class="settings-form">
                <h3>Cài đặt giới hạn tải lên</h3>
                <form method="POST">
                    <div class="form-group">
                        <label><i class="fas fa-cog"></i> Số lần tải lên mỗi tháng</label>
                        <input type="number" name="upload_limit" value="<?php echo $upload_limit; ?>" min="1" required>
                    </div>
                    <button type="submit" name="update_upload_limit">Cập nhật</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$is_super_admin): ?>
            <div class="upload-form">
                <h3>Tải lên tài liệu</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label><i class="fas fa-file-upload"></i> Chọn tài liệu (PDF, DOC, DOCX - Tối đa 20MB)</label>
                        <input type="file" name="document" accept=".pdf,.doc,.docx" required>
                    </div>
                    <button type="submit" name="upload_document">Tải Lên</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="document-list">
            <h3>Danh sách tài liệu</h3>
            <?php if (empty($checkdocuments)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <p>Chưa có tài liệu nào được tải lên. Hãy tải lên tài liệu đầu tiên của bạn!</p>
                </div>
            <?php else: ?>
                <?php foreach ($checkdocuments as $doc): ?>
                    <div class="document-card">
                        <div class="document-info">
                            <?php if ($is_super_admin): ?>
                                <p>
                                    <strong><i class="fas fa-user"></i> Người dùng:</strong>
                                    <?php echo htmlspecialchars($doc['username']); ?>
                                </p>
                            <?php endif; ?>
                            <p>
                                <strong><i class="fas fa-file"></i> Tên file:</strong>
                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download><?php echo htmlspecialchars($doc['filename']); ?></a>
                            </p>
                            <p>
                                <strong><i class="fas fa-info-circle"></i> Trạng thái:</strong>
                                <span class="status-<?php echo $doc['status']; ?>">
                                    <?php echo $doc['status'] === 'pending' ? 'Chờ duyệt' : ($doc['status'] === 'approved' ? 'Đã duyệt' : 'Bị từ chối'); ?>
                                </span>
                            </p>
                            <p>
                                <strong><i class="fas fa-clock"></i> Ngày tải lên:</strong>
                                <?php echo htmlspecialchars($doc['created_at']); ?>
                            </p>
                            <?php if ($doc['admin_note']): ?>
                                <p>
                                    <strong><i class="fas fa-comment"></i> Ghi chú admin:</strong>
                                    <?php echo htmlspecialchars($doc['admin_note']); ?>
                                </p>
                            <?php endif; ?>
                            <?php if (isset($reports[$doc['id']]) && $doc['status'] === 'approved'): ?>
                                <p>
                                    <strong><i class="fas fa-file-download"></i> Báo cáo:</strong>
                                    <?php foreach ($reports[$doc['id']] as $report): ?>
                                        <a href="<?php echo htmlspecialchars($report['file_path']); ?>" download><?php echo htmlspecialchars($report['filename']); ?></a><br>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_super_admin && $doc['status'] === 'pending'): ?>
                            <div class="action-buttons">
                                <form method="POST" enctype="multipart/form-data" class="admin-form">
                                    <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                    <textarea name="admin_note" placeholder="Nhập ghi chú (tùy chọn)"></textarea>
                                    <div class="form-group">
                                        <label><i class="fas fa-file-upload"></i> File báo cáo (PDF, DOC, DOCX - Có thể chọn nhiều file)</label>
                                        <input type="file" name="report_files[]" accept=".pdf,.doc,.docx" multiple>
                                    </div>
                                    <div class="action-buttons">
                                        <button type="submit" name="admin_action" value="approve" class="approve-btn">Duyệt</button>
                                        <button type="submit" name="admin_action" value="reject" class="reject-btn">Từ chối</button>
                                    </div>
                                    <input type="hidden" name="action" value="approved">
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        window.onload = function() {
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
        };
    </script>
</body>
</html>
<?php
ob_end_flush();
mysqli_close($conn);
?>