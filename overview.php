<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lấy số liệu cho tổng quan
$total_files = 0;
$total_users = 0;
$total_emails = 0;

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM files");
$stmt->execute();
$total_files = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$total_users = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM email_logs");
$stmt->execute();
$total_emails = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
?>

<div class="content-section">
    <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> Tổng Quan Dashboard</h2>
    <div class="overview-grid">
        <div class="overview-card">
            <i class="fas fa-file"></i>
            <h3>Tổng File</h3>
            <p><?php echo $total_files; ?></p>
        </div>
        <div class="overview-card">
            <i class="fas fa-users"></i>
            <h3>Tổng Người Dùng</h3>
            <p><?php echo $total_users; ?></p>
        </div>
        <div class="overview-card">
            <i class="fas fa-envelope"></i>
            <h3>Tổng Email</h3>
            <p><?php echo $total_emails; ?></p>
        </div>
    </div>
    <div class="quick-actions">
        <a href="file_manager.php" class="action-btn"><i class="fas fa-folder"></i> Quản Lý File</a>
        <a href="profile.php" class="action-btn"><i class="fas fa-user"></i> Xem Profile</a>
        <a href="converters.php" class="action-btn"><i class="fas fa-cog"></i> Công Cụ</a>
        <a href="chat.php" class="action-btn"><i class="fas fa-comments"></i> Chat</a>
    </div>
</div>