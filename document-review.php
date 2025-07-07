<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php'; // Adjust path if PHPMailer is installed differently

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin người dùng
$user_query = "SELECT is_super_admin FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$is_super_admin = $user_data['is_super_admin'] ?? 0;

// Tạo bảng document_uploads nếu chưa tồn tại
$sql_create_uploads = "CREATE TABLE IF NOT EXISTS document_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_create_uploads) or die("Error creating document_uploads table: " . mysqli_error($conn));

// Tạo bảng document_reviews nếu chưa tồn tại
$sql_create_reviews = "CREATE TABLE IF NOT EXISTS document_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    admin_id INT NOT NULL,
    notes TEXT,
    review_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES document_uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_create_reviews) or die("Error creating document_reviews table: " . mysqli_error($conn));

// Tạo bảng document_results nếu chưa tồn tại
$sql_create_results = "CREATE TABLE IF NOT EXISTS document_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    admin_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES document_uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_create_results) or die("Error creating document_results table: " . mysqli_error($conn));

// Hàm gửi email thông báo
function sendEmailNotification($conn, $smtp_config, $recipient, $subject, $body) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_config['host'];
        $mail->SMTPAuth = $smtp_config['smtp_auth'];
        $mail->Username = $smtp_config['username'];
        $mail->Password = $smtp_config['password'];
        $mail->SMTPSecure = $smtp_config['smtp_secure'];
        $mail->Port = $smtp_config['port'];
        $mail->CharSet = 'UTF-8';

        // Sender and recipient
        $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
        $mail->addAddress($recipient);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        // Send email
        $mail->send();

        // Log email to email_logs table
        $sql = "INSERT INTO email_logs (recipient, subject, content, status) VALUES (?, ?, ?, 'Sent')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $recipient, $subject, $body);
        $stmt->execute();

        return true;
    } catch (Exception $e) {
        // Log failure to email_logs table
        $sql = "INSERT INTO email_logs (recipient, subject, content, status) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $status = 'Failed: ' . $mail->ErrorInfo;
        $stmt->bind_param("ssss", $recipient, $subject, $body, $status);
        $stmt->execute();

        return false;
    }
}

// Khởi tạo biến thông báo và tab mặc định
$success_message = '';
$error_message = '';

// Lấy thông báo từ session nếu có
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : ($is_super_admin ? 'review' : 'upload');

// Xử lý tải lên tài liệu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    // Kiểm tra xem có file được tải lên không
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        
        // Kiểm tra kích thước file (20MB = 20 * 1024 * 1024 bytes)
        $max_size = 20 * 1024 * 1024;
        if ($file_size > $max_size) {
            $_SESSION['error_message'] = "Kích thước file vượt quá giới hạn 20MB.";
            header("Location: document-review?tab=upload");
            exit;
        } else {
            // Kiểm tra loại file
            $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            
            if (!in_array($file_type, $allowed_types) && !in_array(strtolower($file_extension), ['pdf', 'doc', 'docx'])) {
                $_SESSION['error_message'] = "Chỉ chấp nhận file PDF, DOC hoặc DOCX.";
                header("Location: document-review?tab=upload");
                exit;
            } else {
                // Tạo thư mục uploads nếu chưa tồn tại
                $upload_dir = 'uploads/documents/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Tạo tên file duy nhất
                $new_filename = uniqid('doc_') . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $new_filename;
                
                // Di chuyển file tải lên vào thư mục đích
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Lưu thông tin file vào cơ sở dữ liệu
                    $sql = "INSERT INTO document_uploads (user_id, filename, original_filename, file_path, file_size, file_type) 
                            VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isssss", $user_id, $new_filename, $file_name, $file_path, $file_size, $file_type);
                    
                    if ($stmt->execute()) {
                        // Lấy thông tin người dùng để thêm vào email
                        $user_query = "SELECT username, full_name FROM users WHERE id = ?";
                        $user_stmt = $conn->prepare($user_query);
                        $user_stmt->bind_param("i", $user_id);
                        $user_stmt->execute();
                        $user_result = $user_stmt->get_result();
                        $user_data = $user_result->fetch_assoc();
                        
                        // Chuẩn bị nội dung email
                        $recipient = 'minhminh3456minh@gmail.com';
                        $subject = 'Thông báo: Tài liệu mới được tải lên để kiểm tra';
                        $body = '
                            <h2>Tài liệu mới chờ kiểm tra</h2>
                            <p>Một tài liệu mới đã được tải lên và đang chờ kiểm tra. Dưới đây là thông tin chi tiết:</p>
                            <ul>
                                <li><strong>Người tải lên:</strong> ' . htmlspecialchars($user_data['username']) . 
                                ($user_data['full_name'] ? ' (' . htmlspecialchars($user_data['full_name']) . ')' : '') . '</li>
                                <li><strong>Tên file:</strong> ' . htmlspecialchars($file_name) . '</li>
                                <li><strong>Kích thước:</strong> ' . formatFileSize($file_size) . '</li>
                                <li><strong>Loại file:</strong> ' . htmlspecialchars($file_type) . '</li>
                                <li><strong>Thời gian tải lên:</strong> ' . date('Y-m-d H:i:s') . '</li>
                            </ul>
                            <p>Vui lòng đăng nhập vào hệ thống để kiểm tra tài liệu: 
                                <a href="https://umters.club/document-review?tab=review">Kiểm tra ngay</a>
                            </p>
                            <p>Trân trọng,<br>Umters Teams</p>
                        ';
                        
                        // Gửi email thông báo
                        if (sendEmailNotification($conn, $smtp_config, $recipient, $subject, $body)) {
                            $_SESSION['success_message'] = "Tải lên tài liệu thành công! Tài liệu của bạn đang chờ được kiểm tra.";
                        } else {
                            $_SESSION['success_message'] = "Tải lên tài liệu thành công, nhưng gửi email thông báo thất bại.";
                        }
                        
                        header("Location: document-review?tab=my-documents");
                        exit;
                    } else {
                        $_SESSION['error_message'] = "Lỗi khi lưu thông tin file: " . $stmt->error;
                        header("Location: document-review?tab=upload");
                        exit;
                    }
                } else {
                    $_SESSION['error_message'] = "Lỗi khi tải file lên server.";
                    header("Location: document-review?tab=upload");
                    exit;
                }
            }
        }
    } else {
        $_SESSION['error_message'] = "Vui lòng chọn file để tải lên.";
        header("Location: document-review?tab=upload");
        exit;
    }
}

