<?php
session_start();
include 'db_config.php';

// Tải thư viện PhpSpreadsheet
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tạo bảng data_keys nếu chưa có
$sql_data_keys = "CREATE TABLE IF NOT EXISTS data_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_code VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(15) DEFAULT NULL,
    class VARCHAR(50) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATETIME DEFAULT NULL,
    created_by INT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
)";
mysqli_query($conn, $sql_data_keys) or die("Error creating data_keys: " . mysqli_error($conn));

// Khởi tạo biến thông báo
$message = '';
$search_result = null;

// Xử lý tải file mẫu
if (isset($_GET['download_sample']) && in_array($_GET['download_sample'], ['csv', 'xlsx'])) {
    $format = $_GET['download_sample'];
    $data = [
        [
            'key_code' => '2403700068',
            'full_name' => 'Nguyễn Văn A',
            'email' => 'user1@domain.com',
            'phone' => '0123456789',
            'class' => '12A1',
            'address' => '123 Đường Láng',
            'use_expiry' => '1',
            'expiry_date' => '2025-12-31 23:59:59'
        ],
        [
            'key_code' => '2403700069',
            'full_name' => 'Trần Thị B',
            'email' => 'user2@localhost',
            'phone' => '0987654321',
            'class' => '12A2',
            'address' => '456 Đường Láng',
            'use_expiry' => '0',
            'expiry_date' => ''
        ]
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="data_keys_sample.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['key_code', 'full_name', 'email', 'phone', 'class', 'address', 'use_expiry', 'expiry_date']);
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    } else {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['key_code', 'full_name', 'email', 'phone', 'class', 'address', 'use_expiry', 'expiry_date'], null, 'A1');
        $sheet->fromArray($data, null, 'A2');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="data_keys_sample.xlsx"');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }
}

// Xóa các Data Key đã hết hạn
mysqli_query($conn, "DELETE FROM data_keys WHERE expiry_date IS NOT NULL AND expiry_date < NOW()");

