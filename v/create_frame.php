<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit;
}

$userId = $_SESSION['user_id'];
$uploadDir = "uploads/user/$userId/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Chụp ảnh độc đáo với PhotoBooth, tùy chỉnh khung ảnh và sắp xếp theo ý muốn.">
    <meta name="keywords" content="photobooth, chụp ảnh, webcam, khung ảnh, tùy chỉnh ảnh">
    <title>PhotoBooth - Chụp Ảnh</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.12.0/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include 'includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Chụp Ảnh Với PhotoBooth</h1>

        <!-- Phần chọn khung -->
        <?php include 'create_frame.php'; ?>

        <!-- Phần chụp ảnh -->
        <div class="flex flex-col md:flex-row justify-center items-start gap-8">
            <!-- Video Stream -->
            <div class="bg-white p-6 rounded-lg shadow-lg w-full md:w-1/2">
                <video id="video" class="w-full rounded-lg"></video>
                <div class="flex gap-4 mt-4">
                    <button id="toggleCam" class="w-1/2 bg-green-600 hover:bg-green-700 text-white py-2 rounded transition transform hover:scale-105">Bật Cam</button>
                    <button id="capture" class="w-1/2 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded transition transform hover:scale-105" disabled>Chụp Ảnh</button>
                </div>
            </div>
            <!-- Khung ảnh và kết quả -->
            <div class="bg-white p-6 rounded-lg shadow-lg w-full md:w-1/2">
                <div id="framePreview" class="mb-4 flex flex-wrap gap-4 p-4 bg-gray-50 rounded-lg relative overflow-hidden">
                    <div class="absolute inset-0 opacity-10" style="background-image: url('https://www.transparenttextures.com/patterns/confetti.png');"></div>
                </div>
                <div id="finalOutput" class="mt-4">
                    <canvas id="finalCanvas" class="w-full rounded-lg hidden"></canvas>
                    <div id="buttonContainer" class="flex gap-4 mt-4 hidden">
                        <button id="downloadButton" class="w-1/2 bg-green-600 hover:bg-green-700 text-white py-2 rounded transition transform hover:scale-105">Tải Về</button>
                        <button id="saveButton" class="w-1/2 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded transition transform hover:scale-105">Lưu Ảnh</button>
                    </div>
                </div>
                <p id="no-image" class="text-center text-gray-500">Chưa có ảnh nào được chụp.</p>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const video = document.getElementById('video');
            const finalCanvas = document.getElementById('finalCanvas');
            const captureButton = document.getElementById('capture');
            const toggleCamButton = document.getElementById('toggleCam');
            const photoCountSelect = document.getElementById('photoCount');
            const layoutSelect = document.getElementById('layout');
            const borderColorSelect = document.getElementById('borderColor');
            const applyFrameButton = document.getElementById('applyFrame');
            const framePreview = document.getElementById('framePreview');
            const noImageText = document.getElementById('no-image');
            const downloadButton = document.getElementById('downloadButton');
            const saveButton = document.getElementById('saveButton');
            const buttonContainer = document.getElementById('buttonContainer');

            let stream = null;
            let isCamOn = false;
            let capturedImages = [];
            let currentFrame = { count: 2, layout: 'horizontal', borderColor: 'gold' };

            // Khởi tạo khung mặc định
            function initializeFrame() {
                framePreview.innerHTML = '<div class="absolute inset-0 opacity-10" style="background-image: url(\'https://www.transparenttextures.com/patterns/confetti.png\');"></div>';
                capturedImages = [];
                finalCanvas.classList.add('hidden');
                buttonContainer.classList.add('hidden');
                noImageText.classList.remove('hidden');

                const count = parseInt(currentFrame.count);
                const layout = currentFrame.layout;
                const borderColor = currentFrame.borderColor;

                let borderStyle = '';
                if (borderColor === 'gold') {
                    borderStyle = 'bg-gradient-to-br from-[#FFD700] to-[#FFA500]';
                } else if (borderColor === 'rose') {
                    borderStyle = 'bg-gradient-to-br from-[#FF6F91] to-[#FFE1E9]';
                } else {
                    borderStyle = 'bg-gradient-to-br from-[#000000] to-[#4B4B4B]';
                }

                for (let i = 0; i < count; i++) {
                    const placeholder = document.createElement('div');
                    placeholder.className = `rounded-lg border-4 border-white shadow-lg relative overflow-hidden ${borderStyle} ${
                        layout === 'horizontal' ? 'w-48 h-32 inline-block' :
                        layout === 'vertical' ? 'w-48 h-32 block' :
                        'w-48 h-32 inline-block'
                    }`;
                    placeholder.innerHTML = `<span class="absolute top-1 left-1 text-white text-xs font-semibold bg-black bg-opacity-50 px-1 rounded">Photo ${i + 1}</span>`;
                    placeholder.id = `placeholder-${i}`;
                    framePreview.appendChild(placeholder);
                }

                if (layout === 'grid' && count === 4) {
                    framePreview.className = 'mb-4 grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg relative overflow-hidden';
                } else {
                    framePreview.className = 'mb-4 flex flex-wrap gap-4 p-4 bg-gray-50 rounded-lg relative overflow-hidden';
                }
            }

            // Bật webcam
            async function startCamera() {
                try {
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        throw new Error('Trình duyệt không hỗ trợ getUserMedia');
                    }
                    stream = await navigator.mediaDevices.getUserMedia({ video: true });
                    if (video.srcObject) {
                        video.srcObject.getTracks().forEach(track => track.stop());
                    }
                    video.srcObject = stream;
                    await video.play();
                    isCamOn = true;
                    toggleCamButton.textContent = 'Tắt Cam';
                    toggleCamButton.classList.remove('bg-green-600', 'hover:bg-green-700');
                    toggleCamButton.classList.add('bg-red-600', 'hover:bg-red-700');
                    captureButton.disabled = false;
                } catch (err) {
                    console.error("Lỗi truy cập webcam: ", err);
                    noImageText.textContent = `Không thể truy cập webcam: ${err.message}`;
                    isCamOn = false;
                    toggleCamButton.textContent = 'Bật Cam';
                    toggleCamButton.classList.remove('bg-red-600', 'hover:bg-red-700');
                    toggleCamButton.classList.add('bg-green-600', 'hover:bg-green-700');
                    captureButton.disabled = true;
                }
            }

            // Tắt webcam
            function stopCamera() {
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                    video.srcObject = null;
                    isCamOn = false;
                    toggleCamButton.textContent = 'Bật Cam';
                    toggleCamButton.classList.remove('bg-red-600', 'hover:bg-red-700');
                    toggleCamButton.classList.add('bg-green-600', 'hover:bg-green-700');
                    captureButton.disabled = true;
                }
            }

            toggleCamButton.addEventListener('click', async () => {
                if (isCamOn) {
                    stopCamera();
                } else {
                    await startCamera();
                }
            });

            // Áp dụng khung
            applyFrameButton.addEventListener('click', () => {
                currentFrame.count = photoCountSelect.value;
                currentFrame.layout = layoutSelect.value;
                currentFrame.borderColor = borderColorSelect.value;
                initializeFrame();
            });

            // Chụp ảnh
            captureButton.addEventListener('click', () => {
                if (!isCamOn || !video.srcObject) {
                    noImageText.textContent = 'Vui lòng bật webcam trước khi chụp.';
                    return;
                }

                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = video.videoWidth;
                tempCanvas.height = video.videoHeight;
                const ctx = tempCanvas.getContext('2d');
                ctx.drawImage(video, 0, 0, tempCanvas.width, tempCanvas.height);

                // Tính toán tỷ lệ để giữ ảnh không bị méo
                const frameWidth = 192;
                const frameHeight = 128;
                const videoAspect = video.videoWidth / video.videoHeight;
                const frameAspect = frameWidth / frameHeight;

                let drawWidth, drawHeight, offsetX, offsetY;
                if (videoAspect > frameAspect) {
                    drawHeight = frameHeight;
                    drawWidth = frameHeight * videoAspect;
                    offsetX = (drawWidth - frameWidth) / 2;
                    offsetY = 0;
                } else {
                    drawWidth = frameWidth;
                    drawHeight = frameWidth / videoAspect;
                    offsetX = 0;
                    offsetY = (drawHeight - frameHeight) / 2;
                }

                const croppedCanvas = document.createElement('canvas');
                croppedCanvas.width = frameWidth;
                croppedCanvas.height = frameHeight;
                const croppedCtx = croppedCanvas.getContext('2d');
                croppedCtx.drawImage(tempCanvas, offsetX, offsetY, drawWidth, drawHeight, 0, 0, frameWidth, frameHeight);
                capturedImages.push(croppedCanvas.toDataURL('image/png'));

                const index = capturedImages.length - 1;
                const placeholder = document.getElementById(`placeholder-${index}`);
                if (placeholder) {
                    placeholder.innerHTML = `<img src="${capturedImages[index]}" class="w-full h-full object-cover rounded-lg">`;
                }

                if (capturedImages.length === parseInt(currentFrame.count)) {
                    renderFinalImage();
                }
            });

            // Vẽ và hiển thị tấm photobooth hoàn chỉnh
            function renderFinalImage() {
                const count = parseInt(currentFrame.count);
                const layout = currentFrame.layout;
                const borderColor = currentFrame.borderColor;
                const photoWidth = 192;
                const photoHeight = 128;
                let canvasWidth, canvasHeight;

                if (layout === 'horizontal') {
                    canvasWidth = photoWidth * count + (count - 1) * 16;
                    canvasHeight = photoHeight + 32; // Thêm padding cho viền
                } else if (layout === 'vertical') {
                    canvasWidth = photoWidth + 32;
                    canvasHeight = photoHeight * count + (count - 1) * 16 + 32;
                } else { // grid (2x2)
                    canvasWidth = photoWidth * 2 + 16 + 32;
                    canvasHeight = photoHeight * 2 + 16 + 32;
                }

                finalCanvas.width = canvasWidth;
                finalCanvas.height = canvasHeight;
                const ctx = finalCanvas.getContext('2d');

                // Vẽ nền với họa tiết confetti
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvasWidth, canvasHeight);
                const pattern = new Image();
                pattern.src = 'https://www.transparenttextures.com/patterns/confetti.png';
                pattern.onload = () => {
                    const patternFill = ctx.createPattern(pattern, 'repeat');
                    ctx.globalAlpha = 0.1;
                    ctx.fillStyle = patternFill;
                    ctx.fillRect(0, 0, canvasWidth, canvasHeight);
                    ctx.globalAlpha = 1.0;
                };

                // Vẽ viền gradient cho khung
                let gradient;
                if (borderColor === 'gold') {
                    gradient = ctx.createLinearGradient(0, 0, canvasWidth, canvasHeight);
                    gradient.addColorStop(0, '#FFD700');
                    gradient.addColorStop(1, '#FFA500');
                } else if (borderColor === 'rose') {
                    gradient = ctx.createLinearGradient(0, 0, canvasWidth, canvasHeight);
                    gradient.addColorStop(0, '#FF6F91');
                    gradient.addColorStop(1, '#FFE1E9');
                } else {
                    gradient = ctx.createLinearGradient(0, 0, canvasWidth, canvasHeight);
                    gradient.addColorStop(0, '#000000');
                    gradient.addColorStop(1, '#4B4B4B');
                }
                ctx.strokeStyle = gradient;
                ctx.lineWidth = 8;
                ctx.strokeRect(16, 16, canvasWidth - 32, canvasHeight - 32);

                capturedImages.forEach((imgSrc, index) => {
                    const img = new Image();
                    img.src = imgSrc;
                    let x, y;

                    if (layout === 'horizontal') {
                        x = 16 + index * (photoWidth + 16);
                        y = 16;
                    } else if (layout === 'vertical') {
                        x = 16;
                        y = 16 + index * (photoHeight + 16);
                    } else { // grid
                        x = 16 + (index % 2) * (photoWidth + 16);
                        y = 16 + Math.floor(index / 2) * (photoHeight + 16);
                    }

                    img.onload = () => {
                        ctx.drawImage(img, x, y, photoWidth, photoHeight);
                        // Thêm nhãn trên ảnh
                        ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
                        ctx.font = '12px Arial';
                        ctx.fillRect(x, y, 60, 20);
                        ctx.fillStyle = '#fff';
                        ctx.fillText(`Photo ${index + 1}`, x + 5, y + 15);
                    };
                });

                finalCanvas.classList.remove('hidden');
                buttonContainer.classList.remove('hidden');
                noImageText.classList.add('hidden');

                // Thiết lập nút tải về
                downloadButton.addEventListener('click', () => {
                    const link = document.createElement('a');
                    link.download = `photobooth-${Date.now()}.png`;
                    link.href = finalCanvas.toDataURL('image/png');
                    link.click();
                });

                // Thiết lập nút lưu
                saveButton.addEventListener('click', () => {
                    saveFinalImage();
                    alert('Ảnh đã được lưu vào server!');
                });
            }

            // Lưu ảnh vào thư mục user
            function saveFinalImage() {
                const dataURL = finalCanvas.toDataURL('image/png');
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'save_photo.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        console.log('Ảnh đã được lưu: ' + xhr.responseText);
                    }
                };
                xhr.send('image=' + encodeURIComponent(dataURL));
            }

            // Khởi tạo khung mặc định
            initializeFrame();
        });
    </script>
</body>
</html>