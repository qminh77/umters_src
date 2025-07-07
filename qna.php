<?php
session_start();
include 'db_config.php';

require 'vendor/autoload.php';
require 'vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php';
require 'vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Khởi tạo biến
$user_id = (int)$_SESSION['user_id'];
$message = '';
$qr_image = '';
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

// Lấy thông tin user
$sql_user = "SELECT username, is_main_admin, is_super_admin FROM users WHERE id = $user_id";
$result_user = mysqli_query($conn, $sql_user);
$user = mysqli_fetch_assoc($result_user) ?: ['username' => 'Unknown', 'is_main_admin' => 0, 'is_super_admin' => 0];

// Tạo bảng qna_rooms nếu chưa có
$sql_qna_rooms = "CREATE TABLE IF NOT EXISTS qna_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    room_type ENUM('register', 'rating', 'question') NOT NULL,
    qr_path VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_qna_rooms) or die("Error creating qna_rooms: " . mysqli_error($conn));

// Tạo bảng qna_submissions nếu chưa có
$sql_qna_submissions = "CREATE TABLE IF NOT EXISTS qna_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(15) DEFAULT NULL,
    content TEXT NOT NULL,
    rating INT DEFAULT NULL,
    status ENUM('unanswered', 'pending', 'answered', 'rejected') DEFAULT 'unanswered',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES qna_rooms(id)
)";
mysqli_query($conn, $sql_qna_submissions) or die("Error creating qna_submissions: " . mysqli_error($conn));

// Tự động lấy tên miền
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

// Xử lý tạo phòng
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_room'])) {
    $room_name = trim($_POST['room_name']);
    $room_type = trim($_POST['room_type']);
    if ($room_name && in_array($room_type, ['register', 'rating', 'question'])) {
        $room_name_escaped = mysqli_real_escape_string($conn, $room_name);
        $sql = "INSERT INTO qna_rooms (user_id, room_name, room_type, is_active) VALUES ($user_id, '$room_name_escaped', '$room_type', 1)";
        if (mysqli_query($conn, $sql)) {
            $new_room_id = mysqli_insert_id($conn);
            $qr_data = $base_url . '/qna_submit?room_id=' . $new_room_id;
            $qrCode = QrCode::create($qr_data)->setSize(300)->setMargin(10);
            $writer = new PngWriter();
            $result = $writer->write($qrCode);
            $qr_path = 'qrcodes/qna-' . time() . '.png';
            if (!is_dir('qrcodes')) {
                mkdir('qrcodes', 0777, true);
            }
            $result->saveToFile($qr_path);
            $qr_path_escaped = mysqli_real_escape_string($conn, $qr_path);
            mysqli_query($conn, "UPDATE qna_rooms SET qr_path = '$qr_path_escaped' WHERE id = $new_room_id");
            $message = "Tạo phòng Q&A thành công!";
            $qr_image = $qr_path;
        } else {
            $message = "Lỗi khi tạo phòng: " . mysqli_error($conn);
        }
    } else {
        $message = "Vui lòng nhập tên phòng và chọn loại phòng hợp lệ!";
    }
}

// Xử lý sửa phòng
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_room'])) {
    $room_id = (int)$_POST['room_id'];
    $room_name = trim($_POST['room_name']);
    $room_type = trim($_POST['room_type']);
    if ($room_name && in_array($room_type, ['register', 'rating', 'question'])) {
        $room_name_escaped = mysqli_real_escape_string($conn, $room_name);
        $sql = "UPDATE qna_rooms SET room_name = '$room_name_escaped', room_type = '$room_type' WHERE id = $room_id AND user_id = $user_id";
        if (mysqli_query($conn, $sql)) {
            $message = "Cập nhật phòng thành công!";
        } else {
            $message = "Lỗi khi cập nhật phòng: " . mysqli_error($conn);
        }
    } else {
        $message = "Vui lòng nhập tên phòng và chọn loại phòng hợp lệ!";
    }
}

// Xử lý xóa phòng
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_room'])) {
    $room_id = (int)$_POST['room_id'];
    $sql = "SELECT qr_path FROM qna_rooms WHERE id = $room_id AND user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $room = mysqli_fetch_assoc($result);
        if (file_exists($room['qr_path'])) {
            unlink($room['qr_path']);
        }
        mysqli_query($conn, "DELETE FROM qna_submissions WHERE room_id = $room_id");
        mysqli_query($conn, "DELETE FROM qna_rooms WHERE id = $room_id AND user_id = $user_id");
        $message = "Xóa phòng thành công!";
    } else {
        $message = "Phòng không tồn tại hoặc bạn không có quyền xóa!";
    }
}

