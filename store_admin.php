<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$is_super_admin = 0;
$user_query = "SELECT is_super_admin FROM users WHERE id = ?";
if ($stmt = $conn->prepare($user_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    $is_super_admin = $user_data['is_super_admin'] ?? 0;
    $stmt->close();
} else {
    error_log("Error preparing user info query: " . $conn->error);
    $_SESSION['error_message'] = "Lỗi hệ thống: Không thể kiểm tra quyền người dùng.";
    header("Location: dashboard.php");
    exit;
}

if (!$is_super_admin) {
    $_SESSION['error_message'] = "Bạn không có quyền truy cập chức năng này.";
    header("Location: dashboard.php");
    exit;
}

function createTableIfNotExists(mysqli $conn, string $sql, string $tableName) {
    if (!mysqli_query($conn, $sql)) {
        error_log("Error creating table $tableName: " . mysqli_error($conn));
    }
}

// Cập nhật câu lệnh CREATE TABLE cho resources để bao gồm original_price và discount_price
$sql_create_resources = "CREATE TABLE IF NOT EXISTS resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    original_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- Thêm cột giá gốc
    discount_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- Thêm cột giá giảm
    category VARCHAR(100) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    preview_image VARCHAR(255) DEFAULT NULL,
    download_count INT DEFAULT 0,
    stock_quantity INT DEFAULT -1,
    allow_repurchase TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL DEFAULT 1
)";
createTableIfNotExists($conn, $sql_create_resources, 'resources');

// Thêm cột original_price nếu chưa tồn tại
$check_original_price_column = mysqli_query($conn, "SHOW COLUMNS FROM resources LIKE 'original_price'");
if (mysqli_num_rows($check_original_price_column) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD COLUMN original_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price");
}

// Thêm cột discount_price nếu chưa tồn tại
$check_discount_price_column = mysqli_query($conn, "SHOW COLUMNS FROM resources LIKE 'discount_price'");
if (mysqli_num_rows($check_discount_price_column) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD COLUMN discount_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER original_price");
}


$check_column_created_by = mysqli_query($conn, "SHOW COLUMNS FROM resources LIKE 'created_by'");
if (mysqli_num_rows($check_column_created_by) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD COLUMN created_by INT NOT NULL DEFAULT 1");
}

$check_stock_column = mysqli_query($conn, "SHOW COLUMNS FROM resources LIKE 'stock_quantity'");
if (mysqli_num_rows($check_stock_column) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD COLUMN stock_quantity INT DEFAULT -1");
}

$check_repurchase_column = mysqli_query($conn, "SHOW COLUMNS FROM resources LIKE 'allow_repurchase'");
if (mysqli_num_rows($check_repurchase_column) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD COLUMN allow_repurchase TINYINT(1) DEFAULT 0");
}

$check_fk_query = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'created_by' AND REFERENCED_TABLE_NAME = 'users'";
$fk_result = mysqli_query($conn, $check_fk_query);
if (!$fk_result || mysqli_num_rows($fk_result) == 0) {
    mysqli_query($conn, "ALTER TABLE resources ADD CONSTRAINT fk_resources_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE");
}

$sql_create_purchases = "CREATE TABLE IF NOT EXISTS resource_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_id INT NOT NULL,
    user_id INT NOT NULL,
    purchase_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'bank_transfer',
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    transaction_id VARCHAR(100) DEFAULT NULL,
    qr_code_path VARCHAR(255) DEFAULT NULL,
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_date TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
createTableIfNotExists($conn, $sql_create_purchases, 'resource_purchases');


$success_message = '';
$error_message = '';

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'resources';

function handleFileUpload(array $file, string $uploadDir, string $prefix): string {
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $new_filename = uniqid($prefix . '_') . '_' . time() . '.' . $file_extension;
        $file_path = $uploadDir . $new_filename;
        
        if (move_uploaded_file($file_tmp, $file_path)) {
            return $file_path;
        } else {
            error_log("Failed to move uploaded file: " . $file_tmp . " to " . $file_path);
        }
    } else if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        error_log("File upload error: " . $file['error']);
    }
    return '';
}

