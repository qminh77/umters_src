<?php
session_start();
include 'db_config.php';

// Bật báo lỗi để debug (tắt trên production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Tạo CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Khởi tạo các biến lỗi và thành công
$edit_error = '';
$email_error = '';
$email_success = '';

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin, email FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $edit_error = "Lỗi kết nối cơ sở dữ liệu. Vui lòng thử lại sau.";
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $edit_error = "Lỗi truy vấn cơ sở dữ liệu. Vui lòng thử lại sau.";
    } else {
        $result_user = $stmt->get_result();
        if ($result_user && $result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
        } else {
            $edit_error = "Lỗi khi lấy thông tin người dùng.";
            $user = [
                'username' => 'Unknown',
                'full_name' => '',
                'is_main_admin' => 0,
                'is_super_admin' => 0,
                'email' => ''
            ];
        }
    }
    $stmt->close();
}

// Kiểm tra quyền super admin hoặc main admin
if (!$user['is_super_admin'] && !$user['is_main_admin']) {
    header("Location: /dashboard");
    exit;
}

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Xử lý gửi email
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_email']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $recipient = filter_input(INPUT_POST, 'recipient', FILTER_SANITIZE_EMAIL);
    $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $body = filter_input(INPUT_POST, 'body', FILTER_SANITIZE_STRING);

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $email_error = "Địa chỉ email không hợp lệ!";
    } elseif (empty($subject) || empty($body)) {
        $email_error = "Vui lòng điền đầy đủ chủ đề và nội dung!";
    } elseif (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $email_error = "Email người gửi không hợp lệ! Vui lòng cập nhật email trong profile.";
    } else {
        $mail = new PHPMailer(true);
        try {
            // Bật debug SMTP
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP Debug level $level: $str");
            };

            // Cấu hình SMTP từ db_config.php
            $mail->isSMTP();
            $mail->Host = $smtp_config['host'];
            $mail->SMTPAuth = $smtp_config['smtp_auth'];
            $mail->Username = $smtp_config['username'];
            $mail->Password = $smtp_config['password'];
            $mail->SMTPSecure = $smtp_config['smtp_secure'];
            $mail->Port = $smtp_config['port'];
            $mail->CharSet = 'UTF-8';

            // Thiết lập người gửi và người nhận
            $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
            $mail->addReplyTo($user['email'], $user['full_name'] ?: $user['username']);
            $mail->addAddress($recipient);

            // Nội dung email
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = nl2br(htmlspecialchars($body));
            $mail->AltBody = strip_tags($body);

            // Gửi email
            $mail->send();

            // Lưu log email
            $stmt = $conn->prepare("INSERT INTO email_logs (recipient, subject, content, send_time, status) VALUES (?, ?, ?, NOW(), 'Sent')");
            if (!$stmt) {
                error_log("Prepare failed for email_logs: " . $conn->error);
                $email_error = "Lỗi lưu log email.";
            } else {
                $stmt->bind_param("sss", $recipient, $subject, $body);
                if (!$stmt->execute()) {
                    error_log("Execute failed for email_logs: " . $stmt->error);
                    $email_error = "Lỗi lưu log email.";
                }
                $stmt->close();
            }

            $email_success = "Gửi email thành công!";
        } catch (Exception $e) {
            error_log("SMTP Error: " . $mail->ErrorInfo);
            $email_error = "Lỗi khi gửi email: " . $mail->ErrorInfo;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi Email - Quản Lý Hiện Đại</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #74ebd5, #acb6e5);
            --container-bg: rgba(255, 255, 255, 0.95);
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --text-secondary: #64748b;
            --link-color: #3b82f6;
            --link-hover-color: #2563eb;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --border-radius: 1.5rem;
            --button-radius: 0.75rem;
            --padding: 1.5rem;
            --small-padding: 1rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
            line-height: 1.6;
        }

        .dashboard-container {
            background: var(--container-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1400px;
            padding: var(--padding);
            margin: 1.5rem auto;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            z-index: 5;
        }

        .dashboard-container:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-title {
            font-size: 1.875rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradientShift 5s ease infinite;
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
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            color: var(--text-color);
        }

        .user-role {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .content-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: var(--padding);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            opacity: 0.8;
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary-gradient-start);
            font-size: 1.25rem;
        }

        .form-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: var(--padding);
            box-shadow: var(--shadow-sm);
            max-width: 600px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .form-group input[type="email"],
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input[type="email"]:focus,
        .form-group input[type="text"]:focus,
        .form-group textarea:focus {
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.25);
            outline: none;
        }

        .form-group button {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-group button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .error-message,
        .success-message {
            padding: 0.75rem 1rem;
            border-radius: var(--button-radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 2.5rem;
            animation: slideIn 0.5s ease;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            :root {
                --padding: 1rem;
                --small-padding: 0.75rem;
            }

            .dashboard-container {
                margin: 1rem;
                padding: var(--padding);
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .form-container {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            .form-group input,
            .form-group textarea,
            .form-group button {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Gửi Email</h1>
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

        <?php include 'taskbar.php'; ?>

        <?php if (!empty($edit_error)): ?>
            <p class="error-message"><?php echo htmlspecialchars($edit_error); ?></p>
        <?php endif; ?>
        <?php if (!empty($email_error)): ?>
            <p class="error-message"><?php echo htmlspecialchars($email_error); ?></p>
        <?php endif; ?>
        <?php if (!empty($email_success)): ?>
            <p class="success-message"><?php echo htmlspecialchars($email_success); ?></p>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-envelope"></i> Gửi Email</h2>
            <div class="form-container">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="recipient">Email người nhận</label>
                        <input type="email" id="recipient" name="recipient" placeholder="Nhập email người nhận" required>
                    </div>
                    <div class="form-group">
                        <label for="subject">Chủ đề</label>
                        <input type="text" id="subject" name="subject" placeholder="Nhập chủ đề email" required>
                    </div>
                    <div class="form-group">
                        <label for="body">Nội dung</label>
                        <textarea id="body" name="body" placeholder="Nhập nội dung email" required></textarea>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="send_email">Gửi Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const elements = document.querySelectorAll('.content-section, .form-container');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>