// Xử lý bật/tắt Q&A
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_qna'])) {
    $room_id = (int)$_POST['room_id'];
    $is_active = (int)$_POST['is_active'];
    $sql = "UPDATE qna_rooms SET is_active = $is_active WHERE id = $room_id AND user_id = $user_id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true, 'is_active' => $is_active]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Xử lý cập nhật trạng thái câu hỏi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['status'])) {
    $submission_id = (int)$_POST['submission_id'];
    $status = trim($_POST['status']);
    if (in_array($status, ['unanswered', 'pending', 'answered', 'rejected'])) {
        $sql = "UPDATE qna_submissions SET status = '$status' WHERE id = $submission_id AND room_id IN (SELECT id FROM qna_rooms WHERE user_id = $user_id)";
        if (mysqli_query($conn, $sql)) {
            $message = "Cập nhật trạng thái thành công!";
        } else {
            $message = "Lỗi khi cập nhật trạng thái: " . mysqli_error($conn);
        }
    } else {
        $message = "Trạng thái không hợp lệ!";
    }
}

// Xử lý xuất Excel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_excel'])) {
    $room_id = (int)$_POST['room_id'];
    $sql = "SELECT s.*, r.room_name, r.room_type FROM qna_submissions s JOIN qna_rooms r ON s.room_id = r.id WHERE s.room_id = $room_id AND r.user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('QNA Submissions');
        $headers = ['ID', 'Room Name', 'Room Type', 'Full Name', 'Email', 'Phone', 'Content', 'Rating', 'Status', 'Submitted At'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        $row = 2;
        while ($submission = mysqli_fetch_assoc($result)) {
            $sheet->setCellValue('A' . $row, $submission['id']);
            $sheet->setCellValue('B' . $row, $submission['room_name']);
            $sheet->setCellValue('C' . $row, ucfirst($submission['room_type']));
            $sheet->setCellValue('D' . $row, $submission['full_name']);
            $sheet->setCellValue('E' . $row, $submission['email']);
            $sheet->setCellValue('F' . $row, $submission['phone']);
            $sheet->setCellValue('G' . $row, $submission['content']);
            $sheet->setCellValue('H' . $row, $submission['rating']);
            $sheet->setCellValue('I' . $row, ucfirst($submission['status']));
            $sheet->setCellValue('J' . $row, $submission['submitted_at']);
            $row++;
        }
        $writer = new Xlsx($spreadsheet);
        $filename = 'qna_room_' . $room_id . '_' . time() . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    } else {
        $message = "Không có dữ liệu để xuất!";
    }
}

// Lấy danh sách phòng
$rooms_per_page = 10;
$page_rooms = isset($_GET['page_rooms']) ? (int)$_GET['page_rooms'] : 1;
if ($page_rooms < 1) $page_rooms = 1;
$offset_rooms = ($page_rooms - 1) * $rooms_per_page;

$sql_total_rooms = "SELECT COUNT(*) as total FROM qna_rooms WHERE user_id = $user_id";
$total_result_rooms = mysqli_query($conn, $sql_total_rooms);
$total_rooms = mysqli_fetch_assoc($total_result_rooms)['total'];
$total_pages_rooms = ceil($total_rooms / $rooms_per_page);

$sql_rooms = "SELECT * FROM qna_rooms WHERE user_id = $user_id ORDER BY created_at DESC LIMIT $offset_rooms, $rooms_per_page";
$result_rooms = mysqli_query($conn, $sql_rooms);
$rooms = mysqli_fetch_all($result_rooms, MYSQLI_ASSOC);

// Lấy thông tin phòng nếu đang xem phòng riêng
$room = null;
if ($room_id) {
    $sql = "SELECT * FROM qna_rooms WHERE id = $room_id AND user_id = $user_id";
    $result = mysqli_query($conn, $sql);
    $room = mysqli_fetch_assoc($result);
}