// Xử lý tải lên kết quả kiểm tra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_result'])) {
    $document_id = (int)$_POST['document_id'];
    $notes = trim($_POST['review_notes']);
    $status = $_POST['review_status'];
    
    // Cập nhật trạng thái tài liệu
    $update_sql = "UPDATE document_uploads SET status = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("si", $status, $document_id);
    
    if ($update_stmt->execute()) {
        // Thêm ghi chú đánh giá
        $review_sql = "INSERT INTO document_reviews (document_id, admin_id, notes) VALUES (?, ?, ?)";
        $review_stmt = $conn->prepare($review_sql);
        $review_stmt->bind_param("iis", $document_id, $user_id, $notes);
        $review_stmt->execute();
        
        // Xử lý tải lên file kết quả (nếu có)
        if (isset($_FILES['result_files']) && !empty($_FILES['result_files']['name'][0])) {
            $result_files = $_FILES['result_files'];
            $file_count = count($result_files['name']);
            
            for ($i = 0; $i < $file_count; $i++) {
                if ($result_files['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = $result_files['name'][$i];
                    $file_tmp = $result_files['tmp_name'][$i];
                    $file_size = $result_files['size'][$i];
                    $file_type = $result_files['type'][$i];
                    $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                    
                    // Tạo thư mục uploads/results nếu chưa tồn tại
                    $upload_dir = 'uploads/results/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Tạo tên file duy nhất
                    $new_filename = uniqid('result_') . '_' . time() . '_' . $i . '.' . $file_extension;
                    $file_path = $upload_dir . $new_filename;
                    
                    // Di chuyển file tải lên vào thư mục đích
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        // Lưu thông tin file kết quả vào cơ sở dữ liệu
                        $result_sql = "INSERT INTO document_results (document_id, admin_id, filename, original_filename, file_path, file_size, file_type) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $result_stmt = $conn->prepare($result_sql);
                        $result_stmt->bind_param("iisssss", $document_id, $user_id, $new_filename, $file_name, $file_path, $file_size, $file_type);
                        $result_stmt->execute();
                    }
                }
            }
        }
        
        $_SESSION['success_message'] = "Đã cập nhật trạng thái và gửi kết quả kiểm tra thành công!";
        header("Location: document-review.php?tab=reviewed");
        exit;
    } else {
        $_SESSION['error_message'] = "Lỗi khi cập nhật trạng thái tài liệu: " . $update_stmt->error;
        header("Location: document-review.php?view=" . $document_id);
        exit;
    }
}