// Xử lý tìm kiếm qua URL (API hoặc giao diện, công khai)
if (isset($_GET['search'])) {
    $key_code = mysqli_real_escape_string($conn, trim($_GET['search']));
    $sql = "SELECT full_name, email, phone, class, address, created_at, expiry_date 
            FROM data_keys 
            WHERE key_code = '$key_code' 
            AND (expiry_date IS NULL OR expiry_date > NOW())";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $search_result = mysqli_fetch_assoc($result);
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'data' => $search_result
            ]);
            exit;
        }
    } else {
        $search_result = false;
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Không tìm thấy thông tin cho mã số này hoặc mã số đã hết hạn.'
            ]);
            exit;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Kết quả tìm kiếm Data Key</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary: #7000FF;
                --primary-light: #9D50FF;
                --primary-dark: #4A00B0;
                --secondary: #00E0FF;
                --secondary-light: #70EFFF;
                --secondary-dark: #00B0C7;
                --accent: #FF3DFF;
                --accent-light: #FF7DFF;
                --accent-dark: #C700C7;
                --background: #0A0A1A;
                --surface: #12122A;
                --surface-light: #1A1A3A;
                --foreground: #FFFFFF;
                --foreground-muted: rgba(255, 255, 255, 0.7);
                --foreground-subtle: rgba(255, 255, 255, 0.5);
                --card: rgba(30, 30, 60, 0.6);
                --card-hover: rgba(40, 40, 80, 0.8);
                --card-active: rgba(50, 50, 100, 0.9);
                --border: rgba(255, 255, 255, 0.1);
                --border-light: rgba(255, 255, 255, 0.05);
                --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
                --glow: 0 0 20px rgba(112, 0, 255, 0.5);
                --radius-sm: 0.75rem;
                --radius: 1.5rem;
                --radius-lg: 2rem;
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Outfit', sans-serif;
                background-color: var(--background);
                color: var(--foreground);
                min-height: 100vh;
                line-height: 1.6;
                overflow-x: hidden;
                background-image: 
                    radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                    radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                    radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
                background-attachment: fixed;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 1rem;
            }

            .result-section {
                background: var(--card);
                padding: 2rem;
                border-radius: var(--radius-lg);
                border: 1px solid var(--border);
                box-shadow: var(--shadow);
                width: 100%;
                max-width: 600px;
                text-align: center;
                backdrop-filter: blur(20px);
                animation: fadeIn 0.5s ease-out;
            }

            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }

            h2 {
                font-size: 1.75rem;
                font-weight: 700;
                margin-bottom: 1.5rem;
                color: var(--foreground);
                background: linear-gradient(to right, var(--secondary), var(--accent));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                position: relative;
            }

            h2::after {
                content: '';
                position: absolute;
                bottom: -0.5rem;
                left: 50%;
                transform: translateX(-50%);
                width: 80px;
                height: 4px;
                background: linear-gradient(90deg, var(--primary), var(--secondary));
                border-radius: 2px;
            }

            .info {
                background: rgba(255, 255, 255, 0.05);
                border-radius: var(--radius);
                border: 1px solid var(--border);
                padding: 1.5rem;
                text-align: left;
                margin-bottom: 1rem;
                transition: all 0.3s ease;
            }

            .info:hover {
                transform: translateY(-5px);
                box-shadow: var(--shadow-sm);
            }

            .info p {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                margin-bottom: 0.75rem;
                font-size: 1rem;
                color: var(--foreground-muted);
            }

            .info p strong {
                color: var(--foreground);
                font-weight: 600;
                min-width: 120px;
            }

            .info p i {
                color: var(--secondary);
            }

            .alert {
                padding: 1rem 1.5rem;
                border-radius: var(--radius);
                margin-bottom: 1.5rem;
                font-weight: 500;
                backdrop-filter: blur(10px);
                display: flex;
                align-items: center;
                gap: 0.5rem;
                animation: slideIn 0.3s ease-out;
            }

            @keyframes slideIn {
                from { opacity: 0; transform: translateX(-20px); }
                to { opacity: 1; transform: translateX(0); }
            }

            .alert-error {
                background: rgba(255, 61, 87, 0.1);
                color: #FF3D57;
                border: 1px solid rgba(255, 61, 87, 0.3);
            }

            .alert-error i {
                font-size: 1.2rem;
            }

            .btn {
                padding: 0.75rem 1.5rem;
                border-radius: var(--radius);
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                font-size: 1rem;
                border: 1px solid var(--border);
            }

            .btn-primary {
                background: linear-gradient(to right, var(--primary), var(--primary-dark));
                color: white;
                border: none;
            }

            .btn-primary:hover {
                background: linear-gradient(to right, var(--primary-light), var(--primary));
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
            }

            @media (max-width: 768px) {
                .result-section {
                    padding: 1.5rem;
                    margin: 1rem;
                }
                h2 {
                    font-size: 1.5rem;
                }
                .info p {
                    font-size: 0.9rem;
                }
            }

            @media (max-width: 480px) {
                .result-section {
                    padding: 1rem;
                }
                h2 {
                    font-size: 1.25rem;
                }
                .info {
                    padding: 1rem;
                }
                .btn {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>
    </head>
    <body>
        <div class="result-section">
            <h2>Kết quả tìm kiếm</h2>
            <?php if ($search_result): ?>
                <div class="info">
                    <p><i class="fas fa-user"></i><strong>Họ tên:</strong> <?php echo htmlspecialchars($search_result['full_name']); ?></p>
                    <p><i class="fas fa-envelope"></i><strong>Email:</strong> <?php echo htmlspecialchars($search_result['email']); ?></p>
                    <?php if ($search_result['phone']): ?>
                        <p><i class="fas fa-phone"></i><strong>Số điện thoại:</strong> <?php echo htmlspecialchars($search_result['phone']); ?></p>
                    <?php endif; ?>
                    <?php if ($search_result['class']): ?>
                        <p><i class="fas fa-graduation-cap"></i><strong>Lớp:</strong> <?php echo htmlspecialchars($search_result['class']); ?></p>
                    <?php endif; ?>
                    <?php if ($search_result['address']): ?>
                        <p><i class="fas fa-map-marker-alt"></i><strong>Địa chỉ:</strong> <?php echo htmlspecialchars($search_result['address']); ?></p>
                    <?php endif; ?>
                    <p><i class="far fa-calendar-alt"></i><strong>Ngày tạo:</strong> <?php echo date('d/m/Y H:i', strtotime($search_result['created_at'])); ?></p>
                    <?php if ($search_result['expiry_date']): ?>
                        <p><i class="far fa-calendar-times"></i><strong>Hết hạn:</strong> <?php echo date('d/m/Y H:i', strtotime($search_result['expiry_date'])); ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> Không tìm thấy thông tin cho mã số này hoặc mã số đã hết hạn.
                </div>
            <?php endif; ?>
            <a href="/" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Quay lại trang chủ</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Kiểm tra quyền superadmin cho phần quản lý
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin']) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Xử lý nhập từ file Excel/CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "Lỗi khi tải lên file!";
    } else {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['csv', 'xlsx', 'xls'];
        if (!in_array($file_ext, $allowed_ext)) {
            $message = "Chỉ hỗ trợ file CSV, XLSX, XLS!";
        } else {
            $data = [];
            if ($file_ext === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                $headers = fgetcsv($handle, 1000, ',');
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $data[] = array_combine($headers, $row);
                }
                fclose($handle);
            } else {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();
                $headers = array_shift($data);
                $data = array_map(function($row) use ($headers) {
                    return array_combine($headers, $row);
                }, $data);
            }

            $success_count = 0;
            $error_messages = [];
            foreach ($data as $row) {
                $key_code = trim($row['key_code'] ?? '');
                $full_name = trim($row['full_name'] ?? '');
                $email = trim($row['email'] ?? '');
                $phone = trim($row['phone'] ?? '');
                $class = trim($row['class'] ?? '');
                $address = trim($row['address'] ?? '');
                $use_expiry = isset($row['use_expiry']) && in_array($row['use_expiry'], ['1', 'true', 'yes']) ? 1 : 0;
                $expiry_date = $use_expiry && !empty($row['expiry_date']) ? trim($row['expiry_date']) : null;

                if (empty($key_code) || empty($full_name) || empty($email)) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Thiếu mã số, họ tên hoặc email!";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9]+$/', $key_code)) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Mã số chỉ được chứa chữ cái và số!";
                    continue;
                }
                if (!preg_match('/^[^@]+@[^@]+$/', $email)) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Email phải chứa ký tự '@'!";
                    continue;
                }
                if ($use_expiry && empty($expiry_date)) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Thiếu ngày hết hạn!";
                    continue;
                }
                if ($use_expiry && strtotime($expiry_date) <= time()) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Ngày hết hạn phải sau thời điểm hiện tại!";
                    continue;
                }

                $key_code_escaped = mysqli_real_escape_string($conn, $key_code);
                $full_name_escaped = mysqli_real_escape_string($conn, $full_name);
                $email_escaped = mysqli_real_escape_string($conn, $email);
                $phone_escaped = mysqli_real_escape_string($conn, $phone);
                $class_escaped = mysqli_real_escape_string($conn, $class);
                $address_escaped = mysqli_real_escape_string($conn, $address);
                $expiry_date_escaped = $use_expiry ? "'" . mysqli_real_escape_string($conn, $expiry_date) . "'" : 'NULL';

                $check_key = mysqli_query($conn, "SELECT id FROM data_keys WHERE key_code = '$key_code_escaped'");
                if (mysqli_num_rows($check_key) > 0) {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Mã số đã tồn tại!";
                    continue;
                }

                $sql = "INSERT INTO data_keys (key_code, full_name, email, phone, class, address, created_by, expiry_date) 
                        VALUES ('$key_code_escaped', '$full_name_escaped', '$email_escaped', '$phone_escaped', 
                                '$class_escaped', '$address_escaped', $user_id, $expiry_date_escaped)";
                if (mysqli_query($conn, $sql)) {
                    $success_count++;
                } else {
                    $error_messages[] = "Dòng dữ liệu với mã số '$key_code': Lỗi khi lưu: " . mysqli_error($conn);
                }
            }

            if ($success_count > 0) {
                $message = "Nhập thành công $success_count Data Key!";
            }
            if ($error_messages) {
                $message .= "<br>Lỗi:<ul><li>" . implode('</li><li>', $error_messages) . "</li></ul>";
            }
        }
    }
}

