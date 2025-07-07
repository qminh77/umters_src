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

// Xử lý đổi mật khẩu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Kiểm tra mật khẩu hiện tại
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!password_verify($current_password, $user_data['password'])) {
        $password_message = "Mật khẩu hiện tại không chính xác!";
    } elseif (strlen($new_password) < 8) {
        $password_message = "Mật khẩu mới phải có ít nhất 8 ký tự!";
    } elseif ($new_password !== $confirm_password) {
        $password_message = "Mật khẩu mới và xác nhận mật khẩu không khớp!";
    } else {
        $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new_password_hashed, $user_id);
        if ($stmt->execute()) {
            $password_message = "Đổi mật khẩu thành công!";
            $success = true;
        } else {
            $password_message = "Lỗi khi đổi mật khẩu: " . $conn->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS Đổi Mật Khẩu - Quản Lý Hiện Đại</title>
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
        .dashboard-container {
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            padding: 1.5rem;
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header section */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
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

        .header-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
            position: relative;
            overflow: hidden;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 0.3; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            position: relative;
        }

        .logo-text::after {
            content: 'UMTERS';
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0;
            animation: textFlicker 8s linear infinite;
        }

        @keyframes textFlicker {
            0%, 92%, 100% { opacity: 0; }
            94%, 96% { opacity: 1; }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-to-home {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            color: var(--foreground);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .back-to-home:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem 0.5rem 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .user-avatar::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.3));
            top: 0;
            left: 0;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Main content */
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        /* Error and success messages */
        .error-message,
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: #00E0FF;
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Password Change Panel */
        .password-panel {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            max-width: 600px;
            margin: 0 auto;
        }

        .password-panel::before {
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

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .panel-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .panel-title i {
            color: var(--accent);
            font-size: 1.5rem;
        }

        .password-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin-top: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--foreground-muted);
        }

        .form-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 61, 255, 0.25);
        }

        .password-requirements {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 0.5rem;
        }

        .requirement-title {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .requirement-title i {
            color: var(--secondary);
        }

        .requirement-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .requirement-item {
            font-size: 0.8125rem;
            color: var(--foreground-muted);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .requirement-item i {
            color: var(--accent);
            font-size: 0.75rem;
        }

        .form-button {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            box-shadow: var(--glow-accent);
            margin-top: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .form-button::before {
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

        .form-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 61, 255, 0.4);
        }

        .form-button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        /* Animations */
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
                gap: 1rem;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
                gap: 0.75rem;
            }

            .back-to-home, .user-profile {
                width: 100%;
            }

            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .password-panel {
                padding: 1rem;
            }

            .panel-title {
                font-size: 1.25rem;
            }

            .form-button {
                padding: 0.75rem 1rem;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Animate elements on page load
            const elements = document.querySelectorAll('.password-panel');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Auto-hide messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.success-message, .error-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                });
            }, 5000);
            
            // Create particle effect
            createParticles();
            
            // Password strength validation
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const lengthCheck = document.getElementById('length_check');
            const matchCheck = document.getElementById('match_check');
            
            function validatePassword() {
                const newPassword = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                // Check length
                if (newPassword.length >= 8) {
                    lengthCheck.innerHTML = '<i class="fas fa-check-circle"></i> Mật khẩu có ít nhất 8 ký tự';
                    lengthCheck.style.color = '#00E0FF';
                } else {
                    lengthCheck.innerHTML = '<i class="fas fa-times-circle"></i> Mật khẩu có ít nhất 8 ký tự';
                    lengthCheck.style.color = 'var(--foreground-muted)';
                }
                
                // Check match
                if (confirmPassword && newPassword === confirmPassword) {
                    matchCheck.innerHTML = '<i class="fas fa-check-circle"></i> Mật khẩu xác nhận khớp';
                    matchCheck.style.color = '#00E0FF';
                } else if (confirmPassword) {
                    matchCheck.innerHTML = '<i class="fas fa-times-circle"></i> Mật khẩu xác nhận khớp';
                    matchCheck.style.color = 'var(--foreground-muted)';
                }
            }
            
            if (newPasswordInput && confirmPasswordInput) {
                newPasswordInput.addEventListener('input', validatePassword);
                confirmPasswordInput.addEventListener('input', validatePassword);
            }
        });
        
        // Function to create particle effect
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.className = 'particles';
            particlesContainer.style.position = 'fixed';
            particlesContainer.style.top = '0';
            particlesContainer.style.left = '0';
            particlesContainer.style.width = '100%';
            particlesContainer.style.height = '100%';
            particlesContainer.style.pointerEvents = 'none';
            particlesContainer.style.zIndex = '0';
            document.body.appendChild(particlesContainer);
            
            const particleCount = 30;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
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
                
                // Style
                particle.style.position = 'absolute';
                particle.style.borderRadius = '50%';
                particle.style.opacity = '0.3';
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon floating"><i class="fas fa-key"></i></div>
                <div class="logo-text">UMTERS Password</div>
            </div>
            
            <div class="header-actions">
                <a href="dashboard.php" class="back-to-home">
                    <i class="fas fa-arrow-left"></i> Trở về trang chủ
                </a>
                
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                    <div class="user-info">
                        <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                        <p class="user-role">
                            <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <?php if (isset($edit_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
            <?php endif; ?>
            <?php if (isset($password_message)): ?>
                <div class="<?php echo isset($success) && $success ? 'success-message' : 'error-message'; ?>">
                    <?php echo htmlspecialchars($password_message); ?>
                </div>
            <?php endif; ?>

            <!-- Password Change Panel -->
            <div class="password-panel">
                <div class="panel-header">
                    <h2 class="panel-title"><i class="fas fa-key"></i> Đổi Mật Khẩu</h2>
                </div>
                
                <form class="password-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="current_password">Mật khẩu hiện tại</label>
                        <input type="password" id="current_password" name="current_password" placeholder="Nhập mật khẩu hiện tại" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="new_password">Mật khẩu mới</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Nhập mật khẩu mới" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Xác nhận mật khẩu mới</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Nhập lại mật khẩu mới" class="form-input" required>
                    </div>
                    
                    <div class="password-requirements">
                        <div class="requirement-title">
                            <i class="fas fa-shield-alt"></i> Yêu cầu mật khẩu
                        </div>
                        <ul class="requirement-list">
                            <li class="requirement-item" id="length_check">
                                <i class="fas fa-circle"></i> Mật khẩu có ít nhất 8 ký tự
                            </li>
                            <li class="requirement-item" id="match_check">
                                <i class="fas fa-circle"></i> Mật khẩu xác nhận khớp
                            </li>
                        </ul>
                    </div>
                    
                    <button type="submit" name="change_password" class="form-button">
                        <i class="fas fa-save"></i> Cập nhật mật khẩu
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>
