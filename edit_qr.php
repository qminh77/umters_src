<?php
session_start();
include 'db_config.php';

require 'vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;

// Kiểm tra quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['qr_data']) || !isset($_SESSION['qr_path'])) {
    header("Location: qrcode.php");
    exit;
}

// Khởi tạo biến
$qr_message = '';
$user_id = (int)$_SESSION['user_id'];
$qr_data = $_SESSION['qr_data'];
$qr_path = $_SESSION['qr_path'];
$qr_image = $qr_path;
$default_expiry = 2592000; // 30 ngày
$expiry_config = isset($_SESSION['qr_expiry']) ? $_SESSION['qr_expiry'] : $default_expiry;

// Xử lý yêu cầu chỉnh sửa QR Code
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Tạo QR Code từ dữ liệu
        $qrCode = QrCode::create($qr_data)->setSize(300);

        // Tùy chỉnh màu sắc
        $bg_color = isset($_POST['bg_color']) ? $_POST['bg_color'] : '#ffffff';
        $fg_color = isset($_POST['fg_color']) ? $_POST['fg_color'] : '#000000';
        $bg_rgb = sscanf($bg_color, "#%02x%02x%02x");
        $fg_rgb = sscanf($fg_color, "#%02x%02x%02x");
        if ($bg_rgb && $fg_rgb) {
            $qrCode->setBackgroundColor(new Color($bg_rgb[0], $bg_rgb[1], $bg_rgb[2]));
            $qrCode->setForegroundColor(new Color($fg_rgb[0], $fg_rgb[1], $fg_rgb[2]));
        } else {
            throw new Exception("Màu sắc không hợp lệ!");
        }

        // Chèn logo nếu có
        if (isset($_FILES['qr_logo']) && $_FILES['qr_logo']['error'] == UPLOAD_ERR_OK) {
            $logo_tmp = $_FILES['qr_logo']['tmp_name'];
            $logo_ext = strtolower(pathinfo($_FILES['qr_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($logo_ext, ['png', 'jpg', 'jpeg'])) {
                $logo_path = 'downloads/logos/logo-' . time() . '.' . $logo_ext;
                if (!is_dir('logos')) {
                    mkdir('logos', 0777, true);
                }
                if (!move_uploaded_file($logo_tmp, $logo_path)) {
                    throw new Exception("Không thể di chuyển file logo!");
                }
                $logo = Logo::create($logo_path)
                    ->setResizeToWidth(100)
                    ->setPunchoutBackground(true);
                $qrCode->setLogo($logo);
            } else {
                $qr_message = "Logo chỉ hỗ trợ định dạng PNG, JPG, JPEG!";
            }
        }

        // Tùy chỉnh khung thiết kế
        $frame_style = isset($_POST['frame_style']) ? $_POST['frame_style'] : 'none';
        switch ($frame_style) {
            case 'square':
                $qrCode->setMargin(20);
                break;
            case 'rounded':
                $qrCode->setRoundBlockSizeMode(new RoundBlockSizeModeMargin());
                break;
            case 'circle':
                $qrCode->setRoundBlockSizeMode(new RoundBlockSizeModeMargin());
                $qrCode->setMargin(30);
                break;
            case 'none':
            default:
                $qrCode->setMargin(10);
                break;
        }

        // Lưu QR Code vào file
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        if (!is_writable(dirname($qr_path))) {
            throw new Exception("Thư mục qrcodes không có quyền ghi!");
        }
        $result->saveToFile($qr_path);
        $qr_image = $qr_path;

        // Nếu nhấn "Lưu QR Code", thêm vào lịch sử
        if (isset($_POST['update_qr'])) {
            $qr_type = 'custom';
            $qr_type_escaped = mysqli_real_escape_string($conn, $qr_type);
            $qr_data_escaped = mysqli_real_escape_string($conn, $qr_data);
            $qr_path_escaped = mysqli_real_escape_string($conn, $qr_path);
            $expiry_time = date('Y-m-d H:i:s', time() + $expiry_config);
            $sql = "INSERT INTO qr_codes (user_id, qr_type, qr_data, qr_path, expiry_time) VALUES ($user_id, '$qr_type_escaped', '$qr_data_escaped', '$qr_path_escaped', '$expiry_time')";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception("Lỗi khi lưu vào cơ sở dữ liệu: " . mysqli_error($conn));
            }
            $qr_message = "Cập nhật và lưu QR Code thành công!";
        }
    } catch (Exception $e) {
        $qr_message = "Lỗi khi xử lý QR Code: " . $e->getMessage();
        error_log("QR Error: " . $e->getMessage() . " | File: " . __FILE__ . " | Line: " . __LINE__);
        http_response_code(500); // Trả về lỗi 500 để AJAX nhận biết
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chỉnh sửa QR Code</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .edit-form {
            margin-top: 20px;
        }
        .edit-form select, .edit-form input {
            width: 100%;
            max-width: 300px;
            margin-bottom: 10px;
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: var(--small-radius);
            background: var(--card-bg);
        }
        .qr-result {
            margin-top: 20px;
            text-align: center;
        }
        .design-options {
            margin-top: 20px;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--small-radius);
            background: var(--card-bg);
        }
        .design-options h3 {
            margin-bottom: 10px;
            font-size: 1.2rem;
        }
        .design-options label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .design-options input[type="color"] {
            height: 40px;
            cursor: pointer;
        }
    </style>
    <script>
        function updateQrPreview() {
            const form = document.getElementById('edit_qr_form');
            const formData = new FormData(form);
            fetch('edit_qr.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server responded with status: ' + response.status);
                }
                return response.text();
            })
            .then(data => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(data, 'text/html');
                const newQrImage = doc.querySelector('.qr-image')?.src;
                const errorMessage = doc.querySelector('.error-message')?.textContent;
                if (newQrImage) {
                    document.getElementById('qr_preview').src = newQrImage + '?t=' + new Date().getTime();
                }
                if (errorMessage) {
                    console.error('Error from server:', errorMessage);
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('Có lỗi xảy ra khi cập nhật QR Code: ' + error.message);
            });
        }

        function saveQrDesign() {
            const form = document.getElementById('edit_qr_form');
            const formData = new FormData(form);
            formData.append('update_qr', '1'); // Thêm flag để lưu
            fetch('edit_qr.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Server responded with status: ' + response.status);
                }
                return response.text();
            })
            .then(data => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(data, 'text/html');
                const successMessage = doc.querySelector('.success-message')?.textContent;
                const errorMessage = doc.querySelector('.error-message')?.textContent;
                if (successMessage) {
                    alert(successMessage);
                } else if (errorMessage) {
                    alert('Lỗi khi lưu: ' + errorMessage);
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                alert('Có lỗi xảy ra khi lưu QR Code: ' + error.message);
            });
        }
    </script>
