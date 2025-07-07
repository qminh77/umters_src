<?php
session_start();
include 'db_config.php';

// Kích hoạt ghi log lỗi
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Kiểm tra thư viện
if (!file_exists('vendor/autoload.php')) {
    die('Lỗi: Không tìm thấy composer autoload. Vui lòng chạy "composer install".');
}
require 'vendor/autoload.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Khởi tạo biến
$message = '';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin = false;

// Kiểm tra quyền admin
if ($user_id) {
    $sql_user = "SELECT is_main_admin, is_super_admin FROM users WHERE id = $user_id";
    $result_user = mysqli_query($conn, $sql_user);
    if ($result_user && mysqli_num_rows($result_user) > 0) {
        $user = mysqli_fetch_assoc($result_user);
        $is_admin = $user['is_main_admin'] || $user['is_super_admin'];
    } else {
        error_log("Lỗi lấy thông tin user: " . mysqli_error($conn));
        $message = "Lỗi khi lấy thông tin người dùng.";
    }
}

// Tự động lấy tên miền
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

// Tạo bảng attendance_lists nếu chưa có
$sql_lists = "CREATE TABLE IF NOT EXISTS attendance_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_name VARCHAR(100) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    qr_code_path VARCHAR(255) DEFAULT NULL,
    expiry_time DATETIME DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
)";
if (!mysqli_query($conn, $sql_lists)) {
    error_log("Lỗi tạo bảng attendance_lists: " . mysqli_error($conn));
    $message = "Lỗi khởi tạo cơ sở dữ liệu.";
}

// Kiểm tra và thêm cột expiry_time nếu chưa có
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM attendance_lists LIKE 'expiry_time'");
if (mysqli_num_rows($check_column) == 0) {
    $alter_table = "ALTER TABLE attendance_lists ADD expiry_time DATETIME DEFAULT NULL";
    if (!mysqli_query($conn, $alter_table)) {
        error_log("Lỗi thêm cột expiry_time: " . mysqli_error($conn));
        $message = "Lỗi khởi tạo cơ sở dữ liệu.";
    }
}

// Tạo bảng attendance_records nếu chưa có
$sql_records = "CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    major VARCHAR(50) DEFAULT NULL,
    phone VARCHAR(15) DEFAULT NULL,
    status TINYINT(1) DEFAULT 0,
    check_in_time TIMESTAMP NULL,
    FOREIGN KEY (list_id) REFERENCES attendance_lists(id)
)";
if (!mysqli_query($conn, $sql_records)) {
    error_log("Lỗi tạo bảng attendance_records: " . mysqli_error($conn));
    $message = "Lỗi khởi tạo cơ sở dữ liệu.";
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

// Xử lý tạo danh sách mới
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_list']) && $is_admin) {
    $list_name = trim($_POST['list_name']);
    $expiry_days = isset($_POST['expiry_days']) ? (int)$_POST['expiry_days'] : 7;
    if ($list_name) {
        $list_name_escaped = mysqli_real_escape_string($conn, $list_name);
        $expiry_time = $expiry_days > 0 ? date('Y-m-d H:i:s', strtotime("+$expiry_days days")) : null;
        $sql = "INSERT INTO attendance_lists (list_name, created_by, expiry_time) VALUES ('$list_name_escaped', $user_id, " . ($expiry_time ? "'$expiry_time'" : "NULL") . ")";
        if (mysqli_query($conn, $sql)) {
            $message = "Tạo danh sách thành công!";
            header("Location: attendance.php");
            exit;
        } else {
            error_log("Lỗi tạo danh sách: " . mysqli_error($conn));
            $message = "Lỗi khi tạo danh sách: " . mysqli_error($conn);
        }
    } else {
        $message = "Vui lòng nhập tên danh sách!";
    }
}