// --- Xử lý thêm/sửa tài nguyên ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_resource'])) {
    $resource_id = isset($_POST['resource_id']) ? (int)$_POST['resource_id'] : 0;
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $original_price = floatval($_POST['original_price']); // Lấy giá gốc
    $discount_price = floatval($_POST['discount_price']); // Lấy giá giảm
    $price = ($discount_price > 0 && $discount_price < $original_price) ? $discount_price : $original_price; // Giá hiển thị/mua là giá giảm nếu hợp lệ, nếu không là giá gốc
    $category = trim($_POST['category']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $stock_quantity = isset($_POST['stock_quantity']) ? (int)$_POST['stock_quantity'] : -1;
    $allow_repurchase = isset($_POST['allow_repurchase']) ? 1 : 0;
    
    if (empty($title)) {
        $_SESSION['error_message'] = "Tên tài nguyên không được để trống.";
        header("Location: store_admin?tab=add" . ($resource_id ? "&edit=$resource_id" : ""));
        exit;
    }
    
    if ($original_price < 0 || $discount_price < 0) {
        $_SESSION['error_message'] = "Giá không được âm.";
        header("Location: store_admin?tab=add" . ($resource_id ? "&edit=$resource_id" : ""));
        exit;
    }

    $old_file_path = '';
    $old_preview_image = '';
    if ($resource_id > 0) {
        $get_old_paths_sql = "SELECT file_path, preview_image FROM resources WHERE id = ?";
        if ($stmt = $conn->prepare($get_old_paths_sql)) {
            $stmt->bind_param("i", $resource_id);
            $stmt->execute();
            $stmt->bind_result($old_file_path, $old_preview_image);
            $stmt->fetch();
            $stmt->close();
        }
    }
    
    $file_path = handleFileUpload($_FILES['resource_file'], 'uploads/resources/', 'resource');
    if (empty($file_path) && $resource_id > 0) {
        $file_path = $old_file_path;
    }

    $preview_image = handleFileUpload($_FILES['preview_image'], 'uploads/previews/', 'preview');
    if (empty($preview_image) && $resource_id > 0) {
        $preview_image = $old_preview_image;
    }
    
    if ($resource_id > 0) {
        // Cập nhật SQL để bao gồm original_price và discount_price
        $update_sql = "UPDATE resources SET title = ?, description = ?, price = ?, original_price = ?, discount_price = ?, category = ?, file_path = ?, preview_image = ?, stock_quantity = ?, allow_repurchase = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sdddsissiiii", $title, $description, $price, $original_price, $discount_price, $category, $file_path, $preview_image, $stock_quantity, $allow_repurchase, $is_active, $resource_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Cập nhật tài nguyên thành công!";
        } else {
            $_SESSION['error_message'] = "Lỗi khi cập nhật tài nguyên: " . $stmt->error;
        }
        $stmt->close();
    } else {
        // Thêm SQL để bao gồm original_price và discount_price
        $insert_sql = "INSERT INTO resources (title, description, price, original_price, discount_price, category, file_path, preview_image, stock_quantity, allow_repurchase, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("sdddsissiiii", $title, $description, $price, $original_price, $discount_price, $category, $file_path, $preview_image, $stock_quantity, $allow_repurchase, $is_active, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Thêm tài nguyên mới thành công!";
        } else {
            $_SESSION['error_message'] = "Lỗi khi thêm tài nguyên: " . $stmt->error;
        }
        $stmt->close();
    }
    
    header("Location: store_admin?tab=resources");
    exit;
}

// --- Xử lý xóa tài nguyên (giữ nguyên) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_resource'])) {
    $resource_id = (int)$_POST['resource_id'];
    
    $file_query = "SELECT file_path, preview_image FROM resources WHERE id = ?";
    if ($stmt = $conn->prepare($file_query)) {
        $stmt->bind_param("i", $resource_id);
        $stmt->execute();
        $file_result = $stmt->get_result();
        $file_data = $file_result->fetch_assoc();
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Lỗi khi lấy thông tin file để xóa: " . $conn->error;
        header("Location: store_admin?tab=resources");
        exit;
    }

    $delete_sql = "DELETE FROM resources WHERE id = ?";
    if ($stmt = $conn->prepare($delete_sql)) {
        $stmt->bind_param("i", $resource_id);
        
        if ($stmt->execute()) {
            if ($file_data['file_path'] && file_exists($file_data['file_path'])) {
                unlink($file_data['file_path']);
            }
            if ($file_data['preview_image'] && file_exists($file_data['preview_image'])) {
                unlink($file_data['preview_image']);
            }
            
            $_SESSION['success_message'] = "Xóa tài nguyên thành công!";
        } else {
            $_SESSION['error_message'] = "Lỗi khi xóa tài nguyên: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Lỗi khi chuẩn bị xóa tài nguyên: " . $conn->error;
    }
    
    header("Location: store_admin?tab=resources");
    exit;
}

