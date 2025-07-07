<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Kiểm tra nếu đã đăng nhập, chuyển hướng đến photobooth
if (isLoggedIn()) {
    header('Location: photobooth');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PhotoBooth - Tạo những bức ảnh độc đáo và thú vị với giao diện đẹp mắt và dễ sử dụng.">
    <meta name="keywords" content="photobooth, chụp ảnh, hiệu ứng ảnh, đăng ký photobooth">
    <meta name="author" content="PhotoBooth Team">
    <title>PhotoBooth - Chụp Ảnh Độc Đáo</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.12.0/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 font-sans">
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section với hiệu ứng parallax -->
    <section class="relative h-screen bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1516035069371-29a1b244cc49');" x-data="{ scrollY: 0 }" @scroll.window="scrollY = window.scrollY">
        <div class="absolute inset-0 bg-black opacity-50"></div>
        <div class="container mx-auto h-full flex items-center justify-center text-center text-white relative z-10">
            <div x-bind:style="'transform: translateY(' + scrollY * 0.3 + 'px)'">
                <h1 class="text-5xl md:text-7xl font-bold mb-4 animate-fade-in">Chào Mừng Đến Với PhotoBooth</h1>
                <p class="text-xl md:text-2xl mb-8 animate-fade-in-delay">Tạo những bức ảnh độc đáo với các hiệu ứng tuyệt đẹp!</p>
                <a href="/register" class="bg-blue-600 hover:bg-blue-700 text-white py-3 px-6 rounded-full text-lg transition transform hover:scale-105">Đăng Ký Ngay</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-16 bg-white">
        <div class="container mx-auto text-center">
            <h2 class="text-4xl font-bold mb-12">Tại Sao Chọn PhotoBooth?</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="p-6 hover:shadow-lg transition transform hover:-translate-y-2">
                    <img src="https://img.icons8.com/color/96/000000/camera.png" alt="Camera Icon" class="mx-auto mb-4">
                    <h3 class="text-2xl font-semibold mb-2">Chụp Ảnh Dễ Dàng</h3>
                    <p>Chỉ cần một cú nhấp chuột để tạo ra những bức ảnh ấn tượng.</p>
                </div>
                <div class="p-6 hover:shadow-lg transition transform hover:-translate-y-2">
                    <img src="https://img.icons8.com/color/96/000000/filter.png" alt="Filter Icon" class="mx-auto mb-4">
                    <h3 class="text-2xl font-semibold mb-2">Hiệu Ứng Độc Đáo</h3>
                    <p>Áp dụng các bộ lọc và hiệu ứng để làm nổi bật bức ảnh của bạn.</p>
                </div>
                <div class="p-6 hover:shadow-lg transition transform hover:-translate-y-2">
                    <img src="https://img.icons8.com/color/96/000000/share.png" alt="Share Icon" class="mx-auto mb-4">
                    <h3 class="text-2xl font-semibold mb-2">Chia Sẻ Nhanh Chóng</h3>
                    <p>Chia sẻ ảnh của bạn lên mạng xã hội chỉ trong vài giây.</p>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>
    <script src="assets/js/script.js"></script>
</body>
</html>