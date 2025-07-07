<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';
require_once 'shortlink_functions.php';

// Khởi tạo ShortLink class
$shortLink = new ShortLink($conn);

// Lấy short code từ URL path
$request_uri = $_SERVER['REQUEST_URI'];
$short_code = null;

// Nếu URL không chứa .php và không phải là thư mục và không phải là trang quản lý
if (!preg_match('/\.php/', $request_uri) && 
    !is_dir($_SERVER['DOCUMENT_ROOT'] . $request_uri) && 
    $request_uri !== '/shortlink' && 
    $request_uri !== '/shortlink/' &&
    !preg_match('/^\/shortlink\?/', $request_uri)) {
    // Lấy phần cuối của URL làm short code
    $short_code = trim($request_uri, '/');
}

// Nếu có short code từ URL path hoặc tham số c
if (!empty($short_code) || (isset($_GET['c']) && !empty($_GET['c']))) {
    $short_code = !empty($short_code) ? $short_code : $_GET['c'];
    $short_code = mysqli_real_escape_string($conn, $short_code);
    $result = $shortLink->redirect($short_code);

    if ($result['status'] === 'success') {
        header("Location: " . $result['original_url']);
        exit;
    } elseif ($result['status'] === 'password_required') {
        ?>
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Xác nhận mật khẩu</title>
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <style>
                :root {
                    --bg-color: #0f172a;
                    --card-bg: #1e293b;
                    --text-color: #e2e8f0;
                    --text-muted: #94a3b8;
                    --primary-color: #8b5cf6;
                    --primary-hover: #7c3aed;
                    --secondary-color: #ec4899;
                    --accent-color: #38bdf8;
                    --border-color: #334155;
                    --error-color: #ef4444;
                    --success-color: #10b981;
                    --input-bg: #334155;
                    --shadow-color: rgba(0, 0, 0, 0.5);
                    --card-radius: 1rem;
                    --button-radius: 0.5rem;
                    --input-radius: 0.5rem;
                    --transition: all 0.3s ease;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Poppins', sans-serif;
                    background-color: var(--bg-color);
                    background-image: 
                        radial-gradient(circle at 15% 50%, rgba(139, 92, 246, 0.15) 0%, transparent 25%),
                        radial-gradient(circle at 85% 30%, rgba(236, 72, 153, 0.15) 0%, transparent 25%);
                    color: var(--text-color);
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 1rem;
                }
                
                .password-form {
                    background-color: var(--card-bg);
                    border-radius: var(--card-radius);
                    box-shadow: 0 10px 25px var(--shadow-color);
                    padding: 2rem;
                    width: 100%;
                    max-width: 400px;
                    position: relative;
                    overflow: hidden;
                    border: 1px solid var(--border-color);
                }
                
                .password-form::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 5px;
                    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
                }
                
                h2 {
                    color: var(--text-color);
                    margin-bottom: 1.5rem;
                    font-weight: 600;
                    text-align: center;
                    font-size: 1.5rem;
                    position: relative;
                    padding-bottom: 0.75rem;
                }
                
                h2::after {
                    content: '';
                    position: absolute;
                    bottom: 0;
                    left: 50%;
                    transform: translateX(-50%);
                    width: 50px;
                    height: 3px;
                    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
                    border-radius: 3px;
                }
                
                .form-group {
                    margin-bottom: 1.5rem;
                    position: relative;
                }
                
                input[type="password"] {
                    width: 100%;
                    padding: 0.75rem 1rem 0.75rem 2.5rem;
                    background-color: var(--input-bg);
                    border: 1px solid var(--border-color);
                    border-radius: var(--input-radius);
                    color: var(--text-color);
                    font-size: 1rem;
                    transition: var(--transition);
                }
                
                input[type="password"]:focus {
                    outline: none;
                    border-color: var(--primary-color);
                    box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.25);
                }
                
                .form-group i {
                    position: absolute;
                    left: 0.75rem;
                    top: 50%;
                    transform: translateY(-50%);
                    color: var(--text-muted);
                    font-size: 1rem;
                }
                
                button {
                    width: 100%;
                    padding: 0.75rem 1.5rem;
                    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
                    color: white;
                    border: none;
                    border-radius: var(--button-radius);
                    font-size: 1rem;
                    font-weight: 500;
                    cursor: pointer;
                    transition: var(--transition);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                }
                
                button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
                }
                
                button::before {
                    content: '\f023';
                    font-family: 'Font Awesome 6 Free';
                    font-weight: 900;
                }
                
                .error-message {
                    background-color: rgba(239, 68, 68, 0.2);
                    color: var(--error-color);
                    padding: 0.75rem 1rem;
                    border-radius: var(--input-radius);
                    margin-bottom: 1.5rem;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }
                
                .error-message::before {
                    content: '\f071';
                    font-family: 'Font Awesome 6 Free';
                    font-weight: 900;
                }
                
                @media (max-width: 480px) {
                    .password-form {
                        padding: 1.5rem;
                    }
                    
                    h2 {
                        font-size: 1.25rem;
                    }
                    
                    input[type="password"] {
                        font-size: 0.9rem;
                    }
                    
                    button {
                        font-size: 0.9rem;
                    }
                }
            </style>
        </head>
        <body>
            <div class="password-form">
                <h2>Short link được bảo vệ bằng mật khẩu</h2>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'password'): ?>
                    <div class="error-message">Mật khẩu không đúng! Vui lòng thử lại.</div>
                <?php endif; ?>
                <form method="POST" action="<?php echo htmlspecialchars($short_code); ?>">
                    <div class="form-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" placeholder="Nhập mật khẩu" required>
                    </div>
                    <button type="submit">Xác nhận</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    } else {
        die($result['message']);
    }
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Xử lý mật khẩu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['c']) && isset($_POST['password'])) {
    $short_code = mysqli_real_escape_string($conn, $_GET['c']);
    $password = trim($_POST['password']);
    $result = $shortLink->verifyPassword($short_code, $password);

    if ($result['status'] === 'success') {
        if (filter_var($result['original_url'], FILTER_VALIDATE_URL)) {
            header("Location: " . $result['original_url']);
            exit;
        } else {
            die("URL gốc không hợp lệ");
        }
    } else {
        header("Location: shortlink?c=$short_code&error=password");
        exit;
    }
}