// --- Xử lý cập nhật trạng thái thanh toán (giữ nguyên) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
    $purchase_id = (int)$_POST['purchase_id'];
    $payment_status = $_POST['payment_status'];
    $notes = trim($_POST['notes']);
    
    $update_sql = "UPDATE resource_purchases SET payment_status = ?, notes = ?";
    $params = [$payment_status, $notes];
    $types = "ss";
    
    if ($payment_status === 'completed') {
        $update_sql .= ", payment_date = NOW()";
    }
    
    $update_sql .= " WHERE id = ?";
    $params[] = $purchase_id;
    $types .= "i";
    
    if ($stmt = $conn->prepare($update_sql)) {
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Cập nhật trạng thái thanh toán thành công!";
        } else {
            $_SESSION['error_message'] = "Lỗi khi cập nhật trạng thái: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Lỗi khi chuẩn bị cập nhật trạng thái: " . $conn->error;
    }
    
    header("Location: store_admin?tab=purchases");
    exit;
}

function getResources(mysqli $conn): array {
    $resources = [];
    // Cập nhật truy vấn để lấy original_price và discount_price
    $sql = "SELECT r.*, COALESCE(u.username, 'Unknown') as creator_name
            FROM resources r
            LEFT JOIN users u ON r.created_by = u.id
            ORDER BY r.created_at DESC";
    
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $resources[] = $row;
        }
        $result->free();
    } else {
        error_log("Error fetching resources: " . $conn->error);
    }
    return $resources;
}

function getPurchases(mysqli $conn): array {
    $purchases = [];
    $sql = "SELECT p.*, r.title as resource_title, r.price as resource_price, u.username, u.full_name, u.email
            FROM resource_purchases p
            JOIN resources r ON p.resource_id = r.id
            JOIN users u ON p.user_id = u.id
            ORDER BY p.purchase_date DESC";
    
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $purchases[] = $row;
        }
        $result->free();
    } else {
        error_log("Error fetching purchases: " . $conn->error);
    }
    return $purchases;
}

$resources = getResources($conn);
$purchases = getPurchases($conn);

$edit_resource = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    // Cập nhật truy vấn để lấy original_price và discount_price
    $edit_query = "SELECT * FROM resources WHERE id = ?";
    if ($stmt = $conn->prepare($edit_query)) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $edit_result = $stmt->get_result();
        $edit_resource = $edit_result->fetch_assoc();
        $stmt->close();
    } else {
        error_log("Error preparing edit resource query: " . $conn->error);
    }
    $active_tab = 'add';
}

function formatCurrency(float $amount): string {
    return number_format($amount, 0, ',', '.') . ' ₫';
}

function formatPaymentStatus(string $status): string {
    switch ($status) {
        case 'pending':
            return '<span class="status-badge pending"><i class="fas fa-clock"></i> Chờ thanh toán</span>';
        case 'completed':
            return '<span class="status-badge completed"><i class="fas fa-check-circle"></i> Đã thanh toán</span>';
        case 'failed':
            return '<span class="status-badge failed"><i class="fas fa-times-circle"></i> Thất bại</span>';
        case 'refunded':
            return '<span class="status-badge refunded"><i class="fas fa-undo"></i> Đã hoàn tiền</span>';
        default:
            return '<span class="status-badge">' . htmlspecialchars($status) . '</span>';
    }
}

