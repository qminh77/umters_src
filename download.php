<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lấy user_id của người dùng hiện tại
$user_id = (int)$_SESSION['user_id'];

// Kiểm tra tham số file
if (!isset($_GET['file']) || empty($_GET['file'])) {
    die("Không tìm thấy file!");
}

$file_path = urldecode($_GET['file']);

// Kiểm tra quyền truy cập file
// File trong thư mục downloads/fileuser/{user_id}/ chỉ được tải bởi chính user đó
$sql = "SELECT file_path FROM user_files WHERE file_path = ? AND user_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "si", $file_path, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {
    // File thuộc về user hiện tại, cho phép tải
    $file = mysqli_fetch_assoc($result);
    $file_path = $file['file_path'];

    // Kiểm tra file có tồn tại không
    if (!file_exists($file_path)) {
        die("File không tồn tại hoặc đã bị xóa!");
    }

    // Thiết lập header để tải file
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));

    // Đọc và gửi file đến trình duyệt
    readfile($file_path);
    exit;
} else {
    // Kiểm tra file trong bảng files (chức năng upload trước đó)
    $sql = "SELECT file_path FROM files WHERE file_path = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $file_path);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        // File thuộc chức năng upload trước đó, cho phép tải (nếu bạn muốn hạn chế quyền, có thể thêm điều kiện kiểm tra user_id)
        $file = mysqli_fetch_assoc($result);
        $file_path = $file['file_path'];

        if (!file_exists($file_path)) {
            die("File không tồn tại hoặc đã bị xóa!");
        }

        // Thiết lập header để tải file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));

        // Đọc và gửi file đến trình duyệt
        readfile($file_path);
        exit;
    } else {
        die("Bạn không có quyền tải file này hoặc file không tồn tại!");
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>