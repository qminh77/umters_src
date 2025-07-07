<?php
session_start();
include 'db_config.php';

// Kiểm tra quyền truy cập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Kiểm tra thư mục uploads/attachments
$upload_dir = 'uploads/attachments/';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        die("Không thể tạo thư mục uploads/attachments. Vui lòng kiểm tra quyền truy cập.");
    }
}

// Kiểm tra quyền ghi cho thư mục uploads/attachments
if (!is_writable($upload_dir)) {
    die("Thư mục uploads/attachments không có quyền ghi. Vui lòng kiểm tra quyền truy cập.");
}

// Kiểm tra PHPMailer
if (!file_exists('vendor/autoload.php')) {
    die("Thư viện PHPMailer chưa được cài đặt. Vui lòng chạy lệnh: composer require phpmailer/phpmailer");
}

require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Xử lý AJAX requests
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    switch ($_POST['ajax_action']) {
        case 'save_draft':
            $smtp_id = $_POST['smtp_id'] ?? null;
            $recipient_email = $_POST['recipient_email'] ?? '';
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO email_drafts (user_id, smtp_config_id, recipient_email, subject, body) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = ?, body = ?, updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iisssss", $user_id, $smtp_id, $recipient_email, $subject, $body, $subject, $body);
            echo json_encode(['success' => $stmt->execute()]);
            exit;

        case 'delete_email':
            $email_id = $_POST['email_id'];
            $stmt = $conn->prepare("UPDATE email_history SET is_deleted = TRUE WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $email_id, $user_id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;

        case 'toggle_star':
            $email_id = $_POST['email_id'];
            $stmt = $conn->prepare("UPDATE email_history SET is_starred = NOT is_starred WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $email_id, $user_id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;

        case 'toggle_important':
            $email_id = $_POST['email_id'];
            $stmt = $conn->prepare("UPDATE email_history SET is_important = NOT is_important WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $email_id, $user_id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;

        case 'toggle_hidden':
            $email_id = $_POST['email_id'];
            $stmt = $conn->prepare("UPDATE email_history SET is_hidden = NOT is_hidden WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $email_id, $user_id);
            echo json_encode(['success' => $stmt->execute()]);
            exit;
    }
}

// Xử lý gửi email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    $smtp_id = $_POST['smtp_id'];
    $recipient_email = $_POST['recipient_email'];
    $subject = $_POST['subject'];
    $body = $_POST['body'];

    $stmt = $conn->prepare("SELECT * FROM smtp_configs WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $smtp_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $smtp_config = $result->fetch_assoc();

    if ($smtp_config) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtp_config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['smtp_username'];
            $mail->Password = $smtp_config['smtp_password'];
            $mail->SMTPSecure = $smtp_config['smtp_encryption'];
            $mail->Port = $smtp_config['smtp_port'];
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
            $mail->addAddress($recipient_email);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;

            // Xử lý file đính kèm
            if (isset($_FILES['attachments'])) {
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        
                        if ($file_size <= 100 * 1024 * 1024) { // 100MB
                            $file_path = $upload_dir . uniqid() . '_' . $file_name;
                            move_uploaded_file($tmp_name, $file_path);
                            
                            $mail->addAttachment($file_path, $file_name);
                        }
                    }
                }
            }

            $mail->send();

            // Lưu vào lịch sử
            $stmt = $conn->prepare("INSERT INTO email_history (user_id, smtp_config_id, recipient_email, subject, body, status) VALUES (?, ?, ?, ?, ?, 'sent')");
            $stmt->bind_param("iisss", $user_id, $smtp_id, $recipient_email, $subject, $body);
            $stmt->execute();
            $email_id = $conn->insert_id;

            // Lưu thông tin file đính kèm
            if (isset($_FILES['attachments'])) {
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        $file_path = $upload_dir . uniqid() . '_' . $file_name;
                        
                        $stmt = $conn->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("issis", $email_id, $file_name, $file_path, $file_size, $file_type);
                        $stmt->execute();
                    }
                }
            }

            $success_message = "Email đã được gửi thành công!";
        } catch (Exception $e) {
            $stmt = $conn->prepare("INSERT INTO email_history (user_id, smtp_config_id, recipient_email, subject, body, status, error_message) VALUES (?, ?, ?, ?, ?, 'failed', ?)");
            $error_msg = $mail->ErrorInfo;
            $stmt->bind_param("iissss", $user_id, $smtp_id, $recipient_email, $subject, $body, $error_msg);
            $stmt->execute();

            $error_message = "Lỗi khi gửi email: " . $mail->ErrorInfo;
        }
    } else {
        $error_message = "Không tìm thấy cấu hình SMTP!";
    }
}

