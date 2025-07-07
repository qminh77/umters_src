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

$user_data = [];
$user_query = "SELECT username, full_name, email FROM users WHERE id = ?";
if ($stmt = $conn->prepare($user_query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    $stmt->close();
} else {
    error_log("Error preparing user query: " . $conn->error);
    $_SESSION['error_message'] = "Có lỗi xảy ra khi lấy thông tin người dùng.";
    header("Location: dashboard.php");
    exit;
}

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

function getActiveResources(mysqli $conn): array {
    $resources = [];
    $sql = "SELECT r.*, u.username as creator_name
            FROM resources r
            LEFT JOIN users u ON r.created_by = u.id
            WHERE r.is_active = 1
            ORDER BY r.created_at DESC";
    
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $resources[] = $row;
        }
        $result->free();
    } else {
        error_log("Error fetching active resources: " . $conn->error);
    }
    return $resources;
}

function getUserPurchases(mysqli $conn, int $userId): array {
    $purchases = [];
    $sql = "SELECT p.*, r.title as resource_title, r.file_path, r.preview_image
            FROM resource_purchases p
            JOIN resources r ON p.resource_id = r.id
            WHERE p.user_id = ?
            ORDER BY p.purchase_date DESC";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $purchases[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Error preparing user purchases query: " . $conn->error);
    }
    return $purchases;
}

function getUserPurchasedResources(mysqli $conn, int $userId): array {
    $purchased = [];
    $sql = "SELECT resource_id, COUNT(*) as purchase_count FROM resource_purchases WHERE user_id = ? AND payment_status = 'completed' GROUP BY resource_id";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $purchased[$row['resource_id']] = $row['purchase_count'];
        }
        $stmt->close();
    } else {
        error_log("Error preparing purchased resources query: " . $conn->error);
    }
    return $purchased;
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


