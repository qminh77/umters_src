<?php
// Tắt hiển thị lỗi cho production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

session_start();

// Regenerate session ID to prevent session fixation
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = true;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting simple implementation
if (!isset($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = time();
    $_SESSION['request_count'] = 1;
} else {
    $time_diff = time() - $_SESSION['last_request_time'];
    if ($time_diff < 60) { // 1 minute window
        $_SESSION['request_count']++;
        if ($_SESSION['request_count'] > 100) { // Max 100 requests per minute
            http_response_code(429);
            die("Too many requests. Please wait.");
        }
    } else {
        $_SESSION['request_count'] = 1;
        $_SESSION['last_request_time'] = time();
    }
}

// Database connection with error handling
try {
    include '../db_config.php';
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // Set charset to prevent character set confusion attacks
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Service temporarily unavailable. Please try again later.");
}

// Authentication check
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Input validation and sanitization
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254;
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Get user information securely
$user_id = (int)$_SESSION['user_id'];
try {
    $stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result_user = $stmt->get_result();
    
    if ($result_user && $result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
        // Sanitize user data
        $user['username'] = sanitize_input($user['username']);
        $user['full_name'] = sanitize_input($user['full_name']);
        $user['is_main_admin'] = (bool)$user['is_main_admin'];
        $user['is_super_admin'] = (bool)$user['is_super_admin'];
    } else {
        throw new Exception("User not found");
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("User query error: " . $e->getMessage());
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Secure upload directory setup
$upload_dir = '../uploads/attachments_' . date('Y_m') . '/';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        error_log("Cannot create upload directory: " . $upload_dir);
        die("Upload functionality temporarily unavailable.");
    }
    
    // Create secure .htaccess
    $htaccess_content = "Options -Indexes\nOptions -ExecCGI\n";
    $htaccess_content .= "AddHandler cgi-script .php .pl .py .jsp .asp .sh .cgi\n";
    $htaccess_content .= "<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n";
    $htaccess_content .= "    Order Allow,Deny\n    Deny from all\n</FilesMatch>\n";
    file_put_contents($upload_dir . '.htaccess', $htaccess_content);
}

if (!is_writable($upload_dir)) {
    error_log("Upload directory not writable: " . $upload_dir);
    die("Upload functionality temporarily unavailable.");
}

// Check PHPMailer
if (!file_exists('../vendor/autoload.php')) {
    die("PHPMailer library not installed. Please run: composer require phpmailer/phpmailer");
}

try {
    require '../vendor/autoload.php';
} catch (Exception $e) {
    error_log("PHPMailer Error: " . $e->getMessage());
    die("Email functionality temporarily unavailable.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$error_message = '';
$success_message = '';

// Handle AJAX requests with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // CSRF token validation for AJAX requests
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
    
    $action = sanitize_input($_POST['ajax_action']);
    
    try {
        switch ($action) {
            case 'save_draft':
                $smtp_id = filter_var($_POST['smtp_id'] ?? null, FILTER_VALIDATE_INT);
                $recipient_email = sanitize_input($_POST['recipient_email'] ?? '');
                $subject = sanitize_input($_POST['subject'] ?? '');
                $body = $_POST['body'] ?? ''; // HTML content, will be handled by HTML Purifier if available
                
                if (!validate_email($recipient_email)) {
                    throw new Exception("Invalid email address");
                }
                
                if (strlen($subject) > 200) {
                    throw new Exception("Subject too long");
                }
                
                if (strlen($body) > 50000) {
                    throw new Exception("Body too long");
                }
                
                $stmt = $conn->prepare("INSERT INTO email_drafts (user_id, smtp_config_id, recipient_email, subject, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE subject = ?, body = ?, updated_at = NOW()");
                $stmt->bind_param("iisssss", $user_id, $smtp_id, $recipient_email, $subject, $body, $subject, $body);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to save draft");
                }
                
                echo json_encode(['success' => true]);
                $stmt->close();
                break;

            case 'delete_email':
                $email_id = filter_var($_POST['email_id'], FILTER_VALIDATE_INT);
                if (!$email_id) {
                    throw new Exception("Invalid email ID");
                }
                
                $stmt = $conn->prepare("UPDATE email_history SET is_deleted = TRUE WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $email_id, $user_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete email");
                }
                
                echo json_encode(['success' => true]);
                $stmt->close();
                break;

            case 'toggle_star':
            case 'toggle_important':
            case 'toggle_hidden':
                $email_id = filter_var($_POST['email_id'], FILTER_VALIDATE_INT);
                if (!$email_id) {
                    throw new Exception("Invalid email ID");
                }
                
                $field_map = [
                    'toggle_star' => 'is_starred',
                    'toggle_important' => 'is_important',
                    'toggle_hidden' => 'is_hidden'
                ];
                
                $field = $field_map[$action];
                $stmt = $conn->prepare("UPDATE email_history SET {$field} = NOT {$field} WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $email_id, $user_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update email");
                }
                
                echo json_encode(['success' => true]);
                $stmt->close();
                break;
                
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle email sending with enhanced security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    // CSRF token validation
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = "Security error. Please refresh the page and try again.";
    } else {
        try {
            $smtp_id = filter_var($_POST['smtp_id'], FILTER_VALIDATE_INT);
            $recipient_email = sanitize_input($_POST['recipient_email']);
            $subject = sanitize_input($_POST['subject']);
            $body = $_POST['body'] ?? '';
            
            // Validation
            if (!$smtp_id || $smtp_id <= 0) {
                throw new Exception("Please select an SMTP configuration");
            }
            
            if (!validate_email($recipient_email)) {
                throw new Exception("Invalid recipient email address");
            }
            
            if (empty($subject) || strlen($subject) > 200) {
                throw new Exception("Subject is required and must be less than 200 characters");
            }
            
            if (strlen($body) > 50000) {
                throw new Exception("Email body is too long");
            }
            
            // Get SMTP configuration
            $stmt = $conn->prepare("SELECT * FROM smtp_configs WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $smtp_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $smtp_config = $result->fetch_assoc();
            $stmt->close();
            
            if (!$smtp_config) {
                throw new Exception("SMTP configuration not found");
            }
            
            // Initialize PHPMailer
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['smtp_username'];
            $mail->Password = $smtp_config['smtp_password'];
            $mail->SMTPSecure = $smtp_config['smtp_encryption'];
            $mail->Port = (int)$smtp_config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
            // Set timeout
            $mail->Timeout = 10;
            $mail->SMTPKeepAlive = false;
            
            $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
            $mail->addAddress($recipient_email);
            
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // Handle file attachments securely
            $attachment_records = [];
            if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['tmp_name'])) {
                $allowed_types = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $max_files = 5;
                $max_size = 10 * 1024 * 1024; // 10MB per file
                
                $file_count = count($_FILES['attachments']['tmp_name']);
                if ($file_count > $max_files) {
                    throw new Exception("Too many attachments. Maximum {$max_files} files allowed.");
                }
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        
                        // Validate file
                        if ($file_size > $max_size) {
                            throw new Exception("File {$file_name} is too large. Maximum 10MB per file.");
                        }
                        
                        // Validate MIME type
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $detected_type = finfo_file($finfo, $tmp_name);
                        finfo_close($finfo);
                        
                        if (!in_array($detected_type, $allowed_types)) {
                            throw new Exception("File type not allowed: {$detected_type}");
                        }
                        
                        // Check for malicious content
                        $file_content = file_get_contents($tmp_name, false, null, 0, 512);
                        if (strpos($file_content, '<?php') !== false || 
                            strpos($file_content, '<script') !== false ||
                            strpos($file_content, '<%') !== false) {
                            throw new Exception("File contains potentially dangerous content: {$file_name}");
                        }
                        
                        // Generate secure filename
                        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $safe_filename = bin2hex(random_bytes(16)) . '.' . $file_extension;
                        $file_path = $upload_dir . $safe_filename;
                        
                        if (move_uploaded_file($tmp_name, $file_path)) {
                            chmod($file_path, 0644);
                            $mail->addAttachment($file_path, basename($file_name));
                            
                            $attachment_records[] = [
                                'name' => basename($file_name),
                                'path' => 'attachments_' . date('Y_m') . '/' . $safe_filename,
                                'size' => $file_size,
                                'type' => $detected_type
                            ];
                        }
                    }
                }
            }
            
            // Send email
            $mail->send();
            
            // Save to history
            $stmt = $conn->prepare("INSERT INTO email_history (user_id, smtp_config_id, recipient_email, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, 'sent', NOW())");
            $stmt->bind_param("iisss", $user_id, $smtp_id, $recipient_email, $subject, $body);
            $stmt->execute();
            $email_id = $conn->insert_id;
            $stmt->close();
            
            // Save attachment records
            if (!empty($attachment_records)) {
                $stmt = $conn->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
                foreach ($attachment_records as $attachment) {
                    $stmt->bind_param("issis", $email_id, $attachment['name'], $attachment['path'], $attachment['size'], $attachment['type']);
                    $stmt->execute();
                }
                $stmt->close();
            }
            
            $success_message = "Email sent successfully!";
            
        } catch (Exception $e) {
            // Log error securely
            error_log("Email sending error: " . $e->getMessage());
            
            // Save failed attempt to history
            if (isset($smtp_id, $recipient_email, $subject, $body)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO email_history (user_id, smtp_config_id, recipient_email, subject, body, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, 'failed', ?, NOW())");
                    $error_msg = $e->getMessage();
                    $stmt->bind_param("iissss", $user_id, $smtp_id, $recipient_email, $subject, $body, $error_msg);
                    $stmt->execute();
                    $stmt->close();
                } catch (Exception $db_e) {
                    error_log("Failed to save error to database: " . $db_e->getMessage());
                }
            }
            
            $error_message = "Failed to send email: " . $e->getMessage();
        }
    }
}

