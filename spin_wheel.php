<?php
include 'db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$response = ['success' => false, 'message' => '', 'error' => ''];

$result = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key='delete_after_spin'");
$delete_after_spin = $result ? mysqli_fetch_assoc($result)['setting_value'] : '1';

$items = [];
$result = mysqli_query($conn, "SELECT items FROM lucky_wheel WHERE id=1");
if ($result && mysqli_num_rows($result) > 0) {
    $items = json_decode(mysqli_fetch_assoc($result)['items'], true);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    if ($action == 'update_items') {
        $items_input = $_POST['items'] ?? '';
        $items_array = array_filter(array_map('trim', explode("\n", $items_input)));
        if (empty($items_array)) {
            echo json_encode(['success' => false, 'error' => 'Danh sách không được để trống']);
            exit;
        }
        $items_data = [];
        foreach ($items_array as $item) {
            $items_data[] = ['content' => $item, 'percentage' => 100 / count($items_array)];
        }
        $items_json = json_encode($items_data);

        $stmt = $conn->prepare("REPLACE INTO lucky_wheel (id, items) VALUES (1, ?)");
        $stmt->bind_param("s", $items_json);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Cập nhật danh sách thành công!', 'items' => $items_data]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Lỗi khi cập nhật: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    } elseif ($action == 'toggle_delete') {
        $new_value = isset($_POST['delete_after_spin']) && $_POST['delete_after_spin'] == '1' ? '1' : '0';
        $stmt = $conn->prepare("UPDATE settings SET setting_value=? WHERE setting_key='delete_after_spin'");
        $stmt->bind_param("s", $new_value);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Cập nhật tùy chọn xóa thành công!', 'value' => $new_value]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Lỗi khi cập nhật: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    } elseif ($action == 'delete_item') {
        $index = intval($_POST['index'] ?? -1);
        if ($index >= 0 && isset($items[$index])) {
            $deleted_item = array_splice($items, $index, 1);
            $items_json = json_encode($items);
            $stmt = $conn->prepare("UPDATE lucky_wheel SET items=? WHERE id=1");
            $stmt->bind_param("s", $items_json);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Xóa mục thành công!', 'items' => $items, 'deleted' => $deleted_item]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Lỗi khi xóa: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'error' => 'Mục không hợp lệ']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vòng Quay May Mắn</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0e7ff, #f3e8ff);
        }
        .wheel-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            aspect-ratio: 1/1;
            margin: 0 auto;
        }
        .wheel-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            border-radius: 50%;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        canvas {
            width: 100%;
            height: 100%;
        }
        .wheel-pointer {
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 60px;
            z-index: 20;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.3));
        }
        .wheel-pointer svg {
            width: 100%;
            height: 100%;
        }
        .wheel-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            background: #ffffff;
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .wheel-center::after {
            content: '';
            width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border-radius: 50%;
        }
        .undo-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 50;
            animation: slideIn 0.3s ease-out;
        }
        .spin-btn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .spin-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        .spin-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.6s ease;
        }
        .spin-btn:hover:not(:disabled)::before {
            left: 100%;
        }
        .result-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .result-modal.active {
            opacity: 1;
            visibility: visible;
        }
        .result-content {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            max-width: 90%;
            width: 400px;
            transform: scale(0.8);
            transition: transform 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        .result-modal.active .result-content {
            transform: scale(1);
        }
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background-color: #f00;
            border-radius: 50%;
            animation: confetti-fall 5s ease-out forwards;
        }
        @keyframes confetti-fall {
            0% {
                transform: translateY(-100vh) rotate(0deg);
                opacity: 1;
            }
            100% {
                transform: translateY(100vh) rotate(720deg);
                opacity: 0;
            }
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        @media (max-width: 640px) {
            .wheel-container {
                max-width: 300px;
            }
            .wheel-pointer {
                top: -25px;
                width: 30px;
                height: 45px;
            }
            .wheel-center {
                width: 40px;
                height: 40px;
            }
            .wheel-center::after {
                width: 16px;
                height: 16px;
            }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6">
    <div class="bg-white rounded-2xl shadow-2xl max-w-5xl w-full p-6 sm:p-8">
        <?php include 'taskbar.php'; ?>
        <h1 class="text-3xl sm:text-4xl font-bold text-center text-transparent bg-clip-text bg-gradient-to-r from-indigo-500 to-purple-600 mb-6">Vòng Quay May Mắn</h1>
        <div id="message" class="hidden bg-green-100 text-green-700 p-4 rounded-lg mb-6 text-center font-medium"></div>
        <div id="error" class="hidden bg-red-100 text-red-700 p-4 rounded-lg mb-6 text-center font-medium"></div>

        <div class="flex flex-col items-center mb-8">
            <div class="wheel-container">
                <div class="wheel-wrapper">
                    <canvas id="wheel" width="500" height="500"></canvas>
                </div>
                <div class="wheel-pointer">
                    <svg viewBox="0 0 40 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 60L0 30L20 0L40 30L20 60Z" fill="#dc2626"/>
                        <path d="M20 45L10 30L20 15L30 30L20 45Z" fill="#ffffff"/>
                    </svg>
                </div>
                <div class="wheel-center"></div>
            </div>
            <button id="spin-btn" class="spin-btn mt-8 bg-gradient-to-r from-indigo-500 to-purple-600 text-white font-semibold py-4 px-10 rounded-full hover:from-indigo-600 hover:to-purple-700 transition transform hover:-translate-y-1 shadow-lg disabled:opacity-70 disabled:cursor-not-allowed text-lg" aria-label="Quay vòng may mắn">QUAY NGAY</button>
        </div>

        <div class="bg-gray-50 p-6 rounded-xl mb-8 shadow-md">
            <h3 class="text-xl font-semibold mb-4 text-gray-800 flex items-center">
                <i class="fas fa-list-ul mr-2 text-indigo-500"></i>
                Danh Sách Nội Dung
            </h3>
            <form id="items-form">
                <textarea id="items-input" name="items" class="w-full h-40 p-4 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:outline-none bg-white resize-y shadow-inner" placeholder="Nhập danh sách, mỗi dòng một mục (ví dụ: Quà 1, Quà 2, Quà 3)..." required aria-label="Danh sách nội dung vòng quay"><?php echo implode("\n", array_column($items, 'content')); ?></textarea>
                <div class="flex flex-col sm:flex-row justify-between mt-4 gap-4">
                    <button type="submit" class="bg-indigo-500 text-white py-2 px-6 rounded-lg hover:bg-indigo-600 transition font-medium flex items-center justify-center">
                        <i class="fas fa-save mr-2"></i> Cập nhật
                    </button>
                    <div class="flex gap-2">
                        <button type="button" id="export-btn" class="bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 transition font-medium flex items-center justify-center">
                            <i class="fas fa-download mr-2"></i> Xuất
                        </button>
                        <label for="import-input" class="bg-purple-500 text-white py-2 px-4 rounded-lg hover:bg-purple-600 transition cursor-pointer font-medium flex items-center justify-center">
                            <i class="fas fa-upload mr-2"></i> Nhập
                        </label>
                        <input type="file" id="import-input" accept=".txt" class="hidden">
                    </div>
                </div>
            </form>
        </div>

        <div class="text-center">
            <form id="toggle-form" class="inline-flex flex-col sm:flex-row items-center bg-gray-50 p-4 rounded-lg gap-4 shadow-md">
                <label class="flex items-center">
                    <input type="checkbox" name="delete_after_spin" value="1" <?php echo $delete_after_spin == '1' ? 'checked' : '' ?> class="mr-2 h-5 w-5 text-indigo-600 focus:ring-indigo-500 rounded">
                    <span class="text-gray-700 font-medium">Xóa mục sau khi quay trúng</span>
                </label>
                <button type="submit" class="bg-green-500 text-white py-2 px-6 rounded-lg hover:bg-green-600 transition font-medium flex items-center justify-center">
                    <i class="fas fa-check mr-2"></i> Lưu
                </button>
            </form>
        </div>
    </div>

    <div id="undo-toast" class="undo-toast hidden bg-gray-800 text-white p-4 rounded-lg shadow-lg flex items-center">
        <span id="undo-message"></span>
        <button id="undo-btn" class="ml-4 bg-blue-500 text-white py-1 px-3 rounded hover:bg-blue-600 transition">Hoàn tác</button>
    </div>

    <div id="result-modal" class="result-modal">
        <div class="result-content">
            <div class="text-5xl mb-4">🎉</div>
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Chúc mừng!</h3>
            <p class="text-lg mb-6">Bạn đã trúng: <span id="result-text" class="font-bold text-indigo-600"></span></p>
            <button id="close-result" class="bg-indigo-500 text-white py-2 px-6 rounded-lg hover:bg-indigo-600 transition font-medium">Đóng</button>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('wheel');
        const ctx = canvas.getContext('2d');
        let items = <?php echo json_encode($items); ?>;
        let isSpinning = false;
        let currentRotation = 0;
        let deletedItem = null;
        let deletedIndex = null;
        let lastWinnerIndex = null;
        const spinButton = document.getElementById('spin-btn');
        const resultModal = document.getElementById('result-modal');
        const resultText = document.getElementById('result-text');
        const closeResult = document.getElementById('close-result');
        const deleteAfterSpin = <?php echo $delete_after_spin; ?> === '1';

        function drawWheel() {
            const radius = canvas.width / 2;
            const centerX = radius;
            const centerY = radius;
            const angleStep = items.length ? 2 * Math.PI / items.length : 0;

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Draw outer circle
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.lineWidth = 6;
            ctx.strokeStyle = 'rgba(0, 0, 0, 0.1)';
            ctx.stroke();

            // Draw segments
            items.forEach((item, i) => {
                const startAngle = i * angleStep;
                const endAngle = (i + 1) * angleStep;

                // Draw segment
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, radius, startAngle, endAngle);
                ctx.fillStyle = `hsl(${i * 360 / items.length}, 85%, 65%)`;
                ctx.fill();
                ctx.closePath();

                // Draw segment border
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.lineTo(centerX + radius * Math.cos(startAngle), centerY + radius * Math.sin(startAngle));
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 2;
                ctx.stroke();

                // Draw text
                ctx.save();
                ctx.translate(centerX, centerY);
                ctx.rotate(startAngle + angleStep / 2);
                
                // Text styling
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 16px Poppins';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                ctx.shadowBlur = 4;
                
                // Truncate text if too long
                const maxLength = radius / 10;
                let displayText = item.content;
                if (ctx.measureText(displayText).width > radius - 60) {
                    let truncated = '';
                    for (let j = 0; j < displayText.length; j++) {
                        truncated += displayText[j];
                        if (ctx.measureText(truncated + '...').width > radius - 60) {
                            truncated = truncated.slice(0, -1) + '...';
                            break;
                        }
                    }
                    displayText = truncated;
                }
                
                ctx.fillText(displayText, radius - 30, 0);
                ctx.restore();
            });
            
            // Draw inner circle for better appearance
            ctx.beginPath();
            ctx.arc(centerX, centerY, 15, 0, 2 * Math.PI);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = 'rgba(0, 0, 0, 0.1)';
            ctx.lineWidth = 2;
            ctx.stroke();
        }

        function spinWheel() {
            if (isSpinning || items.length === 0) {
                if (items.length === 0) alert('Vui lòng thêm ít nhất một mục để quay!');
                return;
            }
            isSpinning = true;
            spinButton.disabled = true;
            
            // Select a random winner
            const winnerIndex = Math.floor(Math.random() * items.length);
            
            // Calculate rotation angle
            const anglePerItem = 2 * Math.PI / items.length;
            const targetAngle = -winnerIndex * anglePerItem - anglePerItem / 2 - Math.PI / 2;
            
            // Tăng số vòng quay và tốc độ
            const extraRotations = 2 * Math.PI * 10; // Tăng lên 10 vòng
            const finalAngle = targetAngle - extraRotations;
            
            // Giảm thời gian quay để tăng tốc độ
            const duration = 4000; // Giảm xuống 4 giây
            
            // Animation variables
            const startTime = performance.now();
            const startAngle = 0;
            
            // Animation function
            function animate(currentTime) {
                const elapsedTime = currentTime - startTime;
                const progress = Math.min(elapsedTime / duration, 1);
                
                // Easing function for smooth deceleration
                const easeOut = function(t) {
                    return 1 - Math.pow(1 - t, 4); // Tăng độ mượt của hiệu ứng giảm tốc
                };
                
                const easedProgress = easeOut(progress);
                const currentAngle = startAngle + (finalAngle - startAngle) * easedProgress;
                
                // Clear and redraw
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                // Save context state
                ctx.save();
                
                // Move to center and rotate
                ctx.translate(canvas.width / 2, canvas.height / 2);
                ctx.rotate(currentAngle);
                
                // Draw wheel (adjusted for rotation)
                const radius = canvas.width / 2;
                const angleStep = 2 * Math.PI / items.length;
                
                // Draw segments
                items.forEach((item, i) => {
                    const startAngle = i * angleStep;
                    const endAngle = (i + 1) * angleStep;
                    
                    // Draw segment
                    ctx.beginPath();
                    ctx.moveTo(0, 0);
                    ctx.arc(0, 0, radius, startAngle, endAngle);
                    ctx.fillStyle = `hsl(${i * 360 / items.length}, 85%, 65%)`;
                    ctx.fill();
                    ctx.closePath();
                    
                    // Draw segment border
                    ctx.beginPath();
                    ctx.moveTo(0, 0);
                    ctx.lineTo(radius * Math.cos(startAngle), radius * Math.sin(startAngle));
                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                    
                    // Draw text
                    ctx.save();
                    ctx.rotate(startAngle + angleStep / 2);
                    ctx.fillStyle = '#ffffff';
                    ctx.font = 'bold 16px Poppins';
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                    ctx.shadowBlur = 4;
                    
                    // Truncate text if too long
                    let displayText = item.content;
                    if (ctx.measureText(displayText).width > radius - 60) {
                        let truncated = '';
                        for (let j = 0; j < displayText.length; j++) {
                            truncated += displayText[j];
                            if (ctx.measureText(truncated + '...').width > radius - 60) {
                                truncated = truncated.slice(0, -1) + '...';
                                break;
                            }
                        }
                        displayText = truncated;
                    }
                    
                    ctx.fillText(displayText, radius - 30, 0);
                    ctx.restore();
                });
                
                // Draw inner circle
                ctx.beginPath();
                ctx.arc(0, 0, 15, 0, 2 * Math.PI);
                ctx.fillStyle = '#ffffff';
                ctx.fill();
                ctx.strokeStyle = 'rgba(0, 0, 0, 0.1)';
                ctx.lineWidth = 2;
                ctx.stroke();
                
                // Restore context
                ctx.restore();
                
                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    // Animation complete
                    isSpinning = false;
                    
                    // Show result after a short delay
                    setTimeout(() => {
                        const winner = items[winnerIndex];
                        showResult(winner.content);
                        
                        // Tự động xóa mục đã quay trúng
                        if (<?php echo $delete_after_spin ? 'true' : 'false'; ?>) {
                            const formData = new FormData();
                            formData.append('delete_item', winner);
                            
                            fetch(window.location.href, {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(() => {
                                // Cập nhật danh sách và vẽ lại vòng quay
                                items = items.filter(item => item !== winner);
                                drawWheel();
                            })
                            .catch(error => console.error('Error:', error));
                        }
                    }, 500);
                }
            }
            
            requestAnimationFrame(animate);
        }

        function showResult(content) {
            resultText.textContent = content;
            resultModal.classList.add('active');
            createConfetti();
        }

        function createConfetti() {
            const confettiCount = 100;
            const colors = ['#f94144', '#f3722c', '#f8961e', '#f9c74f', '#90be6d', '#43aa8b', '#577590', '#277da1'];
            
            for (let i = 0; i < confettiCount; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.width = Math.random() * 10 + 5 + 'px';
                confetti.style.height = confetti.style.width;
                confetti.style.animationDuration = Math.random() * 3 + 2 + 's';
                confetti.style.animationDelay = Math.random() * 2 + 's';
                
                document.body.appendChild(confetti);
                
                // Remove confetti after animation
                setTimeout(() => {
                    confetti.remove();
                }, 5000);
            }
        }

        function weightedRandom(items) {
            const total = items.reduce((sum, item) => sum + parseFloat(item.percentage), 0);
            if (total <= 0) return Math.floor(Math.random() * items.length);

            let random = Math.random() * total;
            for (let i = 0; i < items.length; i++) {
                random -= parseFloat(items[i].percentage);
                if (random <= 0) return i;
            }
            return items.length - 1;
        }

        function deleteItem(index, showUndo = false) {
            fetch('?action=delete_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `index=${index}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    items = data.items;
                    updateTextarea();
                    drawWheel();
                    showMessage(data.message);
                    if (showUndo) {
                        deletedItem = data.deleted[0];
                        deletedIndex = index;
                        showUndoToast();
                    }
                } else {
                    showError(data.error);
                }
            })
            .catch(() => showError('Lỗi kết nối, vui lòng thử lại.'));
        }

        function showUndoToast() {
            const toast = document.getElementById('undo-toast');
            document.getElementById('undo-message').textContent = `Đã xóa "${deletedItem.content}"`;
            toast.classList.remove('hidden');
            setTimeout(() => toast.classList.add('hidden'), 5000);
        }

        function undoDelete() {
            if (deletedItem && deletedIndex !== null) {
                items.splice(deletedIndex, 0, deletedItem);
                fetch('?action=update_items', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `items=${encodeURIComponent(items.map(item => item.content).join('\n'))}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        items = data.items;
                        updateTextarea();
                        drawWheel();
                        showMessage('Hoàn tác thành công!');
                        document.getElementById('undo-toast').classList.add('hidden');
                        deletedItem = null;
                        deletedIndex = null;
                    } else {
                        showError(data.error);
                    }
                })
                .catch(() => showError('Lỗi kết nối, vui lòng thử lại.'));
            }
        }

        function updateTextarea() {
            const textarea = document.getElementById('items-input');
            textarea.value = items.map(item => item.content).join('\n');
            localStorage.setItem('lucky_wheel_items', textarea.value);
        }

        function showMessage(msg) {
            const msgDiv = document.getElementById('message');
            msgDiv.textContent = msg;
            msgDiv.classList.remove('hidden');
            setTimeout(() => msgDiv.classList.add('hidden'), 3000);
        }

        function showError(err) {
            const errDiv = document.getElementById('error');
            errDiv.textContent = err;
            errDiv.classList.remove('hidden');
            setTimeout(() => errDiv.classList.add('hidden'), 3000);
        }

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Event Listeners
        document.getElementById('items-form').addEventListener('submit', e => {
            e.preventDefault();
            const textarea = document.getElementById('items-input');
            if (!textarea.value.trim()) {
                showError('Danh sách không được để trống!');
                return;
            }
            const formData = new FormData(e.target);
            fetch('?action=update_items', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    items = data.items;
                    updateTextarea();
                    drawWheel();
                    showMessage(data.message);
                } else {
                    showError(data.error);
                }
            })
            .catch(() => showError('Lỗi kết nối, vui lòng thử lại.'));
        });

        document.getElementById('items-input').addEventListener('input', debounce(() => {
            const textarea = document.getElementById('items-input');
            localStorage.setItem('lucky_wheel_items', textarea.value);
            const itemsArray = textarea.value.split('\n').filter(line => line.trim());
            items = itemsArray.map(content => ({
                content,
                percentage: itemsArray.length ? 100 / itemsArray.length : 0
            }));
            drawWheel();
        }, 300));

        document.getElementById('toggle-form').addEventListener('submit', e => {
            e.preventDefault();
            const formData = new FormData(e.target);
            fetch('?action=toggle_delete', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message);
                } else {
                    showError(data.error);
                }
            })
            .catch(() => showError('Lỗi kết nối, vui lòng thử lại.'));
        });

        document.getElementById('spin-btn').addEventListener('click', spinWheel);

        document.getElementById('export-btn').addEventListener('click', () => {
            const text = items.map(item => item.content).join('\n');
            const blob = new Blob([text], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'lucky_wheel_items.txt';
            a.click();
            URL.revokeObjectURL(url);
        });

        document.getElementById('import-input').addEventListener('change', e => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('items-input').value = e.target.result;
                    document.getElementById('items-input').dispatchEvent(new Event('input'));
                };
                reader.readAsText(file);
            }
        });

        document.getElementById('undo-btn').addEventListener('click', undoDelete);
        
        closeResult.addEventListener('click', () => {
            resultModal.classList.remove('active');
            spinButton.disabled = false;
            
            // If delete after spin is enabled, delete the item now
            if (deleteAfterSpin && lastWinnerIndex !== null) {
                deleteItem(lastWinnerIndex, true);
                lastWinnerIndex = null;
            }
        });

        // Load from localStorage
        const savedItems = localStorage.getItem('lucky_wheel_items');
        if (savedItems) {
            document.getElementById('items-input').value = savedItems;
            document.getElementById('items-input').dispatchEvent(new Event('input'));
        }

        // Responsive canvas sizing
        function resizeCanvas() {
            const container = document.querySelector('.wheel-container');
            const size = Math.min(container.clientWidth, 500);
            canvas.width = size;
            canvas.height = size;
            drawWheel();
        }

        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();
        drawWheel();
    </script>
</body>
</html>