// Xử lý tạo Data Key thủ công
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_key'])) {
    $key_code = trim($_POST['key_code']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $class = trim($_POST['class']);
    $address = trim($_POST['address']);
    $use_expiry = isset($_POST['use_expiry']) && $_POST['use_expiry'] === '1';
    $expiry_date = $use_expiry && !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    if (empty($key_code) || empty($full_name) || empty($email)) {
        $message = "Mã số, họ tên và email là bắt buộc!";
    } elseif (!preg_match('/^[^@]+@[^@]+$/', $email)) {
        $message = "Email phải chứa ký tự '@'!";
    } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $key_code)) {
        $message = "Mã số chỉ được chứa chữ cái và số!";
    } elseif ($use_expiry && empty($expiry_date)) {
        $message = "Vui lòng chọn ngày hết hạn!";
    } elseif ($use_expiry && strtotime($expiry_date) <= time()) {
        $message = "Ngày hết hạn phải sau thời điểm hiện tại!";
    } else {
        $key_code_escaped = mysqli_real_escape_string($conn, $key_code);
        $full_name_escaped = mysqli_real_escape_string($conn, $full_name);
        $email_escaped = mysqli_real_escape_string($conn, $email);
        $phone_escaped = mysqli_real_escape_string($conn, $phone);
        $class_escaped = mysqli_real_escape_string($conn, $class);
        $address_escaped = mysqli_real_escape_string($conn, $address);
        $expiry_date_escaped = $use_expiry ? "'" . mysqli_real_escape_string($conn, $expiry_date) . "'" : 'NULL';
        
        $check_key = mysqli_query($conn, "SELECT id FROM data_keys WHERE key_code = '$key_code_escaped'");
        if (mysqli_num_rows($check_key) > 0) {
            $message = "Mã số đã tồn tại!";
        } else {
            $sql = "INSERT INTO data_keys (key_code, full_name, email, phone, class, address, created_by, expiry_date) 
                    VALUES ('$key_code_escaped', '$full_name_escaped', '$email_escaped', '$phone_escaped', 
                            '$class_escaped', '$address_escaped', $user_id, $expiry_date_escaped)";
            if (mysqli_query($conn, $sql)) {
                $message = "Tạo Data Key thành công!";
            } else {
                $message = "Lỗi khi tạo Data Key: " . mysqli_error($conn);
            }
        }
    }
}