// Khởi tạo biến thông báo và tab mặc định
$success_message = '';
$error_message = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'create';

// Xử lý tạo short link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shortlink'])) {
    if (!isset($user_id) || $user_id <= 0) {
        $error_message = "Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.";
    } else {
        $original_url = trim($_POST['original_url'] ?? '');
        $custom_slug = trim($_POST['custom_slug'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $expiration_date = trim($_POST['expiration_date'] ?? '');

        if (empty($original_url)) {
            $error_message = "URL gốc không được để trống.";
        } else {
            $original_url = mysqli_real_escape_string($conn, $original_url);
            $custom_slug = $custom_slug ? mysqli_real_escape_string($conn, $custom_slug) : null;
            $password = $password ? mysqli_real_escape_string($conn, $password) : null;
            $expiration_date = $expiration_date ? mysqli_real_escape_string($conn, $expiration_date) : null;

            $result = $shortLink->createShortLink(
                $user_id,
                $original_url,
                $custom_slug,
                $password,
                $expiration_date
            );

            if ($result['status'] === 'success') {
                $success_message = "Tạo short link thành công! Short URL: <a href='shortlink?c={$result['slug']}' target='_blank'>https://umters.club/shortlink?c={$result['slug']}</a>";
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

// Xử lý chỉnh sửa short link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_shortlink'])) {
    $shortlink_id = (int)$_POST['shortlink_id'];
    $original_url = trim($_POST['original_url'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $expiration_date = trim($_POST['expiration_date'] ?? '');

    if (empty($original_url)) {
        $error_message = "URL gốc không được để trống.";
    } else {
        $original_url = mysqli_real_escape_string($conn, $original_url);
        $password = $password ? mysqli_real_escape_string($conn, $password) : null;
        $expiration_date = $expiration_date ? mysqli_real_escape_string($conn, $expiration_date) : null;

        $result = $shortLink->updateShortLink(
            $user_id,
            $shortlink_id,
            $original_url,
            $password,
            $expiration_date
        );

        if ($result['status'] === 'success') {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    }
}

// Xử lý xóa short link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shortlink'])) {
    $short_link_id = (int)$_POST['shortlink_id'];
    $result = $shortLink->deleteShortLink($user_id, $short_link_id);

    if ($result['status'] === 'success') {
        $success_message = $result['message'];
    } else {
        $error_message = $result['message'];
    }
}

// Lấy danh sách short links
$shortlinks = $shortLink->getShortLinks($user_id);

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
    } else {
        $user = [
            'username' => 'Unknown',
            'full_name' => '',
            'is_main_admin' => 0,
            'is_super_admin' => 0
        ];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Short Link</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --card-bg-hover: #334155;
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --primary-color: #8b5cf6;
            --primary-hover: #7c3aed;
            --secondary-color: #ec4899;
            --accent-color: #38bdf8;
            --border-color: #334155;
            --error-color: #ef4444;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --info-color: #3b82f8;
            --input-bg: #334155;
            --shadow-color: rgba(0, 0, 0, 0.5);
            --card-radius: 1rem;
            --button-radius: 0.5rem;
            --input-radius: 0.5rem;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(139, 92, 246, 0.15) 0%, transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(236, 72, 153, 0.15) 0%, transparent 25%);
            color: var(--text-color);
            min-height: 100vh;
            line-height: 1.6;
            position: relative;
        }
        
        /* Animated background particles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            animation: float 15s infinite ease-in-out;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) translateX(0);
            }
            25% {
                transform: translateY(-30px) translateX(15px);
            }
            50% {
                transform: translateY(-15px) translateX(-15px);
            }
            75% {
                transform: translateY(30px) translateX(10px);
            }
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .user-role {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .nav-links {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .nav-link {
            color: var(--text-color);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: var(--button-radius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-link:hover {
            background-color: var(--card-bg);
        }
        
        .nav-link.home-link {
            background-color: var(--card-bg);
        }
        
        .nav-link.home-link:hover {
            background-color: var(--card-bg-hover);
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: var(--card-radius);
            box-shadow: 0 10px 25px var(--shadow-color);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-color);
            position: relative;
            padding-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .card-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
        }
        
        .card-title i {
            color: var(--primary-color);
        }
        
        .tab-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tab-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }
        
        .tab-button.active {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group label i {
            color: var(--primary-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--input-radius);
            color: var(--text-color);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.25);
        }
        
        .form-control::placeholder {
            color: var(--text-muted);
        }
        
        .form-control[readonly] {
            background-color: rgba(51, 65, 85, 0.5);
            cursor: not-allowed;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }
        
        .btn-block {
            display: flex;
            width: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }
        
        .btn-primary:hover {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
        }
        
        .btn-danger {
            background: linear-gradient(90deg, var(--error-color), #b91c1c);
        }
        
        .btn-danger:hover {
            background: linear-gradient(90deg, #b91c1c, var(--error-color));
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-info {
            background: linear-gradient(90deg, var(--info-color), #1d4ed8);
        }
        
        .btn-info:hover {
            background: linear-gradient(90deg, #1d4ed8, var(--info-color));
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(90deg, var(--success-color), #059669);
        }
        
        .btn-success:hover {
            background: linear-gradient(90deg, #059669, var(--success-color));
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(90deg, var(--warning-color), #d97706);
        }
        
        .btn-warning:hover {
            background: linear-gradient(90deg, #d97706, var(--warning-color));
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.4);
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--input-radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        
        .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        
        .alert i {
            margin-top: 0.25rem;
        }
        
        .shortlink-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .shortlink-card {
            background-color: var(--card-bg);
            border-radius: var(--card-radius);
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .shortlink-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px var(--shadow-color);
            border-color: var(--primary-color);
        }
        
        .shortlink-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            flex: 1;
        }
        
        .shortlink-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            word-break: break-all;
        }
        
        .shortlink-title i {
            color: var(--primary-color);
            flex-shrink: 0;
        }
        
        .shortlink-info {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .shortlink-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .shortlink-info-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .shortlink-info-label i {
            color: var(--primary-color);
        }
        
        .shortlink-info-value {
            font-weight: 500;
            word-break: break-all;
        }
        
        .shortlink-info-value a {
            color: var(--accent-color);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .shortlink-info-value a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }
        
        .shortlink-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background-color: rgba(51, 65, 85, 0.5);
            border-top: 1px solid var(--border-color);
        }
        
        .shortlink-action-btn {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--input-radius);
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            color: white;
        }
        
        .shortlink-action-btn i {
            font-size: 0.85rem;
        }
        
        .copy-btn {
            background-color: var(--warning-color);
        }
        
        .copy-btn:hover {
            background-color: #d97706;
            transform: translateY(-2px);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            opacity: 0.5;
        }
        
        .empty-state-text {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-title {
                font-size: 1.75rem;
            }
            
            .card {
                padding: 1.5rem;
            }
            
            .card-title {
                font-size: 1.25rem;
            }
            
            .tab-buttons {
                flex-direction: column;
            }
            
            .tab-button {
                width: 100%;
            }
            
            .shortlink-grid {
                grid-template-columns: 1fr;
            }
            
            .shortlink-actions {
                flex-wrap: wrap;
            }
            
            .shortlink-action-btn {
                flex: 0 0 calc(50% - 0.25rem);
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 0.75rem;
            }
            
            .header-title {
                font-size: 1.5rem;
            }
            
            .card {
                padding: 1.25rem;
            }
            
            .card-title {
                font-size: 1.1rem;
            }
            
            .form-control {
                font-size: 0.9rem;
            }
            
            .btn {
                font-size: 0.9rem;
            }
            
            .shortlink-action-btn {
                font-size: 0.8rem;
                padding: 0.4rem;
            }
        }
    </style>
</head>
<body>
    <!-- Animated background particles -->
    <div class="particles">
        <?php for ($i = 0; $i < 20; $i++): ?>
            <div class="particle" style="
                width: <?php echo rand(5, 20); ?>px;
                height: <?php echo rand(5, 20); ?>px;
                background-color: <?php echo rand(0, 1) ? 'rgba(139, 92, 246, 0.3)' : 'rgba(236, 72, 153, 0.3)'; ?>;
                left: <?php echo rand(0, 100); ?>%;
                top: <?php echo rand(0, 100); ?>%;
                animation-duration: <?php echo rand(15, 30); ?>s;
                animation-delay: <?php echo rand(0, 5); ?>s;
            "></div>
        <?php endfor; ?>
    </div>

    <div class="container">
        <div class="header">
            <h1 class="header-title">Quản Lý Short Link</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?></div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></span>
                    <span class="user-role">
                        <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="nav-links">
            <a href="index.php" class="nav-link home-link">
                <i class="fas fa-home"></i> Trang chủ
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error_message); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success_message; ?></div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="tab-buttons">
                <button class="tab-button <?php echo $active_tab == 'create' ? 'active' : ''; ?>" onclick="location.href='shortlink?tab=create'">
                    <i class="fas fa-plus"></i> Tạo Short Link
                </button>
                <button class="tab-button <?php echo $active_tab == 'manage' ? 'active' : ''; ?>" onclick="location.href='shortlink?tab=manage'">
                    <i class="fas fa-list"></i> Quản Lý Short Link
                </button>
            </div>

            <div class="tab-content <?php echo $active_tab == 'create' ? 'active' : ''; ?>" id="create-tab">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Tạo một short link mới</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="original_url"><i class="fas fa-link"></i> URL gốc</label>
                        <input type="text" id="original_url" name="original_url" class="form-control" placeholder="Nhập URL gốc (e.g., https://example.com)" required>
                    </div>
                    <div class="form-group">
                        <label for="custom_slug"><i class="fas fa-tag"></i> Custom Slug (tùy chọn)</label>
                        <input type="text" id="custom_slug" name="custom_slug" class="form-control" placeholder="Nhập slug tùy chỉnh (e.g., my-link)">
                    </div>
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Mật khẩu bảo vệ (tùy chọn)</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Mật khẩu (để trống nếu không cần)">
                    </div>
                    <div class="form-group">
                        <label for="expiration_date"><i class="fas fa-calendar-alt"></i> Ngày hết hạn (tùy chọn)</label>
                        <input type="datetime-local" id="expiration_date" name="expiration_date" class="form-control">
                    </div>
                    <button type="submit" name="create_shortlink" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Tạo Short Link
                    </button>
                </form>
            </div>

            <div class="tab-content <?php echo $active_tab == 'manage' ? 'active' : ''; ?>" id="manage-tab">
                <h3 class="card-title"><i class="fas fa-list-alt"></i> Danh sách các short link của bạn</h3>
                
                <?php if (empty($shortlinks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-link"></i>
                        <p class="empty-state-text">Chưa có short link nào được tạo. Hãy tạo short link đầu tiên của bạn!</p>
                        <button class="btn btn-primary" onclick="location.href='shortlink?tab=create'">
                            <i class="fas fa-plus"></i> Tạo Short Link
                        </button>
                    </div>
                <?php else: ?>
                    <div class="shortlink-grid">
                        <?php foreach ($shortlinks as $link): ?>
                            <div class="shortlink-card">
                                <div class="shortlink-header">
                                    <h4 class="shortlink-title">
                                        <i class="fas fa-link"></i>
                                        <?php echo htmlspecialchars(strlen($link['original_url']) > 30 ? substr($link['original_url'], 0, 30) . '...' : $link['original_url']); ?>
                                    </h4>
                                    <div class="shortlink-info">
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-link"></i> URL gốc
                                            </div>
                                            <div class="shortlink-info-value">
                                                <a href="<?php echo htmlspecialchars($link['original_url']); ?>" target="_blank">
                                                    <?php echo htmlspecialchars($link['original_url']); ?>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-cut"></i> Short URL
                                            </div>
                                            <div class="shortlink-info-value">
                                                <a href="shortlink?c=<?php echo htmlspecialchars($link['short_code']); ?>" target="_blank">
                                                    <?php echo "https://umters.club/shortlink?c=" . htmlspecialchars($link['short_code']); ?>
                                                </a>
                                                <button class="copy-btn" title="Sao chép" onclick="copyToClipboard('<?php echo htmlspecialchars($link['short_code']); ?>')">
                                                    <i class="fas fa-copy"></i> Sao chép
                                                </button>
                                            </div>
                                        </div>
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-chart-line"></i> Lượt truy cập
                                            </div>
                                            <div class="shortlink-info-value">
                                                <?php echo $link['click_count']; ?> lần
                                            </div>
                                        </div>
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-clock"></i> Ngày tạo
                                            </div>
                                            <div class="shortlink-info-value">
                                                <?php echo htmlspecialchars($link['created_at']); ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($link['expiry_time'])): ?>
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-calendar-alt"></i> Ngày hết hạn
                                            </div>
                                            <div class="shortlink-info-value">
                                                <?php echo htmlspecialchars($link['expiry_time']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($link['password'])): ?>
                                        <div class="shortlink-info-item">
                                            <div class="shortlink-info-label">
                                                <i class="fas fa-lock"></i> Bảo vệ bằng mật khẩu
                                            </div>
                                            <div class="shortlink-info-value">
                                                <i class="fas fa-check-circle" style="color: var(--success-color);"></i> Có
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="shortlink-actions">
                                    <a href="shortlink?tab=edit&id=<?php echo $link['id']; ?>" class="shortlink-action-btn btn-info">
                                        <i class="fas fa-edit"></i> Sửa
                                    </a>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="shortlink_id" value="<?php echo $link['id']; ?>">
                                        <button type="submit" name="delete_shortlink" class="shortlink-action-btn btn-danger" onclick="return confirm('Bạn có chắc muốn xóa short link này?');">
                                            <i class="fas fa-trash-alt"></i> Xóa
                                        </button>
                                    </form>
                                    <a href="qrcode.php?data=<?php echo urlencode('https://umters.club/shortlink?c=' . htmlspecialchars($link['short_code'])); ?>" class="shortlink-action-btn btn-success" target="_blank">
                                        <i class="fas fa-qrcode"></i> QR
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['tab']) && $_GET['tab'] == 'edit' && isset($_GET['id']) && is_numeric($_GET['id'])): ?>
                <?php
                $shortlink_id = (int)$_GET['id'];
                $sql = "SELECT original_url, short_code, password, expiry_time FROM short_links WHERE id = ? AND user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $shortlink_id, $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $result->num_rows > 0) {
                        $shortlink = $result->fetch_assoc();
                    ?>
                        <div class="tab-content active" id="edit-tab">
                            <h3 class="card-title"><i class="fas fa-edit"></i> Chỉnh sửa thông tin short link</h3>
                            <form method="POST">
                                <input type="hidden" name="shortlink_id" value="<?php echo $shortlink_id; ?>">
                                <div class="form-group">
                                    <label for="edit_original_url"><i class="fas fa-link"></i> URL gốc</label>
                                    <input type="text" id="edit_original_url" name="original_url" class="form-control" value="<?php echo htmlspecialchars($shortlink['original_url']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_short_code"><i class="fas fa-tag"></i> Short Code</label>
                                    <input type="text" id="edit_short_code" class="form-control" value="<?php echo htmlspecialchars($shortlink['short_code']); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label for="edit_password"><i class="fas fa-lock"></i> Mật khẩu bảo vệ</label>
                                    <input type="password" id="edit_password" name="password" class="form-control" placeholder="Mật khẩu mới (tùy chọn, để trống nếu không đổi)">
                                </div>
                                <div class="form-group">
                                    <label for="edit_expiration_date"><i class="fas fa-calendar-alt"></i> Ngày hết hạn</label>
                                    <input type="datetime-local" id="edit_expiration_date" name="expiration_date" class="form-control" value="<?php echo $shortlink['expiry_time'] ? htmlspecialchars($shortlink['expiry_time']) : ''; ?>">
                                </div>
                                <button type="submit" name="edit_shortlink" class="btn btn-primary btn-block">
                                    <i class="fas fa-save"></i> Lưu Thay Đổi
                                </button>
                            </form>
                        </div>
                    <?php } else {
                        echo "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i><div>Short link không tồn tại hoặc bạn không có quyền chỉnh sửa.</div></div>";
                    }
                    $stmt->close();
                } else {
                    echo "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i><div>Lỗi truy vấn cơ sở dữ liệu.</div></div>";
                }
                ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Copy to clipboard function
        function copyToClipboard(shortCode) {
        const fullUrl = window.location.origin + '/shortlink?c=' + shortCode;
        navigator.clipboard.writeText(fullUrl).then(() => {
            alert('Đã sao chép: ' + fullUrl);
        }).catch(err => {
            console.error('Lỗi khi sao chép: ', err);
            const tempInput = document.createElement('input');
            tempInput.value = fullUrl;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            alert('Đã sao chép: ' + fullUrl);
        });
    }

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
mysqli_close($conn);
?>
