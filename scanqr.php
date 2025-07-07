<?php
session_start();
include 'db_config.php';

// Kích hoạt ghi log lỗi
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Khởi tạo biến
$message = '';
$list_id = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;
$qr_scanned = $list_id > 0;
$registration_info = null;

// Kiểm tra và thêm cột expiry_time nếu chưa có
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM attendance_lists LIKE 'expiry_time'");
if (mysqli_num_rows($check_column) == 0) {
    $alter_table = "ALTER TABLE attendance_lists ADD expiry_time DATETIME DEFAULT NULL";
    if (!mysqli_query($conn, $alter_table)) {
        error_log("Lỗi thêm cột expiry_time: " . mysqli_error($conn));
        $message = "Lỗi khởi tạo cơ sở dữ liệu.";
    }
}

// Kiểm tra và thêm cột phone nếu chưa có
$check_phone_column = mysqli_query($conn, "SHOW COLUMNS FROM attendance_records LIKE 'phone'");
if (mysqli_num_rows($check_phone_column) == 0) {
    $alter_table = "ALTER TABLE attendance_records ADD phone VARCHAR(15) DEFAULT NULL";
    if (!mysqli_query($conn, $alter_table)) {
        error_log("Lỗi thêm cột phone: " . mysqli_error($conn));
        $message = "Lỗi khởi tạo cơ sở dữ liệu.";
    }
}

