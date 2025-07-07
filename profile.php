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
$stmt = $conn->prepare("SELECT username, phone, email, full_name, class, address, is_main_admin, is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
if ($result_user && $result_user->num_rows > 0) {
    $user = $result_user->fetch_assoc();
} else {
    $edit_error = "Lỗi khi lấy thông tin người dùng.";
    $user = [
        'username' => 'Unknown',
        'phone' => '',
        'email' => '',
        'full_name' => '',
        'class' => '',
        'address' => '',
        'is_main_admin' => 0,
        'is_super_admin' => 0
    ];
}
$stmt->close();

// Xử lý cập nhật profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $full_name = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $class = filter_input(INPUT_POST, 'class', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, class = ?, address = ? WHERE id = ?");
    $stmt->bind_param("sssssi", $full_name, $email, $phone, $class, $address, $user_id);
    if ($stmt->execute()) {
        $user['full_name'] = $full_name;
        $user['email'] = $email;
        $user['phone'] = $phone;
        $user['class'] = $class;
        $user['address'] = $address;
        $success_message = "Cập nhật profile thành công!";
    } else {
        $error_message = "Lỗi khi cập nhật profile: " . $conn->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Quản Lý</title>
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
        .profile-container {
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

        /* Profile content */
        .profile-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1.5rem;
        }

        /* Profile sidebar */
        .profile-sidebar {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            height: fit-content;
            position: relative;
            overflow: hidden;
        }

        .profile-sidebar::before {
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

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 3rem;
            box-shadow: var(--glow);
            margin: 0 auto;
            position: relative;
            overflow: hidden;
        }

        .profile-avatar::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.3));
            top: 0;
            left: 0;
        }

        .profile-name {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            margin-top: 0.5rem;
        }

        .profile-role {
            text-align: center;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-3px);
            border-color: var(--border);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .stat-card:nth-child(1) .stat-icon {
            color: var(--primary-light);
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.3);
        }

        .stat-card:nth-child(2) .stat-icon {
            color: var(--secondary);
            box-shadow: 0 0 10px rgba(0, 224, 255, 0.3);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            text-align: center;
        }

        /* Profile main */
        .profile-main {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .profile-section {
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

        .profile-section::before {
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

        .section-header {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .section-title i {
            color: var(--primary-light);
        }

        /* Profile info */
        .profile-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .info-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-label i {
            color: var(--primary-light);
            font-size: 0.875rem;
        }

        .info-value {
            font-size: 1rem;
            color: var(--foreground);
            font-weight: 500;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .info-value:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
            border-color: var(--primary-light);
        }

        /* Profile form */
        .profile-form {
            margin-top: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--primary-light);
            font-size: 0.875rem;
        }

        .form-input {
            padding: 0.75rem 1rem;
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

        .form-submit {
            margin-top: 1.5rem;
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
            width: 100%;
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

        /* Responsive styles */
        @media (max-width: 1200px) {
            .profile-content {
                grid-template-columns: 1fr;
            }

            .profile-sidebar {
                order: 1;
            }

            .profile-main {
                order: 2;
            }
        }

        @media (max-width: 768px) {
            .profile-container {
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

            .profile-info,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stat-card {
                padding: 0.75rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }

            .section-title {
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

    <div class="profile-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-user-circle"></i> Profile</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if (isset($edit_error) || isset($error_message) || isset($success_message)): ?>
        <div class="message-container">
            <?php if (isset($edit_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if (isset($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="profile-content">
            <div class="profile-sidebar">
                <div class="profile-avatar floating">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></h2>
                <p class="profile-role">
                    <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                </p>
                
                <div class="profile-stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value">0</div>
                        <div class="stat-label">Hoạt động</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-value">0</div>
                        <div class="stat-label">Tài liệu</div>
                    </div>
                </div>
            </div>

            <div class="profile-main">
                <div class="profile-section">
                    <div class="section-header">
                        <h3 class="section-title"><i class="fas fa-info-circle"></i> Thông tin cá nhân</h3>
                    </div>
                    
                    <div class="profile-info">
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-user"></i> Tên đăng nhập</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-phone"></i> Số điện thoại</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?: 'Chưa cập nhật'); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email'] ?: 'Chưa cập nhật'); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-id-card"></i> Họ tên</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['full_name'] ?: 'Chưa cập nhật'); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-graduation-cap"></i> Lớp</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['class'] ?: 'Chưa cập nhật'); ?></div>
                        </div>
                        <div class="info-group">
                            <div class="info-label"><i class="fas fa-map-marker-alt"></i> Địa chỉ</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['address'] ?: 'Chưa cập nhật'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($user['is_main_admin'] || $user['is_super_admin']): ?>
                <div class="profile-section">
                    <div class="section-header">
                        <h3 class="section-title"><i class="fas fa-edit"></i> Cập nhật thông tin</h3>
                    </div>
                    
                    <form method="POST" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="full_name" class="form-label"><i class="fas fa-id-card"></i> Họ tên</label>
                                <input type="text" id="full_name" name="full_name" class="form-input" placeholder="Nhập họ tên" value="<?php echo htmlspecialchars($user['full_name'] ?: ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" id="email" name="email" class="form-input" placeholder="Nhập email" value="<?php echo htmlspecialchars($user['email'] ?: ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="phone" class="form-label"><i class="fas fa-phone"></i> Số điện thoại</label>
                                <input type="text" id="phone" name="phone" class="form-input" placeholder="Nhập số điện thoại" value="<?php echo htmlspecialchars($user['phone'] ?: ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="class" class="form-label"><i class="fas fa-graduation-cap"></i> Lớp</label>
                                <input type="text" id="class" name="class" class="form-input" placeholder="Nhập lớp" value="<?php echo htmlspecialchars($user['class'] ?: ''); ?>">
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="address" class="form-label"><i class="fas fa-map-marker-alt"></i> Địa chỉ</label>
                                <input type="text" id="address" name="address" class="form-input" placeholder="Nhập địa chỉ" value="<?php echo htmlspecialchars($user['address'] ?: ''); ?>">
                            </div>
                        </div>
                        
                        <button type="submit" name="update_profile" class="form-submit">
                            <i class="fas fa-save"></i> Lưu thay đổi
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.profile-sidebar', 100);
            animateElements('.profile-section', 200);
            animateElements('.info-group', 50);
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
