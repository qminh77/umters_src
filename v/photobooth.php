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
    <script src="photobooth_layout/layout.js"></script>
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include 'includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Chụp Ảnh Với PhotoBooth</h1>

        <!-- Chọn layout và delay -->
        <div class="flex flex-wrap gap-4 justify-center mb-8">
            <div>
                <label class="block font-semibold mb-1">Chọn Layout</label>
                <select id="layout" class="border rounded px-2 py-1">
                    <option value="A">Layout A (4 ảnh)</option>
                    <option value="B">Layout B (3 ảnh)</option>
                    <option value="C">Layout C (2 ảnh)</option>
                    <option value="D">Layout D (6 ảnh)</option>
                </select>
            </div>
            <div>
                <label class="block font-semibold mb-1">Delay (giây)</label>
                <input id="delay" type="number" min="1" max="5" value="2" class="border rounded px-2 py-1 w-16">
            </div>
            <button id="startPhotobooth" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">Chụp Photobooth</button>
        </div>

        <!-- Video Stream -->
        <div class="flex flex-col md:flex-row justify-center items-start gap-8">
            <div class="bg-white p-6 rounded-lg shadow-lg w-full md:w-1/2">
                <video id="video" class="w-full rounded-lg"></video>
                <div class="flex gap-4 mt-4">
                    <button id="toggleCam" class="w-1/2 bg-green-600 hover:bg-green-700 text-white py-2 rounded transition transform hover:scale-105">Bật Cam</button>
                    <button id="capture" class="w-1/2 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded transition transform hover:scale-105" disabled>Chụp Ảnh</button>
                </div>
            </div>
            <!-- Khung ảnh và kết quả -->
            <div class="bg-white p-6 rounded-lg shadow-lg w-full md:w-1/2">
                <div id="framePreview" class="mb-4 flex flex-wrap gap-4"></div>
                <canvas id="finalCanvas" class="w-full rounded-lg hidden"></canvas>
                <p id="no-image" class="text-center text-gray-500">Chưa có ảnh nào được chụp.</p>
                <!-- Hiển thị các ảnh đã chụp riêng lẻ -->
                <div id="capturedImagesContainer" class="hidden mt-4 flex flex-wrap gap-4"></div>
                <!-- Nút chuyển sang trang tùy chỉnh màu khung -->
                <div id="customizeFrameContainer" class="hidden mt-4">
                    <button id="customizeFrameBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">Tùy chỉnh màu khung</button>
                </div>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
    <div id="countdown" class="fixed inset-0 flex items-center justify-center z-50 text-7xl font-bold text-white bg-black bg-opacity-50 hidden"></div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const video = document.getElementById('video');
            const finalCanvas = document.getElementById('finalCanvas');
            const startPhotoboothBtn = document.getElementById('startPhotobooth');
            const layoutSelect = document.getElementById('layout');
            const delayInput = document.getElementById('delay');
            const framePreview = document.getElementById('framePreview');
            const noImageText = document.getElementById('no-image');
            const toggleCamButton = document.getElementById('toggleCam');
            const countdownDiv = document.getElementById('countdown');
            let stream = null;
            let isCamOn = false;
            const photoboothLayout = new PhotoboothLayout();

            // Layout cấu hình
            const layoutConfig = {
                A: { count: 4, type: 'vertical' },
                B: { count: 3, type: 'vertical' },
                C: { count: 2, type: 'vertical' },
                D: { count: 6, type: 'grid' }
            };

            // Bật webcam tự động
            async function startCamera() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    alert('Trình duyệt không hỗ trợ webcam!');
                    return;
                }
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ 
                        video: { 
                            width: { ideal: 1920 },
                            height: { ideal: 1080 },
                            facingMode: "user"
                        } 
                    });
                    video.srcObject = stream;
                    await video.play();
                    isCamOn = true;
                    toggleCamButton.textContent = 'Tắt Cam';
                    toggleCamButton.classList.remove('bg-green-600', 'hover:bg-green-700');
                    toggleCamButton.classList.add('bg-red-600', 'hover:bg-red-700');
                } catch (err) {
                    console.error("Lỗi truy cập webcam: ", err);
                    alert("Không thể truy cập webcam. Vui lòng kiểm tra quyền truy cập và thử lại.");
                }
            }

            function stopCamera() {
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    stream = null;
                    video.srcObject = null;
                    isCamOn = false;
                    toggleCamButton.textContent = 'Bật Cam';
                    toggleCamButton.classList.remove('bg-red-600', 'hover:bg-red-700');
                    toggleCamButton.classList.add('bg-green-600', 'hover:bg-green-700');
                }
            }

            toggleCamButton.addEventListener('click', async () => {
                if (isCamOn) {
                    stopCamera();
                } else {
                    await startCamera();
                }
            });

            // Hiển thị khung preview
            function showPreviewPlaceholders(layout) {
                framePreview.innerHTML = '';
                const { count, type } = layoutConfig[layout];
                for (let i = 0; i < count; i++) {
                    const div = document.createElement('div');
                    div.className = 'w-32 h-24 bg-gray-200 rounded-lg border-2 border-dashed border-gray-400 flex items-center justify-center text-gray-500';
                    div.innerText = `Ảnh ${i+1}`;
                    framePreview.appendChild(div);
                }
                noImageText.classList.remove('hidden');
                finalCanvas.classList.add('hidden');
            }

            // Hiển thị countdown
            function showCountdown(seconds) {
                return new Promise(resolve => {
                    countdownDiv.classList.remove('hidden');
                    let current = seconds;
                    countdownDiv.textContent = current;
                    const interval = setInterval(() => {
                        current--;
                        if (current > 0) {
                            countdownDiv.textContent = current;
                        } else {
                            clearInterval(interval);
                            countdownDiv.textContent = '';
                            countdownDiv.classList.add('hidden');
                            resolve();
                        }
                    }, 1000);
                });
            }

            // Chụp nhiều ảnh với delay
            async function capturePhotobooth() {
                const layout = layoutSelect.value;
                const delay = Math.max(1, Math.min(5, parseInt(delayInput.value)));
                const { count, type } = layoutConfig[layout];
                let capturedImages = [];

                for (let i = 0; i < count; i++) {
                    framePreview.children[i].innerText = `Chuẩn bị...`;
                    await showCountdown(delay);
                    // Chụp ảnh với chất lượng cao
                    const tempCanvas = document.createElement('canvas');
                    tempCanvas.width = video.videoWidth;
                    tempCanvas.height = video.videoHeight;
                    const ctx = tempCanvas.getContext('2d', { alpha: false });
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(video, 0, 0, tempCanvas.width, tempCanvas.height);
                    // Lưu ảnh với chất lượng cao
                    capturedImages.push(tempCanvas.toDataURL('image/jpeg', 0.95));
                    framePreview.children[i].innerHTML = `<img src="${capturedImages[i]}" class="w-full h-full object-cover rounded-lg">`;
                }
                noImageText.classList.add('hidden');
                window._lastCapturedImages = capturedImages; // Lưu lại để đổi màu khung
                await renderFinalImage(layout, capturedImages);
            }

            // Ghép ảnh theo layout
            async function renderFinalImage(layout, images) {
                // Hiển thị các ảnh đã chụp riêng lẻ
                const container = document.getElementById('capturedImagesContainer');
                container.innerHTML = '';
                container.classList.remove('hidden');
                images.forEach((imgSrc, index) => {
                    const imgDiv = document.createElement('div');
                    imgDiv.className = 'w-48 h-36 border-2 border-gray-300 rounded overflow-hidden';
                    const img = document.createElement('img');
                    img.src = imgSrc;
                    img.className = 'w-full h-full object-cover';
                    imgDiv.appendChild(img);
                    container.appendChild(imgDiv);
                });
                // Hiện nút chuyển sang trang tùy chỉnh màu khung
                document.getElementById('customizeFrameContainer').classList.remove('hidden');
            }

            // Sự kiện
            layoutSelect.addEventListener('change', () => showPreviewPlaceholders(layoutSelect.value));
            startPhotoboothBtn.addEventListener('click', async () => {
                if (!isCamOn) await startCamera();
                showPreviewPlaceholders(layoutSelect.value);
                await capturePhotobooth();
            });

            // Xử lý chuyển sang trang tùy chỉnh màu khung
            document.getElementById('customizeFrameBtn').addEventListener('click', () => {
                // Lưu ảnh đã chụp vào localStorage để trang mới có thể truy cập
                localStorage.setItem('capturedImages', JSON.stringify(window._lastCapturedImages));
                localStorage.setItem('layout', layoutSelect.value);
                // Chuyển sang trang tùy chỉnh màu khung
                window.location.href = 'customize_frame.php';
            });

            // Khởi tạo
            showPreviewPlaceholders(layoutSelect.value);
        });
    </script>
</body>
</html>