// Xử lý điểm danh sau khi quét mã QR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkin']) && isset($_POST['list_id'])) {
    try {
        // Xóa bộ đệm đầu ra
        if (ob_get_length()) {
            ob_clean();
        }

        $list_id = (int)$_POST['list_id'];
        $student_id = trim($_POST['student_id']);
        
        // Kiểm tra dữ liệu đầu vào
        if (empty($student_id)) {
            $message = "Vui lòng nhập mã số sinh viên!";
            error_log("Lỗi điểm danh: Mã số sinh viên trống (list_id: $list_id)");
        } else {
            // Kiểm tra danh sách tồn tại và thời hạn
            $sql_check_expiry = "SELECT expiry_time FROM attendance_lists WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql_check_expiry);
            if (!$stmt) {
                throw new Exception("Lỗi chuẩn bị truy vấn expiry_time: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, 'i', $list_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Lỗi thực thi truy vấn expiry_time: " . mysqli_error($conn));
            }
            $result_expiry = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result_expiry) == 0) {
                $message = "Danh sách không tồn tại!";
                error_log("Lỗi điểm danh: Danh sách không tồn tại (list_id: $list_id)");
            } else {
                $list = mysqli_fetch_assoc($result_expiry);
                if ($list['expiry_time'] && strtotime($list['expiry_time']) < time()) {
                    $message = "Mã QR đã hết hạn!";
                    error_log("Lỗi điểm danh: Mã QR hết hạn (list_id: $list_id)");
                } else {
                    // Kiểm tra mã số sinh viên
                    $student_id_escaped = mysqli_real_escape_string($conn, $student_id);
                    $sql_check_student = "SELECT id, student_id, full_name, email, major, phone 
                                        FROM attendance_records 
                                        WHERE list_id = ? AND student_id = ?";
                    $stmt = mysqli_prepare($conn, $sql_check_student);
                    if (!$stmt) {
                        throw new Exception("Lỗi chuẩn bị truy vấn mã số sinh viên: " . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt, 'is', $list_id, $student_id_escaped);
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Lỗi thực thi truy vấn mã số sinh viên: " . mysqli_error($conn));
                    }
                    $result_check_student = mysqli_stmt_get_result($stmt);
                    if (mysqli_num_rows($result_check_student) == 0) {
                        $message = "Mã số sinh viên không có trong danh sách!";
                        error_log("Lỗi điểm danh: Mã số không có trong danh sách (student_id: $student_id, list_id: $list_id)");
                    } else {
                        // Lưu thông tin đăng ký
                        $registration_info = mysqli_fetch_assoc($result_check_student);

                        // Thực hiện điểm danh
                        $sql = "UPDATE attendance_records 
                                SET status = 1, check_in_time = NOW() 
                                WHERE list_id = ? AND student_id = ? AND status = 0";
                        $stmt = mysqli_prepare($conn, $sql);
                        if (!$stmt) {
                            throw new Exception("Lỗi chuẩn bị truy vấn điểm danh: " . mysqli_error($conn));
                        }
                        mysqli_stmt_bind_param($stmt, 'is', $list_id, $student_id_escaped);
                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Lỗi thực thi truy vấn điểm danh: " . mysqli_error($conn));
                        }
                        if (mysqli_stmt_affected_rows($stmt) > 0) {
                            $message = "Đã điểm danh thành công!";
                        } else {
                            $message = "Mã số sinh viên đã được điểm danh trước đó!";
                            error_log("Lỗi điểm danh: Mã số đã điểm danh (student_id: $student_id, list_id: $list_id)");
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    } catch (Exception $e) {
        error_log("Lỗi điểm danh exception: " . $e->getMessage());
        $message = "Lỗi hệ thống: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quét mã QR điểm danh</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <style>
        :root {
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #74ebd5, #acb6e5);
            --container-bg: rgba(255, 255, 255, 0.98);
            --card-bg: #ffffff;
            --form-bg: #f8fafc;
            --text-color: #334155;
            --text-secondary: #64748b;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --border-radius: 1rem;
            --small-radius: 0.75rem;
            --button-radius: 1.5rem;
            --padding: 2.5rem;
        }

        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
        }

        .scanner-container {
            background: var(--container-bg);
            padding: var(--padding);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 600px;
            margin: 20px auto;
            text-align: center;
        }

        h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 1.5rem;
        }

        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--small-radius);
            margin: 1rem 0;
            font-weight: 500;
        }

        .error-message {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .success-message {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        #scanner {
            max-width: 100%;
            border: 2px solid var(--primary-gradient-start);
            border-radius: var(--small-radius);
            margin-bottom: 1rem;
        }

        .input {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid rgba(116, 235, 213, 0.5);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            width: 100%;
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .button {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        .registration-info {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1rem;
            margin-top: 1rem;
            text-align: left;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .registration-info p {
            margin: 0.5rem 0;
            font-size: 1rem;
        }

        .registration-info p strong {
            color: var(--text-color);
        }

        @media (max-width: 480px) {
            .scanner-container {
                padding: 1rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .input, .button {
                font-size: 0.9rem;
            }

            .registration-info p {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="scanner-container">
        <h2>Quét mã QR điểm danh</h2>
        <div class="message-container">
            <?php if ($message): ?>
                <div class="<?php echo strpos($message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$qr_scanned): ?>
            <video id="scanner" autoplay playsinline style="display: none;"></video>
            <canvas id="canvas" style="display: none;"></canvas>
            <p id="status">Đang khởi động camera...</p>
            <button id="startScanner" class="button"><i class="fas fa-camera"></i> Bật camera</button>
        <?php else: ?>
            <p id="status" class="success-message">Đã quét mã QR! Vui lòng nhập mã số sinh viên.</p>
            <form method="POST">
                <input type="hidden" name="list_id" value="<?php echo $list_id; ?>">
                <input type="text" name="student_id" placeholder="Nhập mã số sinh viên" class="input" required>
                <button type="submit" name="checkin" class="button"><i class="fas fa-check"></i> Điểm danh</button>
            </form>
            <?php if ($registration_info && strpos($message, 'thành công') !== false): ?>
                <div class="registration-info">
                    <p><strong>Mã số:</strong> <?php echo htmlspecialchars($registration_info['student_id']); ?></p>
                    <p><strong>Họ tên:</strong> <?php echo htmlspecialchars($registration_info['full_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($registration_info['email'] ?? 'N/A'); ?></p>
                    <p><strong>Ngành:</strong> <?php echo htmlspecialchars($registration_info['major'] ?? 'N/A'); ?></p>
                    <p><strong>Số điện thoại:</strong> <?php echo htmlspecialchars($registration_info['phone'] ?? 'N/A'); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        const video = document.getElementById('scanner');
        const canvasElement = document.getElementById('canvas');
        const canvas = canvasElement.getContext('2d');
        const status = document.getElementById('status');
        const startScannerBtn = document.getElementById('startScanner');

        function startScanner() {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(stream => {
                    video.srcObject = stream;
                    video.style.display = 'block';
                    canvasElement.style.display = 'block';
                    startScannerBtn.style.display = 'none';
                    status.textContent = 'Quét mã QR...';
                    tick();
                })
                .catch(err => {
                    status.textContent = 'Lỗi truy cập camera: ' + err.message;
                    status.classList.add('error-message');
                    console.error('Lỗi camera:', err);
                });
        }

        function tick() {
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvasElement.height = video.videoHeight;
                canvasElement.width = video.videoWidth;
                canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
                const imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: 'dontInvert',
                });

                if (code) {
                    try {
                        const url = new URL(code.data);
                        const listId = url.searchParams.get('list_id');
                        if (listId && url.pathname.includes('attendance.php') && url.searchParams.get('action') === 'checkin') {
                            video.srcObject.getTracks().forEach(track => track.stop());
                            window.location.href = `scanqr.php?list_id=${listId}`;
                        } else {
                            status.textContent = 'Mã QR không hợp lệ!';
                            status.classList.add('error-message');
                        }
                    } catch (e) {
                        status.textContent = 'Lỗi xử lý mã QR!';
                        status.classList.add('error-message');
                        console.error('Lỗi URL:', e);
                    }
                }
            }
            requestAnimationFrame(tick);
        }

        <?php if (!$qr_scanned): ?>
            startScannerBtn.addEventListener('click', startScanner);
        <?php endif; ?>
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>