// Xử lý tải lên file Excel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_excel']) && $is_admin && isset($_FILES['excel_file'])) {
    $list_id = (int)$_POST['list_id'];
    if ($_FILES['excel_file']['error'] == 0) {
        $file_tmp = $_FILES['excel_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if (in_array($file_ext, ['xlsx', 'xls'])) {
            try {
                $spreadsheet = IOFactory::load($file_tmp);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                $header = array_shift($rows);

                foreach ($rows as $row) {
                    if (!empty($row[0])) {
                        $student_id = mysqli_real_escape_string($conn, trim($row[0]));
                        $full_name = mysqli_real_escape_string($conn, trim($row[1]));
                        $email = mysqli_real_escape_string($conn, trim($row[2] ?? ''));
                        $major = mysqli_real_escape_string($conn, trim($row[3] ?? ''));
                        $phone = mysqli_real_escape_string($conn, trim($row[4] ?? ''));
                        $sql = "INSERT INTO attendance_records (list_id, student_id, full_name, email, major, phone) 
                                VALUES ($list_id, '$student_id', '$full_name', '$email', '$major', '$phone')";
                        if (!mysqli_query($conn, $sql)) {
                            error_log("Lỗi chèn record: " . mysqli_error($conn));
                        }
                    }
                }
                $message = "Tải lên danh sách thành công!";
                header("Location: attendance.php?list_id=$list_id");
                exit;
            } catch (Exception $e) {
                error_log("Lỗi xử lý file Excel: " . $e->getMessage());
                $message = "Lỗi khi xử lý file Excel: " . $e->getMessage();
            }
        } else {
            $message = "Vui lòng tải lên file Excel (.xlsx hoặc .xls)!";
        }
    } else {
        $message = "Lỗi khi tải file!";
    }
}

// Xử lý tạo mã QR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_qr']) && $is_admin) {
    $list_id = (int)$_POST['list_id'];
    $qr_data = $base_url . "/attendance.php?list_id=$list_id&action=checkin";

    try {
        $qrCode = QrCode::create($qr_data)
            ->setSize(300)
            ->setMargin(10);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $qr_dir = 'qrcodes/';
        if (!is_dir($qr_dir)) {
            if (!mkdir($qr_dir, 0777, true)) {
                error_log("Lỗi tạo thư mục qrcodes");
                $message = "Lỗi tạo thư mục lưu mã QR.";
            }
        }
        $qr_path = $qr_dir . 'attendance-' . time() . '.png';
        $result->saveToFile($qr_path);

        $qr_path_escaped = mysqli_real_escape_string($conn, $qr_path);
        $sql = "UPDATE attendance_lists SET qr_code_path = '$qr_path_escaped' WHERE id = $list_id";
        if (mysqli_query($conn, $sql)) {
            $message = "Tạo mã QR thành công!";
            header("Location: attendance.php?list_id=$list_id");
            exit;
        } else {
            error_log("Lỗi lưu mã QR: " . mysqli_error($conn));
            $message = "Lỗi khi lưu mã QR: " . mysqli_error($conn);
        }
    } catch (Exception $e) {
        error_log("Lỗi tạo mã QR: " . $e->getMessage());
        $message = "Lỗi khi tạo mã QR: " . $e->getMessage();
    }
}

// Xử lý xóa danh sách
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_list']) && $is_admin) {
    $list_id = (int)$_POST['list_id'];
    $sql = "SELECT qr_code_path FROM attendance_lists WHERE id = $list_id AND created_by = $user_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $list = mysqli_fetch_assoc($result);
        if ($list['qr_code_path'] && file_exists($list['qr_code_path'])) {
            unlink($list['qr_code_path']);
        }
        mysqli_query($conn, "DELETE FROM attendance_records WHERE list_id = $list_id");
        mysqli_query($conn, "DELETE FROM attendance_lists WHERE id = $list_id");
        $message = "Xóa danh sách thành công!";
        header("Location: attendance.php");
        exit;
    } else {
        $message = "Danh sách không tồn tại hoặc bạn không có quyền xóa!";
    }
}

