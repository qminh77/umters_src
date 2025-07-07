<?php
session_start();

// Kiểm tra yêu cầu POST từ JavaScript
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (isset($data['valid']) && $data['valid'] === true) {
        $_SESSION['valid_qr_scan'] = true;
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Phương thức không được phép']);
}
?>