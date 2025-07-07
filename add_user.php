<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Tạo CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
if ($result_user && $result_user->num_rows > 0) {
    $user = $result_user->fetch_assoc();
} else {
    $edit_error = "Lỗi khi lấy thông tin người dùng.";
    $user = [
        'username' => 'Unknown',
        'full_name' => '',
        'is_main_admin' => 0,
        'is_super_admin' => 0
    ];
}
$stmt->close();

// Kiểm tra quyền super admin
if (!$user['is_super_admin']) {
    header("Location: dashboard.php");
    exit;
}

// Xử lý thêm user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = trim($_POST['password']);
    if (strlen($password) < 8) {
        $password_message = "Mật khẩu phải có ít nhất 8 ký tự!";
    } else {
        $password_hashed = password_hash($password, PASSWORD_DEFAULT);
        $is_main_admin = isset($_POST['is_main_admin']) ? 1 : 0;
        $is_super_admin = 0;

        // Kiểm tra username tồn tại
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row['count'] > 0) {
            $password_message = "Tên đăng nhập '$username' đã tồn tại! Vui lòng chọn tên khác.";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, is_main_admin, is_super_admin) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $username, $password_hashed, $is_main_admin, $is_super_admin);
            if ($stmt->execute()) {
                $success_message = "Thêm tài khoản thành công!";
            } else {
                $password_message = "Lỗi khi thêm tài khoản: " . $conn->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Quản Lý Hiện Đại</title>
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
        .add-user-container {
            max-width: 1200px;
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
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border-left: 4px solid var(--secondary);
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

        /* Content grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1.5rem;
        }

        /* Info panel */
        .info-panel {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            height: fit-content;
            position: relative;
            overflow: hidden;
        }

        .info-panel::before {
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

        .info-header {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .info-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .info-content {
            color: var(--foreground-muted);
            font-size: 0.875rem;
            line-height: 1.7;
        }

        .info-list {
            margin-top: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(4px);
            border-color: var(--primary-light);
        }

        .info-item-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--secondary);
            flex-shrink: 0;
        }

        .info-item-content {
            flex: 1;
        }

        .info-item-title {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .info-item-description {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        /* Form panel */
        .form-panel {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .form-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 80% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .form-header {
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .form-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .form-title i {
            color: var(--primary-light);
        }

        .form-subtitle {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-top: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .form-checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            transition: all 0.3s ease;
        }

        .form-checkbox-group:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--primary-light);
        }

        .form-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            outline: none;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
        }

        .form-checkbox:checked {
            background: var(--primary);
            border-color: var(--primary-light);
        }

        .form-checkbox:checked::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.75rem;
        }

        .form-checkbox-label {
            font-size: 0.875rem;
            color: var(--foreground);
            cursor: pointer;
        }

        .form-submit {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .form-submit::before {
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

        .form-submit:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .form-submit:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .form-submit:active {
            transform: translateY(0);
        }

        .form-submit i {
            font-size: 1.125rem;
        }

        /* Password strength meter */
        .password-strength {
            margin-top: 0.5rem;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-full);
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            text-align: right;
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .add-user-container {
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
        }

        @media (max-width: 480px) {
            .info-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .form-title {
                font-size: 1.125rem;
            }
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .floating {
            animation: float 6s ease-in-out infinite;
        }

        .floating-slow {
            animation: float 8s ease-in-out infinite;
        }

        .floating-fast {
            animation: float 4s ease-in-out infinite;
        }

        /* Particle background */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="add-user-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-user-plus"></i> Add User</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if (isset($edit_error) || isset($password_message) || isset($success_message)): ?>
        <div class="message-container">
            <?php if (isset($edit_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
            <?php endif; ?>
            <?php if (isset($password_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($password_message); ?></div>
            <?php endif; ?>
            <?php if (isset($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="content-grid">
            <div class="info-panel">
                <div class="info-header">
                    <div class="info-icon floating">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h2 class="info-title">Quản lý người dùng</h2>
                </div>
                <div class="info-content">
                    <p>Tạo tài khoản mới với các quyền phù hợp. Chỉ Super Admin mới có thể thêm người dùng mới vào hệ thống.</p>
                </div>
                <div class="info-list">
                    <div class="info-item">
                        <div class="info-item-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="info-item-content">
                            <h3 class="info-item-title">Admin</h3>
                            <p class="info-item-description">Có quyền truy cập cơ bản vào hệ thống và quản lý tài liệu.</p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="info-item-content">
                            <h3 class="info-item-title">Main Admin</h3>
                            <p class="info-item-description">Có thêm quyền gửi email và quản lý một số chức năng nâng cao.</p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-icon">
                            <i class="fas fa-user-crown"></i>
                        </div>
                        <div class="info-item-content">
                            <h3 class="info-item-title">Super Admin</h3>
                            <p class="info-item-description">Có toàn quyền quản lý hệ thống, bao gồm thêm/xóa người dùng.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-panel">
                <div class="form-header">
                    <h2 class="form-title"><i class="fas fa-user-plus"></i> Thêm tài khoản mới</h2>
                    <p class="form-subtitle">Tạo tài khoản admin mới với thông tin đăng nhập an toàn</p>
                </div>
                
                <form method="POST" id="add-user-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Tên đăng nhập</label>
                        <input type="text" id="username" name="username" class="form-input" placeholder="Nhập tên đăng nhập" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Mật khẩu</label>
                        <input type="password" id="password" name="password" class="form-input" placeholder="Nhập mật khẩu (ít nhất 8 ký tự)" required>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password-strength-bar"></div>
                        </div>
                        <div class="password-strength-text" id="password-strength-text"></div>
                    </div>
                    
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="is_main_admin" name="is_main_admin" value="1" class="form-checkbox">
                        <label for="is_main_admin" class="form-checkbox-label">Tạo làm Main Admin</label>
                    </div>
                    
                    <div class="form-group" style="margin-top: 1.5rem;">
                        <button type="submit" name="add_user" class="form-submit">
                            <i class="fas fa-user-plus"></i> Thêm tài khoản
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.info-panel', 100);
            animateElements('.form-panel', 200);
            animateElements('.info-item', 50);
            animateElements('.form-group', 50);
            
            // Hiệu ứng hiển thị thông báo
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
            
            // Kiểm tra độ mạnh mật khẩu
            const passwordInput = document.getElementById('password');
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');
            
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                
                // Cập nhật thanh độ mạnh
                strengthBar.style.width = strength.percent + '%';
                strengthBar.style.backgroundColor = strength.color;
                
                // Cập nhật text
                strengthText.textContent = strength.text;
                strengthText.style.color = strength.color;
            });
            
            function checkPasswordStrength(password) {
                // Mặc định
                let strength = {
                    percent: 0,
                    color: '#FF3D57',
                    text: ''
                };
                
                if (password.length === 0) {
                    return strength;
                }
                
                // Kiểm tra độ mạnh
                let score = 0;
                
                // Độ dài
                if (password.length >= 8) score += 1;
                if (password.length >= 12) score += 1;
                
                // Chữ hoa, chữ thường
                if (/[A-Z]/.test(password)) score += 1;
                if (/[a-z]/.test(password)) score += 1;
                
                // Số và ký tự đặc biệt
                if (/[0-9]/.test(password)) score += 1;
                if (/[^A-Za-z0-9]/.test(password)) score += 1;
                
                // Cập nhật strength dựa trên score
                if (score === 0) {
                    strength.percent = 10;
                    strength.color = '#FF3D57';
                    strength.text = 'Rất yếu';
                } else if (score <= 2) {
                    strength.percent = 25;
                    strength.color = '#FF3D57';
                    strength.text = 'Yếu';
                } else if (score <= 4) {
                    strength.percent = 50;
                    strength.color = '#FFA500';
                    strength.text = 'Trung bình';
                } else if (score <= 5) {
                    strength.percent = 75;
                    strength.color = '#2196F3';
                    strength.text = 'Mạnh';
                } else {
                    strength.percent = 100;
                    strength.color = '#00E0FF';
                    strength.text = 'Rất mạnh';
                }
                
                return strength;
            }
        });
        
        // Hàm tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                // Random color
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Hàm animation cho các phần tử
        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * delay);
            });
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>
