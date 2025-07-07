<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin Control Panel - Quản lý user và khung mẫu PhotoBooth.">
    <title>AdminCP - PhotoBooth</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Admin Control Panel</h1>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-2xl font-semibold mb-4">Quản Lý User</h2>
                <p>Quản lý danh sách user, xóa user nếu cần.</p>
                <a href="manage_users.php" class="mt-4 inline-block bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded transition">Đi Tới</a>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-2xl font-semibold mb-4">Tải Lên Khung Mẫu</h2>
                <p>Tải lên các khung mẫu để người dùng sử dụng trong PhotoBooth.</p>
                <a href="upload_frame.php" class="mt-4 inline-block bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded transition">Đi Tới</a>
            </div>
        </div>
        <div class="bg-white p-6 rounded-lg shadow-lg">
    <h2 class="text-2xl font-semibold mb-4">Quản Lý Ảnh</h2>
    <p>Quản lý các ảnh đã tải lên bởi người dùng.</p>
    <a href="manage_photos.php" class="mt-4 inline-block bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded transition">Đi Tới</a>
</div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>