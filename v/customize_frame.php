<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Tùy chỉnh màu khung cho ảnh đã chụp với PhotoBooth.">
    <meta name="keywords" content="photobooth, chụp ảnh, webcam, khung ảnh, tùy chỉnh ảnh">
    <title>PhotoBooth - Tùy chỉnh màu khung</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.12.0/dist/cdn.min.js" defer></script>
    <script src="photobooth_layout/layout.js"></script>
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include 'includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Tùy chỉnh màu khung</h1>

        <!-- Hiển thị các ảnh đã chụp riêng lẻ -->
        <div id="capturedImagesContainer" class="flex flex-wrap gap-4 justify-center mb-8"></div>

        <!-- Giao diện chọn màu khung -->
        <div class="flex flex-col items-center gap-4">
            <label for="colorInput" class="font-semibold">Chọn màu khung:</label>
            <input type="color" id="colorInput" value="#ffffff" class="w-10 h-10 p-0 border-2 border-gray-300 rounded">
            <button id="applyFrameColor" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">Áp dụng</button>
        </div>

        <!-- Canvas để hiển thị ảnh đã ghép -->
        <canvas id="finalCanvas" class="w-full rounded-lg hidden mt-8"></canvas>

        <!-- Nút tải xuống ảnh đã ghép -->
        <button id="downloadBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded transition hidden mt-4">Tải xuống</button>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const capturedImagesContainer = document.getElementById('capturedImagesContainer');
            const colorInput = document.getElementById('colorInput');
            const applyFrameColorBtn = document.getElementById('applyFrameColor');
            const finalCanvas = document.getElementById('finalCanvas');
            const downloadBtn = document.getElementById('downloadBtn');
            const photoboothLayout = new PhotoboothLayout();

            // Lấy ảnh đã chụp từ localStorage
            const capturedImages = JSON.parse(localStorage.getItem('capturedImages')) || [];
            const layout = localStorage.getItem('layout') || 'A';

            // Hiển thị các ảnh đã chụp riêng lẻ
            capturedImages.forEach((imgSrc, index) => {
                const imgDiv = document.createElement('div');
                imgDiv.className = 'w-48 h-36 border-2 border-gray-300 rounded overflow-hidden';
                const img = document.createElement('img');
                img.src = imgSrc;
                img.className = 'w-full h-full object-cover';
                imgDiv.appendChild(img);
                capturedImagesContainer.appendChild(imgDiv);
            });

            // Xử lý đổi màu khung
            applyFrameColorBtn.addEventListener('click', async () => {
                const color = colorInput.value;
                photoboothLayout.setFrameColor(color);
                // Render lại ảnh với màu khung mới
                const canvas = await photoboothLayout.renderLayout(layout, capturedImages);
                finalCanvas.width = canvas.width;
                finalCanvas.height = canvas.height;
                const ctx = finalCanvas.getContext('2d');
                ctx.drawImage(canvas, 0, 0);
                finalCanvas.classList.remove('hidden');
                downloadBtn.classList.remove('hidden');
            });

            // Xử lý tải xuống ảnh đã ghép
            downloadBtn.addEventListener('click', () => {
                const link = document.createElement('a');
                link.download = 'photobooth.png';
                link.href = finalCanvas.toDataURL('image/png');
                link.click();
            });
        });
    </script>
</body>
</html> 