</head>
<body>
<div class="dashboard-container">
    <h2>Chỉnh sửa QR Code</h2>
            <?php include 'taskbar.php'; ?>

    <a href="qrcode.php" class="logout-btn" style="display: inline-block; margin-bottom: 20px;">
        <i class="fas fa-arrow-left"></i> Quay lại Tạo QR
    </a>

    <?php if ($qr_message): ?>
        <p class="<?php echo strpos($qr_message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>"><?php echo $qr_message; ?></p>
    <?php endif; ?>

    <div class="edit-form">
        <form id="edit_qr_form" method="POST" enctype="multipart/form-data">
            <div class="design-options">
                <h3>Tùy chỉnh thiết kế</h3>
                <label>Màu nền QR Code:</label>
                <input type="color" name="bg_color" value="#ffffff" class="qr-input" style="width: 100px;" oninput="updateQrPreview()">
                <label>Màu mã QR Code:</label>
                <input type="color" name="fg_color" value="#000000" class="qr-input" style="width: 100px;" oninput="updateQrPreview()">
                <label>Chèn Logo/Brand:</label>
                <input type="file" name="qr_logo" accept=".png,.jpg,.jpeg" class="qr-input" onchange="updateQrPreview()">
                <label>Chọn khung thiết kế:</label>
                <select name="frame_style" class="qr-select" onchange="updateQrPreview()">
                    <option value="none">Không khung</option>
                    <option value="square">Khung vuông</option>
                    <option value="rounded">Khung bo góc</option>
                    <option value="circle">Khung tròn</option>
                </select>
            </div>
            <button type="button" onclick="saveQrDesign()" class="qr-button">Lưu QR Code</button>
        </form>
    </div>

    <div class="qr-result">
        <img id="qr_preview" src="<?php echo $qr_image; ?>" alt="QR Code" class="qr-image">
        <br>
        <a href="<?php echo $qr_image; ?>" download class="qr-button">Tải xuống QR Code</a>
    </div>
</div>
</body>
</html>
<?php mysqli_close($conn); ?>