// Lấy số lượng câu hỏi theo trạng thái
$status_counts = ['all' => 0, 'unanswered' => 0, 'pending' => 0, 'answered' => 0, 'rejected' => 0];
if ($room_id) {
    $sql = "SELECT status, COUNT(*) as count FROM qna_submissions WHERE room_id = $room_id GROUP BY status";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $status_counts[$row['status']] = $row['count'];
    }
    $sql = "SELECT COUNT(*) as count FROM qna_submissions WHERE room_id = $room_id";
    $result = mysqli_query($conn, $sql);
    $status_counts['all'] = mysqli_fetch_assoc($result)['count'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hỏi & Đáp</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <link rel="stylesheet" href="style.css">

    <style>
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            50% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
        }
        .slide-in { animation: slideIn 0.5s ease-out; }
        .fade-out { animation: fadeOut 0.5s ease-out forwards; }
        .pulse { animation: pulse 1.5s ease-out; }
        .hover-glow { transition: all 0.3s ease; }
        .hover-glow:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15); }
        .qr-card { backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.1); }
        .status-unanswered { border-left: 4px solid #3b82f6; }
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-answered { border-left: 4px solid #22c55e; }
        .status-rejected { border-left: 4px solid #ef4444; }
        .ripple { position: relative; overflow: hidden; }
        .ripple::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 0; height: 0;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .ripple:hover::after {
            width: 200px; height: 200px;
        }
        .tab-active { background: #14b8a6; color: white; border-bottom: 3px solid #0d9488; }
        .tab-hover:hover { background: #e0f2fe; }
        .content-box { max-height: 150px; overflow-y: auto; white-space: pre-wrap; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-teal-50 via-blue-100 to-purple-100 font-inter flex items-center justify-center p-6">
    <div class="w-full max-w-7xl bg-white/90 rounded-3xl shadow-2xl p-8 md:p-12 transition-all duration-300">
        <?php if ($room_id && $room): ?>
            <!-- Giao diện phòng riêng -->
            <div class="relative">
                <div class="fixed top-6 right-6 z-10 qr-card rounded-2xl p-4 shadow-lg">
                    <img src="<?php echo htmlspecialchars($room['qr_path']); ?>" alt="QR Code" class="w-40 h-40 rounded-lg hover-glow">
                    <a href="<?php echo htmlspecialchars($room['qr_path']); ?>" download class="block mt-3 text-center px-4 py-2 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                        <i class="fas fa-download mr-2"></i> Tải QR
                    </a>
                </div>
                <h2 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($room['room_name']); ?></h2>
                <div class="flex items-center gap-4 mb-8 flex-wrap">
                    <a href="qna" class="inline-flex items-center px-5 py-2 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                        <i class="fas fa-arrow-left mr-2"></i> Quay lại
                    </a>
                    <button id="toggle-refresh" class="inline-flex items-center px-5 py-2 bg-blue-500 text-white rounded-full hover:bg-blue-600 ripple transition-all">
                        <i class="fas fa-sync-alt mr-2"></i> Tắt Auto-Refresh
                    </button>
                    <button id="manual-refresh" class="inline-flex items-center px-5 py-2 bg-gray-500 text-white rounded-full hover:bg-gray-600 ripple transition-all">
                        <i class="fas fa-redo mr-2"></i> Làm mới
                    </button>
                    <button id="toggle-sound" class="inline-flex items-center px-5 py-2 bg-purple-500 text-white rounded-full hover:bg-purple-600 ripple transition-all">
                        <i class="fas fa-volume-up mr-2"></i> Tắt Âm thanh
                    </button>
                    <button id="toggle-qna-<?php echo $room_id; ?>" class="toggle-qna inline-flex items-center px-5 py-2 <?php echo $room['is_active'] ? 'bg-green-500' : 'bg-red-500'; ?> text-white rounded-full hover:bg-<?php echo $room['is_active'] ? 'green' : 'red'; ?>-600 ripple transition-all" data-room-id="<?php echo $room_id; ?>" data-is-active="<?php echo $room['is_active']; ?>">
                        <i class="fas fa-<?php echo $room['is_active'] ? 'power-off' : 'ban'; ?> mr-2"></i> <?php echo $room['is_active'] ? 'Tắt Q&A' : 'Bật Q&A'; ?>
                    </button>
                </div>
                <div id="message-container" class="mb-8">
                    <?php if ($message): ?>
                        <div class="p-4 rounded-xl <?php echo strpos($message, 'thành công') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> transition-all duration-500">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="bg-gray-50/50 p-8 rounded-2xl shadow-inner">
                    <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-question-circle mr-3 text-teal-500"></i> Câu hỏi / Đánh giá
                    </h3>
                    <div class="flex gap-4 mb-6 flex-wrap overflow-x-auto pb-2">
                        <button class="tab-filter px-6 py-3 rounded-xl bg-gray-100 text-gray-700 font-medium tab-hover transition-all" data-status="all">Tất cả <span class="ml-2 px-2 py-1 bg-teal-100 text-teal-700 rounded-full" id="count-all"><?php echo $status_counts['all']; ?></span></button>
                        <button class="tab-filter px-6 py-3 rounded-xl bg-gray-100 text-gray-700 font-medium tab-hover transition-all" data-status="unanswered">Chưa trả lời <span class="ml-2 px-2 py-1 bg-blue-100 text-blue-700 rounded-full" id="count-unanswered"><?php echo $status_counts['unanswered']; ?></span></button>
                        <button class="tab-filter px-6 py-3 rounded-xl bg-gray-100 text-gray-700 font-medium tab-hover transition-all" data-status="pending">Để sau <span class="ml-2 px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full" id="count-pending"><?php echo $status_counts['pending']; ?></span></button>
                        <button class="tab-filter px-6 py-3 rounded-xl bg-gray-100 text-gray-700 font-medium tab-hover transition-all" data-status="answered">Đã trả lời <span class="ml-2 px-2 py-1 bg-green-100 text-green-700 rounded-full" id="count-answered"><?php echo $status_counts['answered']; ?></span></button>
                        <button class="tab-filter px-6 py-3 rounded-xl bg-gray-100 text-gray-700 font-medium tab-hover transition-all" data-status="rejected">Từ chối <span class="ml-2 px-2 py-1 bg-red-100 text-red-700 rounded-full" id="count-rejected"><?php echo $status_counts['rejected']; ?></span></button>
                    </div>
                    <div id="submissions-list" class="space-y-6">
                        <!-- Danh sách câu hỏi sẽ được cập nhật qua AJAX -->
                    </div>
                    <form method="POST" class="mt-8">
                        <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">
                        <button type="submit" name="export_excel" class="inline-flex items-center px-6 py-3 bg-green-500 text-white rounded-full hover:bg-green-600 ripple transition-all">
                            <i class="fas fa-file-excel mr-2"></i> Xuất Excel
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Giao diện danh sách phòng -->
            <h2 class="text-4xl font-bold text-gray-900 text-center mb-10">Hỏi & Đáp</h2>
            <?php include 'taskbar'; ?>
            <div id="message-container" class="mb-8">
                <?php if ($message): ?>
                    <div class="p-4 rounded-xl <?php echo strpos($message, 'thành công') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> transition-all duration-500">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="grid md:grid-cols-2 gap-8 mb-10">
                <!-- Form tạo phòng -->
                <div class="bg-white/80 p-8 rounded-2xl shadow-lg hover-glow">
                    <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-plus-circle mr-3 text-teal-500"></i> Tạo phòng Q&A mới
                    </h3>
                    <form method="POST" class="space-y-5">
                        <input type="text" name="room_name" placeholder="Tên phòng" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                        <select name="room_type" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                            <option value="">Chọn loại phòng</option>
                            <option value="register">Đăng ký</option>
                            <option value="rating">Đánh giá chất lượng</option>
                            <option value="question">Câu hỏi tự do</option>
                        </select>
                        <button type="submit" name="create_room" class="w-full inline-flex items-center justify-center px-6 py-3 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                            <i class="fas fa-plus mr-2"></i> Tạo phòng
                        </button>
                    </form>
                </div>
                <!-- Hiển thị QR Code -->
                <?php if ($qr_image): ?>
                    <div class="bg-white/80 p-8 rounded-2xl shadow-lg hover-glow text-center">
                        <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center justify-center">
                            <i class="fas fa-qrcode mr-3 text-teal-500"></i> Mã QR phòng mới
                        </h3>
                        <img src="<?php echo $qr_image; ?>" alt="QR Code" class="w-56 h-56 mx-auto rounded-xl shadow-md hover-glow">
                        <a href="<?php echo $qr_image; ?>" download class="inline-flex items-center px-6 py-3 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all mt-6">
                            <i class="fas fa-download mr-2"></i> Tải xuống QR
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Danh sách phòng -->
            <div class="bg-white/80 p-8 rounded-2xl shadow-lg">
                <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-list mr-3 text-teal-500"></i> Danh sách phòng Q&A
                </h3>
                <?php if (empty($rooms)): ?>
                    <div class="text-center p-10 text-gray-500">
                        <i class="fas fa-list-alt text-6xl mb-4 opacity-50"></i>
                        <p class="text-lg">Chưa có phòng Q&A nào. Hãy tạo phòng đầu tiên!</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($rooms as $room): ?>
                            <div class="bg-white rounded-2xl shadow-md overflow-hidden hover-glow">
                                <img src="<?php echo htmlspecialchars($room['qr_path']); ?>" alt="QR Code" class="w-40 h-40 mx-auto mt-6 rounded-xl">
                                <div class="p-6">
                                    <h4 class="text-xl font-semibold text-gray-800 flex items-center">
                                        <i class="fas fa-room mr-2 text-teal-500"></i> <?php echo htmlspecialchars($room['room_name']); ?>
                                    </h4>
                                    <p class="text-gray-600 mt-2">Loại: <?php echo ucfirst(htmlspecialchars($room['room_type'])); ?></p>
                                    <p class="text-gray-600">Tạo: <?php echo date('d/m/Y H:i', strtotime($room['created_at'])); ?></p>
                                    <p class="text-gray-600">Trạng thái: <?php echo $room['is_active'] ? '<span class="text-green-600">Bật</span>' : '<span class="text-red-600">Tắt</span>'; ?></p>
                                    <div class="flex gap-3 mt-4 flex-wrap">
                                        <a href="qna?room_id=<?php echo $room['id']; ?>" class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-blue-500 text-white rounded-xl hover:bg-blue-600 ripple transition-all">
                                            <i class="fas fa-eye mr-2"></i> Xem
                                        </a>
                                        <form method="POST" class="flex-1">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" name="delete_room" class="w-full inline-flex items-center justify-center px-4 py-2 bg-red-500 text-white rounded-xl hover:bg-red-600 ripple transition-all" onclick="return confirm('Bạn có chắc muốn xóa phòng này?');">
                                                <i class="fas fa-trash-alt mr-2"></i> Xóa
                                            </button>
                                        </form>
                                        <form method="POST" class="flex-1">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" name="export_excel" class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-500 text-white rounded-xl hover:bg-green-600 ripple transition-all">
                                                <i class="fas fa-file-excel mr-2"></i> Xuất
                                            </button>
                                        </form>
                                        <button class="toggle-qna flex-1 inline-flex items-center justify-center px-4 py-2 <?php echo $room['is_active'] ? 'bg-green-500' : 'bg-red-500'; ?> text-white rounded-xl hover:bg-<?php echo $room['is_active'] ? 'green' : 'red'; ?>-600 ripple transition-all" data-room-id="<?php echo $room['id']; ?>" data-is-active="<?php echo $room['is_active']; ?>">
                                            <i class="fas fa-<?php echo $room['is_active'] ? 'power-off' : 'ban'; ?> mr-2"></i> <?php echo $room['is_active'] ? 'Tắt Q&A' : 'Bật Q&A'; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Phân trang -->
                    <?php if ($total_pages_rooms > 1): ?>
                        <div class="flex justify-center gap-6 mt-10">
                            <?php if ($page_rooms > 1): ?>
                                <a href="qna?page_rooms=<?php echo $page_rooms - 1; ?>" class="inline-flex items-center px-6 py-3 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                                    <i class="fas fa-chevron-left mr-2"></i> Trang trước
                                </a>
                            <?php endif; ?>
                            <span class="text-gray-600 text-lg">Trang <?php echo $page_rooms; ?> / <?php echo $total_pages_rooms; ?></span>
                            <?php if ($page_rooms < $total_pages_rooms): ?>
                                <a href="qna?page_rooms=<?php echo $page_rooms + 1; ?>" class="inline-flex items-center px-6 py-3 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                                    Trang sau <i class="fas fa-chevron-right ml-2"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($room_id): ?>
        <script>
            let lastSubmissionId = 0;
            let autoRefresh = true;
            let soundEnabled = true;
            let currentStatusFilter = 'unanswered';
            let submissionsCache = new Map();

            function updateStatusCounts() {
                fetch('fetch_status_counts?room_id=<?php echo $room_id; ?>')
                    .then(response => response.json())
                    .then(counts => {
                        document.getElementById('count-all').textContent = counts.all || 0;
                        document.getElementById('count-unanswered').textContent = counts.unanswered || 0;
                        document.getElementById('count-pending').textContent = counts.pending || 0;
                        document.getElementById('count-answered').textContent = counts.answered || 0;
                        document.getElementById('count-rejected').textContent = counts.rejected || 0;
                    })
                    .catch(error => console.error('Error updating counts:', error));
            }

            function loadSubmissions() {
                if (!autoRefresh && currentStatusFilter !== 'unanswered' && currentStatusFilter !== 'pending' && currentStatusFilter !== 'all') return;
                
                const url = `fetch_submissions.php?room_id=<?php echo $room_id; ?>${currentStatusFilter !== 'all' ? '&status=' + currentStatusFilter : ''}`;
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        const submissionsList = document.getElementById('submissions-list');
                        let newSubmissions = data.filter(submission => !submissionsCache.has(submission.id));

                        if (newSubmissions.length > 0 && soundEnabled) {
                            new Audio('assets/notify.mp3').play();
                        }

                        newSubmissions.forEach(submission => {
                            submissionsCache.set(submission.id, submission);
                            const card = document.createElement('div');
                            card.className = `slide-in bg-white p-6 rounded-2xl shadow-md hover-glow status-${submission.status} pulse`;
                            card.dataset.id = submission.id;
                            card.innerHTML = `
                                <div class="flex items-center justify-between mb-3">
                                    <div class="content-box text-gray-800 text-lg">${submission.content}</div>
                                    <span class="px-3 py-1 rounded-full text-sm font-medium ${
                                        submission.status === 'unanswered' ? 'bg-blue-100 text-blue-700' :
                                        submission.status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                                        submission.status === 'answered' ? 'bg-green-100 text-green-700' :
                                        'bg-red-100 text-red-700'
                                    }">${submission.status === 'unanswered' ? 'Chưa trả lời' : submission.status === 'pending' ? 'Để sau' : submission.status === 'answered' ? 'Đã trả lời' : 'Từ chối'}</span>
                                </div>
                                <div class="text-gray-500 text-sm mt-2">
                                    ${submission.full_name ? `<span class="block"><strong>Họ tên:</strong> ${submission.full_name}</span>` : ''}
                                    ${submission.email ? `<span class="block"><strong>Email:</strong> ${submission.email}</span>` : ''}
                                    ${submission.phone ? `<span class="block"><strong>SĐT:</strong> ${submission.phone}</span>` : ''}
                                    ${submission.rating ? `<span class="block"><strong>Đánh giá:</strong> ${submission.rating}/5</span>` : ''}
                                    <span class="block"><strong>Thời gian:</strong> ${submission.submitted_at}</span>
                                </div>
                                <div class="flex gap-3 mt-4 flex-wrap">
                                    <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                        <input type="hidden" name="submission_id" value="${submission.id}">
                                        <button type="submit" name="status" value="answered" class="w-full px-4 py-2 bg-green-500 text-white rounded-xl hover:bg-green-600 ripple transition-all">Đã trả lời</button>
                                    </form>
                                    <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                        <input type="hidden" name="submission_id" value="${submission.id}">
                                        <button type="submit" name="status" value="rejected" class="w-full px-4 py-2 bg-red-500 text-white rounded-xl hover:bg-red-600 ripple transition-all">Từ chối</button>
                                    </form>
                                    <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                        <input type="hidden" name="submission_id" value="${submission.id}">
                                        <button type="submit" name="status" value="pending" class="w-full px-4 py-2 bg-yellow-500 text-white rounded-xl hover:bg-yellow-600 ripple transition-all">Để sau</button>
                                    </form>
                                    <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                        <input type="hidden" name="submission_id" value="${submission.id}">
                                        <button type="submit" name="status" value="unanswered" class="w-full px-4 py-2 bg-blue-500 text-white rounded-xl hover:bg-blue-600 ripple transition-all">Chưa trả lời</button>
                                    </form>
                                </div>
                            `;
                            submissionsList.insertBefore(card, submissionsList.firstChild);
                            lastSubmissionId = Math.max(lastSubmissionId, submission.id);
                            setTimeout(() => card.classList.remove('pulse'), 1500);
                        });

                        // Lọc lại danh sách hiển thị theo trạng thái
                        Array.from(submissionsList.children).forEach(card => {
                            const submissionId = parseInt(card.dataset.id);
                            const submission = submissionsCache.get(submissionId);
                            if (submission && currentStatusFilter !== 'all' && submission.status !== currentStatusFilter) {
                                card.remove();
                            }
                        });

                        // Render toàn bộ danh sách khi chuyển tab
                        if (newSubmissions.length === 0) {
                            submissionsList.innerHTML = '';
                            data.forEach(submission => {
                                if (!submissionsCache.has(submission.id)) {
                                    submissionsCache.set(submission.id, submission);
                                }
                                const card = document.createElement('div');
                                card.className = `bg-white p-6 rounded-2xl shadow-md hover-glow status-${submission.status}`;
                                card.dataset.id = submission.id;
                                card.innerHTML = `
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="content-box text-gray-800 text-lg">${submission.content}</div>
                                        <span class="px-3 py-1 rounded-full text-sm font-medium ${
                                            submission.status === 'unanswered' ? 'bg-blue-100 text-blue-700' :
                                            submission.status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                                            submission.status === 'answered' ? 'bg-green-100 text-green-700' :
                                            'bg-red-100 text-red-700'
                                        }">${submission.status === 'unanswered' ? 'Chưa trả lời' : submission.status === 'pending' ? 'Để sau' : submission.status === 'answered' ? 'Đã trả lời' : 'Từ chối'}</span>
                                    </div>
                                    <div class="text-gray-500 text-sm mt-2">
                                        ${submission.full_name ? `<span class="block"><strong>Họ tên:</strong> ${submission.full_name}</span>` : ''}
                                        ${submission.email ? `<span class="block"><strong>Email:</strong> ${submission.email}</span>` : ''}
                                        ${submission.phone ? `<span class="block"><strong>SĐT:</strong> ${submission.phone}</span>` : ''}
                                        ${submission.rating ? `<span class="block"><strong>Đánh giá:</strong> ${submission.rating}/5</span>` : ''}
                                        <span class="block"><strong>Thời gian:</strong> ${submission.submitted_at}</span>
                                    </div>
                                    <div class="flex gap-3 mt-4 flex-wrap">
                                        <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                            <input type="hidden" name="submission_id" value="${submission.id}">
                                            <button type="submit" name="status" value="answered" class="w-full px-4 py-2 bg-green-500 text-white rounded-xl hover:bg-green-600 ripple transition-all">Đã trả lời</button>
                                        </form>
                                        <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                            <input type="hidden" name="submission_id" value="${submission.id}">
                                            <button type="submit" name="status" value="rejected" class="w-full px-4 py-2 bg-red-500 text-white rounded-xl hover:bg-red-600 ripple transition-all">Từ chối</button>
                                        </form>
                                        <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                            <input type="hidden" name="submission_id" value="${submission.id}">
                                            <button type="submit" name="status" value="pending" class="w-full px-4 py-2 bg-yellow-500 text-white rounded-xl hover:bg-yellow-600 ripple transition-all">Để sau</button>
                                        </form>
                                        <form method="POST" action="" class="flex-1 min-w-[120px] status-form">
                                            <input type="hidden" name="submission_id" value="${submission.id}">
                                            <button type="submit" name="status" value="unanswered" class="w-full px-4 py-2 bg-blue-500 text-white rounded-xl hover:bg-blue-600 ripple transition-all">Chưa trả lời</button>
                                        </form>
                                    </div>
                                `;
                                submissionsList.appendChild(card);
                            });
                        }

                        if (submissionsCache.size === 0) {
                            submissionsList.innerHTML = `
                                <div class="text-center p-10 text-gray-500">
                                    <i class="fas fa-question text-6xl mb-4 opacity-50"></i>
                                    <p class="text-lg">Chưa có câu hỏi hoặc đánh giá nào.</p>
                                </div>`;
                        }

                        updateStatusCounts();
                    })
                    .catch(error => console.error('Error:', error));
            }

            // Xử lý toggle bật/tắt Q&A
            document.querySelectorAll('.toggle-qna').forEach(button => {
                button.addEventListener('click', () => {
                    const roomId = button.dataset.roomId;
                    const isActive = button.dataset.isActive === '1' ? 0 : 1;
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `toggle_qna=1&room_id=${roomId}&is_active=${isActive}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            button.dataset.isActive = isActive;
                            button.className = `toggle-qna flex-1 inline-flex items-center justify-center px-4 py-2 ${isActive ? 'bg-green-500 hover:bg-green-600' : 'bg-red-500 hover:bg-red-600'} text-white rounded-xl ripple transition-all`;
                            button.innerHTML = `<i class="fas fa-${isActive ? 'power-off' : 'ban'} mr-2"></i> ${isActive ? 'Tắt Q&A' : 'Bật Q&A'}`;
                        } else {
                            console.error('Error toggling Q&A:', data.error);
                        }
                    })
                    .catch(error => console.error('Error:', error));
                });
            });

            // Xử lý filter trạng thái
            document.querySelectorAll('.tab-filter').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.tab-filter').forEach(t => t.classList.remove('tab-active'));
                    tab.classList.add('tab-active');
                    currentStatusFilter = tab.dataset.status;
                    lastSubmissionId = 0;
                    submissionsCache.clear();
                    document.getElementById('submissions-list').innerHTML = '';
                    loadSubmissions();
                });
            });

            // Xử lý submit trạng thái và ẩn câu hỏi
            document.addEventListener('submit', (e) => {
                if (e.target.classList.contains('status-form')) {
                    e.preventDefault();
                    const form = e.target;
                    const submissionId = form.querySelector('input[name="submission_id"]').value;
                    const status = form.querySelector('button[type="submit"]').value;
                    const card = document.querySelector(`[data-id="${submissionId}"]`);

                    const formData = new FormData();
                    formData.append('submission_id', submissionId);
                    formData.append('status', status);
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(() => {
                        const submission = submissionsCache.get(parseInt(submissionId));
                        if (submission) {
                            submission.status = status;
                            submissionsCache.set(parseInt(submissionId), submission);
                        }

                        if ((currentStatusFilter === 'unanswered' || currentStatusFilter === 'pending') && (status === 'answered' || status === 'rejected')) {
                            card.classList.add('fade-out');
                            setTimeout(() => card.remove(), 500);
                        } else {
                            const badge = card.querySelector('span.rounded-full');
                            badge.textContent = status === 'unanswered' ? 'Chưa trả lời' : status === 'pending' ? 'Để sau' : status === 'answered' ? 'Đã trả lời' : 'Từ chối';
                            badge.className = `px-3 py-1 rounded-full text-sm font-medium ${
                                status === 'unanswered' ? 'bg-blue-100 text-blue-700' :
                                status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                                status === 'answered' ? 'bg-green-100 text-green-700' :
                                'bg-red-100 text-red-700'
                            }`;
                        }
                        updateStatusCounts();
                    })
                    .catch(error => console.error('Error:', error));
                }
            });

            // Xử lý toggle auto-refresh
            document.getElementById('toggle-refresh').addEventListener('click', () => {
                autoRefresh = !autoRefresh;
                document.getElementById('toggle-refresh').innerHTML = `
                    <i class="fas fa-sync-alt mr-2"></i> ${autoRefresh ? 'Tắt' : 'Bật'} Auto-Refresh
                `;
            });

            // Xử lý toggle âm thanh
            document.getElementById('toggle-sound').addEventListener('click', () => {
                soundEnabled = !soundEnabled;
                document.getElementById('toggle-sound').innerHTML = `
                    <i class="fas fa-${soundEnabled ? 'volume-up' : 'volume-mute'} mr-2"></i> ${soundEnabled ? 'Tắt' : 'Bật'} Âm thanh
                `;
            });

            // Xử lý làm mới thủ công
            document.getElementById('manual-refresh').addEventListener('click', () => {
                lastSubmissionId = 0;
                submissionsCache.clear();
                document.getElementById('submissions-list').innerHTML = '';
                loadSubmissions();
            });

            // Tải submissions ban đầu
            loadSubmissions();
            // Cập nhật mỗi 3 giây
            const refreshInterval = setInterval(loadSubmissions, 3000);

            // Ẩn thông báo sau 5 giây
            setTimeout(() => {
                const messageContainer = document.getElementById('message-container');
                if (messageContainer) {
                    messageContainer.style.transition = 'opacity 0.5s, transform 0.5s';
                    messageContainer.style.opacity = '0';
                    messageContainer.style.transform = 'translateY(-20px)';
                    setTimeout(() => messageContainer.style.display = 'none', 500);
                }
            }, 5000);
        </script>
    <?php endif; ?>
</body>
</html>
<?php mysqli_close($conn); ?>