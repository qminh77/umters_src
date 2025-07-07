<?php
session_start();
header('Content-Type: application/json');
include 'db_config.php';

$response = array();

if (!isset($_SESSION['user_id'])) {
    $response['success'] = false;
    $response['message'] = 'Vui lòng đăng nhập!';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $fileName = basename($file['name']);
    $fileTmp = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileError = $file['error'];
    
    $maxSize = 2 * 1024 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        $response['success'] = false;
        $response['message'] = 'File quá lớn, tối đa 2GB!';
        echo json_encode($response);
        exit;
    }
    
    $allowedTypes = ['jpg', 'png', 'mp4', 'mov', 'webm', 'jpeg', 'docx', 'xlsx', 'pdf'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedTypes)) {
        $response['success'] = false;
        $response['message'] = 'Loại file không được hỗ trợ!';
        echo json_encode($response);
        exit;
    }
    
    $prefix = 'umters_';
    $newFileName = $prefix . uniqid() . '.' . $fileExt;
    $uploadDir = 'downloads/';
    $uploadPath = $uploadDir . $newFileName;
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    if (move_uploaded_file($fileTmp, $uploadPath)) {
        $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $ip_address = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        $filePath = mysqli_real_escape_string($conn, $uploadPath);
        $sql = "INSERT INTO files (filename, file_path, user_agent, ip_address) VALUES ('$fileName', '$filePath', '$user_agent', '$ip_address')";
        if (mysqli_query($conn, $sql)) {
            $response['success'] = true;
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $response['link'] = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $uploadPath;
        } else {
            $response['success'] = false;
            $response['message'] = 'Lỗi khi lưu vào database: ' . mysqli_error($conn);
        }
    } else {
        $response['success'] = false;
        $response['message'] = 'Lỗi khi tải file lên!';
    }
} else {
    $response['success'] = false;
    $response['message'] = 'Không có file được gửi!';
}

echo json_encode($response);
mysqli_close($conn);
?>