function generatePaymentQR(float $amount, string $description, int $purchaseId): array {
    $bank_info = [
        'bank' => 'MBbank',
        'account_number' => '9999999997706',
        'account_name' => 'NGUYEN QUOC MINH',
        'amount' => $amount,
        'description' => "Thanh toan don hang #$purchaseId - $description"
    ];
    
    $qr_content = "Ngân hàng: {$bank_info['bank']}\n";
    $qr_content .= "Số tài khoản: {$bank_info['account_number']}\n";
    $qr_content .= "Tên tài khoản: {$bank_info['account_name']}\n";
    $qr_content .= "Số tiền: " . number_format($amount, 0, ',', '.') . " VND\n";
    $qr_content .= "Nội dung: {$bank_info['description']}";
    
    return [
        'content' => $qr_content,
        'bank_info' => $bank_info
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý tài nguyên</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Màu chính */
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            --success: #10B981;
            --success-light: #34D399;
            --success-dark: #059669;
            --warning: #F59E0B;
            --warning-light: #FBBF24;
            --warning-dark: #D97706;
            --danger: #FF3D57;
            --danger-light: #FF5D77;
            --danger-dark: #E01F3D;
            
            /* Màu nền và text */
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            
            /* Màu card */
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            
            /* Border và shadow */
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.4);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --glow-accent: 0 0 20px rgba(255, 61, 255, 0.5);
            --glow-secondary: 0 0 20px rgba(0, 224, 255, 0.5);
            --glow-success: 0 0 20px rgba(16, 185, 129, 0.5);
            --glow-warning: 0 0 20px rgba(245, 158, 11, 0.5);
            --glow-danger: 0 0 20px rgba(255, 61, 87, 0.5);
            
            /* Border radius */
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
            --radius-xl: 3rem;
            --radius-full: 9999px;
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

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-light), var(--secondary-light));
        }

        /* Main layout */
        .resource-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-to-dashboard {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-dashboard:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Message styles */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border-left: 4px solid #FF3D57;
        }

        .error-message::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .success-message::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Tab navigation */
        .tab-navigation {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .tab-navigation::-webkit-scrollbar {
            display: none;
        }

        .tab-button {
            padding: 0.875rem 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .tab-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.1), rgba(0, 224, 255, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .tab-button:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .tab-button:hover::before {
            opacity: 1;
        }

        .tab-button.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-color: var(--primary-light);
            color: white;
            box-shadow: var(--glow);
        }

        .tab-button.active::before {
            display: none;
        }

        .tab-button i {
            font-size: 1.125rem;
        }

        .tab-button .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: var(--accent);
            color: white;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }

        /* Tab content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .section-header {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary-light);
        }

        .section-subtitle {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-top: 0.25rem;
            max-width: 600px;
        }

        /* Form styles */
        .form-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--primary-light);
        }

        .form-input,
        .form-textarea,
        .form-select {
            width: 100%;
            padding: 0.875rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-input-display {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-display:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
        }

        .file-input-icon {
            color: var(--primary-light);
        }

        .file-input-text {
            color: var(--foreground-muted);
            font-size: 0.875rem;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .checkbox-input {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-radius: 4px;
            outline: none;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .checkbox-input:checked {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkbox-input:checked:after {
            content: '\f00c';
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.75rem;
        }

        .checkbox-label {
            font-size: 0.875rem;
            color: var(--foreground);
            cursor: pointer;
        }

        /* Button styles */
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            border: none;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.3);
        }

        .btn-secondary:hover {
            background: linear-gradient(90deg, var(--secondary-light), var(--secondary));
            box-shadow: 0 6px 16px rgba(0, 224, 255, 0.4);
        }

        .btn-success {
            background: linear-gradient(90deg, var(--success), var(--success-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(90deg, var(--success-light), var(--success));
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }

        .btn-warning {
            background: linear-gradient(90deg, var(--warning), var(--warning-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-warning:hover {
            background: linear-gradient(90deg, var(--warning-light), var(--warning));
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
        }

        .btn-danger {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(90deg, var(--danger-light), var(--danger));
            box-shadow: 0 6px 16px rgba(255, 61, 87, 0.4);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Resource grid */
        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .resource-card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .resource-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.05) 0%, transparent 50%, rgba(0, 224, 255, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .resource-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }

        .resource-card:hover::before {
            opacity: 1;
        }

        .resource-image {
            width: 100%;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .resource-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .resource-image-placeholder {
            font-size: 3rem;
            color: var(--primary-light);
            opacity: 0.5;
        }

        .resource-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border);
        }

        .resource-title {
            font-weight: 600;
            font-size: 1.125rem;
            color: var(--foreground);
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .resource-category {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            display: inline-block;
        }

        .resource-body {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .resource-description {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            line-height: 1.6;
            max-height: none;
            overflow: visible;
            display: block;
            -webkit-line-clamp: unset;
            -webkit-box-orient: unset;
        }

        .resource-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .resource-price i {
            font-size: 1.25rem;
        }

        .resource-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .resource-status {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .status-active {
            color: var(--success);
        }

        .status-inactive {
            color: var(--danger);
        }

        .resource-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.75rem;
        }

        .resource-action {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            border: none;
            color: white;
        }

        /* Purchase table */
        .table-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--foreground);
            font-size: 0.875rem;
        }

        .table td {
            color: var(--foreground-muted);
            font-size: 0.875rem;
        }

        .table tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-badge.completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-badge.failed {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
        }

        .status-badge.refunded {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground-muted);
        }

        /* QR Code display */
        .qr-display {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            margin-top: 1rem;
        }

        .qr-content {
            background: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            font-family: monospace;
            font-size: 0.875rem;
            color: #333;
            white-space: pre-line;
            line-height: 1.6;
        }

        .bank-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        .bank-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .bank-info-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .bank-info-value {
            font-size: 0.875rem;
            color: var(--foreground);
            font-weight: 500;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .modal-overlay.active .modal-container {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
        }

        /* Empty state */
        .empty-state {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 3rem 2rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--primary-light);
            opacity: 0.7;
            animation: pulse 3s infinite ease-in-out;
        }

        @keyframes pulse {
            0% { opacity: 0.5; transform: scale(0.95); }
            50% { opacity: 0.7; transform: scale(1); }
            100% { opacity: 0.5; transform: scale(0.95); }
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .empty-description {
            font-size: 1rem;
            color: var(--foreground-muted);
            max-width: 500px;
            margin: 0 auto;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .resource-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .resource-container {
                padding: 0 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .back-to-dashboard {
                width: 100%;
                justify-content: center;
            }

            .tab-navigation {
                flex-wrap: wrap;
            }

            .tab-button {
                flex: 1;
                justify-content: center;
            }

            .resource-grid {
                grid-template-columns: 1fr;
            }

            .form-section {
                padding: 1.5rem;
            }

            .table-container {
                overflow-x: auto;
            }

            .bank-info {
                grid-template-columns: 1fr;
            }

            .modal-container {
                padding: 1.5rem;
                margin: 1rem;
            }
        }

        @media (max-width: 480px) {
            .resource-footer {
                flex-direction: column;
            }

            .resource-action {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="resource-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-store"></i>Quản lý tài nguyên</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="tab-navigation">
            <a href="store_admin?tab=resources" class="tab-button <?php echo $active_tab == 'resources' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i> Tài nguyên
                <?php if (count($resources) > 0): ?>
                <span class="badge"><?php echo count($resources); ?></span>
                <?php endif; ?>
            </a>
            <a href="store_admin?tab=add" class="tab-button <?php echo $active_tab == 'add' ? 'active' : ''; ?>">
                <i class="fas fa-plus"></i> Thêm tài nguyên
            </a>
            <a href="store_admin?tab=purchases" class="tab-button <?php echo $active_tab == 'purchases' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Đơn hàng
                <?php if (count($purchases) > 0): ?>
                <span class="badge"><?php echo count($purchases); ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="tab-content <?php echo $active_tab == 'resources' ? 'active' : ''; ?>" id="resources-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-box"></i> Danh sách tài nguyên</h2>
                    <p class="section-subtitle">Quản lý tất cả tài nguyên có sẵn trong hệ thống.</p>
                </div>
                <a href="store_admin?tab=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Thêm tài nguyên
                </a>
            </div>

            <?php if (empty($resources)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open empty-icon"></i>
                <h3 class="empty-title">Chưa có tài nguyên nào</h3>
                <p class="empty-description">Bạn chưa thêm tài nguyên nào. Hãy thêm tài nguyên đầu tiên để bắt đầu bán hàng.</p>
                <div class="empty-action">
                    <a href="store_admin?tab=add" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Thêm tài nguyên
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="resource-grid">
                <?php foreach ($resources as $resource): ?>
                <?php
                // Xác định giá hiển thị cho admin
                $display_price_admin = $resource['price'];
                $has_discount_admin = ($resource['discount_price'] > 0 && $resource['discount_price'] < $resource['original_price']);
                if ($has_discount_admin) {
                    $display_price_admin = $resource['discount_price'];
                }
                ?>
                <div class="resource-card">
                    <div class="resource-image">
                        <?php if ($resource['preview_image'] && file_exists($resource['preview_image'])): ?>
                            <img src="<?php echo htmlspecialchars($resource['preview_image']); ?>" alt="<?php echo htmlspecialchars($resource['title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image resource-image-placeholder"></i>
                        <?php endif; ?>
                    </div>
                    <div class="resource-header">
                        <div class="resource-title"><?php echo htmlspecialchars($resource['title']); ?></div>
                        <?php if ($resource['category']): ?>
                        <div class="resource-category"><?php echo htmlspecialchars($resource['category']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="resource-body">
                        <?php if ($resource['description']): ?>
                        <div class="resource-description"><?php echo htmlspecialchars($resource['description']); ?></div>
                        <?php endif; ?>
                        
                        <div class="resource-price-group">
                            <?php if ($has_discount_admin): ?>
                                <span class="resource-price-original"><?php echo formatCurrency($resource['original_price']); ?></span>
                            <?php endif; ?>
                            <span class="resource-price-current">
                                <i class="fas fa-tag"></i>
                                <?php echo formatCurrency($display_price_admin); ?>
                            </span>
                        </div>

                        <div class="resource-meta">
                            <div class="resource-status">
                                <i class="fas fa-<?php echo $resource['is_active'] ? 'check-circle status-active' : 'times-circle status-inactive'; ?>"></i>
                                <?php echo $resource['is_active'] ? 'Đang bán' : 'Tạm dừng'; ?>
                            </div>
                            <div>
                                <?php if ($resource['stock_quantity'] == -1): ?>
                                    Không giới hạn
                                <?php elseif ($resource['stock_quantity'] == 0): ?>
                                    <span style="color: var(--danger);">Hết hàng</span>
                                <?php else: ?>
                                    Còn <?php echo $resource['stock_quantity']; ?>
                                <?php endif; ?>
                            </div>
                            <div><i class="fas fa-download"></i> <?php echo $resource['download_count']; ?> lượt tải</div>
                        </div>
                    </div>
                    <div class="resource-footer">
                        <a href="store_admin?tab=add&edit=<?php echo $resource['id']; ?>" class="resource-action btn-warning">
                            <i class="fas fa-edit"></i> Sửa
                        </a>
                        <?php if ($resource['file_path'] && file_exists($resource['file_path'])): ?>
                        <a href="<?php echo htmlspecialchars($resource['file_path']); ?>" class="resource-action btn-secondary" download>
                            <i class="fas fa-download"></i> Tải
                        </a>
                        <?php endif; ?>
                        <button type="button" class="resource-action btn-danger" onclick="confirmDeleteResource(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars($resource['title']); ?>')">
                            <i class="fas fa-trash-alt"></i> Xóa
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="tab-content <?php echo $active_tab == 'add' ? 'active' : ''; ?>" id="add-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-<?php echo $edit_resource ? 'edit' : 'plus'; ?>"></i> 
                        <?php echo $edit_resource ? 'Chỉnh sửa tài nguyên' : 'Thêm tài nguyên mới'; ?>
                    </h2>
                    <p class="section-subtitle">
                        <?php echo $edit_resource ? 'Cập nhật thông tin tài nguyên.' : 'Thêm tài nguyên mới vào hệ thống để bán.'; ?>
                    </p>
                </div>
            </div>

            <div class="form-section">
                <form method="POST" enctype="multipart/form-data">
                    <?php if ($edit_resource): ?>
                    <input type="hidden" name="resource_id" value="<?php echo $edit_resource['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-heading"></i> Tên tài nguyên</label>
                            <input type="text" name="title" class="form-input" 
                                   value="<?php echo $edit_resource ? htmlspecialchars($edit_resource['title']) : ''; ?>" 
                                   placeholder="Nhập tên tài nguyên..." required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-tag"></i> Danh mục</label>
                            <input type="text" name="category" class="form-input" 
                                   value="<?php echo $edit_resource ? htmlspecialchars($edit_resource['category']) : ''; ?>" 
                                   placeholder="Ví dụ: Tài liệu, Video, Phần mềm...">
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label"><i class="fas fa-align-left"></i> Mô tả</label>
                        <textarea name="description" class="form-textarea" 
                                  placeholder="Mô tả chi tiết về tài nguyên..."><?php echo $edit_resource ? htmlspecialchars($edit_resource['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-dollar-sign"></i> Giá gốc (VNĐ)</label>
                            <input type="number" name="original_price" class="form-input" min="0" step="1000" 
                                   value="<?php echo $edit_resource ? $edit_resource['original_price'] : '0'; ?>" 
                                   placeholder="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-percentage"></i> Giá giảm (VNĐ)</label>
                            <input type="number" name="discount_price" class="form-input" min="0" step="1000" 
                                   value="<?php echo $edit_resource ? $edit_resource['discount_price'] : '0'; ?>" 
                                   placeholder="0">
                            <small style="color: var(--foreground-muted); font-size: 0.75rem; margin-top: 0.25rem;">
                                Nếu đặt giá giảm, giá này sẽ được sử dụng nếu nhỏ hơn giá gốc và lớn hơn 0.
                            </small>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-boxes"></i> Số lượng tồn kho</label>
                            <input type="number" name="stock_quantity" class="form-input" min="-1" 
                                   value="<?php echo $edit_resource ? $edit_resource['stock_quantity'] : '-1'; ?>" 
                                   placeholder="-1 (Không giới hạn)">
                            <small style="color: var(--foreground-muted); font-size: 0.75rem; margin-top: 0.25rem;">
                                -1 = Không giới hạn, 0 = Hết hàng, >0 = Số lượng cụ thể
                            </small>
                        </div>
                        <div class="form-group">
                             <div class="checkbox-wrapper" style="margin-top: 2rem;">
                                <input type="checkbox" name="allow_repurchase" class="checkbox-input" id="allow-repurchase" 
                                       <?php echo ($edit_resource && $edit_resource['allow_repurchase']) ? 'checked' : ''; ?>>
                                <label for="allow-repurchase" class="checkbox-label">Cho phép mua lại</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-file"></i> File tài nguyên</label>
                            <div class="file-input-wrapper">
                                <input type="file" name="resource_file" class="file-input" id="resource-file">
                                <div class="file-input-display">
                                    <i class="fas fa-upload file-input-icon"></i>
                                    <span class="file-input-text" id="resource-file-text">
                                        <?php if ($edit_resource && $edit_resource['file_path']): ?>
                                            File hiện tại: <?php echo basename($edit_resource['file_path']); ?>
                                        <?php else: ?>
                                            Chọn file tài nguyên
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-image"></i> Ảnh preview</label>
                        <div class="file-input-wrapper">
                            <input type="file" name="preview_image" class="file-input" id="preview-image" accept="image/*">
                            <div class="file-input-display">
                                <i class="fas fa-image file-input-icon"></i>
                                <span class="file-input-text" id="preview-image-text">
                                    <?php if ($edit_resource && $edit_resource['preview_image']): ?>
                                        Ảnh hiện tại: <?php echo basename($edit_resource['preview_image']); ?>
                                    <?php else: ?>
                                        Chọn ảnh preview (tùy chọn)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" class="checkbox-input" id="is-active" 
                               <?php echo (!$edit_resource || $edit_resource['is_active']) ? 'checked' : ''; ?>>
                        <label for="is-active" class="checkbox-label">Kích hoạt bán hàng</label>
                    </div>
                    
                    <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                        <button type="submit" name="save_resource" class="btn btn-primary">
                            <i class="fas fa-save"></i> 
                            <?php echo $edit_resource ? 'Cập nhật' : 'Thêm mới'; ?>
                        </button>
                        <a href="store_admin?tab=resources" class="btn btn-outline">
                            <i class="fas fa-times"></i> Hủy bỏ
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="tab-content <?php echo $active_tab == 'purchases' ? 'active' : ''; ?>" id="purchases-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-shopping-cart"></i> Quản lý đơn hàng</h2>
                    <p class="section-subtitle">Theo dõi và quản lý các đơn hàng mua tài nguyên.</p>
                </div>
            </div>

            <?php if (empty($purchases)): ?>
            <div class="empty-state">
                <i class="fas fa-shopping-cart empty-icon"></i>
                <h3 class="empty-title">Chưa có đơn hàng nào</h3>
                <p class="empty-description">Chưa có ai mua tài nguyên của bạn. Hãy chia sẻ tài nguyên để thu hút khách hàng.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tài nguyên</th>
                            <th>Khách hàng</th>
                            <th>Số tiền</th>
                            <th>Trạng thái</th>
                            <th>Ngày mua</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td>#<?php echo $purchase['id']; ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($purchase['resource_title']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--foreground-subtle);">
                                    <?php echo formatCurrency($purchase['resource_price']); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($purchase['username']); ?></div>
                                <?php if ($purchase['full_name']): ?>
                                <div style="font-size: 0.75rem; color: var(--foreground-subtle);">
                                    <?php echo htmlspecialchars($purchase['full_name']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($purchase['email']): ?>
                                <div style="font-size: 0.75rem; color: var(--foreground-subtle);">
                                    <?php echo htmlspecialchars($purchase['email']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: var(--success);">
                                <?php echo formatCurrency($purchase['purchase_amount']); ?>
                            </td>
                            <td><?php echo formatPaymentStatus($purchase['payment_status']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($purchase['purchase_date'])); ?></td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="showPaymentDetails(<?php echo $purchase['id']; ?>, '<?php echo htmlspecialchars($purchase['resource_title']); ?>', <?php echo $purchase['purchase_amount']; ?>)">
                                        <i class="fas fa-qrcode"></i> QR
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success" 
                                            onclick="updatePaymentStatus(<?php echo $purchase['id']; ?>, '<?php echo $purchase['payment_status']; ?>', '<?php echo htmlspecialchars($purchase['notes'] ?? ''); ?>')">
                                        <i class="fas fa-edit"></i> Cập nhật
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="qr-modal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-qrcode"></i> Thông tin thanh toán
                </div>
                <button type="button" class="modal-close" onclick="closeQRModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="qr-content">
                </div>
        </div>
    </div>

    <div class="modal-overlay" id="payment-modal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-edit"></i> Cập nhật trạng thái thanh toán
                </div>
                <button type="button" class="modal-close" onclick="closePaymentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="payment-form">
                <input type="hidden" name="purchase_id" id="payment-purchase-id">
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-check-circle"></i> Trạng thái thanh toán</label>
                    <select name="payment_status" class="form-select" id="payment-status">
                        <option value="pending">Chờ thanh toán</option>
                        <option value="completed">Đã thanh toán</option>
                        <option value="failed">Thất bại</option>
                        <option value="refunded">Đã hoàn tiền</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-comment"></i> Ghi chú</label>
                    <textarea name="notes" class="form-textarea" id="payment-notes" 
                              placeholder="Ghi chú về trạng thái thanh toán..."></textarea>
                </div>
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" onclick="closePaymentModal()">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="submit" name="update_payment_status" class="btn btn-success">
                        <i class="fas fa-save"></i> Cập nhật
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="delete-modal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Xác nhận xóa
                </div>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <p>Bạn có chắc chắn muốn xóa tài nguyên "<span id="delete-resource-name"></span>"?</p>
                <p style="color: var(--danger); margin-top: 0.5rem;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Hành động này không thể hoàn tác và sẽ xóa tất cả dữ liệu liên quan.
                </p>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Hủy
                </button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="resource_id" id="delete-resource-id">
                    <button type="submit" name="delete_resource" class="btn btn-danger">
                        <i class="fas fa-trash-alt"></i> Xóa tài nguyên
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileInputs = document.querySelectorAll('.file-input');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const display = this.parentElement.querySelector('.file-input-text');
                    if (this.files.length > 0) {
                        display.textContent = this.files[0].name;
                    }
                });
            });

            const messages = document.querySelectorAll('.error-message, .success-message');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        });

        function showPaymentDetails(purchaseId, resourceTitle, amount) {
            const modal = document.getElementById('qr-modal');
            const content = document.getElementById('qr-content');
            
            const bankInfo = {
                bank: 'MBbank',
                account_number: '9999999997706',
                account_name: 'NGUYEN QUOC MINH'
            };

            const formattedAmount = formatCurrency(amount);
            const qrContentText = `Ngân hàng: ${bankInfo.bank}\nSố tài khoản: ${bankInfo.account_number}\nTên tài khoản: ${bankInfo.account_name}\nSố tiền: ${formattedAmount}\nNội dung: Thanh toan don hang #${purchaseId} - ${resourceTitle}`;
            
            content.innerHTML = `
                <div class="qr-display">
                    <h3 style="margin-bottom: 1rem; color: var(--foreground);">
                        <i class="fas fa-shopping-cart"></i> Đơn hàng #${purchaseId}
                    </h3>
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--primary-light);">
                        ${resourceTitle}
                    </div>
                    <div class="qr-content">${qrContentText}</div>
                    <div class="bank-info">
                        <div class="bank-info-item">
                            <div class="bank-info-label">Ngân hàng</div>
                            <div class="bank-info-value">${bankInfo.bank}</div>
                        </div>
                        <div class="bank-info-item">
                            <div class="bank-info-label">Số tài khoản</div>
                            <div class="bank-info-value">${bankInfo.account_number}</div>
                        </div>
                        <div class="bank-info-item">
                            <div class="bank-info-label">Tên tài khoản</div>
                            <div class="bank-info-value">${bankInfo.account_name}</div>
                        </div>
                        <div class="bank-info-item">
                            <div class="bank-info-label">Số tiền</div>
                            <div class="bank-info-value" style="color: var(--success); font-weight: 600;">
                                ${formattedAmount}
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: var(--radius-sm); color: var(--warning);">
                        <i class="fas fa-info-circle"></i> 
                        Khách hàng cần chuyển khoản đúng số tiền và nội dung để được xử lý tự động.
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
        }

        function closeQRModal() {
            document.getElementById('qr-modal').classList.remove('active');
        }

        function updatePaymentStatus(purchaseId, currentStatus, currentNotes) {
            document.getElementById('payment-purchase-id').value = purchaseId;
            document.getElementById('payment-status').value = currentStatus;
            document.getElementById('payment-notes').value = currentNotes;
            document.getElementById('payment-modal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('payment-modal').classList.remove('active');
        }

        function confirmDeleteResource(resourceId, resourceName) {
            document.getElementById('delete-resource-id').value = resourceId;
            document.getElementById('delete-resource-name').textContent = resourceName;
            document.getElementById('delete-modal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.remove('active');
        }

        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(amount);
        }

        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal-overlay');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const activeModals = document.querySelectorAll('.modal-overlay.active');
                activeModals.forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
mysqli_close($conn);
?>