// Xử lý xóa Data Key
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_key']) && isset($_POST['key_id'])) {
    $key_id = (int)$_POST['key_id'];
    $sql = "DELETE FROM data_keys WHERE id = $key_id AND created_by = $user_id";
    if (mysqli_query($conn, $sql)) {
        $message = "Xóa Data Key thành công!";
    } else {
        $message = "Lỗi khi xóa Data Key: " . mysqli_error($conn);
    }
}

// Phân trang cho danh sách Data Keys
$keys_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $keys_per_page;

$sql_total = "SELECT COUNT(*) as total FROM data_keys WHERE created_by = $user_id";
$total_result = mysqli_query($conn, $sql_total);
$total_keys = $total_result ? mysqli_fetch_assoc($total_result)['total'] : 0;
$total_pages = ceil($total_keys / $keys_per_page);

$sql_keys = "SELECT id, key_code, full_name, email, phone, class, address, created_at, expiry_date 
             FROM data_keys 
             WHERE created_by = $user_id 
             ORDER BY created_at DESC 
             LIMIT $offset, $keys_per_page";
$result_keys = mysqli_query($conn, $sql_keys);
$data_keys = $result_keys ? mysqli_fetch_all($result_keys, MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Data Key</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            gap: 1rem;
        }

        .user-menu a {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
        }

        .user-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-menu .btn {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
        }

        .user-menu .btn:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .dashboard-container {
            background: var(--card);
            padding: 2rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1200px;
            margin: 2rem auto;
            backdrop-filter: blur(20px);
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--foreground);
            margin-bottom: 2rem;
            text-align: center;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            padding-bottom: 0.75rem;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 120px;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 3px;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border);
        }

        .tab {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: var(--foreground-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            border-radius: 21px 21px 0 0;

        }

        .tab.active {
            color: var(--foreground);
            border-bottom-color: var(--primary);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab:hover {
            color: var(--foreground);
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease-out;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .alert-error ul {
            margin-top: 0.5rem;
            padding-left: 1.5rem;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .form-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .form-section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-section:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-title i {
            color: var(--secondary);
            font-size: 1.5rem;
        }

        .form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .field-label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--foreground);
            font-size: 0.95rem;
        }

        .input {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
            width: 100%;
            color: var(--foreground);
            box-shadow: var(--shadow-sm);
        }

        .input[type="file"] {
            padding: 0.6rem;
            border-style: dashed;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.05);
        }

        .input[type="file"]:hover {
            border-color: var(--primary-light);
            background: rgba(255, 255, 255, 0.1);
        }

        .input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-label {
            font-weight: 500;
            color: var(--foreground);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-input {
            width: 1.2rem;
            height: 1.2rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .expiry-field {
            display: none;
            animation: fadeIn 0.3s ease-out;
        }

        .expiry-field.active {
            display: block;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            border: 1px solid var(--border);
        }

        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-info {
            background: linear-gradient(to right, var(--secondary), var(--secondary-dark));
            color: white;
            border: none;
        }

        .btn-info:hover {
            background: linear-gradient(to right, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .sample-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .guide-section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .guide-section:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .guide-content {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            text-align: left;
        }

        .guide-content p {
            /*display: flex;*/
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
            color: var(--foreground-muted);
        }

        .guide-content p i {
            color: var(--secondary);
        }

        .guide-content code {
            background: rgba(255, 255, 255, 0.1);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.9rem;
            color: var(--foreground);
        }

        .history {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-top: 2rem;
        }

        .history-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-title i {
            color: var(--secondary);
            font-size: 1.5rem;
        }

        .history-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .history-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .history-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .history-info {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .history-key {
            font-weight: 600;
            color: var(--foreground);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-key i {
            color: var(--secondary);
            font-size: 1.2rem;
        }

        .history-data {
            color: var(--foreground-muted);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .history-data strong {
            color: var(--foreground);
            font-weight: 500;
        }

        .history-dates {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.85rem;
            color: var(--foreground-muted);
        }

        .history-dates i {
            color: var(--secondary);
            margin-right: 0.25rem;
        }

        .history-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-top: 1px solid var(--border);
        }

        .history-btn {
            flex: 1;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 500;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            text-decoration: none;
        }

        .copy-btn {
            background: linear-gradient(to right, var(--secondary), var(--secondary-dark));
            color: white;
            border: none;
        }

        .copy-btn:hover {
            background: linear-gradient(to right, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .delete-btn {
            background: linear-gradient(to right, #FF3D57, #C70039);
            color: white;
            border: none;
            cursor: pointer;
        }

        .delete-btn:hover {
            background: linear-gradient(to right, #FF5069, #DC143C);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--foreground-muted);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--foreground-muted);
            opacity: 0.5;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            align-items: center;
        }

        .pagination-link {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .pagination-link:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(112, 0, 255, 0.4);
        }

        .pagination-info {
            color: var(--foreground-muted);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            color: var(--foreground);
            padding: 1rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            z-index: 1100;
            display: none;
            animation: fadeIn 0.3s ease;
            border: 1px solid var(--border);
        }

        .toast.success {
            border-left: 4px solid var(--secondary);
        }

        @media (min-width: 992px) {
            .form-container {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .dashboard-container {
                padding: 1.5rem;
                margin: 1rem;
            }
            .form-container {
                grid-template-columns: 1fr;
            }
            h2 {
                font-size: 1.75rem;
            }
            .tabs {
                flex-direction: column;
                align-items: center;
            }
            .tab {
                width: 100%;
                text-align: center;
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                margin-bottom: 0.5rem;
            }
            .tab.active {
                border-color: var(--primary);
                border-width: 1px;
            }
            .history-list {
                grid-template-columns: 1fr;
            }
            .btn, .pagination-link {
                width: 100%;
                justify-content: center;
            }
            .pagination {
                flex-direction: column;
                gap: 0.5rem;
            }
            .sample-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                padding: 1rem;
                margin: 0.5rem;
            }
            h2 {
                font-size: 1.5rem;
            }
            .form-title, .guide-title, .history-title {
                font-size: 1.1rem;
            }
            .input, .btn, .pagination-link {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            .history-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .history-btn {
                width: 100%;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    document.getElementById(this.dataset.tab).classList.add('active');
                });
            });

            // Message auto-hide
            const messages = document.querySelectorAll('.alert');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateX(-20px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                }, 5000);
            });

            // Copy link toast
            document.querySelectorAll('.copy-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const url = this.getAttribute('data-url');
                    navigator.clipboard.writeText(url).then(() => {
                        const toast = document.createElement('div');
                        toast.className = 'toast success';
                        toast.style.position = 'fixed';
                        toast.style.bottom = '20px';
                        toast.style.right = '20px';
                        toast.style.zIndex = '1000';
                        toast.innerHTML = '<i class="fas fa-check-circle"></i> Đã sao chép liên kết!';
                        document.body.appendChild(toast);
                        setTimeout(() => {
                            toast.style.opacity = '0';
                            toast.style.transform = 'translateX(20px)';
                            setTimeout(() => toast.remove(), 500);
                        }, 3000);
                    });
                });
            });

            // Toggle expiry date field
            const useExpiryCheckbox = document.getElementById('use_expiry');
            const expiryField = document.getElementById('expiry_field');
            if (useExpiryCheckbox && expiryField) {
                useExpiryCheckbox.addEventListener('change', function() {
                    expiryField.classList.toggle('active', this.checked);
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-key"></i></div>
                <div class="logo-text">API</div>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </header>

        <div class="dashboard-container">
            <h2><i class="fas fa-key"></i> Quản lý Data Key</h2>

            <?php if ($message): ?>
                <div class="alert <?php echo strpos($message, 'thành công') !== false && !strpos($message, '<ul>') ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="tabs">
                <div class="tab active" data-tab="manual-tab">Nhập thủ công</div>
                <div class="tab" data-tab="import-tab">Nhập từ file</div>
            </div>

            <div class="form-container">
                <!-- Tab nhập thủ công -->
                <div id="manual-tab" class="tab-content active">
                    <div class="form-section">
                        <div class="form-title">
                            <i class="fas fa-plus-circle"></i> Tạo Data Key thủ công
                        </div>
                        <form class="form" method="POST">
                            <label class="field-label">Mã số (Data Key)</label>
                            <input type="text" name="key_code" placeholder="Ví dụ: 2403700068" class="input" required>
                            
                            <label class="field-label">Họ tên</label>
                            <input type="text" name="full_name" placeholder="Họ và tên" class="input" required>
                            
                            <label class="field-label">Email</label>
                            <input type="text" name="email" placeholder="Ví dụ: user@domain.com" class="input" required>
                            
                            <label class="field-label">Số điện thoại</label>
                            <input type="text" name="phone" placeholder="Số điện thoại" class="input">
                            
                            <label class="field-label">Lớp</label>
                            <input type="text" name="class" placeholder="Lớp" class="input">
                            
                            <label class="field-label">Địa chỉ</label>
                            <input type="text" name="address" placeholder="Địa chỉ" class="input">
                            
                            <div class="checkbox-group">
                                <input type="checkbox" id="use_expiry" name="use_expiry" value="1" class="checkbox-input">
                                <label for="use_expiry" class="checkbox-label">Bật ngày hết hạn</label>
                            </div>
                            
                            <div id="expiry_field" class="expiry-field">
                                <label class="field-label">Ngày hết hạn</label>
                                <input type="datetime-local" name="expiry_date" class="input">
                            </div>
                            
                            <button type="submit" name="create_key" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Tạo Data Key
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Tab nhập từ file -->
                <div id="import-tab" class="tab-content">
                    <div class="form-section">
                        <div class="form-title">
                            <i class="fas fa-file-import"></i> Nhập từ Excel/CSV
                        </div>
                        <form class="form" method="POST" enctype="multipart/form-data">
                            <label class="field-label">Chọn file (CSV, XLSX, XLS)</label>
                            <input type="file" name="import_file" accept=".csv,.xlsx,.xls" class="input" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Nhập Data Key
                            </button>
                        </form>
                        <div class="sample-buttons">
                            <a href="?download_sample=csv" class="btn btn-info">
                                <i class="fas fa-file-csv"></i> Tải mẫu CSV
                            </a>
                            <a href="?download_sample=xlsx" class="btn btn-info">
                                <i class="fas fa-file-excel"></i> Tải mẫu Excel
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Hướng dẫn sử dụng -->
                <div class="guide-section">
                    <div class="form-title">
                        <i class="fas fa-info-circle"></i> Hướng dẫn sử dụng
                    </div>
                    <div class="guide-content">
                        <p><i class="fas fa-check-circle"></i> Nhập thủ công: Điền thông tin và mã số để tạo Data Key.</p>
                        <p><i class="fas fa-check-circle"></i> Nhập từ file: Sử dụng file CSV/Excel với các cột: <code>key_code</code>, <code>full_name</code>, <code>email</code>, <code>phone</code>, <code>class</code>, <code>address</code>, <code>use_expiry</code> (0/1), <code>expiry_date</code> (YYYY-MM-DD HH:MM:SS).</p>
                        <p><i class="fas fa-check-circle"></i> Tải file mẫu để đảm bảo định dạng đúng.</p>
                        <p><i class="fas fa-check-circle"></i> Mã số phải là duy nhất và chỉ chứa chữ cái/số.</p>
                        <p><i class="fas fa-check-circle"></i> Email chỉ cần chứa ký tự '@' (ví dụ: user@domain.com).</p>
                        <p><i class="fas fa-check-circle"></i> Bật ngày hết hạn nếu muốn Data Key tự xóa sau thời gian chỉ định.</p>
                        <p><i class="fas fa-check-circle"></i> Sử dụng URL: <code><?php echo htmlspecialchars($base_url); ?>/data_key.php?search={mã_số}</code></p>
                        <p><i class="fas fa-check-circle"></i> API JSON: <code><?php echo htmlspecialchars($base_url); ?>/data_key.php?search={mã_số}&format=json</code></p>
                    </div>
                </div>
            </div>

            <!-- Lịch sử Data Keys -->
            <div class="history">
                <div class="history-title">
                    <i class="fas fa-history"></i> Lịch sử Data Keys
                </div>
                <?php if (empty($data_keys)): ?>
                    <div class="empty-state">
                        <i class="fas fa-key"></i>
                        <p>Chưa có Data Key nào được tạo. Hãy tạo Data Key đầu tiên của bạn!</p>
                    </div>
                <?php else: ?>
                    <div class="history-list">
                        <?php 
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'];
                        $base_url = $protocol . '://' . $host;
                        foreach ($data_keys as $key): ?>
                            <div class="history-card">
                                <div class="history-info">
                                    <div class="history-key">
                                        <i class="fas fa-key"></i>
                                        <?php echo htmlspecialchars($key['key_code']); ?>
                                    </div>
                                    <div class="history-data">
                                        <strong>Họ tên:</strong> <?php echo htmlspecialchars($key['full_name']); ?><br>
                                        <strong>Email:</strong> <?php echo htmlspecialchars($key['email']); ?>
                                    </div>
                                    <div class="history-dates">
                                        <span><i class="far fa-calendar-plus"></i> Tạo: <?php echo date('d/m/Y H:i', strtotime($key['created_at'])); ?></span>
                                        <?php if ($key['expiry_date']): ?>
                                            <span><i class="far fa-calendar-times"></i> Hết hạn: <?php echo date('d/m/Y H:i', strtotime($key['expiry_date'])); ?></span>
                                        <?php else: ?>
                                            <span><i class="far fa-infinity"></i> Vô thời hạn</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="history-actions">
                                    <button class="history-btn copy-btn" data-url="<?php echo htmlspecialchars($base_url . '/data_key.php?search=' . $key['key_code']); ?>">
                                        <i class="fas fa-copy"></i> Sao chép liên kết
                                    </button>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                        <button type="submit" name="delete_key" class="history-btn delete-btn" onclick="return confirm('Bạn có chắc muốn xóa Data Key này? Hành động này không thể hoàn tác.');">
                                            <i class="fas fa-trash-alt"></i> Xóa
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Phân trang -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="data_key.php?page=<?php echo $page - 1; ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i> Trang trước
                                </a>
                            <?php endif; ?>
                            <span class="pagination-info">Trang <?php echo $page; ?> / <?php echo $total_pages; ?></span>
                            <?php if ($page < $total_pages): ?>
                                <a href="data_key.php?page=<?php echo $page + 1; ?>" class="pagination-link">
                                    Trang sau <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>