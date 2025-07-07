<?php
// ===== FILE: delete_draft.php =====
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

include '../db_config.php';

$id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    $stmt = $conn->prepare("DELETE FROM email_drafts WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Failed to delete draft");
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Delete draft error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
// ===== FILE: delete_smtp.php =====
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

include '../db_config.php';

$id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Check if this is the only SMTP config
    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM smtp_configs WHERE user_id = ?");
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count = $count_result->fetch_assoc()['count'];
    $count_stmt->close();
    
    if ($count <= 1) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete the last SMTP configuration']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM smtp_configs WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Failed to delete SMTP configuration");
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Delete SMTP error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
// ===== FILE: get_draft.php =====
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include '../db_config.php';

$id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    $stmt = $conn->prepare("SELECT smtp_config_id, recipient_email, subject, body FROM email_drafts WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $draft = $result->fetch_assoc();
        
        // Sanitize output
        $response = [
            'smtp_config_id' => (int)$draft['smtp_config_id'],
            'recipient_email' => htmlspecialchars($draft['recipient_email'], ENT_QUOTES, 'UTF-8'),
            'subject' => htmlspecialchars($draft['subject'], ENT_QUOTES, 'UTF-8'),
            'body' => $draft['body'] // HTML content
        ];
        
        echo json_encode($response);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Draft not found']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Get draft error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
// ===== FILE: get_email.php =====
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include '../db_config.php';

$id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    // Try to get from inbox first, then from email history
    $stmt = $conn->prepare("SELECT sender_email, subject, body FROM email_inbox WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $email = $result->fetch_assoc();
    } else {
        // Try email history
        $stmt->close();
        $stmt = $conn->prepare("SELECT recipient_email as sender_email, subject, body FROM email_history WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $email = $result->fetch_assoc();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Email not found']);
            exit;
        }
    }
    
    // Sanitize output
    $response = [
        'sender_email' => htmlspecialchars($email['sender_email'], ENT_QUOTES, 'UTF-8'),
        'subject' => htmlspecialchars($email['subject'], ENT_QUOTES, 'UTF-8'),
        'body' => $email['body'] // HTML content
    ];
    
    echo json_encode($response);
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Get email error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
// ===== FILE: add_smtp.php =====
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

include '../db_config.php';

// Validate and sanitize inputs
$smtp_name = filter_var($_POST['smtp_name'], FILTER_SANITIZE_STRING);
$smtp_host = filter_var($_POST['smtp_host'], FILTER_SANITIZE_STRING);
$smtp_port = filter_var($_POST['smtp_port'], FILTER_VALIDATE_INT);
$smtp_username = filter_var($_POST['smtp_username'], FILTER_SANITIZE_EMAIL);
$smtp_password = $_POST['smtp_password']; // Don't filter password
$smtp_encryption = filter_var($_POST['smtp_encryption'], FILTER_SANITIZE_STRING);
$from_name = filter_var($_POST['from_name'], FILTER_SANITIZE_STRING);
$from_email = filter_var($_POST['from_email'], FILTER_SANITIZE_EMAIL);
$is_default = isset($_POST['is_default']) ? 1 : 0;

$user_id = (int)$_SESSION['user_id'];

// Validation
$errors = [];

if (empty($smtp_name) || strlen($smtp_name) > 100) {
    $errors[] = 'SMTP name is required and must be less than 100 characters';
}

if (empty($smtp_host) || strlen($smtp_host) > 255) {
    $errors[] = 'SMTP host is required and must be less than 255 characters';
}

if (!$smtp_port || $smtp_port < 1 || $smtp_port > 65535) {
    $errors[] = 'SMTP port must be between 1 and 65535';
}

if (!filter_var($smtp_username, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'SMTP username must be a valid email address';
}

if (empty($smtp_password) || strlen($smtp_password) > 255) {
    $errors[] = 'SMTP password is required and must be less than 255 characters';
}

if (!in_array($smtp_encryption, ['tls', 'ssl'])) {
    $errors[] = 'SMTP encryption must be either TLS or SSL';
}

if (empty($from_name) || strlen($from_name) > 100) {
    $errors[] = 'From name is required and must be less than 100 characters';
}

if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'From email must be a valid email address';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
    exit;
}

try {
    $conn->begin_transaction();
    
    // If this is set as default, unset others
    if ($is_default) {
        $stmt = $conn->prepare("UPDATE smtp_configs SET is_default = 0 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // Insert new SMTP config
    $stmt = $conn->prepare("INSERT INTO smtp_configs (user_id, smtp_name, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_name, from_email, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issiasssii", $user_id, $smtp_name, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $from_name, $from_email, $is_default);
    
    if ($stmt->execute()) {
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'SMTP configuration added successfully']);
    } else {
        throw new Exception("Failed to add SMTP configuration");
    }
    
    $stmt->close();
} catch (Exception $e) {
    $conn->rollback();
    error_log("Add SMTP error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
// ===== FILE: security_functions.php =====
// Security helper functions

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize_html($html) {
    // Basic HTML sanitization - should use HTML Purifier in production
    $allowed_tags = '<p><br><strong><em><u><h1><h2><h3><ul><ol><li><blockquote><a>';
    return strip_tags($html, $allowed_tags);
}

function rate_limit_check($action, $max_attempts = 10, $window = 300) {
    $key = $action . '_' . ($_SESSION['user_id'] ?? 'anonymous');
    $current_time = time();
    
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [
            'count' => 1,
            'first_attempt' => $current_time
        ];
        return true;
    }
    
    $rate_data = &$_SESSION['rate_limits'][$key];
    
    // Reset if window has passed
    if ($current_time - $rate_data['first_attempt'] > $window) {
        $rate_data = [
            'count' => 1,
            'first_attempt' => $current_time
        ];
        return true;
    }
    
    $rate_data['count']++;
    return $rate_data['count'] <= $max_attempts;
}

function log_security_event($event, $details = []) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'user_id' => $_SESSION['user_id'] ?? 'anonymous',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => $details
    ];
    
    error_log('SECURITY: ' . json_encode($log_entry));
}

