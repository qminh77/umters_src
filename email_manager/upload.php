<?php
session_start();
header('Content-Type: application/json');

// CSRF Protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Danh sách MIME types được phép
$allowed_types = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'application/msword', 
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    'application/zip', 'application/x-rar-compressed'
];

// Danh sách extensions được phép
$allowed_extensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'zip', 'rar'
];

// Thư mục uploads với random name để tăng bảo mật
$base_upload_dir = '../uploads/';
$upload_subdir = 'attachments_' . date('Y_m') . '/';
$upload_dir = $base_upload_dir . $upload_subdir;

// Tạo thư mục uploads với permission an toàn
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Không thể tạo thư mục uploads']);
        exit;
    }
    
    // Tạo .htaccess để bảo vệ thư mục
    $htaccess_content = "Options -Indexes\n";
    $htaccess_content .= "Options -ExecCGI\n";
    $htaccess_content .= "AddHandler cgi-script .php .pl .py .jsp .asp .sh .cgi\n";
    $htaccess_content .= "<FilesMatch \"\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n";
    $htaccess_content .= "    Order Allow,Deny\n";
    $htaccess_content .= "    Deny from all\n";
    $htaccess_content .= "</FilesMatch>\n";
    
    file_put_contents($upload_dir . '.htaccess', $htaccess_content);
}

// Kiểm tra quyền ghi
if (!is_writable($upload_dir)) {
    echo json_encode(['success' => false, 'error' => 'Thư mục uploads không có quyền ghi']);
    exit;
}

$files = [];
$errors = [];

if (!isset($_FILES['attachments'])) {
    echo json_encode(['success' => false, 'error' => 'Không có file được tải lên']);
    exit;
}

foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['attachments']['name'][$key];
        $file_size = $_FILES['attachments']['size'][$key];
        $file_type = $_FILES['attachments']['type'][$key];
        $file_tmp = $_FILES['attachments']['tmp_name'][$key];
        
        // Validate file size (10MB max cho security)
        if ($file_size > 10 * 1024 * 1024) {
            $errors[] = "File $file_name vượt quá giới hạn 10MB";
            continue;
        }
        
        // Validate file extension
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "File extension không được phép: $file_extension";
            continue;
        }
        
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        if (!in_array($detected_type, $allowed_types)) {
            $errors[] = "File type không được phép: $detected_type";
            continue;
        }
        
        // Validate file content (magic bytes)
        $file_content = file_get_contents($file_tmp, false, null, 0, 512);
        if (strpos($file_content, '<?php') !== false || 
            strpos($file_content, '<script') !== false ||
            strpos($file_content, '<%') !== false) {
            $errors[] = "File chứa nội dung không an toàn: $file_name";
            continue;
        }
        
        // Tạo tên file an toàn
        $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($file_name, PATHINFO_FILENAME));
        $safe_filename = substr($safe_filename, 0, 50); // Giới hạn độ dài
        $unique_name = bin2hex(random_bytes(16)) . '_' . $safe_filename . '.' . $file_extension;
        $file_path = $upload_dir . $unique_name;
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Set file permissions
            chmod($file_path, 0644);
            
            $files[] = [
                'name' => basename($file_name),
                'path' => $upload_subdir . $unique_name, // Relative path for database
                'full_path' => $file_path, // Full path for internal use
                'size' => $file_size,
                'type' => $detected_type
            ];
        } else {
            $errors[] = "Không thể tải lên file: $file_name";
        }
    } else {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File quá lớn (php.ini)',
            UPLOAD_ERR_FORM_SIZE => 'File quá lớn (form)',
            UPLOAD_ERR_PARTIAL => 'File upload không hoàn tất',
            UPLOAD_ERR_NO_FILE => 'Không có file nào được chọn',
            UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm',
            UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file',
            UPLOAD_ERR_EXTENSION => 'Upload bị chặn bởi extension'
        ];
        
        $error_code = $_FILES['attachments']['error'][$key];
        $error_msg = $error_messages[$error_code] ?? 'Lỗi không xác định';
        $errors[] = "Lỗi khi tải lên file " . $_FILES['attachments']['name'][$key] . ": " . $error_msg;
    }
}

if (empty($errors)) {
    echo json_encode(['success' => true, 'files' => $files]);
} else {
    // Clean up uploaded files if there are errors
    foreach ($files as $file) {
        if (file_exists($file['full_path'])) {
            unlink($file['full_path']);
        }
    }
    echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
}
?>