// --- Xử lý mua tài nguyên ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_resource'])) {
    $resource_id = (int)$_POST['resource_id'];
    
    $conn->begin_transaction();
    
    try {
        $resource = null;
        $resource_query = "SELECT id, title, price, original_price, discount_price, stock_quantity, allow_repurchase FROM resources WHERE id = ? AND is_active = 1 FOR UPDATE";
        if ($stmt = $conn->prepare($resource_query)) {
            $stmt->bind_param("i", $resource_id);
            $stmt->execute();
            $resource_result = $stmt->get_result();
            $resource = $resource_result->fetch_assoc();
            $stmt->close();
        } else {
            throw new Exception("Lỗi khi chuẩn bị truy vấn tài nguyên: " . $conn->error);
        }

        if (!$resource) {
            throw new Exception("Tài nguyên không tồn tại hoặc đã bị vô hiệu hóa.");
        }
        
        if ($resource['stock_quantity'] === 0) {
            throw new Exception("Tài nguyên này đã hết hàng.");
        }
        
        $check_purchase_sql = "SELECT id FROM resource_purchases WHERE user_id = ? AND resource_id = ? AND payment_status = 'completed'";
        if ($stmt = $conn->prepare($check_purchase_sql)) {
            $stmt->bind_param("ii", $user_id, $resource_id);
            $stmt->execute();
            $existing_purchase = $stmt->get_result();
            $stmt->close();

            if ($existing_purchase->num_rows > 0 && !$resource['allow_repurchase']) {
                throw new Exception("Bạn đã mua tài nguyên này rồi và không thể mua lại.");
            }
        } else {
            throw new Exception("Lỗi khi chuẩn bị kiểm tra giao dịch cũ: " . $conn->error);
        }
        
        $actual_price = ($resource['discount_price'] > 0 && $resource['discount_price'] < $resource['original_price']) ? $resource['discount_price'] : $resource['original_price']; // Lỗi logic ở đây, nên là price nếu không có original_price

        $insert_purchase_sql = "INSERT INTO resource_purchases (resource_id, user_id, purchase_amount, payment_status) VALUES (?, ?, ?, 'pending')";
        if ($stmt = $conn->prepare($insert_purchase_sql)) {
            $stmt->bind_param("iid", $resource_id, $user_id, $actual_price);
            
            if (!$stmt->execute()) {
                throw new Exception("Lỗi khi tạo đơn hàng: " . $stmt->error);
            }
            
            $purchase_id = $stmt->insert_id;
            $stmt->close();
        } else {
            throw new Exception("Lỗi khi chuẩn bị tạo đơn hàng: " . $conn->error);
        }

        if ($resource['stock_quantity'] > 0) {
            $update_stock_sql = "UPDATE resources SET stock_quantity = stock_quantity - 1 WHERE id = ?";
            if ($stmt = $conn->prepare($update_stock_sql)) {
                $stmt->bind_param("i", $resource_id);
                if (!$stmt->execute()) {
                    throw new Exception("Lỗi khi cập nhật tồn kho: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Lỗi khi chuẩn bị cập nhật tồn kho: " . $conn->error);
            }
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Đơn hàng đã được tạo thành công! Vui lòng thanh toán để hoàn tất.";
        header("Location: store?purchase_id=" . $purchase_id);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: store");
        exit;
    }
}

$purchase_detail = null;
if (isset($_GET['purchase_id']) && is_numeric($_GET['purchase_id'])) {
    $purchase_id_get = (int)$_GET['purchase_id'];
    $purchase_query = "SELECT p.*, r.title as resource_title
                       FROM resource_purchases p
                       JOIN resources r ON p.resource_id = r.id
                       WHERE p.id = ? AND p.user_id = ?";
    if ($stmt = $conn->prepare($purchase_query)) {
        $stmt->bind_param("ii", $purchase_id_get, $user_id);
        $stmt->execute();
        $purchase_result = $stmt->get_result();
        $purchase_detail = $purchase_result->fetch_assoc();
        $stmt->close();
    } else {
        error_log("Error preparing purchase detail query: " . $conn->error);
    }
}

$resources = getActiveResources($conn);
$user_purchases = getUserPurchases($conn, $user_id);
$user_purchased_resources = getUserPurchasedResources($conn, $user_id);

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cửa hàng tài nguyên</title>
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
        .store-container {
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--foreground);
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--foreground-muted);
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

        .btn-disabled {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground-subtle);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-disabled:hover {
            transform: none;
            box-shadow: none;
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

        .resource-card.purchased {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
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

        .purchased-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: var(--success);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
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

        /* CSS cho mô tả ẩn bớt */
        .resource-description {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            line-height: 1.6;
            margin-bottom: 0.5rem; /* Điều chỉnh khoảng cách */
            white-space: pre-wrap; /* Giữ nguyên xuống dòng từ DB */
            word-wrap: break-word; /* Ngắt từ dài */
            overflow: hidden;
            position: relative; /* Cho phép tạo gradient overlay */
        }

        .resource-description.collapsed {
            max-height: 4.8em; /* Khoảng 3 dòng * 1.6 line-height */
            -webkit-line-clamp: 3; /* Cắt 3 dòng cho WebKit browsers */
            -webkit-box-orient: vertical;
            display: -webkit-box;
        }
        
        /* Tạo hiệu ứng mờ dần ở cuối mô tả bị cắt */
        .resource-description.collapsed::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2em; /* Chiều cao của hiệu ứng mờ */
            background: linear-gradient(to top, var(--card) 0%, transparent 100%);
            pointer-events: none;
        }


        .description-toggle {
            color: var(--primary-light);
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.3s ease;
            text-decoration: none; /* Bỏ gạch chân mặc định của a */
        }

        .description-toggle:hover {
            color: var(--primary);
        }

        .resource-price-group {
            display: flex;
            align-items: baseline; /* Căn chỉnh theo baseline để dễ nhìn */
            gap: 0.75rem;
        }

        .resource-price-original {
            font-size: 1rem;
            color: var(--foreground-muted);
            text-decoration: line-through;
            font-weight: 500;
        }

        .resource-price-current {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success); /* Hoặc màu accent khác nếu muốn nổi bật giá giảm */
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .resource-price-current i {
            font-size: 1.25rem;
        }

        .resource-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .resource-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.75rem;
        }

        .resource-action {
            flex: 1;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
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
        @media (max-width: 992px) {
            .resource-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .store-container {
                padding: 0 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .user-info {
                width: 100%;
                justify-content: space-between;
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
    <div class="store-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-store"></i>Cửa hàng tài nguyên</h1>
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user_data['username'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <?php if ($user_data['email']): ?>
                    <div class="user-email"><?php echo htmlspecialchars($user_data['email']); ?></div>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php" class="back-to-dashboard">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
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

        <?php if ($purchase_detail): ?>
        <div class="qr-display">
            <h3 style="margin-bottom: 1rem; color: var(--foreground);">
                <i class="fas fa-shopping-cart"></i> Đơn hàng #<?php echo $purchase_detail['id']; ?>
            </h3>
            <div style="margin-bottom: 1rem; font-weight: 600; color: var(--primary-light);">
                <?php echo htmlspecialchars($purchase_detail['resource_title']); ?>
            </div>
            <?php 
            $qr_data = generatePaymentQR($purchase_detail['purchase_amount'], $purchase_detail['resource_title'], $purchase_detail['id']);
            ?>
            <div class="qr-content"><?php echo $qr_data['content']; ?></div>
            <div class="bank-info">
                <div class="bank-info-item">
                    <div class="bank-info-label">Ngân hàng</div>
                    <div class="bank-info-value"><?php echo $qr_data['bank_info']['bank']; ?></div>
                </div>
                <div class="bank-info-item">
                    <div class="bank-info-label">Số tài khoản</div>
                    <div class="bank-info-value"><?php echo $qr_data['bank_info']['account_number']; ?></div>
                </div>
                <div class="bank-info-item">
                    <div class="bank-info-label">Tên tài khoản</div>
                    <div class="bank-info-value"><?php echo $qr_data['bank_info']['account_name']; ?></div>
                </div>
                <div class="bank-info-item">
                    <div class="bank-info-label">Số tiền</div>
                    <div class="bank-info-value" style="color: var(--success); font-weight: 600;">
                        <?php echo formatCurrency($purchase_detail['purchase_amount']); ?>
                    </div>
                </div>
            </div>
            <div style="margin-top: 1rem; padding: 1rem; background: rgba(245, 158, 11, 0.1); border-radius: var(--radius-sm); color: var(--warning);">
                <i class="fas fa-info-circle"></i> 
                Vui lòng chuyển khoản đúng số tiền và nội dung để được xử lý tự động.
            </div>
        </div>
        <?php endif; ?>

        <div class="tab-navigation">
            <a href="#" class="tab-button active" onclick="showTab(event, 'store')">
                <i class="fas fa-store"></i> Cửa hàng
                <?php if (count($resources) > 0): ?>
                <span class="badge"><?php echo count($resources); ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="tab-button" onclick="showTab(event, 'purchases')">
                <i class="fas fa-shopping-bag"></i> Đơn hàng của tôi
                <?php if (count($user_purchases) > 0): ?>
                <span class="badge"><?php echo count($user_purchases); ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="tab-content active" id="store-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-store"></i> Tài nguyên có sẵn</h2>
                    <p class="section-subtitle">Khám phá và mua các tài nguyên chất lượng cao.</p>
                </div>
            </div>

            <?php if (empty($resources)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open empty-icon"></i>
                <h3 class="empty-title">Chưa có tài nguyên nào</h3>
                <p class="empty-description">Hiện tại chưa có tài nguyên nào được bán. Vui lòng quay lại sau.</p>
            </div>
            <?php else: ?>
            <div class="resource-grid">
                <?php foreach ($resources as $resource): ?>
                <?php
                $is_purchased_completed = isset($user_purchased_resources[$resource['id']]);
                $can_purchase = !$is_purchased_completed || $resource['allow_repurchase'];
                $is_out_of_stock = $resource['stock_quantity'] === 0;

                $has_completed_purchase_for_download = false;
                foreach ($user_purchases as $purchase_item) {
                    if ($purchase_item['resource_id'] == $resource['id'] && $purchase_item['payment_status'] == 'completed') {
                        $has_completed_purchase_for_download = true;
                        break;
                    }
                }

                $display_price = $resource['price'];
                $has_discount = ($resource['discount_price'] > 0 && $resource['discount_price'] < $resource['original_price']);
                if ($has_discount) {
                    $display_price = $resource['discount_price'];
                }
                ?>
                <div class="resource-card <?php echo $is_purchased_completed ? 'purchased' : ''; ?>">
                    <div class="resource-image">
                        <?php if ($resource['preview_image'] && file_exists($resource['preview_image'])): ?>
                            <img src="<?php echo htmlspecialchars($resource['preview_image']); ?>" alt="<?php echo htmlspecialchars($resource['title']); ?>">
                        <?php else: ?>
                            <i class="fas fa-image resource-image-placeholder"></i>
                        <?php endif; ?>
                        <?php if ($is_purchased_completed): ?>
                        <div class="purchased-badge">
                            <i class="fas fa-check"></i> Đã mua
                        </div>
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
                        <div class="resource-description collapsed" id="desc-<?php echo $resource['id']; ?>">
                            <?php echo nl2br(htmlspecialchars($resource['description'])); ?>
                        </div>
                        <?php if (strlen($resource['description']) > 150): // Chỉ hiển thị nút "Xem thêm" nếu mô tả dài hơn 150 ký tự ?>
                        <div class="description-toggle" onclick="toggleDescription(<?php echo $resource['id']; ?>)">
                            <span id="toggle-text-<?php echo $resource['id']; ?>">Xem thêm</span>
                            <i class="fas fa-chevron-down" id="toggle-icon-<?php echo $resource['id']; ?>"></i>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="resource-price-group">
                            <?php if ($has_discount): ?>
                                <span class="resource-price-original"><?php echo formatCurrency($resource['original_price']); ?></span>
                            <?php endif; ?>
                            <span class="resource-price-current">
                                <i class="fas fa-tag"></i>
                                <?php echo formatCurrency($display_price); ?>
                            </span>
                        </div>
                        
                        <div class="resource-meta">
                            <div>Tác giả: <?php echo htmlspecialchars($resource['creator_name']); ?></div>
                            <div>
                                <?php if ($resource['stock_quantity'] == -1): ?>
                                    <i class="fas fa-infinity" style="color: var(--success);"></i> Không giới hạn
                                <?php elseif ($resource['stock_quantity'] == 0): ?>
                                    <i class="fas fa-times-circle" style="color: var(--danger);"></i> Hết hàng
                                <?php else: ?>
                                    <i class="fas fa-box" style="color: var(--warning);"></i> Còn <?php echo $resource['stock_quantity']; ?>
                                <?php endif; ?>
                            </div>
                            <div><i class="fas fa-download"></i> <?php echo $resource['download_count']; ?> lượt tải</div>
                        </div>
                    </div>
                    <div class="resource-footer">
                        <?php if ($is_out_of_stock): ?>
                            <button class="resource-action btn-disabled" disabled>
                                <i class="fas fa-times-circle"></i> Hết hàng
                            </button>
                        <?php elseif ($is_purchased_completed && !$resource['allow_repurchase']): ?>
                            <?php if ($has_completed_purchase_for_download && $resource['file_path'] && file_exists($resource['file_path'])): ?>
                            <a href="<?php echo htmlspecialchars($resource['file_path']); ?>" class="resource-action btn-success" download>
                                <i class="fas fa-download"></i> Tải xuống
                            </a>
                            <?php else: ?>
                            <button class="resource-action btn-disabled" disabled>
                                <i class="fas fa-check"></i> Đã mua
                            </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" style="width: 100%;">
                                <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                                <button type="submit" name="purchase_resource" class="resource-action btn-primary">
                                    <i class="fas fa-shopping-cart"></i> 
                                    <?php echo $is_purchased_completed ? 'Mua lại' : 'Mua ngay'; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="purchases-tab">
            <div class="section-header">
                <div>
                    <h2 class="section-title"><i class="fas fa-shopping-bag"></i> Đơn hàng của tôi</h2>
                    <p class="section-subtitle">Theo dõi các đơn hàng và tải xuống tài nguyên đã mua.</p>
                </div>
            </div>

            <?php if (empty($user_purchases)): ?>
            <div class="empty-state">
                <i class="fas fa-shopping-bag empty-icon"></i>
                <h3 class="empty-title">Chưa có đơn hàng nào</h3>
                <p class="empty-description">Bạn chưa mua tài nguyên nào. Hãy khám phá cửa hàng để tìm tài nguyên phù hợp.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Đơn hàng</th>
                            <th>Tài nguyên</th>
                            <th>Số tiền</th>
                            <th>Trạng thái</th>
                            <th>Ngày mua</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_purchases as $purchase): ?>
                        <tr>
                            <td>#<?php echo $purchase['id']; ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($purchase['resource_title']); ?></div>
                            </td>
                            <td style="font-weight: 600; color: var(--success);">
                                <?php echo formatCurrency($purchase['purchase_amount']); ?>
                            </td>
                            <td><?php echo formatPaymentStatus($purchase['payment_status']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($purchase['purchase_date'])); ?></td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if ($purchase['payment_status'] == 'pending'): ?>
                                    <a href="store?purchase_id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-qrcode"></i> Thanh toán
                                    </a>
                                    <?php elseif ($purchase['payment_status'] == 'completed' && $purchase['file_path'] && file_exists($purchase['file_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($purchase['file_path']); ?>" class="btn btn-sm btn-success" download>
                                        <i class="fas fa-download"></i> Tải xuống
                                    </a>
                                    <?php endif; ?>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            const urlParams = new URLSearchParams(window.location.search);
            const purchaseId = urlParams.get('purchase_id');
            const initialTab = purchaseId ? 'purchases' : 'store';
            showTab(null, initialTab);

            if (purchaseId) {
                const qrDisplay = document.querySelector('.qr-display');
                if (qrDisplay) {
                    qrDisplay.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });

        function showTab(event, tabName) {
            if (event) {
                event.preventDefault();
            }

            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });

            document.getElementById(tabName + '-tab').classList.add('active');

            const clickedButton = document.querySelector(`.tab-button[onclick*="'${tabName}'"]`);
            if (clickedButton) {
                clickedButton.classList.add('active');
            }
        }

        // Hàm JavaScript mới để ẩn/hiện mô tả
        function toggleDescription(resourceId) {
            const desc = document.getElementById('desc-' + resourceId);
            const toggleText = document.getElementById('toggle-text-' + resourceId);
            const toggleIcon = document.getElementById('toggle-icon-' + resourceId);
            
            if (desc.classList.contains('collapsed')) {
                desc.classList.remove('collapsed');
                toggleText.textContent = 'Thu gọn';
                toggleIcon.classList.remove('fa-chevron-down');
                toggleIcon.classList.add('fa-chevron-up');
            } else {
                desc.classList.add('collapsed');
                toggleText.textContent = 'Xem thêm';
                toggleIcon.classList.remove('fa-chevron-up');
                toggleIcon.classList.add('fa-chevron-down');
            }
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
mysqli_close($conn);
?>