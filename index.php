<?php
session_start();
include 'db_config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Kiểm tra remember me token
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    
    // Tìm token trong database
    $stmt = $conn->prepare("SELECT user_id, expires_at FROM remember_tokens WHERE token = ?");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $expires_at = new DateTime($row['expires_at']);
            $now = new DateTime();
            
            // Nếu token chưa hết hạn
            if ($now < $expires_at) {
                // Lấy thông tin user
                $user_id = $row['user_id'];
                $stmt2 = $conn->prepare("SELECT id, is_main_admin, is_super_admin FROM users WHERE id = ?");
                if ($stmt2) {
                    $stmt2->bind_param("i", $user_id);
                    $stmt2->execute();
                    $user_result = $stmt2->get_result();
                    $user = $user_result->fetch_assoc();
                    
                    if ($user) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['is_main_admin'] = $user['is_main_admin'];
                        $_SESSION['is_super_admin'] = $user['is_super_admin'];
                        header("Location: dashboard.php");
                        exit;
                    }
                    $stmt2->close();
                }
            } else {
                // Xóa token hết hạn
                $stmt3 = $conn->prepare("DELETE FROM remember_tokens WHERE token = ?");
                if ($stmt3) {
                    $stmt3->bind_param("s", $token);
                    $stmt3->execute();
                    $stmt3->close();
                }
                setcookie('remember_token', '', time() - 3600, '/');
            }
        }
        $stmt->close();
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']);

    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, password, is_main_admin, is_super_admin FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_main_admin'] = $user['is_main_admin'];
            $_SESSION['is_super_admin'] = $user['is_super_admin'];

            // Nếu người dùng chọn "Ghi nhớ đăng nhập"
            if ($remember_me) {
                // Tạo token mới
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+30 days')); // Token hết hạn sau 30 ngày
                
                // Lưu token vào database
                $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("iss", $user['id'], $token, $expires_at);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Lưu token vào cookie
                    setcookie('remember_token', $token, strtotime('+30 days'), '/', '', true, true);
                }
            }

            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Tên đăng nhập hoặc mật khẩu không đúng!";
        }
    } else {
        $error = "Lỗi kết nối cơ sở dữ liệu!";
    }
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = $_POST['username'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if username or email exists
    $sql_check = "SELECT id FROM users WHERE username = ? OR email = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "ss", $username, $email);
    mysqli_stmt_execute($stmt_check);
    $result_check = mysqli_stmt_get_result($stmt_check);

    if (mysqli_num_rows($result_check) > 0) {
        $error = "Tên đăng nhập hoặc email đã tồn tại!";
    } else {
        // Insert new user
        $sql = "INSERT INTO users (username, phone, email, password) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssss", $username, $phone, $email, $password);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Đăng ký thành công! Vui lòng đăng nhập.";
        } else {
            $error = "Lỗi khi đăng ký!";
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_stmt_close($stmt_check);
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS - Đăng Nhập & Đăng Ký</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
            position: relative;
        }

        /* Animated background elements */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-circle {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
        }

        .bg-circle-1 {
            background: var(--primary);
            width: 40vw;
            height: 40vw;
            top: -10%;
            left: -10%;
            animation: float-slow 20s ease-in-out infinite alternate;
        }

        .bg-circle-2 {
            background: var(--secondary);
            width: 35vw;
            height: 35vw;
            bottom: -10%;
            right: -10%;
            animation: float-slow 25s ease-in-out infinite alternate-reverse;
        }

        .bg-circle-3 {
            background: var(--accent);
            width: 25vw;
            height: 25vw;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation: pulse 15s ease-in-out infinite;
        }

        @keyframes float-slow {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(5%, 5%) rotate(10deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(0.8); opacity: 0.1; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.2; }
        }

        /* Stars background */
        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }

        .star {
            position: absolute;
            background: white;
            border-radius: 50%;
        }

        /* Main container */
        .auth-container {
            width: 100%;
            max-width: 1100px;
            min-height: 600px;
            display: flex;
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-lg), 
                        0 0 0 1px var(--border),
                        0 0 40px rgba(112, 0, 255, 0.2),
                        0 0 80px rgba(0, 224, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            background: rgba(18, 18, 42, 0.4);
            position: relative;
            z-index: 10;
        }

        .auth-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, 
                transparent, 
                var(--primary-light), 
                var(--secondary-light), 
                var(--accent-light), 
                transparent);
            z-index: 1;
        }

        .auth-container::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, 
                transparent, 
                var(--accent-light), 
                var(--secondary-light), 
                var(--primary-light), 
                transparent);
            z-index: 1;
        }

        /* Left side - Image and welcome message */
        .auth-image {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem;
            position: relative;
            overflow: hidden;
            background: rgba(10, 10, 26, 0.6);
        }

        .auth-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                linear-gradient(135deg, rgba(112, 0, 255, 0.1), rgba(0, 224, 255, 0.1)),
                url('https://source.unsplash.com/random/1200x1600?dark,tech,abstract') center/cover no-repeat;
            opacity: 0.2;
            z-index: -1;
        }

        .auth-image-content {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 400px;
        }

        .auth-logo {
            margin-bottom: 2.5rem;
            position: relative;
            display: inline-block;
        }

        .logo-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: var(--glow), 
                        0 0 0 1px rgba(255, 255, 255, 0.1),
                        0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .logo-circle::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            animation: pulse-logo 3s ease-in-out infinite;
        }

        @keyframes pulse-logo {
            0% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 0.3; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .logo-icon {
            font-size: 3.5rem;
            color: white;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .logo-glow {
            position: absolute;
            width: 140%;
            height: 140%;
            top: -20%;
            left: -20%;
            background: radial-gradient(circle, rgba(112, 0, 255, 0.4) 0%, rgba(0, 224, 255, 0) 70%);
            z-index: -1;
            animation: rotate 10s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .auth-image-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .auth-image-description {
            font-size: 1.1rem;
            color: var(--foreground-muted);
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .auth-features {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 2rem;
            width: 100%;
        }

        .auth-feature {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .auth-feature:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .feature-text {
            font-size: 0.9rem;
            color: var(--foreground-muted);
        }

        .feature-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--foreground);
        }

        /* Right side - Forms */
        .auth-forms {
            flex: 1;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .auth-forms::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 1px;
            height: 100%;
            background: linear-gradient(180deg, 
                transparent, 
                var(--primary-light), 
                var(--secondary-light), 
                var(--accent-light), 
                transparent);
            z-index: 1;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .auth-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .auth-subtitle {
            font-size: 1rem;
            color: var(--foreground-muted);
        }

        /* Alert messages */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border-color: rgba(255, 61, 87, 0.3);
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: #00E0FF;
            border-color: rgba(0, 224, 255, 0.3);
        }

        .alert i {
            font-size: 1.25rem;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Forms */
        .auth-form {
            display: none;
        }

        .auth-form.active {
            display: block;
            animation: fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 60px;
            padding: 0 1.25rem 0 3rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.25);
            transform: translateY(-2px);
        }

        .form-input::placeholder {
            color: var(--foreground-subtle);
        }

        .form-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--foreground-muted);
            font-size: 1.25rem;
            transition: all 0.3s ease;
        }

        .form-input:focus + .form-icon {
            color: var(--primary);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--foreground-muted);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: transparent;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
        }

        .password-toggle:hover {
            color: var(--foreground);
        }

        .forgot-password {
            display: block;
            text-align: right;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            text-decoration: none;
            margin-top: 0.75rem;
            transition: all 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--secondary);
            transform: translateX(-3px);
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.5rem 0;
        }

        .remember-checkbox {
            position: relative;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .remember-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .remember-checkbox:hover input ~ .checkmark {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
        }

        .remember-checkbox input:checked ~ .checkmark {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-color: var(--primary);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .remember-checkbox input:checked ~ .checkmark:after {
            display: block;
        }

        .remember-checkbox .checkmark:after {
            left: 7px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .remember-label {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            cursor: pointer;
            user-select: none;
        }

        .form-button {
            width: 100%;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: var(--glow);
            letter-spacing: 0.5px;
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
            box-shadow: 0 10px 25px rgba(112, 0, 255, 0.4);
        }

        .form-button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .form-button:active {
            transform: translateY(-1px);
        }

        .toggle-form {
            text-align: center;
            margin-top: 2rem;
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        .toggle-form-button {
            background: transparent;
            border: none;
            color: var(--secondary);
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 0.25rem 0.5rem;
            margin-left: 0.25rem;
        }

        .toggle-form-button:hover {
            color: var(--secondary-light);
            text-decoration: underline;
        }

        /* Responsive styles */
        @media (max-width: 1024px) {
            .auth-container {
                max-width: 900px;
            }
            
            .auth-image, .auth-forms {
                padding: 2rem;
            }
            
            .auth-feature {
                padding: 0.75rem;
            }
            
            .feature-icon {
                width: 36px;
                height: 36px;
                font-size: 1.1rem;
            }
        }

        @media (max-width: 768px) {
            .auth-container {
                flex-direction: column;
                max-width: 500px;
                min-height: auto;
            }
            
            .auth-image {
                display: none;
            }
            
            .auth-forms::before {
                display: none;
            }
            
            .auth-forms {
                padding: 2rem;
            }
            
            .auth-title {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .auth-forms {
                padding: 1.5rem;
            }
            
            .auth-title {
                font-size: 1.75rem;
            }
            
            .form-input {
                height: 55px;
                font-size: 0.9rem;
            }
            
            .form-button {
                height: 55px;
            }
        }
    </style>
</head>
<body>
    <!-- Background animations -->
    <div class="bg-animation">
        <div class="bg-circle bg-circle-1"></div>
        <div class="bg-circle bg-circle-2"></div>
        <div class="bg-circle bg-circle-3"></div>
    </div>
    
    <!-- Stars background -->
    <div class="stars" id="stars"></div>
    
    <div class="auth-container">
        <!-- Left side - Image and welcome message -->
        <div class="auth-image">
            <div class="auth-image-content">
                <div class="auth-logo">
                    <div class="logo-circle">
                        <i class="fas fa-cloud logo-icon"></i>
                        <div class="logo-glow"></div>
                    </div>
                </div>
                
                <h1 class="auth-image-title">UMTERS</h1>
                <p class="auth-image-description">
                    Hệ thống quản lý hiện đại với giao diện mạnh mẽ và các tính năng tiên tiến.
                </p>
                
                <div class="auth-features">
                    <div class="auth-feature">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-text">
                            <div class="feature-title">Bảo mật cao cấp</div>
                            <p>Hệ thống xác thực đa lớp và mã hóa dữ liệu</p>
                        </div>
                    </div>
                    
                    <div class="auth-feature">
                        <div class="feature-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div class="feature-text">
                            <div class="feature-title">Hiệu suất tối ưu</div>
                            <p>Trải nghiệm nhanh chóng và mượt mà</p>
                        </div>
                    </div>
                    
                    <div class="auth-feature">
                        <div class="feature-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="feature-text">
                            <div class="feature-title">Công cụ đa dạng</div>
                            <p>Nhiều tiện ích và tính năng quản lý hiện đại</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right side - Forms -->
        <div class="auth-forms">
            <div class="auth-header">
                <h2 class="auth-title" id="form-title">Đăng Nhập</h2>
                <p class="auth-subtitle" id="form-subtitle">Truy cập tài khoản của bạn</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Login Form -->
            <form method="POST" id="login-form" class="auth-form <?php echo !isset($_POST['register']) ? 'active' : ''; ?>">
                <div class="form-group">
                    <input type="text" id="login-username" name="username" class="form-input" placeholder="Tên đăng nhập" required>
                    <i class="fas fa-user form-icon"></i>
                </div>
                
                <div class="form-group">
                    <input type="password" id="login-password" name="password" class="form-input" placeholder="Mật khẩu" required>
                    <i class="fas fa-lock form-icon"></i>
                    <button type="button" class="password-toggle" id="eye">
                        <i class="fas fa-eye"></i>
                    </button>
                    <a href="forgot_password.php" class="forgot-password">Quên mật khẩu?</a>
                </div>
                
                <div class="remember-me">
                    <label class="remember-checkbox">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <span class="checkmark"></span>
                    </label>
                    <span class="remember-label">Ghi nhớ đăng nhập</span>
                </div>
                
                <button type="submit" name="login" class="form-button">Đăng Nhập</button>
                
                <div class="toggle-form">
                    Chưa có tài khoản?
                    <button type="button" class="toggle-form-button" onclick="toggleForm('register')">Đăng ký ngay</button>
                </div>
            </form>
            
            <!-- Register Form -->
            <form method="POST" id="register-form" class="auth-form <?php echo isset($_POST['register']) ? 'active' : ''; ?>">
                <div class="form-group">
                    <input type="text" id="register-username" name="username" class="form-input" placeholder="Tên đăng nhập" required>
                    <i class="fas fa-user form-icon"></i>
                </div>
                
                <div class="form-group">
                    <input type="tel" id="register-phone" name="phone" class="form-input" placeholder="Số điện thoại" required>
                    <i class="fas fa-phone form-icon"></i>
                </div>
                
                <div class="form-group">
                    <input type="email" id="register-email" name="email" class="form-input" placeholder="Email" required>
                    <i class="fas fa-envelope form-icon"></i>
                </div>
                
                <div class="form-group">
                    <input type="password" id="register-password" name="password" class="form-input" placeholder="Mật khẩu" required>
                    <i class="fas fa-lock form-icon"></i>
                    <button type="button" class="password-toggle" id="eye-register">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <button type="submit" name="register" class="form-button">Đăng Ký</button>
                
                <div class="toggle-form">
                    Đã có tài khoản?
                    <button type="button" class="toggle-form-button" onclick="toggleForm('login')">Đăng nhập ngay</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Create stars background
        document.addEventListener('DOMContentLoaded', () => {
            createStars();
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 500);
                });
            }, 5000);
        });
        
        // Create stars in the background
        function createStars() {
            const starsContainer = document.getElementById('stars');
            const starCount = 100;
            
            for (let i = 0; i < starCount; i++) {
                const star = document.createElement('div');
                star.className = 'star';
                
                // Random size
                const size = Math.random() * 2 + 1;
                star.style.width = `${size}px`;
                star.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                star.style.left = `${posX}%`;
                star.style.top = `${posY}%`;
                
                // Random opacity
                const opacity = Math.random() * 0.5 + 0.1;
                star.style.opacity = opacity;
                
                // Random animation
                const duration = Math.random() * 3 + 1;
                star.style.animation = `twinkle ${duration}s ease-in-out infinite alternate`;
                
                starsContainer.appendChild(star);
            }
        }
        
        // Toggle between login and register forms
        function toggleForm(formType) {
            const loginForm = document.getElementById('login-form');
            const registerForm = document.getElementById('register-form');
            const formTitle = document.getElementById('form-title');
            const formSubtitle = document.getElementById('form-subtitle');
            
            if (formType === 'login') {
                loginForm.classList.add('active');
                registerForm.classList.remove('active');
                formTitle.textContent = 'Đăng Nhập';
                formSubtitle.textContent = 'Truy cập tài khoản của bạn';
                
                // Add animation
                formTitle.style.animation = 'fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                formSubtitle.style.animation = 'fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                setTimeout(() => {
                    formTitle.style.animation = '';
                    formSubtitle.style.animation = '';
                }, 500);
            } else {
                loginForm.classList.remove('active');
                registerForm.classList.add('active');
                formTitle.textContent = 'Đăng Ký';
                formSubtitle.textContent = 'Tạo tài khoản mới để bắt đầu';
                
                // Add animation
                formTitle.style.animation = 'fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                formSubtitle.style.animation = 'fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                setTimeout(() => {
                    formTitle.style.animation = '';
                    formSubtitle.style.animation = '';
                }, 500);
            }
        }
        
        // Password visibility toggle for login form
        document.getElementById("eye").addEventListener("click", function() {
            const passwordInput = document.getElementById('login-password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            
            // Add animation
            this.style.transform = 'scale(1.2)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 200);
        });
        
        // Password visibility toggle for register form
        document.getElementById("eye-register").addEventListener("click", function() {
            const passwordInput = document.getElementById('register-password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            
            // Add animation
            this.style.transform = 'scale(1.2)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 200);
        });
        
        // Add twinkle animation for stars
        const style = document.createElement('style');
        style.textContent = `
            @keyframes twinkle {
                0% { opacity: 0.1; }
                100% { opacity: 0.7; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