// Xử lý điểm danh
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkin'])) {
    $list_id = (int)$_POST['list_id'];
    $student_id = trim($_POST['student_id']);
    
    $sql_check_expiry = "SELECT expiry_time FROM attendance_lists WHERE id = $list_id";
    $result_expiry = mysqli_query($conn, $sql_check_expiry);
    if (!$result_expiry) {
        error_log("Lỗi kiểm tra expiry_time: " . mysqli_error($conn));
        $message = "Lỗi hệ thống khi kiểm tra danh sách.";
    } else if (mysqli_num_rows($result_expiry) > 0) {
        $list = mysqli_fetch_assoc($result_expiry);
        if ($list['expiry_time'] && strtotime($list['expiry_time']) < time()) {
            $message = "Mã QR đã hết hạn!";
        } else if ($student_id) {
            $student_id_escaped = mysqli_real_escape_string($conn, $student_id);
            $sql = "UPDATE attendance_records 
                    SET status = 1, check_in_time = NOW() 
                    WHERE list_id = $list_id AND student_id = '$student_id_escaped' AND status = 0";
            if (mysqli_query($conn, $sql)) {
                if (mysqli_affected_rows($conn) > 0) {
                    $message = "Điểm danh thành công!";
                } else {
                    $message = "Mã số sinh viên không hợp lệ hoặc đã điểm danh!";
                }
            } else {
                error_log("Lỗi điểm danh: " . mysqli_error($conn));
                $message = "Lỗi hệ thống khi điểm danh.";
            }
        } else {
            $message = "Vui lòng nhập mã số sinh viên!";
        }
    } else {
        $message = "Danh sách không tồn tại!";
    }
}

// Xử lý xuất báo cáo Excel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_report']) && $is_admin) {
    $list_id = (int)$_POST['list_id'];
    $sql_records = "SELECT student_id, full_name, email, major, phone, status, check_in_time 
                    FROM attendance_records WHERE list_id = $list_id ORDER BY full_name";
    $result_records = mysqli_query($conn, $sql_records);

    if ($result_records) {
        if (ob_get_length()) {
            ob_clean();
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Báo cáo điểm danh');

        $sheet->setCellValue('A1', 'Mã số');
        $sheet->setCellValue('B1', 'Họ tên');
        $sheet->setCellValue('C1', 'Email');
        $sheet->setCellValue('D1', 'Ngành');
        $sheet->setCellValue('E1', 'Số điện thoại');
        $sheet->setCellValue('F1', 'Trạng thái');
        $sheet->setCellValue('G1', 'Thời gian điểm danh');

        $row = 2;
        while ($record = mysqli_fetch_assoc($result_records)) {
            $sheet->setCellValue("A$row", $record['student_id']);
            $sheet->setCellValue("B$row", $record['full_name']);
            $sheet->setCellValue("C$row", $record['email']);
            $sheet->setCellValue("D$row", $record['major']);
            $sheet->setCellValue("E$row", $record['phone']);
            $sheet->setCellValue("F$row", $record['status'] ? 'Đã điểm danh' : 'Chưa điểm danh');
            $sheet->setCellValue("G$row", $record['check_in_time'] ? date('d/m/Y H:i', strtotime($record['check_in_time'])) : '');
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "BaoCaoDiemDanh_$list_id_" . time() . ".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"$filename\"");
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    } else {
        error_log("Lỗi xuất báo cáo: " . mysqli_error($conn));
        $message = "Lỗi khi xuất báo cáo: " . mysqli_error($conn);
    }
}

// Xử lý tải mẫu Excel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['download_template']) && $is_admin) {
    if (ob_get_length()) {
        ob_clean();
    }
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Mẫu danh sách');

    $sheet->setCellValue('A1', 'Mã số');
    $sheet->setCellValue('B1', 'Họ tên');
    $sheet->setCellValue('C1', 'Email');
    $sheet->setCellValue('D1', 'Ngành');
    $sheet->setCellValue('E1', 'Số điện thoại');

    $writer = new Xlsx($spreadsheet);
    $filename = "MauDanhSach.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment;filename=\"$filename\"");
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

// Lấy danh sách attendance_lists
$lists = [];
if ($is_admin) {
    $sql_lists = "SELECT * FROM attendance_lists WHERE created_by = $user_id ORDER BY created_at DESC";
    $result_lists = mysqli_query($conn, $sql_lists);
    if ($result_lists) {
        $lists = mysqli_fetch_all($result_lists, MYSQLI_ASSOC);
    } else {
        error_log("Lỗi lấy danh sách: " . mysqli_error($conn));
        $message = "Lỗi khi lấy danh sách.";
    }
}