// Lấy danh sách cấu hình SMTP
$stmt = $conn->prepare("SELECT * FROM smtp_configs WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$smtp_configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy lịch sử email
$stmt = $conn->prepare("SELECT eh.*, sc.smtp_name FROM email_history eh JOIN smtp_configs sc ON eh.smtp_config_id = sc.id WHERE eh.user_id = ? AND eh.is_deleted = FALSE ORDER BY eh.created_at DESC LIMIT 50");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$email_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy danh sách nháp
$stmt = $conn->prepare("SELECT * FROM email_drafts WHERE user_id = ? ORDER BY updated_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$drafts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy danh sách email đến
$stmt = $conn->prepare("SELECT ei.*, sc.smtp_name FROM email_inbox ei JOIN smtp_configs sc ON ei.smtp_config_id = sc.id WHERE ei.user_id = ? AND ei.is_hidden = FALSE ORDER BY ei.received_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$inbox = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Email</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #60a5fa;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #1f2937;
            --text-secondary: #6b7280;
            --bg-primary: #ffffff;
            --bg-secondary: #f3f4f6;
            --border-color: #e5e7eb;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --radius: 0.5rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-secondary);
            color: var(--text-color);
            line-height: 1.6;
            margin: 0;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--bg-primary);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            margin: 0;
            font-size: 1.875rem;
            font-weight: 700;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
        }

        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
        }

        textarea {
            min-height: 200px;
            resize: vertical;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: var(--bg-primary);
            border-radius: var(--radius);
            padding: 2rem;
            max-width: 600px;
            margin: 2rem auto;
            position: relative;
        }

        .close-modal {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            font-weight: 600;
            background: var(--bg-secondary);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-sent {
            background: #dcfce7;
            color: #166534;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .email-form {
            background: var(--bg-secondary);
            padding: 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
        }

        .history-section {
            background: var(--bg-primary);
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .editor-container {
            height: 300px;
            margin-bottom: 1rem;
        }

        .file-upload {
            margin-bottom: 1rem;
        }

        .file-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--bg-secondary);
            border-radius: var(--radius);
            margin-bottom: 0.5rem;
        }

        .file-item button {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }

        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .tab.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .email-actions {
            display: flex;
            gap: 0.5rem;
        }

        .email-actions button {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        .starred {
            color: #fbbf24;
        }

        .important {
            color: var(--danger-color);
        }

        .hidden {
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Quản Lý Email</h1>
            <button class="btn btn-secondary" onclick="openSmtpModal()">
                <i class="fas fa-cog"></i>
                Cấu Hình SMTP
            </button>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab active" data-tab="compose">Soạn Email</div>
            <div class="tab" data-tab="drafts">Nháp</div>
            <div class="tab" data-tab="sent">Đã Gửi</div>
            <div class="tab" data-tab="inbox">Hộp Thư Đến</div>
            <div class="tab" data-tab="important">Quan Trọng</div>
        </div>

        <!-- Tab Soạn Email -->
        <div class="tab-content active" id="compose">
            <div class="email-form">
                <form method="POST" enctype="multipart/form-data" id="emailForm">
                    <input type="hidden" name="action" value="send_email">
                    
                    <div class="form-group">
                        <label for="smtp_id">Chọn Cấu Hình SMTP</label>
                        <select id="smtp_id" name="smtp_id" required>
                            <?php foreach ($smtp_configs as $config): ?>
                                <option value="<?php echo $config['id']; ?>">
                                    <?php echo htmlspecialchars($config['smtp_name']); ?>
                                    <?php echo $config['is_default'] ? ' (Mặc định)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="recipient_email">Email Người Nhận</label>
                        <input type="email" id="recipient_email" name="recipient_email" required>
                    </div>

                    <div class="form-group">
                        <label for="subject">Tiêu Đề</label>
                        <input type="text" id="subject" name="subject" required>
                    </div>

                    <div class="form-group">
                        <label for="body">Nội Dung</label>
                        <div id="editor" class="editor-container"></div>
                        <input type="hidden" name="body" id="body">
                    </div>

                    <div class="form-group">
                        <label for="attachments">File Đính Kèm (tối đa 100MB)</label>
                        <input type="file" id="attachments" name="attachments[]" multiple>
                        <div id="fileList" class="file-list"></div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="saveDraft()">
                            <i class="fas fa-save"></i>
                            Lưu Nháp
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            Gửi Email
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tab Nháp -->
        <div class="tab-content" id="drafts">
            <table class="table">
                <thead>
                    <tr>
                        <th>Người Nhận</th>
                        <th>Tiêu Đề</th>
                        <th>Cập Nhật</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drafts as $draft): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($draft['recipient_email']); ?></td>
                            <td><?php echo htmlspecialchars($draft['subject']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($draft['updated_at'])); ?></td>
                            <td>
                                <button class="btn btn-primary" onclick="loadDraft(<?php echo $draft['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                    Chỉnh Sửa
                                </button>
                                <button class="btn btn-danger" onclick="deleteDraft(<?php echo $draft['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                    Xóa
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Tab Đã Gửi -->
        <div class="tab-content" id="sent">
            <table class="table">
                <thead>
                    <tr>
                        <th>Thời Gian</th>
                        <th>Người Nhận</th>
                        <th>Tiêu Đề</th>
                        <th>Trạng Thái</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($email_history as $email): ?>
                        <tr class="<?php echo $email['is_hidden'] ? 'hidden' : ''; ?>">
                            <td><?php echo date('d/m/Y H:i', strtotime($email['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($email['recipient_email']); ?></td>
                            <td><?php echo htmlspecialchars($email['subject']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $email['status']; ?>">
                                    <?php echo $email['status'] === 'sent' ? 'Đã gửi' : 'Thất bại'; ?>
                                </span>
                            </td>
                            <td class="email-actions">
                                <button onclick="toggleStar(<?php echo $email['id']; ?>)" class="<?php echo $email['is_starred'] ? 'starred' : ''; ?>">
                                    <i class="fas fa-star"></i>
                                </button>
                                <button onclick="toggleImportant(<?php echo $email['id']; ?>)" class="<?php echo $email['is_important'] ? 'important' : ''; ?>">
                                    <i class="fas fa-exclamation"></i>
                                </button>
                                <button onclick="toggleHidden(<?php echo $email['id']; ?>)">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                                <button onclick="deleteEmail(<?php echo $email['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Tab Hộp Thư Đến -->
        <div class="tab-content" id="inbox">
            <table class="table">
                <thead>
                    <tr>
                        <th>Thời Gian</th>
                        <th>Người Gửi</th>
                        <th>Tiêu Đề</th>
                        <th>Trạng Thái</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inbox as $email): ?>
                        <tr class="<?php echo $email['is_hidden'] ? 'hidden' : ''; ?>">
                            <td><?php echo date('d/m/Y H:i', strtotime($email['received_at'])); ?></td>
                            <td><?php echo htmlspecialchars($email['sender_name'] ?? $email['sender_email']); ?></td>
                            <td><?php echo htmlspecialchars($email['subject']); ?></td>
                            <td>
                                <?php if (!$email['is_read']): ?>
                                    <span class="status-badge">Chưa đọc</span>
                                <?php endif; ?>
                            </td>
                            <td class="email-actions">
                                <button onclick="replyEmail(<?php echo $email['id']; ?>)">
                                    <i class="fas fa-reply"></i>
                                </button>
                                <button onclick="toggleStar(<?php echo $email['id']; ?>)" class="<?php echo $email['is_starred'] ? 'starred' : ''; ?>">
                                    <i class="fas fa-star"></i>
                                </button>
                                <button onclick="toggleImportant(<?php echo $email['id']; ?>)" class="<?php echo $email['is_important'] ? 'important' : ''; ?>">
                                    <i class="fas fa-exclamation"></i>
                                </button>
                                <button onclick="toggleHidden(<?php echo $email['id']; ?>)">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Tab Quan Trọng -->
        <div class="tab-content" id="important">
            <table class="table">
                <thead>
                    <tr>
                        <th>Thời Gian</th>
                        <th>Người Gửi/Nhận</th>
                        <th>Tiêu Đề</th>
                        <th>Loại</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Hiển thị email quan trọng từ cả hộp thư đến và đã gửi
                    $important_emails = array_merge(
                        array_filter($inbox, function($email) { return $email['is_important']; }),
                        array_filter($email_history, function($email) { return $email['is_important']; })
                    );
                    usort($important_emails, function($a, $b) {
                        return strtotime($b['received_at'] ?? $b['created_at']) - strtotime($a['received_at'] ?? $a['created_at']);
                    });
                    foreach ($important_emails as $email): 
                    ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($email['received_at'] ?? $email['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($email['sender_email'] ?? $email['recipient_email']); ?></td>
                            <td><?php echo htmlspecialchars($email['subject']); ?></td>
                            <td><?php echo isset($email['sender_email']) ? 'Đến' : 'Đã gửi'; ?></td>
                            <td class="email-actions">
                                <?php if (isset($email['sender_email'])): ?>
                                    <button onclick="replyEmail(<?php echo $email['id']; ?>)">
                                        <i class="fas fa-reply"></i>
                                    </button>
                                <?php endif; ?>
                                <button onclick="toggleImportant(<?php echo $email['id']; ?>)" class="important">
                                    <i class="fas fa-exclamation"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Cấu Hình SMTP -->
    <div id="smtpModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeSmtpModal()">&times;</span>
            <h2>Cấu Hình SMTP</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_smtp">
                
                <div class="form-group">
                    <label for="smtp_name">Tên Cấu Hình</label>
                    <input type="text" id="smtp_name" name="smtp_name" required>
                </div>

                <div class="form-group">
                    <label for="smtp_host">SMTP Host</label>
                    <input type="text" id="smtp_host" name="smtp_host" required>
                </div>

                <div class="form-group">
                    <label for="smtp_port">SMTP Port</label>
                    <input type="number" id="smtp_port" name="smtp_port" required>
                </div>

                <div class="form-group">
                    <label for="smtp_username">SMTP Username</label>
                    <input type="text" id="smtp_username" name="smtp_username" required>
                </div>

                <div class="form-group">
                    <label for="smtp_password">SMTP Password</label>
                    <input type="password" id="smtp_password" name="smtp_password" required>
                </div>

                <div class="form-group">
                    <label for="smtp_encryption">Mã Hóa</label>
                    <select id="smtp_encryption" name="smtp_encryption" required>
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="from_name">Tên Người Gửi</label>
                    <input type="text" id="from_name" name="from_name" required>
                </div>

                <div class="form-group">
                    <label for="from_email">Email Người Gửi</label>
                    <input type="email" id="from_email" name="from_email" required>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_default" value="1">
                        Đặt làm mặc định
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">Lưu Cấu Hình</button>
            </form>

            <h3 class="mt-8">Danh Sách Cấu Hình</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Tên</th>
                        <th>Host</th>
                        <th>Port</th>
                        <th>Username</th>
                        <th>Mặc Định</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($smtp_configs as $config): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($config['smtp_name']); ?></td>
                            <td><?php echo htmlspecialchars($config['smtp_host']); ?></td>
                            <td><?php echo htmlspecialchars($config['smtp_port']); ?></td>
                            <td><?php echo htmlspecialchars($config['smtp_username']); ?></td>
                            <td><?php echo $config['is_default'] ? '✓' : ''; ?></td>
                            <td>
                                <button class="btn btn-danger" onclick="deleteConfig(<?php echo $config['id']; ?>)">Xóa</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Khởi tạo Quill Editor
        var quill = new Quill('#editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'align': [] }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });

        // Lưu nội dung editor vào hidden input trước khi submit
        document.getElementById('emailForm').onsubmit = function() {
            document.getElementById('body').value = quill.root.innerHTML;
            return true;
        };

        // Xử lý file đính kèm
        document.getElementById('attachments').onchange = function() {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            for (let i = 0; i < this.files.length; i++) {
                const file = this.files[i];
                const fileSize = file.size / (1024 * 1024); // Convert to MB
                
                if (fileSize > 100) {
                    alert('File ' + file.name + ' vượt quá giới hạn 100MB');
                    continue;
                }
                
                const li = document.createElement('div');
                li.className = 'file-item';
                li.innerHTML = `
                    <span>${file.name} (${fileSize.toFixed(2)}MB)</span>
                    <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                fileList.appendChild(li);
            }
        };

        // Tự động lưu nháp mỗi 30 giây
        setInterval(saveDraft, 30000);

        function saveDraft() {
            const formData = new FormData();
            formData.append('ajax_action', 'save_draft');
            formData.append('smtp_id', document.getElementById('smtp_id').value);
            formData.append('recipient_email', document.getElementById('recipient_email').value);
            formData.append('subject', document.getElementById('subject').value);
            formData.append('body', quill.root.innerHTML);

            fetch('email_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Đã lưu nháp');
                }
            });
        }

        function loadDraft(draftId) {
            fetch('get_draft.php?id=' + draftId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('smtp_id').value = data.smtp_config_id;
                    document.getElementById('recipient_email').value = data.recipient_email;
                    document.getElementById('subject').value = data.subject;
                    quill.root.innerHTML = data.body;
                    
                    // Chuyển sang tab soạn thảo
                    document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                    document.querySelector('[data-tab="compose"]').classList.add('active');
                    document.getElementById('compose').classList.add('active');
                });
        }

        function deleteDraft(draftId) {
            if (confirm('Bạn có chắc chắn muốn xóa nháp này?')) {
                fetch('delete_draft.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${draftId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error);
                    }
                });
            }
        }

        function toggleStar(emailId) {
            fetch('email_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=toggle_star&email_id=${emailId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function toggleImportant(emailId) {
            fetch('email_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=toggle_important&email_id=${emailId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function toggleHidden(emailId) {
            fetch('email_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=toggle_hidden&email_id=${emailId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function deleteEmail(emailId) {
            if (confirm('Bạn có chắc chắn muốn xóa email này?')) {
                fetch('email_manager.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_action=delete_email&email_id=${emailId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
        }

        function replyEmail(emailId) {
            fetch('get_email.php?id=' + emailId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('recipient_email').value = data.sender_email;
                    document.getElementById('subject').value = 'Re: ' + data.subject;
                    quill.root.innerHTML = `<br><br><blockquote>${data.body}</blockquote>`;
                    
                    // Chuyển sang tab soạn thảo
                    document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                    document.querySelector('[data-tab="compose"]').classList.add('active');
                    document.getElementById('compose').classList.add('active');
                });
        }

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });

        function openSmtpModal() {
            document.getElementById('smtpModal').style.display = 'block';
        }

        function closeSmtpModal() {
            document.getElementById('smtpModal').style.display = 'none';
        }

        // Đóng modal khi click ra ngoài
        window.onclick = function(event) {
            if (event.target == document.getElementById('smtpModal')) {
                closeSmtpModal();
            }
        }
    </script>
</body>
</html> 