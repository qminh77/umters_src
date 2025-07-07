<?php
include 'db_config.php';

// Xóa file hết hạn
$sql_expired = "SELECT id, file_path FROM files WHERE expires_at IS NOT NULL AND expires_at <= NOW()";
$result_expired = mysqli_query($conn, $sql_expired);
if ($result_expired) {
    while ($file = mysqli_fetch_assoc($result_expired)) {
        $file_path = $file['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        $file_id = $file['id'];
        mysqli_query($conn, "DELETE FROM files WHERE id = $file_id");
    }
}

mysqli_close($conn);
echo "Đã xóa các file hết hạn!\n";