// Lấy danh sách records và thống kê
$records = [];
$stats = ['attended' => 0, 'not_attended' => 0];
$selected_list_id = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;
if ($selected_list_id && $is_admin) {
    $sql_records = "SELECT * FROM attendance_records WHERE list_id = $selected_list_id ORDER BY full_name";
    $result_records = mysqli_query($conn, $sql_records);
    if ($result_records) {
        $records = mysqli_fetch_all($result_records, MYSQLI_ASSOC);
        foreach ($records as $record) {
            if ($record['status']) {
                $stats['attended']++;
            } else {
                $stats['not_attended']++;
            }
        }
    } else {
        error_log("Lỗi lấy records: " . mysqli_error($conn));
        $message = "Lỗi khi lấy danh sách sinh viên.";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý điểm danh</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --hover-bg: #f1f5f9;
            --text-color: #334155;
            --text-secondary: #64748b;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --delete-color: #ef4444;
            --delete-hover-color: #dc2626;
            --download-color: #22c55e;
            --download-hover-color: #16a34a;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --border-radius: 1rem;
            --small-radius: 0.75rem;
            --button-radius: 1.5rem;
            --padding: 2.5rem;
            --transition-speed: 0.3s;
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

        .dashboard-container {
            background: var(--container-bg);
            padding: var(--padding);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
        }

        h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            text-align: center;
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

        .form-section, .list-section, .checkin-section, .stats-section {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-title i {
            color: var(--primary-gradient-start);
        }

        .input, .select {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid rgba(116, 235, 213, 0.5);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            width: 100%;
            color: var(--text-color);
        }

        .input[type="file"] {
            padding: 0.6rem;
            border-style: dashed;
            cursor: pointer;
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
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        .export-btn {
            background: linear-gradient(90deg, var(--download-color), var(--download-hover-color));
        }

        .export-btn:hover {
            background: linear-gradient(90deg, var(--download-hover-color), var(--download-color));
        }

        .list-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .list-table th, .list-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .list-table th {
            background: var(--form-bg);
            font-weight: 600;
        }

        .qr-image {
            max-width: 200px;
            height: auto;
            border-radius: var(--small-radius);
        }

        .delete-btn {
            background: var(--delete-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--small-radius);
            cursor: pointer;
        }

        .delete-btn:hover {
            background: var(--delete-hover-color);
        }

        .stats-section canvas {
            max-width: 400px;
            margin: 0 auto;
        }

        .form-group {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        @media (max-width: 768px) {
            .list-table {
                display: block;
                overflow-x: auto;
            }

            .stats-section canvas {
                max-width: 100%;
            }

            .form-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h2>Quản lý điểm danh</h2>
        <?php include 'taskbar.php'; ?>

        <div class="message-container">
            <?php if ($message): ?>
                <div class="<?php echo strpos($message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($is_admin): ?>
            <!-- Tạo danh sách mới -->
            <div class="form-section">
                <div class="form-title">
                    <i class="fas fa-plus"></i> Tạo danh sách mới
                </div>
                <form method="POST">
                    <div class="form-group">
                        <input type="text" name="list_name" placeholder="Tên danh sách" class="input" required>
                        <input type="number" name="expiry_days" placeholder="Thời hạn (ngày)" class="input" min="0" value="7">
                    </div>
                    <button type="submit" name="create_list" class="button"><i class="fas fa-plus"></i> Tạo danh sách</button>
                </form>
            </div>

            <!-- Danh sách hiện có -->
            <div class="list-section">
                <div class="form-title">
                    <i class="fas fa-list"></i> Danh sách điểm danh
                </div>
                <?php if (empty($lists)): ?>
                    <p>Chưa có danh sách nào!</p>
                <?php else: ?>
                    <table class="list-table">
                        <tr>
                            <th>Tên danh sách</th>
                            <th>Ngày tạo</th>
                            <th>Thời hạn QR</th>
                            <th>Mã QR</th>
                            <th>Hành động</th>
                        </tr>
                        <?php foreach ($lists as $list): ?>
                            <tr>
                                <td>
                                    <a href="attendance.php?list_id=<?php echo $list['id']; ?>">
                                        <?php echo htmlspecialchars($list['list_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($list['created_at'])); ?></td>
                                <td><?php echo $list['expiry_time'] ? date('d/m/Y H:i', strtotime($list['expiry_time'])) : 'Không giới hạn'; ?></td>
                                <td>
                                    <?php if ($list['qr_code_path']): ?>
                                        <img src="<?php echo htmlspecialchars($list['qr_code_path']); ?>" class="qr-image">
                                        <a href="<?php echo htmlspecialchars($list['qr_code_path']); ?>" download class="button"><i class="fas fa-download"></i> Tải QR</a>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="list_id" value="<?php echo $list['id']; ?>">
                                            <button type="submit" name="generate_qr" class="button"><i class="fas fa-qrcode"></i> Tạo QR</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="list_id" value="<?php echo $list['id']; ?>">
                                        <button type="submit" name="delete_list" class="delete-btn" onclick="return confirm('Bạn có chắc muốn xóa danh sách này?');"><i class="fas fa-trash"></i> Xóa</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Tải lên danh sách từ Excel và tải mẫu -->
            <?php if ($selected_list_id): ?>
                <div class="form-section">
                    <div class="form-title">
                        <i class="fas fa-upload"></i> Tải lên danh sách từ Excel
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="list_id" value="<?php echo $selected_list_id; ?>">
                        <input type="file" name="excel_file" accept=".xlsx,.xls" class="input" required>
                        <button type="submit" name="upload_excel" class="button"><i class="fas fa-upload"></i> Tải lên</button>
                    </form>
                    <form method="POST">
                        <button type="submit" name="download_template" class="button export-btn"><i class="fas fa-download"></i> Tải mẫu Excel</button>
                    </form>
                    <p style="margin-top: 1rem; color: var(--text-secondary);">
                        File Excel cần có các cột: Mã số, Họ tên, Email, Ngành, Số điện thoại
                    </p>
                </div>

                <!-- Thống kê điểm danh -->
                <div class="stats-section">
                    <div class="form-title">
                        <i class="fas fa-chart-pie"></i> Thống kê điểm danh
                    </div>
                    <canvas id="attendanceChart"></canvas>
                    <script>
                        const ctx = document.getElementById('attendanceChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: ['Đã điểm danh', 'Chưa điểm danh'],
                                datasets: [{
                                    data: [<?php echo $stats['attended']; ?>, <?php echo $stats['not_attended']; ?>],
                                    backgroundColor: ['#22c55e', '#ef4444'],
                                    borderColor: ['#ffffff', '#ffffff'],
                                    borderWidth: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: { position: 'top' },
                                    title: { display: true, text: 'Tỷ lệ điểm danh' }
                                }
                            }
                        });
                    </script>
                </div>

                <!-- Danh sách sinh viên -->
                <div class="list-section">
                    <div class="form-title">
                        <i class="fas fa-users"></i> Danh sách sinh viên
                    </div>
                    <form method="POST">
                        <input type="hidden" name="list_id" value="<?php echo $selected_list_id; ?>">
                        <button type="submit" name="export_report" class="button export-btn"><i class="fas fa-file-export"></i> Xuất báo cáo</button>
                    </form>
                    <?php if (empty($records)): ?>
                        <p>Chưa có sinh viên trong danh sách này!</p>
                    <?php else: ?>
                        <table class="list-table">
                            <tr>
                                <th>Mã số</th>
                                <th>Họ tên</th>
                                <th>Email</th>
                                <th>Ngành</th>
                                <th>Số điện thoại</th>
                                <th>Trạng thái</th>
                                <th>Thời gian điểm danh</th>
                            </tr>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($record['major'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($record['phone'] ?? ''); ?></td>
                                    <td><?php echo $record['status'] ? 'Đã điểm danh' : 'Chưa điểm danh'; ?></td>
                                    <td><?php echo $record['check_in_time'] ? date('d/m/Y H:i', strtotime($record['check_in_time'])) : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Phần điểm danh bằng mã QR -->
        <?php if (isset($_GET['list_id']) && isset($_GET['action']) && $_GET['action'] == 'checkin'): ?>
            <div class="checkin-section">
                <div class="form-title">
                    <i class="fas fa-check-circle"></i> Điểm danh
                </div>
                <form method="POST">
                    <input type="hidden" name="list_id" value="<?php echo (int)$_GET['list_id']; ?>">
                    <input type="text" name="student_id" placeholder="Nhập mã số sinh viên" class="input" required>
                    <button type="submit" name="checkin" class="button"><i class="fas fa-check"></i> Điểm danh</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>