// Xử lý xóa tài liệu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $document_id = (int)$_POST['document_id'];
    
    // Kiểm tra quyền xóa (chỉ chủ sở hữu hoặc super admin mới có quyền xóa)
    $check_sql = "SELECT user_id, file_path FROM document_uploads WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $document_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $_SESSION['error_message'] = "Tài liệu không tồn tại.";
        header("Location: document-review.php?tab=" . ($is_super_admin ? 'reviewed' : 'my-documents'));
        exit;
    }
    
    $document_data = $check_result->fetch_assoc();
    
    if ($document_data['user_id'] != $user_id && !$is_super_admin) {
        $_SESSION['error_message'] = "Bạn không có quyền xóa tài liệu này.";
        header("Location: document-review.php?tab=my-documents");
        exit;
    }
    
    // Bắt đầu transaction
    $conn->begin_transaction();
    
    try {
        // Lấy danh sách file kết quả để xóa
        $result_files_sql = "SELECT file_path FROM document_results WHERE document_id = ?";
        $result_files_stmt = $conn->prepare($result_files_sql);
        $result_files_stmt->bind_param("i", $document_id);
        $result_files_stmt->execute();
        $result_files_result = $result_files_stmt->get_result();
        
        $file_paths = [];
        while ($row = $result_files_result->fetch_assoc()) {
            $file_paths[] = $row['file_path'];
        }
        
        // Thêm file gốc vào danh sách xóa
        $file_paths[] = $document_data['file_path'];
        
        // Xóa bản ghi từ cơ sở dữ liệu (các bảng liên quan sẽ bị xóa cascade)
        $delete_sql = "DELETE FROM document_uploads WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $document_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Lỗi khi xóa tài liệu: " . $delete_stmt->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        // Xóa các file vật lý
        foreach ($file_paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        
        $_SESSION['success_message'] = "Đã xóa tài liệu thành công!";
    } catch (Exception $e) {
        // Rollback transaction nếu có lỗi
        $conn->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    header("Location: document-review.php?tab=" . ($is_super_admin ? 'reviewed' : 'my-documents'));
    exit;
}

// Lấy danh sách tài liệu đã tải lên của người dùng
function getUserDocuments($conn, $user_id) {
    $sql = "SELECT d.*, 
                  (SELECT COUNT(*) FROM document_results WHERE document_id = d.id) AS result_count,
                  (SELECT notes FROM document_reviews WHERE document_id = d.id ORDER BY review_time DESC LIMIT 1) AS latest_notes
           FROM document_uploads d 
           WHERE d.user_id = ? 
           ORDER BY d.upload_time DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    
    return $documents;
}

// Lấy danh sách tài liệu chờ kiểm tra (cho admin)
function getPendingDocuments($conn) {
    $sql = "SELECT d.*, u.username, u.full_name 
            FROM document_uploads d 
            JOIN users u ON d.user_id = u.id 
            WHERE d.status = 'pending' 
            ORDER BY d.upload_time ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    
    return $documents;
}

// Lấy danh sách tài liệu đã kiểm tra (cho admin)
function getReviewedDocuments($conn) {
    $sql = "SELECT d.*, u.username, u.full_name 
            FROM document_uploads d 
            JOIN users u ON d.user_id = u.id 
            WHERE d.status != 'pending' 
            ORDER BY d.upload_time DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    
    return $documents;
}

// Lấy thông tin chi tiết của một tài liệu
function getDocumentDetails($conn, $document_id) {
    $sql = "SELECT d.*, u.username, u.full_name 
            FROM document_uploads d 
            JOIN users u ON d.user_id = u.id 
            WHERE d.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Lấy danh sách kết quả kiểm tra của một tài liệu
function getDocumentResults($conn, $document_id) {
    $sql = "SELECT r.*, u.username, u.full_name 
            FROM document_results r 
            JOIN users u ON r.admin_id = u.id 
            WHERE r.document_id = ? 
            ORDER BY r.upload_time DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    
    return $results;
}

// Lấy ghi chú đánh giá mới nhất của một tài liệu
function getLatestReview($conn, $document_id) {
    $sql = "SELECT r.*, u.username, u.full_name 
            FROM document_reviews r 
            JOIN users u ON r.admin_id = u.id 
            WHERE r.document_id = ? 
            ORDER BY r.review_time DESC 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Lấy danh sách tài liệu dựa trên vai trò người dùng
$user_documents = getUserDocuments($conn, $user_id);
$pending_documents = $is_super_admin ? getPendingDocuments($conn) : [];
$reviewed_documents = $is_super_admin ? getReviewedDocuments($conn) : [];

// Xử lý hiển thị chi tiết tài liệu
$document_details = null;
$document_results = [];
$latest_review = null;

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $document_id = (int)$_GET['view'];
    $document_details = getDocumentDetails($conn, $document_id);
    
    // Kiểm tra quyền truy cập
    if ($document_details && ($document_details['user_id'] == $user_id || $is_super_admin)) {
        $document_results = getDocumentResults($conn, $document_id);
        $latest_review = getLatestReview($conn, $document_id);
        $active_tab = 'view';
    } else {
        $_SESSION['error_message'] = "Bạn không có quyền xem tài liệu này.";
        header("Location: document-review.php?tab=" . ($is_super_admin ? 'review' : 'my-documents'));
        exit;
    }
}

// Định dạng kích thước file
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Định dạng trạng thái
function formatStatus($status) {
    switch ($status) {
        case 'pending':
            return '<span class="status-badge pending">Chờ kiểm tra</span>';
        case 'approved':
            return '<span class="status-badge approved">Đã duyệt</span>';
        case 'rejected':
            return '<span class="status-badge rejected">Từ chối</span>';
        default:
            return '<span class="status-badge">' . htmlspecialchars($status) . '</span>';
    }
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiểm tra tài liệu</title>
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
            --danger: #FF3D57;
            --danger-light: #FF5D77;
            --danger-dark: #E01F3D;
            
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
            
            /* Border radius */
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
            --radius-xl: 3rem;
            --radius-full: 9999px;
            
            /* Status colors */
            --pending-color: #F59E0B;
            --pending-bg: rgba(245, 158, 11, 0.1);
            --approved-color: #10B981;
            --approved-bg: rgba(16, 185, 129, 0.1);
            --rejected-color: #EF4444;
            --rejected-bg: rgba(239, 68, 68, 0.1);
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
        .document-container {
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

        /* Tab navigation */
        .tab-navigation {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .tab-navigation::-webkit-scrollbar {
            display: none;
        }

        .tab-button {
            padding: 0.875rem 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .tab-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.1), rgba(0, 224, 255, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .tab-button:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .tab-button:hover::before {
            opacity: 1;
        }

        .tab-button.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-color: var(--primary-light);
            color: white;
            box-shadow: var(--glow);
        }

        .tab-button.active::before {
            display: none;
        }

        .tab-button i {
            font-size: 1.125rem;
        }

        .tab-button .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: var(--accent);
            color: white;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }

        /* Tab content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .section-header {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary-light);
        }

        .section-subtitle {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-top: 0.25rem;
            max-width: 600px;
        }

        /* Upload section */
        .upload-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
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

        .upload-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--primary-light);
        }

        .file-drop-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 2.5rem 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.03);
            position: relative;
            overflow: hidden;
            cursor: pointer;
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
            font-size: 3rem;
            color: var(--primary-light);
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }

        .file-drop-area:hover .file-icon {
            transform: scale(1.1);
            color: var(--primary);
        }

        .file-message {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
        }

        .file-submessage {
            font-size: 0.875rem;
            color: var(--foreground-muted);
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

        .submit-button {
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
            align-self: flex-start;
            text-decoration: none;
        }

        .submit-button::before {
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

        .submit-button:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .submit-button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .submit-button:active {
            transform: translateY(0);
        }

        .submit-button i {
            font-size: 1.125rem;
        }

        /* Document list */
        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .document-card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .document-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.05) 0%, transparent 50%, rgba(0, 224, 255, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .document-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .document-card:hover::before {
            opacity: 1;
        }

        .document-header {
            padding: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .document-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-light);
            flex-shrink: 0;
        }

        .document-title {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .document-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--foreground);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-all;
        }

        .document-user {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .document-user i {
            font-size: 0.75rem;
        }

        .document-body {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .document-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .info-label i {
            font-size: 0.75rem;
        }

        .info-value {
            font-size: 0.875rem;
            color: var(--foreground);
            font-weight: 500;
        }

        .document-status {
            margin-top: 0.5rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.pending {
            background: var(--pending-bg);
            color: var(--pending-color);
        }

        .status-badge.approved {
            background: var(--approved-bg);
            color: var(--approved-color);
        }

        .status-badge.rejected {
            background: var(--rejected-bg);
            color: var(--rejected-color);
        }

        .document-notes {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .document-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.75rem;
        }

        .document-action {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            border: none;
            color: white;
        }

        .action-view {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .action-view:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(112, 0, 255, 0.3);
        }

        .action-download {
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
        }

        .action-download:hover {
            background: linear-gradient(135deg, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 224, 255, 0.3);
        }

        .action-review {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
        }

        .action-review:hover {
            background: linear-gradient(135deg, var(--accent-light), var(--accent));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 61, 255, 0.3);
        }

        .action-delete {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
        }

        .action-delete:hover {
            background: linear-gradient(135deg, var(--danger-light), var(--danger));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 61, 87, 0.3);
        }

        /* Empty state */
        .empty-state {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 3rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--primary-light);
            opacity: 0.7;
            animation: pulse 3s infinite ease-in-out;
        }

        @keyframes pulse {
            0% { opacity: 0.5; transform: scale(0.95); }
            50% { opacity: 0.7; transform: scale(1); }
            100% { opacity: 0.5; transform: scale(0.95); }
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .empty-description {
            font-size: 1rem;
            color: var(--foreground-muted);
            max-width: 500px;
            margin: 0 auto;
        }

        .empty-action {
            margin-top: 1rem;
        }

        /* Document detail view */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }

        .back-link:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .document-detail {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .detail-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            position: relative;
        }

        .detail-header::before {
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
        }

        .detail-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-all;
        }

        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .meta-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .meta-group i {
            color: var(--primary-light);
            font-size: 1rem;
        }

        .meta-text {
            font-size: 0.875rem;
            color: var(--foreground-muted);
        }

        .detail-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            padding: 2rem;
        }

        .detail-section {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .section-heading {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .section-heading i {
            color: var(--primary-light);
        }

        /* File list */
        .file-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .file-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .file-item-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary-light);
            flex-shrink: 0;
        }

        .file-item-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }

        .file-item-name {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--foreground);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-item-meta {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .file-item-download {
            padding: 0.5rem 0.75rem;
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            flex-shrink: 0;
        }

        .file-item-download:hover {
            background: linear-gradient(135deg, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 224, 255, 0.3);
        }

        /* Review form */
        .review-form {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .review-textarea {
            width: 100%;
            min-height: 150px;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .review-textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .radio-group {
            display: flex;
            gap: 1.5rem;
        }

        .radio-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .radio-item input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-radius: 50%;
            outline: none;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .radio-item input[type="radio"]:checked {
            border-color: var(--primary-light);
        }

        .radio-item input[type="radio"]:checked:after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-light);
        }

        .radio-item label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--foreground);
            cursor: pointer;
        }

        .radio-item:nth-child(1) input[type="radio"]:checked {
            border-color: var(--approved-color);
        }

        .radio-item:nth-child(1) input[type="radio"]:checked:after {
            background: var(--approved-color);
        }

        .radio-item:nth-child(2) input[type="radio"]:checked {
            border-color: var(--rejected-color);
        }

        .radio-item:nth-child(2) input[type="radio"]:checked:after {
            background: var(--rejected-color);
        }

        /* Review notes */
        .review-notes {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
        }

        .notes-content {
            font-size: 0.875rem;
            color: var(--foreground);
            white-space: pre-wrap;
            line-height: 1.6;
        }

        .notes-meta {
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-style: italic;
        }

        .notes-meta i {
            font-size: 0.75rem;
        }

        /* Delete button styles */
        .delete-button {
            padding: 0.5rem 0.75rem;
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .delete-button:hover {
            background: linear-gradient(135deg, var(--danger-light), var(--danger));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 61, 87, 0.3);
        }

        .delete-form {
            display: inline;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .modal-overlay.active .modal-container {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .modal-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: rgba(255, 61, 87, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--danger);
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
        }

        .modal-content {
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .modal-button {
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-button-cancel {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
        }

        .modal-button-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .modal-button-delete {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
            border: none;
        }

        .modal-button-delete:hover {
            background: linear-gradient(135deg, var(--danger-light), var(--danger));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 61, 87, 0.3);
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .detail-content {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (max-width: 992px) {
            .document-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .document-container {
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

            .tab-navigation {
                flex-wrap: wrap;
            }

            .tab-button {
                flex: 1;
                justify-content: center;
            }

            .document-grid {
                grid-template-columns: 1fr;
            }

            .upload-section {
                padding: 1.5rem;
            }

            .detail-header {
                padding: 1.25rem;
            }

            .detail-title {
                font-size: 1.25rem;
            }

            .detail-meta {
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }

            .detail-content {
                padding: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .document-info {
                grid-template-columns: 1fr;
            }

            .document-footer {
                flex-direction: column;
            }

            .document-action {
                width: 100%;
            }

            .radio-group {
                flex-direction: column;
                gap: 0.75rem;
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

    <div class="document-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-file-alt"></i>Kiểm tra tài liệu</h1>
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

        <div class="tab-navigation">
            <?php if (!$is_super_admin): ?>
            <a href="document-review.php?tab=upload" class="tab-button <?php echo $active_tab == 'upload' ? 'active' : ''; ?>">
                <i class="fas fa-cloud-upload-alt"></i> Tải lên tài liệu
            </a>
            <a href="document-review.php?tab=my-documents" class="tab-button <?php echo $active_tab == 'my-documents' ? 'active' : ''; ?>">
                <i class="fas fa-folder-open"></i> Tài liệu của tôi
                <?php if (count($user_documents) > 0): ?>
                <span class="badge"><?php echo count($user_documents); ?></span>
                <?php endif; ?>
            </a>
            <?php else: ?>
            <a href="document-review.php?tab=review" class="tab-button <?php echo $active_tab == 'review' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-check"></i> Chờ kiểm tra
                <?php if (count($pending_documents) > 0): ?>
                <span class="badge"><?php echo count($pending_documents); ?></span>
                <?php endif; ?>
            </a>
            <a href="document-review.php?tab=reviewed" class="tab-button <?php echo $active_tab == 'reviewed' ? 'active' : ''; ?>">
                <i class="fas fa-check-double"></i> Đã kiểm tra
                <?php if (count($reviewed_documents) > 0): ?>
                <span class="badge"><?php echo count($reviewed_documents); ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>

        <?php if (!$is_super_admin): ?>
        <!-- Tab Upload -->
        <div class="tab-content <?php echo $active_tab == 'upload' ? 'active' : ''; ?>" id="upload-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-cloud-upload-alt"></i> Tải lên tài liệu mới</h2>
                    <p class="section-subtitle">Tải lên tài liệu của bạn để được kiểm tra và phê duyệt.</p>
                </div>
            </div>

            <div class="upload-section">
                <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-file-alt"></i> Chọn tài liệu</label>
                        <div class="file-drop-area" id="drop-area">
                            <i class="fas fa-cloud-upload-alt file-icon"></i>
                            <p class="file-message">Kéo thả hoặc nhấp để chọn tài liệu</p>
                            <p class="file-submessage">Chấp nhận file PDF, DOC, DOCX (tối đa 20MB)</p>
                            <input type="file" name="document" id="document" class="file-input" accept=".pdf,.doc,.docx" required>
                        </div>
                        <div class="file-name-display" id="file-name"></div>
                    </div>
                    <button type="submit" name="upload_document" class="submit-button">
                        <i class="fas fa-upload"></i> Tải lên tài liệu
                    </button>
                </form>
            </div>
        </div>

        <!-- Tab My Documents -->
        <div class="tab-content <?php echo $active_tab == 'my-documents' ? 'active' : ''; ?>" id="my-documents-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-folder-open"></i> Tài liệu của tôi</h2>
                    <p class="section-subtitle">Danh sách tài liệu bạn đã tải lên và trạng thái kiểm tra.</p>
                </div>
            </div>

            <?php if (empty($user_documents)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt empty-icon"></i>
                <h3 class="empty-title">Chưa có tài liệu nào</h3>
                <p class="empty-description">Bạn chưa tải lên tài liệu nào. Hãy tải lên tài liệu đầu tiên của bạn để được kiểm tra.</p>
                <div class="empty-action">
                    <a href="document-review.php?tab=upload" class="submit-button">
                        <i class="fas fa-cloud-upload-alt"></i> Tải lên tài liệu
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="document-grid">
                <?php foreach ($user_documents as $doc): ?>
                <div class="document-card">
                    <div class="document-header">
                        <div class="document-icon">
                            <i class="fas fa-file-<?php echo pathinfo($doc['original_filename'], PATHINFO_EXTENSION) == 'pdf' ? 'pdf' : 'word'; ?>"></i>
                        </div>
                        <div class="document-title">
                            <div class="document-name"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                        </div>
                    </div>
                    <div class="document-body">
                        <div class="document-info">
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-weight"></i> Kích thước</div>
                                <div class="info-value"><?php echo formatFileSize($doc['file_size']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-calendar-alt"></i> Ngày tải lên</div>
                                <div class="info-value"><?php echo date('d/m/Y', strtotime($doc['upload_time'])); ?></div>
                            </div>
                        </div>
                        <div class="document-status">
                            <?php 
                            $status_class = '';
                            $status_text = '';
                            $status_icon = '';
                            
                            switch ($doc['status']) {
                                case 'pending':
                                    $status_class = 'pending';
                                    $status_text = 'Chờ kiểm tra';
                                    $status_icon = 'clock';
                                    break;
                                case 'approved':
                                    $status_class = 'approved';
                                    $status_text = 'Đã duyệt';
                                    $status_icon = 'check-circle';
                                    break;
                                case 'rejected':
                                    $status_class = 'rejected';
                                    $status_text = 'Từ chối';
                                    $status_icon = 'times-circle';
                                    break;
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fas fa-<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                            </span>
                        </div>
                        <?php if ($doc['latest_notes']): ?>
                        <div class="document-notes">
                            <strong>Ghi chú:</strong> <?php echo htmlspecialchars(substr($doc['latest_notes'], 0, 100)); ?>
                            <?php echo (strlen($doc['latest_notes']) > 100) ? '...' : ''; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="document-footer">
                        <a href="document-review.php?view=<?php echo $doc['id']; ?>" class="document-action action-view">
                            <i class="fas fa-eye"></i> Xem chi tiết
                        </a>
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" class="document-action action-download" download>
                            <i class="fas fa-download"></i> Tải xuống
                        </a>
                        <button type="button" class="document-action action-delete" onclick="confirmDelete(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                            <i class="fas fa-trash-alt"></i> Xóa
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Tab Review (Admin) -->
        <div class="tab-content <?php echo $active_tab == 'review' ? 'active' : ''; ?>" id="review-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-clipboard-check"></i> Tài liệu chờ kiểm tra</h2>
                    <p class="section-subtitle">Danh sách tài liệu đang chờ được kiểm tra và phê duyệt.</p>
                </div>
            </div>

            <?php if (empty($pending_documents)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle empty-icon"></i>
                <h3 class="empty-title">Không có tài liệu nào chờ kiểm tra</h3>
                <p class="empty-description">Tất cả tài liệu đã được kiểm tra. Kiểm tra lại sau khi có tài liệu mới được tải lên.</p>
            </div>
            <?php else: ?>
            <div class="document-grid">
                <?php foreach ($pending_documents as $doc): ?>
                <div class="document-card">
                    <div class="document-header">
                        <div class="document-icon">
                            <i class="fas fa-file-<?php echo pathinfo($doc['original_filename'], PATHINFO_EXTENSION) == 'pdf' ? 'pdf' : 'word'; ?>"></i>
                        </div>
                        <div class="document-title">
                            <div class="document-name"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                            <div class="document-user">
                                <i class="fas fa-user"></i> 
                                <?php echo htmlspecialchars($doc['username']); ?>
                                <?php if ($doc['full_name']): ?>
                                    (<?php echo htmlspecialchars($doc['full_name']); ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="document-body">
                        <div class="document-info">
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-weight"></i> Kích thước</div>
                                <div class="info-value"><?php echo formatFileSize($doc['file_size']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-calendar-alt"></i> Ngày tải lên</div>
                                <div class="info-value"><?php echo date('d/m/Y', strtotime($doc['upload_time'])); ?></div>
                            </div>
                        </div>
                        <div class="document-status">
                            <span class="status-badge pending">
                                <i class="fas fa-clock"></i> Chờ kiểm tra
                            </span>
                        </div>
                    </div>
                    <div class="document-footer">
                        <a href="document-review.php?view=<?php echo $doc['id']; ?>" class="document-action action-review">
                            <i class="fas fa-clipboard-check"></i> Kiểm tra
                        </a>
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" class="document-action action-download" download>
                            <i class="fas fa-download"></i> Tải xuống
                        </a>
                        <button type="button" class="document-action action-delete" onclick="confirmDelete(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                            <i class="fas fa-trash-alt"></i> Xóa
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Reviewed (Admin) -->
        <div class="tab-content <?php echo $active_tab == 'reviewed' ? 'active' : ''; ?>" id="reviewed-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-check-double"></i> Tài liệu đã kiểm tra</h2>
                    <p class="section-subtitle">Danh sách tài liệu đã được kiểm tra và phê duyệt hoặc từ chối.</p>
                </div>
            </div>

            <?php if (empty($reviewed_documents)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list empty-icon"></i>
                <h3 class="empty-title">Chưa có tài liệu nào được kiểm tra</h3>
                <p class="empty-description">Chưa có tài liệu nào được kiểm tra. Hãy kiểm tra các tài liệu đang chờ xử lý.</p>
            </div>
            <?php else: ?>
            <div class="document-grid">
                <?php foreach ($reviewed_documents as $doc): ?>
                <div class="document-card">
                    <div class="document-header">
                        <div class="document-icon">
                            <i class="fas fa-file-<?php echo pathinfo($doc['original_filename'], PATHINFO_EXTENSION) == 'pdf' ? 'pdf' : 'word'; ?>"></i>
                        </div>
                        <div class="document-title">
                            <div class="document-name"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                            <div class="document-user">
                                <i class="fas fa-user"></i> 
                                <?php echo htmlspecialchars($doc['username']); ?>
                                <?php if ($doc['full_name']): ?>
                                    (<?php echo htmlspecialchars($doc['full_name']); ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="document-body">
                        <div class="document-info">
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-weight"></i> Kích thước</div>
                                <div class="info-value"><?php echo formatFileSize($doc['file_size']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><i class="fas fa-calendar-alt"></i> Ngày tải lên</div>
                                <div class="info-value"><?php echo date('d/m/Y', strtotime($doc['upload_time'])); ?></div>
                            </div>
                        </div>
                        <div class="document-status">
                            <?php 
                            $status_class = '';
                            $status_text = '';
                            $status_icon = '';
                            
                            switch ($doc['status']) {
                                case 'approved':
                                    $status_class = 'approved';
                                    $status_text = 'Đã duyệt';
                                    $status_icon = 'check-circle';
                                    break;
                                case 'rejected':
                                    $status_class = 'rejected';
                                    $status_text = 'Từ chối';
                                    $status_icon = 'times-circle';
                                    break;
                                default:
                                    $status_class = 'pending';
                                    $status_text = 'Chờ kiểm tra';
                                    $status_icon = 'clock';
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fas fa-<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                            </span>
                        </div>
                    </div>
                    <div class="document-footer">
                        <a href="document-review.php?view=<?php echo $doc['id']; ?>" class="document-action action-view">
                            <i class="fas fa-eye"></i> Xem chi tiết
                        </a>
                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" class="document-action action-download" download>
                            <i class="fas fa-download"></i> Tải xuống
                        </a>
                        <button type="button" class="document-action action-delete" onclick="confirmDelete(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                            <i class="fas fa-trash-alt"></i> Xóa
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Document Detail View -->
        <?php if ($active_tab == 'view' && $document_details): ?>
        <div class="tab-content active" id="view-tab">
            <a href="document-review.php?tab=<?php echo $is_super_admin ? ($document_details['status'] == 'pending' ? 'review' : 'reviewed') : 'my-documents'; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Quay lại
            </a>

            <div class="document-detail">
                <div class="detail-header">
                    <h2 class="detail-title"><?php echo htmlspecialchars($document_details['original_filename']); ?></h2>
                    <div class="detail-meta">
                        <div class="meta-group">
                            <i class="fas fa-user"></i>
                            <span class="meta-text">
                                <?php echo htmlspecialchars($document_details['username']); ?>
                                <?php if ($document_details['full_name']): ?>
                                    (<?php echo htmlspecialchars($document_details['full_name']); ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-group">
                            <i class="fas fa-weight"></i>
                            <span class="meta-text"><?php echo formatFileSize($document_details['file_size']); ?></span>
                        </div>
                        <div class="meta-group">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="meta-text"><?php echo date('d/m/Y H:i', strtotime($document_details['upload_time'])); ?></span>
                        </div>
                        <div class="meta-group">
                            <?php 
                            $status_class = '';
                            $status_text = '';
                            $status_icon = '';
                            
                            switch ($document_details['status']) {
                                case 'pending':
                                    $status_class = 'pending';
                                    $status_text = 'Chờ kiểm tra';
                                    $status_icon = 'clock';
                                    break;
                                case 'approved':
                                    $status_class = 'approved';
                                    $status_text = 'Đã duyệt';
                                    $status_icon = 'check-circle';
                                    break;
                                case 'rejected':
                                    $status_class = 'rejected';
                                    $status_text = 'Từ chối';
                                    $status_icon = 'times-circle';
                                    break;
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fas fa-<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="detail-content">
                    <div class="detail-section">
                        <h3 class="section-heading"><i class="fas fa-file-alt"></i> Tài liệu gốc</h3>
                        <div class="file-list">
                            <div class="file-item">
                                <div class="file-item-icon">
                                    <i class="fas fa-file-<?php echo pathinfo($document_details['original_filename'], PATHINFO_EXTENSION) == 'pdf' ? 'pdf' : 'word'; ?>"></i>
                                </div>
                                <div class="file-item-info">
                                    <div class="file-item-name"><?php echo htmlspecialchars($document_details['original_filename']); ?></div>
                                    <div class="file-item-meta">
                                        <span><?php echo formatFileSize($document_details['file_size']); ?></span>
                                        <span><?php echo date('d/m/Y H:i', strtotime($document_details['upload_time'])); ?></span>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($document_details['file_path']); ?>" class="file-item-download" download>
                                    <i class="fas fa-download"></i> Tải xuống
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($document_results)): ?>
                        <h3 class="section-heading"><i class="fas fa-file-download"></i> Kết quả kiểm tra</h3>
                        <div class="file-list">
                            <?php foreach ($document_results as $result): ?>
                            <div class="file-item">
                                <div class="file-item-icon">
                                    <i class="fas fa-file-<?php echo pathinfo($result['original_filename'], PATHINFO_EXTENSION) == 'pdf' ? 'pdf' : 'word'; ?>"></i>
                                </div>
                                <div class="file-item-info">
                                    <div class="file-item-name"><?php echo htmlspecialchars($result['original_filename']); ?></div>
                                    <div class="file-item-meta">
                                        <span><?php echo formatFileSize($result['file_size']); ?></span>
                                        <span><?php echo date('d/m/Y H:i', strtotime($result['upload_time'])); ?></span>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($result['file_path']); ?>" class="file-item-download" download>
                                    <i class="fas fa-download"></i> Tải xuống
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Nút xóa tài liệu trong trang chi tiết -->
                        <div style="margin-top: 1.5rem;">
                            <button type="button" class="delete-button" onclick="confirmDelete(<?php echo $document_details['id']; ?>, '<?php echo htmlspecialchars($document_details['original_filename']); ?>')">
                                <i class="fas fa-trash-alt"></i> Xóa tài liệu này
                            </button>
                        </div>
                    </div>

                    <div class="detail-section">
                        <?php if ($is_super_admin && $document_details['status'] == 'pending'): ?>
                        <h3 class="section-heading"><i class="fas fa-clipboard-check"></i> Kiểm tra tài liệu</h3>
                        <form method="POST" enctype="multipart/form-data" class="review-form">
                            <input type="hidden" name="document_id" value="<?php echo $document_details['id']; ?>">
                            
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-comment-alt"></i> Ghi chú kiểm tra</label>
                                <textarea name="review_notes" class="review-textarea" placeholder="Nhập ghi chú kiểm tra tài liệu này..."></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-check-circle"></i> Trạng thái kiểm tra</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <input type="radio" id="status-approved" name="review_status" value="approved" checked>
                                        <label for="status-approved">Đã duyệt</label>
                                    </div>
                                    <div class="radio-item">
                                        <input type="radio" id="status-rejected" name="review_status" value="rejected">
                                        <label for="status-rejected">Từ chối</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><i class="fas fa-file-upload"></i> Tải lên file kết quả (tùy chọn)</label>
                                <div class="file-drop-area" id="result-drop-area">
                                    <i class="fas fa-cloud-upload-alt file-icon"></i>
                                    <p class="file-message">Kéo thả hoặc nhấp để chọn file kết quả</p>
                                    <p class="file-submessage">Có thể chọn nhiều file</p>
                                    <input type="file" name="result_files[]" class="file-input" multiple>
                                </div>
                                <div class="file-name-display" id="result-file-name"></div>
                            </div>
                            
                            <button type="submit" name="upload_result" class="submit-button">
                                <i class="fas fa-paper-plane"></i> Gửi kết quả kiểm tra
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if ($latest_review): ?>
                        <h3 class="section-heading"><i class="fas fa-comment-alt"></i> Ghi chú kiểm tra</h3>
                        <div class="review-notes">
                            <div class="notes-content"><?php echo nl2br(htmlspecialchars($latest_review['notes'])); ?></div>
                            <div class="notes-meta">
                                <i class="fas fa-user"></i> Kiểm tra bởi <?php echo htmlspecialchars($latest_review['username']); ?> 
                                vào <?php echo date('d/m/Y H:i', strtotime($latest_review['review_time'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Modal xác nhận xóa -->
        <div class="modal-overlay" id="delete-modal">
            <div class="modal-container">
                <div class="modal-header">
                    <div class="modal-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="modal-title">Xác nhận xóa tài liệu</div>
                </div>
                <div class="modal-content">
                    <p>Bạn có chắc chắn muốn xóa tài liệu "<span id="delete-document-name"></span>"?</p>
                    <p>Hành động này không thể hoàn tác và tất cả dữ liệu liên quan sẽ bị xóa vĩnh viễn.</p>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-button modal-button-cancel" onclick="closeDeleteModal()">Hủy bỏ</button>
                    <form method="POST" class="delete-form" id="delete-form">
                        <input type="hidden" name="document_id" id="delete-document-id">
                        <button type="submit" name="delete_document" class="modal-button modal-button-delete">Xóa tài liệu</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Hiệu ứng hiển thị tên file khi chọn file
            const fileInputs = document.querySelectorAll('.file-input');
            fileInputs.forEach(input => {
                const fileNameDisplay = input.closest('.form-group').querySelector('.file-name-display');
                
                if (fileNameDisplay) {
                    input.addEventListener('change', function() {
                        if (this.files.length > 0) {
                            if (this.files.length === 1) {
                                fileNameDisplay.textContent = this.files[0].name;
                            } else {
                                fileNameDisplay.textContent = `Đã chọn ${this.files.length} file`;
                            }
                            fileNameDisplay.style.display = 'block';
                        } else {
                            fileNameDisplay.style.display = 'none';
                        }
                    });
                }
            });
            
            // Hiệu ứng kéo thả file
            const dropAreas = document.querySelectorAll('.file-drop-area');
            
            dropAreas.forEach(dropArea => {
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
                    const fileInput = dropArea.querySelector('.file-input');
                    const fileNameDisplay = dropArea.closest('.form-group').querySelector('.file-name-display');
                    
                    fileInput.files = files;
                    
                    if (fileNameDisplay && files.length > 0) {
                        if (files.length === 1) {
                            fileNameDisplay.textContent = files[0].name;
                        } else {
                            fileNameDisplay.textContent = `Đã chọn ${files.length} file`;
                        }
                        fileNameDisplay.style.display = 'block';
                    }
                }
            });
            
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
            
            // Animation cho các phần tử
            animateElements('.document-card', 100);
            animateElements('.file-item', 100);
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
        
        // Hàm xác nhận xóa tài liệu
        function confirmDelete(documentId, documentName) {
            document.getElementById('delete-document-id').value = documentId;
            document.getElementById('delete-document-name').textContent = documentName;
            document.getElementById('delete-modal').classList.add('active');
        }
        
        // Hàm đóng modal xác nhận xóa
        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.remove('active');
        }
        
        // Đóng modal khi click bên ngoài
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('delete-modal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
mysqli_close($conn);
?>
