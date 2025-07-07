<?php
session_start();
include 'db_config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Xử lý đăng ký
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    // Kiểm tra CSRF token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Yêu cầu không hợp lệ!";
    } else {
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        // Kiểm tra định dạng email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Định dạng email không hợp lệ!";
        }
        // Kiểm tra định dạng số điện thoại (10-11 số, bắt đầu bằng 0)
        elseif (!preg_match('/^0[0-9]{9,10}$/', $phone)) {
            $error = "Số điện thoại không hợp lệ! (Phải bắt đầu bằng 0 và 10-11 số)";
        } else {
            // Kiểm tra trùng username hoặc email
            $sql_check = "SELECT id FROM users WHERE username = '$username' OR email = '$email'";
            $result_check = mysqli_query($conn, $sql_check);
            if (mysqli_num_rows($result_check) > 0) {
                $error = "Tên đăng nhập hoặc email đã tồn tại!";
            } else {
                $sql = "INSERT INTO users (username, phone, email, password) VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "ssss", $username, $phone, $email, $password);
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Đăng ký thành công! Vui lòng <a href='login.php'>đăng nhập</a>.";
                    unset($_SESSION['csrf_token']); // Đặt lại token sau khi thành công
                } else {
                    $error = "Lỗi khi đăng ký!";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Ký</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* (Giữ nguyên CSS như trước) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #6e8efb, #a777e3);
            overflow: hidden;
        }

        .container {
            background: #ffffff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
            font-weight: 600;
        }

        .error, .success {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            display: inline-block;
        }

        .error {
            color: #ff4444;
            background: #ffebee;
        }

        .success {
            color: #28a745;
            background: #e8f5e9;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        input[type="text"],
        input[type="tel"],
        input[type="email"],
        input[type="password"] {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="tel"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: #6e8efb;
        }

        button {
            padding: 12px;
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: transform 0.3s, background 0.3s;
        }

        button:hover {
            transform: scale(1.05);
            background: linear-gradient(90deg, #5d7de8, #8f5ec1);
        }

        .toggle-form {
            margin-top: 10px;
            color: #6e8efb;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
        }

        .toggle-form:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px;
                margin: 10px;
            }

            h2 {
                font-size: 20px;
            }

            input[type="text"],
            input[type="tel"],
            input[type="email"],
            input[type="password"] {
                font-size: 12px;
                padding: 10px;
            }

            button {
                font-size: 12px;
                padding: 10px;
            }

            .error, .success {
                font-size: 12px;
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>

        <h2>Đăng Ký</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="text" name="username" placeholder="Tên đăng nhập" required>
            <input type="tel" name="phone" placeholder="Số điện thoại" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Mật khẩu" required>
            <button type="submit" name="register">Đăng Ký</button>
        </form>
        <p class="toggle-form" onclick="window.location.href='login.php'">Đã có tài khoản? Đăng nhập ngay!</p>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>