// Get SMTP configurations securely
try {
    $stmt = $conn->prepare("SELECT * FROM smtp_configs WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $smtp_configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("SMTP configs query error: " . $e->getMessage());
    $smtp_configs = [];
}

// Get email history securely
try {
    $stmt = $conn->prepare("SELECT eh.*, sc.smtp_name FROM email_history eh JOIN smtp_configs sc ON eh.smtp_config_id = sc.id WHERE eh.user_id = ? AND eh.is_deleted = FALSE ORDER BY eh.created_at DESC LIMIT 50");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $email_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Email history query error: " . $e->getMessage());
    $email_history = [];
}

// Get drafts securely
try {
    $stmt = $conn->prepare("SELECT * FROM email_drafts WHERE user_id = ? ORDER BY updated_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $drafts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Drafts query error: " . $e->getMessage());
    $drafts = [];
}

// Get inbox securely
try {
    $stmt = $conn->prepare("SELECT ei.*, sc.smtp_name FROM email_inbox ei JOIN smtp_configs sc ON ei.smtp_config_id = sc.id WHERE ei.user_id = ? AND ei.is_hidden = FALSE ORDER BY ei.received_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $inbox = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Inbox query error: " . $e->getMessage());
    $inbox = [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <title>UMTERS - Quản Lý Email</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
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
            
            /* Status colors */
            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.1);
            --success-border: rgba(34, 197, 94, 0.3);
            --error: #ef4444;
            --error-bg: rgba(239, 68, 68, 0.1);
            --error-border: rgba(239, 68, 68, 0.3);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --warning-border: rgba(245, 158, 11, 0.3);
            --info: #3b82f6;
            --info-bg: rgba(59, 130, 246, 0.1);
            --info-border: rgba(59, 130, 246, 0.3);
            
            /* Star and important colors */
            --star-color: #fbbf24;
            --important-color: #ef4444;
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

        .config-button {
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
            cursor: pointer;
        }

        .config-button:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
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
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid var(--error-border);
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
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
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

        /* Tabs */
        .tabs-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }

        .tabs {
            display: flex;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            background: rgba(18, 18, 42, 0.7);
            border-bottom: 1px solid var(--border);
        }

        .tabs::-webkit-scrollbar {
            display: none;
        }

        .tab {
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: var(--foreground-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab:hover {
            color: var(--foreground);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab.active {
            color: var(--secondary);
            background: rgba(0, 224, 255, 0.05);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--secondary);
            border-radius: var(--radius-full) var(--radius-full) 0 0;
        }

        .tab-content {
            display: none;
            padding: 1.5rem;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Email form */
        .email-form {
            background: rgba(30, 30, 60, 0.3);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
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
            color: var(--foreground);
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

        /* Quill editor */
        .editor-container {
            border-radius: var(--radius-sm);
            overflow: hidden;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
        }

        .ql-toolbar.ql-snow {
            background: rgba(30, 30, 60, 0.5);
            border: none;
            border-bottom: 1px solid var(--border);
        }

        .ql-container.ql-snow {
            background: rgba(18, 18, 42, 0.7);
            border: none;
            color: var(--foreground);
            height: 250px;
        }

        /* File list */
        .file-list {
            margin-top: 0.5rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .file-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-icon {
            color: var(--secondary);
            font-size: 1.25rem;
        }

        .file-name {
            font-size: 0.875rem;
            color: var(--foreground);
        }

        .file-size {
            font-size: 0.75rem;
            color: var(--foreground-muted);
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--foreground-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .file-remove:hover {
            background: var(--error-bg);
            color: var(--error);
        }

        /* Form actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: var(--glow);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-light));
            transform: translateY(-2px);
            box-shadow: var(--glow-accent);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            border-color: var(--secondary);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error), #ff5757);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #ff5757, var(--error));
            transform: translateY(-2px);
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            background: rgba(18, 18, 42, 0.5);
            border: 1px solid var(--border);
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            background: rgba(30, 30, 60, 0.5);
            font-weight: 600;
            color: var(--foreground);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr {
            transition: all 0.3s ease;
        }

        .table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .table tr.hidden {
            opacity: 0.5;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-sent {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .status-failed {
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid var(--error-border);
        }

        .status-unread {
            background: var(--info-bg);
            color: var(--info);
            border: 1px solid var(--info-border);
        }

        /* Email actions */
        .email-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground-muted);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            color: var(--foreground);
        }

        .action-btn.starred {
            color: var(--star-color);
            background: rgba(251, 191, 36, 0.1);
            border-color: rgba(251, 191, 36, 0.3);
        }

        .action-btn.important {
            color: var(--important-color);
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 10, 26, 0.8);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 1000;
            overflow-y: auto;
            padding: 2rem 1rem;
        }

        .modal-content {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            animation: modalFadeIn 0.3s ease;
            overflow: hidden;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(30, 30, 60, 0.5);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            margin: 0;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground-muted);
            transition: all 0.3s ease;
            cursor: pointer;
            font-size: 1.25rem;
        }

        .modal-close:hover {
            background: var(--error-bg);
            color: var(--error);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            background: rgba(30, 30, 60, 0.3);
        }

        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
            text-align: center;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--foreground-subtle);
            margin-bottom: 1.5rem;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
        }

        .empty-description {
            color: var(--foreground-muted);
            max-width: 400px;
            margin: 0 auto;
        }

        /* Responsive styles */
        @media (max-width: 1024px) {
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
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
                flex-wrap: wrap;
                justify-content: space-between;
            }
            
            .back-to-home, .user-profile, .config-button {
                flex: 1;
                min-width: 150px;
            }
            
            .tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
            }
            
            .tab {
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
            }
            
            .tab-content {
                padding: 1rem;
            }
            
            .email-form {
                padding: 1rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
            
            .email-actions {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header {
                padding: 0.75rem;
            }
            
            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .back-to-home, .user-profile, .config-button {
                width: 100%;
                justify-content: center;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .tab {
                padding: 0.5rem 0.75rem;
                font-size: 0.75rem;
            }
            
            .form-actions {
                gap: 0.5rem;
            }
            
            .modal-content {
                margin: 0;
            }
            
            .modal-header, .modal-body, .modal-footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon"><i class="fas fa-envelope"></i></div>
                <div class="logo-text">UMTERS</div>
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
                
                <button class="config-button" onclick="openSmtpModal()">
                    <i class="fas fa-cog"></i> Cấu hình SMTP
                </button>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <h1 class="page-title">Quản Lý Email</h1>
            
            <!-- Messages -->
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <!-- Tabs Container -->
            <div class="tabs-container">
                <div class="tabs">
                    <div class="tab active" data-tab="compose">
                        <i class="fas fa-pen-to-square"></i> Soạn Email
                    </div>
                    <div class="tab" data-tab="drafts">
                        <i class="fas fa-save"></i> Nháp
                    </div>
                    <div class="tab" data-tab="sent">
                        <i class="fas fa-paper-plane"></i> Đã Gửi
                    </div>
                    <div class="tab" data-tab="inbox">
                        <i class="fas fa-inbox"></i> Hộp Thư Đến
                    </div>
                    <div class="tab" data-tab="important">
                        <i class="fas fa-exclamation"></i> Quan Trọng
                    </div>
                </div>
                
                <!-- Tab Soạn Email -->
                <div class="tab-content active" id="compose">
                    <div class="email-form">
                        <form method="POST" enctype="multipart/form-data" id="emailForm">
                            <input type="hidden" name="action" value="send_email">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="form-group">
                                <label class="form-label" for="smtp_id">Chọn Cấu Hình SMTP</label>
                                <select id="smtp_id" name="smtp_id" class="form-select" required>
                                    <?php if (empty($smtp_configs)): ?>
                                        <option value="">Chưa có cấu hình SMTP nào</option>
                                    <?php else: ?>
                                        <?php foreach ($smtp_configs as $config): ?>
                                            <option value="<?php echo $config['id']; ?>">
                                                <?php echo htmlspecialchars($config['smtp_name']); ?>
                                                <?php echo $config['is_default'] ? ' (Mặc định)' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="recipient_email">Email Người Nhận</label>
                                <input type="email" id="recipient_email" name="recipient_email" class="form-input" placeholder="Nhập email người nhận" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="subject">Tiêu Đề</label>
                                <input type="text" id="subject" name="subject" class="form-input" placeholder="Nhập tiêu đề email" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="editor">Nội Dung</label>
                                <div id="editor" class="editor-container"></div>
                                <input type="hidden" name="body" id="body">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="attachments">File Đính Kèm (tối đa 10MB mỗi file)</label>
                                <input type="file" id="attachments" name="attachments[]" class="form-input" multiple>
                                <div id="fileList" class="file-list"></div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="saveDraft()">
                                    <i class="fas fa-save"></i> Lưu Nháp
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Gửi Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Tab Nháp -->
                <div class="tab-content" id="drafts">
                    <?php if (empty($drafts)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-save"></i>
                            </div>
                            <h3 class="empty-title">Chưa có email nháp</h3>
                            <p class="empty-description">Các email nháp của bạn sẽ xuất hiện ở đây. Bạn có thể lưu nháp khi soạn email.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
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
                                                <div class="email-actions">
                                                    <button class="action-btn" onclick="loadDraft(<?php echo $draft['id']; ?>)" title="Chỉnh sửa">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="action-btn" onclick="deleteDraft(<?php echo $draft['id']; ?>)" title="Xóa">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab Đã Gửi -->
                <div class="tab-content" id="sent">
                    <?php if (empty($email_history)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <h3 class="empty-title">Chưa có email đã gửi</h3>
                            <p class="empty-description">Các email bạn đã gửi sẽ xuất hiện ở đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
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
                                                    <i class="fas fa-<?php echo $email['status'] === 'sent' ? 'check' : 'times'; ?>"></i>
                                                    <?php echo $email['status'] === 'sent' ? 'Đã gửi' : 'Thất bại'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="email-actions">
                                                    <button class="action-btn <?php echo $email['is_starred'] ? 'starred' : ''; ?>" onclick="toggleStar(<?php echo $email['id']; ?>)" title="Đánh dấu sao">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                    <button class="action-btn <?php echo $email['is_important'] ? 'important' : ''; ?>" onclick="toggleImportant(<?php echo $email['id']; ?>)" title="Đánh dấu quan trọng">
                                                        <i class="fas fa-exclamation"></i>
                                                    </button>
                                                    <button class="action-btn" onclick="toggleHidden(<?php echo $email['id']; ?>)" title="Ẩn/Hiện">
                                                        <i class="fas fa-eye-slash"></i>
                                                    </button>
                                                    <button class="action-btn" onclick="deleteEmail(<?php echo $email['id']; ?>)" title="Xóa">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab Hộp Thư Đến -->
                <div class="tab-content" id="inbox">
                    <?php if (empty($inbox)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h3 class="empty-title">Hộp thư đến trống</h3>
                            <p class="empty-description">Các email bạn nhận được sẽ xuất hiện ở đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
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
                                                    <span class="status-badge status-unread">
                                                        <i class="fas fa-envelope"></i> Chưa đọc
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="email-actions">
                                                    <button class="action-btn" onclick="replyEmail(<?php echo $email['id']; ?>)" title="Trả lời">
                                                        <i class="fas fa-reply"></i>
                                                    </button>
                                                    <button class="action-btn <?php echo $email['is_starred'] ? 'starred' : ''; ?>" onclick="toggleStar(<?php echo $email['id']; ?>)" title="Đánh dấu sao">
                                                        <i class="fas fa-star"></i>
                                                    </button>
                                                    <button class="action-btn <?php echo $email['is_important'] ? 'important' : ''; ?>" onclick="toggleImportant(<?php echo $email['id']; ?>)" title="Đánh dấu quan trọng">
                                                        <i class="fas fa-exclamation"></i>
                                                    </button>
                                                    <button class="action-btn" onclick="toggleHidden(<?php echo $email['id']; ?>)" title="Ẩn/Hiện">
                                                        <i class="fas fa-eye-slash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab Quan Trọng -->
                <div class="tab-content" id="important">
                    <?php 
                    // Hiển thị email quan trọng từ cả hộp thư đến và đã gửi
                    $important_emails = array_merge(
                        array_filter($inbox, function($email) { return $email['is_important']; }),
                        array_filter($email_history, function($email) { return $email['is_important']; })
                    );
                    usort($important_emails, function($a, $b) {
                        return strtotime($b['received_at'] ?? $b['created_at']) - strtotime($a['received_at'] ?? $a['created_at']);
                    });
                    ?>
                    
                    <?php if (empty($important_emails)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <h3 class="empty-title">Không có email quan trọng</h3>
                            <p class="empty-description">Các email được đánh dấu quan trọng sẽ xuất hiện ở đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
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
                                    <?php foreach ($important_emails as $email): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($email['received_at'] ?? $email['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($email['sender_email'] ?? $email['recipient_email']); ?></td>
                                            <td><?php echo htmlspecialchars($email['subject']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo isset($email['sender_email']) ? 'status-unread' : 'status-sent'; ?>">
                                                    <i class="fas fa-<?php echo isset($email['sender_email']) ? 'inbox' : 'paper-plane'; ?>"></i>
                                                    <?php echo isset($email['sender_email']) ? 'Đến' : 'Đã gửi'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="email-actions">
                                                    <?php if (isset($email['sender_email'])): ?>
                                                        <button class="action-btn" onclick="replyEmail(<?php echo $email['id']; ?>)" title="Trả lời">
                                                            <i class="fas fa-reply"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="action-btn important" onclick="toggleImportant(<?php echo $email['id']; ?>)" title="Bỏ đánh dấu quan trọng">
                                                        <i class="fas fa-exclamation"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Cấu Hình SMTP -->
    <div id="smtpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Cấu Hình SMTP</h2>
                <button class="modal-close" onclick="closeSmtpModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body">
                <form method="POST" id="smtpForm">
                    <input type="hidden" name="action" value="add_smtp">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_name">Tên Cấu Hình</label>
                        <input type="text" id="smtp_name" name="smtp_name" class="form-input" placeholder="Ví dụ: Gmail, Công ty, ..." required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_host">SMTP Host</label>
                        <input type="text" id="smtp_host" name="smtp_host" class="form-input" placeholder="Ví dụ: smtp.gmail.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_port">SMTP Port</label>
                        <input type="number" id="smtp_port" name="smtp_port" class="form-input" placeholder="Ví dụ: 587" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_username">SMTP Username</label>
                        <input type="text" id="smtp_username" name="smtp_username" class="form-input" placeholder="Ví dụ: your.email@gmail.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_password">SMTP Password</label>
                        <input type="password" id="smtp_password" name="smtp_password" class="form-input" placeholder="Mật khẩu hoặc mã ứng dụng" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="smtp_encryption">Mã Hóa</label>
                        <select id="smtp_encryption" name="smtp_encryption" class="form-select" required>
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="from_name">Tên Người Gửi</label>
                        <input type="text" id="from_name" name="from_name" class="form-input" placeholder="Tên hiển thị khi gửi email" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="from_email">Email Người Gửi</label>
                        <input type="email" id="from_email" name="from_email" class="form-input" placeholder="Email hiển thị khi gửi" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <input type="checkbox" name="is_default" value="1">
                            Đặt làm mặc định
                        </label>
                    </div>
                </form>
                
                <?php if (!empty($smtp_configs)): ?>
                    <h3 style="margin: 2rem 0 1rem; font-size: 1.25rem; color: var(--foreground);">Danh Sách Cấu Hình</h3>
                    <div class="table-container">
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
                                        <td>
                                            <?php if ($config['is_default']): ?>
                                                <span class="status-badge status-sent">
                                                    <i class="fas fa-check"></i> Mặc định
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="action-btn" onclick="deleteConfig(<?php echo $config['id']; ?>)" title="Xóa">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSmtpModal()">
                    <i class="fas fa-times"></i> Hủy
                </button>
                <button type="button" class="btn btn-primary" onclick="submitSmtpForm()">
                    <i class="fas fa-save"></i> Lưu Cấu Hình
                </button>
            </div>
        </div>
    </div>

    <script>
        // Store CSRF token globally
        window.CSRF_TOKEN = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
        
        // Initialize Quill Editor
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
            },
            placeholder: 'Soạn nội dung email...'
        });

        // Save content before form submission
        document.getElementById('emailForm').onsubmit = function() {
            document.getElementById('body').value = quill.root.innerHTML;
            return true;
        };

        // File upload handling with security
        document.getElementById('attachments').onchange = function() {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'];
            const maxSize = 10 * 1024 * 1024; // 10MB
            const maxFiles = 5;
            
            if (this.files.length > maxFiles) {
                alert(`Chỉ được phép tối đa ${maxFiles} files`);
                this.value = '';
                return;
            }
            
            for (let i = 0; i < this.files.length; i++) {
                const file = this.files[i];
                
                if (file.size > maxSize) {
                    alert('File ' + file.name + ' vượt quá giới hạn 10MB');
                    this.value = '';
                    return;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    alert('File type ' + file.type + ' không được phép');
                    this.value = '';
                    return;
                }
                
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                
                const fileInfo = document.createElement('div');
                fileInfo.className = 'file-info';
                
                const fileIcon = document.createElement('div');
                fileIcon.className = 'file-icon';
                fileIcon.innerHTML = '<i class="fas fa-file"></i>';
                
                const fileDetails = document.createElement('div');
                fileDetails.className = 'file-details';
                
                const fileName = document.createElement('div');
                fileName.className = 'file-name';
                fileName.textContent = file.name;
                
                const fileSizeEl = document.createElement('div');
                fileSizeEl.className = 'file-size';
                fileSizeEl.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
                
                fileDetails.appendChild(fileName);
                fileDetails.appendChild(fileSizeEl);
                
                fileInfo.appendChild(fileIcon);
                fileInfo.appendChild(fileDetails);
                
                const removeButton = document.createElement('button');
                removeButton.className = 'file-remove';
                removeButton.innerHTML = '<i class="fas fa-times"></i>';
                removeButton.type = 'button';
                removeButton.onclick = function() {
                    fileItem.remove();
                };
                
                fileItem.appendChild(fileInfo);
                fileItem.appendChild(removeButton);
                fileList.appendChild(fileItem);
            }
        };

        // Secure AJAX helper
        function secureAjax(url, options = {}) {
            const defaultOptions = {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            };
            
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', window.CSRF_TOKEN);
            }
            
            return fetch(url, { ...defaultOptions, ...options })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                });
        }

        // Auto-save draft with security
        setInterval(saveDraft, 30000);

        function saveDraft() {
            const recipient = document.getElementById('recipient_email').value;
            const subject = document.getElementById('subject').value;
            const body = quill.root.innerHTML;
            
            if (recipient || subject || body) {
                const formData = new FormData();
                formData.append('ajax_action', 'save_draft');
                formData.append('smtp_id', document.getElementById('smtp_id').value);
                formData.append('recipient_email', recipient);
                formData.append('subject', subject);
                formData.append('body', body);

                secureAjax(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(data => {
                    if (data.success) {
                        console.log('Draft saved');
                    }
                }).catch(error => {
                    console.error('Save draft error:', error);
                });
            }
        }

        function loadDraft(draftId) {
            secureAjax('get_draft.php?id=' + encodeURIComponent(draftId), {
                method: 'GET'
            }).then(data => {
                if (data.smtp_config_id) {
                    document.getElementById('smtp_id').value = data.smtp_config_id;
                }
                if (data.recipient_email) {
                    document.getElementById('recipient_email').value = data.recipient_email;
                }
                if (data.subject) {
                    document.getElementById('subject').value = data.subject;
                }
                if (data.body) {
                    quill.root.innerHTML = data.body;
                }
                switchTab('compose');
            }).catch(error => {
                console.error('Load draft error:', error);
                alert('Failed to load draft');
            });
        }

        function deleteDraft(draftId) {
            if (confirm('Bạn có chắc chắn muốn xóa nháp này?')) {
                const formData = new FormData();
                formData.append('id', draftId);
                
                secureAjax('delete_draft.php', {
                    method: 'POST',
                    body: formData
                }).then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || 'Failed to delete draft');
                    }
                }).catch(error => {
                    console.error('Delete draft error:', error);
                    alert('Failed to delete draft');
                });
            }
        }

        function toggleStar(emailId) {
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_star');
            formData.append('email_id', emailId);
            
            secureAjax(window.location.href, {
                method: 'POST',
                body: formData
            }).then(data => {
                if (data.success) {
                    location.reload();
                }
            }).catch(error => {
                console.error('Toggle star error:', error);
            });
        }

        function toggleImportant(emailId) {
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_important');
            formData.append('email_id', emailId);
            
            secureAjax(window.location.href, {
                method: 'POST',
                body: formData
            }).then(data => {
                if (data.success) {
                    location.reload();
                }
            }).catch(error => {
                console.error('Toggle important error:', error);
            });
        }

        function toggleHidden(emailId) {
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_hidden');
            formData.append('email_id', emailId);
            
            secureAjax(window.location.href, {
                method: 'POST',
                body: formData
            }).then(data => {
                if (data.success) {
                    location.reload();
                }
            }).catch(error => {
                console.error('Toggle hidden error:', error);
            });
        }

        function deleteEmail(emailId) {
            if (confirm('Bạn có chắc chắn muốn xóa email này?')) {
                const formData = new FormData();
                formData.append('ajax_action', 'delete_email');
                formData.append('email_id', emailId);
                
                secureAjax(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(data => {
                    if (data.success) {
                        location.reload();
                    }
                }).catch(error => {
                    console.error('Delete email error:', error);
                });
            }
        }

        function replyEmail(emailId) {
            secureAjax('get_email.php?id=' + encodeURIComponent(emailId), {
                method: 'GET'
            }).then(data => {
                if (data.sender_email) {
                    document.getElementById('recipient_email').value = data.sender_email;
                }
                if (data.subject) {
                    const subject = data.subject.startsWith('Re: ') ? data.subject : 'Re: ' + data.subject;
                    document.getElementById('subject').value = subject;
                }
                if (data.body) {
                    const quotedBody = `<br><br><blockquote style="border-left: 3px solid #ccc; padding-left: 1rem; color: #888;">${data.body}</blockquote>`;
                    quill.root.innerHTML = quotedBody;
                }
                switchTab('compose');
            }).catch(error => {
                console.error('Reply email error:', error);
                alert('Failed to load email for reply');
            });
        }

        function deleteConfig(configId) {
            if (confirm('Bạn có chắc chắn muốn xóa cấu hình SMTP này?')) {
                const formData = new FormData();
                formData.append('id', configId);
                
                secureAjax('delete_smtp.php', {
                    method: 'POST',
                    body: formData
                }).then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || 'Failed to delete configuration');
                    }
                }).catch(error => {
                    console.error('Delete config error:', error);
                    alert('Failed to delete configuration');
                });
            }
        }

        // Tab switching
        function switchTab(tabId) {
            const validTabs = ['compose', 'drafts', 'sent', 'inbox', 'important'];
            if (!validTabs.includes(tabId)) {
                console.error('Invalid tab ID:', tabId);
                return;
            }
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
                if (tab.dataset.tab === tabId) {
                    tab.classList.add('active');
                }
            });
            
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            const targetTab = document.getElementById(tabId);
            if (targetTab) {
                targetTab.classList.add('active');
            }
        }

        // Tab event listeners
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                switchTab(tab.dataset.tab);
            });
        });

        // Modal functions
        function openSmtpModal() {
            document.getElementById('smtpModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeSmtpModal() {
            document.getElementById('smtpModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function submitSmtpForm() {
            const form = document.getElementById('smtpForm');
            
            // Validate form
            const requiredFields = ['smtp_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_name', 'from_email'];
            for (let field of requiredFields) {
                const input = form.querySelector(`[name="${field}"]`);
                if (!input || !input.value.trim()) {
                    alert(`Vui lòng điền ${field}`);
                    return;
                }
            }
            
            // Validate email
            const emailField = form.querySelector('[name="from_email"]');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailField.value)) {
                alert('Email không hợp lệ');
                return;
            }
            
            // Validate port
            const portField = form.querySelector('[name="smtp_port"]');
            const port = parseInt(portField.value);
            if (isNaN(port) || port < 1 || port > 65535) {
                alert('Port không hợp lệ (1-65535)');
                return;
            }
            
            form.submit();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('smtpModal');
            if (event.target === modal) {
                closeSmtpModal();
            }
        }

        // Form validation before submit
        function validateEmailForm() {
            const recipient = document.getElementById('recipient_email').value;
            const subject = document.getElementById('subject').value;
            const smtpId = document.getElementById('smtp_id').value;
            
            if (!smtpId) {
                alert('Vui lòng chọn cấu hình SMTP');
                return false;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(recipient)) {
                alert('Vui lòng nhập email người nhận hợp lệ');
                return false;
            }
            
            if (!subject.trim()) {
                alert('Vui lòng nhập tiêu đề email');
                return false;
            }
            
            if (subject.length > 200) {
                alert('Tiêu đề quá dài (tối đa 200 ký tự)');
                return false;
            }
            
            const bodyContent = quill.root.innerHTML;
            if (bodyContent.length > 50000) {
                alert('Nội dung email quá dài');
                return false;
            }
            
            return true;
        }

        // Enhanced form submission
        document.getElementById('emailForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!validateEmailForm()) {
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang gửi...';
            submitBtn.disabled = true;
            
            // Update hidden body field
            document.getElementById('body').value = quill.root.innerHTML;
            
            // Submit form
            this.submit();
        });

        // Notification system
        function showNotification(message, type = 'info', duration = 5000) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 6px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                max-width: 400px;
                word-wrap: break-word;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                transform: translateX(100%);
                transition: transform 0.3s ease;
            `;
            
            const colors = {
                success: '#22c55e',
                error: '#ef4444',
                warning: '#f59e0b',
                info: '#3b82f6'
            };
            
            notification.style.backgroundColor = colors[type] || colors.info;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 10);
            
            // Auto remove
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, duration);
        }

        // Create particles animation
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

        // Input sanitization for security
        function sanitizeInput(input) {
            const element = document.createElement('div');
            element.textContent = input;
            return element.innerHTML;
        }

        // Rate limiting for client-side
        const rateLimiter = {
            requests: new Map(),
            isAllowed: function(action, maxRequests = 10, windowMs = 60000) {
                const now = Date.now();
                const key = action;
                
                if (!this.requests.has(key)) {
                    this.requests.set(key, []);
                }
                
                const requests = this.requests.get(key);
                
                // Remove old requests outside the window
                while (requests.length > 0 && now - requests[0] > windowMs) {
                    requests.shift();
                }
                
                if (requests.length >= maxRequests) {
                    return false;
                }
                
                requests.push(now);
                return true;
            }
        };

        // Enhanced auto-save with rate limiting
        function saveDraftWithRateLimit() {
            if (!rateLimiter.isAllowed('save_draft', 5, 60000)) {
                return; // Rate limited
            }
            
            saveDraft();
        }

        // Security: Prevent XSS in Quill editor
        quill.on('text-change', function() {
            const content = quill.root.innerHTML;
            
            // Check for suspicious content
            const suspiciousPatterns = [
                /<script[^>]*>/i,
                /javascript:/i,
                /on\w+\s*=/i,
                /<iframe[^>]*>/i,
                /<object[^>]*>/i,
                /<embed[^>]*>/i
            ];
            
            let hasSuspiciousContent = false;
            for (let pattern of suspiciousPatterns) {
                if (pattern.test(content)) {
                    hasSuspiciousContent = true;
                    break;
                }
            }
            
            if (hasSuspiciousContent) {
                showNotification('Phát hiện nội dung không an toàn, đã được loại bỏ', 'warning');
                // Remove suspicious content
                quill.root.innerHTML = content.replace(/<script[^>]*>.*?<\/script>/gi, '')
                                             .replace(/javascript:/gi, '')
                                             .replace(/on\w+\s*=\s*["'][^"']*["']/gi, '')
                                             .replace(/<iframe[^>]*>.*?<\/iframe>/gi, '')
                                             .replace(/<object[^>]*>.*?<\/object>/gi, '')
                                             .replace(/<embed[^>]*>/gi, '');
            }
            
            // Limit content length
            if (content.length > 50000) {
                showNotification('Nội dung quá dài, vui lòng rút gọn', 'warning');
            }
        });

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            
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
            
            // Enhanced auto-save with rate limiting
            setInterval(saveDraftWithRateLimit, 30000);
            
            // Prevent common attacks
            document.addEventListener('keydown', function(e) {
                // Prevent F12, Ctrl+Shift+I, Ctrl+U in production
                // if ((e.key === 'F12') || 
                //     (e.ctrlKey && e.shiftKey && e.key === 'I') || 
                //     (e.ctrlKey && e.key === 'u')) {
                //     e.preventDefault();
                //     return false;
                // }
            });
            
            // Security: Disable right-click context menu in production
            // document.addEventListener('contextmenu', function(e) {
            //     e.preventDefault();
            //     return false;
            // });
            
            // Security: Disable text selection in sensitive areas
            document.querySelectorAll('.sensitive').forEach(element => {
                element.style.userSelect = 'none';
                element.style.webkitUserSelect = 'none';
                element.style.mozUserSelect = 'none';
                element.style.msUserSelect = 'none';
            });
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            // Save draft before leaving
            saveDraft();
        });

        // Security: Clear sensitive data on page hide
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Optional: Clear sensitive form data
                // This can be uncommented if needed for high-security environments
                // document.querySelectorAll('input[type="password"]').forEach(input => {
                //     input.value = '';
                // });
            }
        });

        // Error handling for uncaught errors
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            // Log to server for monitoring (optional)
            // logErrorToServer(e.error);
        });

        // Handle network errors gracefully
        window.addEventListener('online', function() {
            showNotification('Kết nối internet đã được khôi phục', 'success');
        });

        window.addEventListener('offline', function() {
            showNotification('Mất kết nối internet', 'warning');
        });
    </script>
</body>
</html>