function validate_email_content($content) {
    // Remove potentially dangerous HTML tags and attributes
    $dangerous_tags = ['script', 'iframe', 'object', 'embed', 'form', 'input'];
    $dangerous_attributes = ['onclick', 'onload', 'onerror', 'onmouseover', 'javascript:'];
    
    foreach ($dangerous_tags as $tag) {
        $content = preg_replace('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', '', $content);
        $content = preg_replace('/<' . $tag . '[^>]*>/is', '', $content);
    }
    
    foreach ($dangerous_attributes as $attr) {
        $content = preg_replace('/' . preg_quote($attr, '/') . '[^>]*>/is', '>', $content);
    }
    
    return $content;
}

function validate_file_upload($file) {
    $allowed_types = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword', 
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain', 'text/csv'
    ];
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'csv'];
    
    $max_size = 10 * 1024 * 1024; // 10MB
    
    $errors = [];
    
    // Check file size
    if ($file['size'] > $max_size) {
        $errors[] = 'File size exceeds 10MB limit';
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($detected_type, $allowed_types)) {
        $errors[] = 'File type not allowed: ' . $detected_type;
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        $errors[] = 'File extension not allowed: ' . $extension;
    }
    
    // Check for malicious content
    $file_content = file_get_contents($file['tmp_name'], false, null, 0, 512);
    if (strpos($file_content, '<?php') !== false || 
        strpos($file_content, '<script') !== false ||
        strpos($file_content, '<%') !== false) {
        $errors[] = 'File contains potentially dangerous content';
    }
    
    return $errors;
}

function secure_filename($filename) {
    // Remove path information and special characters
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    
    // Limit length
    if (strlen($filename) > 100) {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $filename = substr($name, 0, 96 - strlen($extension)) . '.' . $extension;
    }
    
    // Add timestamp to prevent conflicts
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $name = pathinfo($filename, PATHINFO_FILENAME);
    
    return $name . '_' . time() . '.' . $extension;
}

function clean_old_files($directory, $max_age_days = 30) {
    if (!is_dir($directory)) {
        return;
    }
    
    $max_age_seconds = $max_age_days * 24 * 60 * 60;
    $current_time = time();
    
    $files = glob($directory . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            $file_age = $current_time - filemtime($file);
            if ($file_age > $max_age_seconds) {
                unlink($file);
            }
        }
    }
}
?>