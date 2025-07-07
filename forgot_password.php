<?php
session_start();

// Bật debug để hiển thị lỗi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kiểm tra và include db_config.php
if (!file_exists('db_config.php')) {
    die("Lỗi: Không tìm thấy file db_config.php!");
}
include 'db_config.php';

// Kiểm tra kết nối cơ sở dữ liệu
if (!isset($conn) || !$conn) {
    die("Lỗi: Không thể kết nối cơ sở dữ liệu: " . mysqli_connect_error());
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        die("Lỗi khi tạo CSRF token: " . $e->getMessage());
    }
}

// Bước 1: Gửi email xác nhận
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_reset'])) {
    // Kiểm tra CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Yêu cầu không hợp lệ!";
    } else {
        $email = mysqli_real_escape_string($conn, $_POST['email']);

        // Kiểm tra định dạng email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Định dạng email không hợp lệ!";
        } else {
            $sql = "SELECT id, email FROM users WHERE email = '$email'";
            $result = mysqli_query($conn, $sql);
            if (!$result) {
                $error = "Lỗi truy vấn SQL: " . mysqli_error($conn);
            } elseif (mysqli_num_rows($result) > 0) {
                $user = mysqli_fetch_assoc($result);
                try {
                    $token = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error = "Lỗi khi tạo token: " . $e->getMessage();
                }

                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $sql = "INSERT INTO password_resets (user_id, token, expiry) VALUES (?, ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                if (!$stmt) {
                    $error = "Lỗi chuẩn bị truy vấn: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($stmt, "iss", $user['id'], $token, $expiry);
                    if (mysqli_stmt_execute($stmt)) {
                        // Gửi email (giả lập)
                        $reset_link = "http://admin.nhontrachcf.edu.vn/forgot_password.php?token=$token";
                        $success = "Link đặt lại mật khẩu (giả lập): <a href='$reset_link'>$reset_link</a>";
                        unset($_SESSION['csrf_token']);
                    } else {
                        $error = "Lỗi khi tạo yêu cầu đặt lại mật khẩu: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Email không tồn tại!";
            }
        }
    }
}

// Bước 2: Xử lý đổi mật khẩu
if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    $sql = "SELECT user_id FROM password_resets WHERE token = '$token' AND expiry > NOW()";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        $error = "Lỗi truy vấn SQL: " . mysqli_error($conn);
    } elseif (mysqli_num_rows($result) > 0) {
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
            // Kiểm tra CSRF token
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                $error = "Yêu cầu không hợp lệ!";
            } else {
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $user_id = mysqli_fetch_assoc($result)['user_id'];

                $sql = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                if (!$stmt) {
                    $error = "Lỗi chuẩn bị truy vấn: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($stmt, "si", $new_password, $user_id);
                    if (mysqli_stmt_execute($stmt)) {
                        $sql = "DELETE FROM password_resets WHERE user_id = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        if (!$stmt) {
                            $error = "Lỗi chuẩn bị truy vấn: " . mysqli_error($conn);
                        } else {
                            mysqli_stmt_bind_param($stmt, "i", $user_id);
                            if (mysqli_stmt_execute($stmt)) {
                                $success = "Mật khẩu đã được đổi! Vui lòng <a href='login.php'>đăng nhập</a>.";
                                unset($_SESSION['csrf_token']);
                                unset($_GET['token']);
                            } else {
                                $error = "Lỗi khi xóa token: " . mysqli_error($conn);
                            }
                        }
                    } else {
                        $error = "Lỗi khi đổi mật khẩu: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    } else {
        $error = "Token không hợp lệ hoặc đã hết hạn!";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quên Mật Khẩu</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
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

        input[type="email"],
        input[type="password"] {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

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

        <?php if (!isset($_GET['token'])): ?>
            <h2>Quên Mật Khẩu</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="email" name="email" placeholder="Nhập email" required>
                <button type="submit" name="send_reset">Gửi Yêu Cầu</button>
            </form>
            <p class="toggle-form" onclick="window.location.href='login.php'">Quay lại đăng nhập</p>
        <?php else: ?>
            <h2>Đổi Mật Khẩu</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="password" name="new_password" placeholder="Mật khẩu mới" required>
                <button type="submit" name="reset_password">Đổi Mật Khẩu</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
// Đóng kết nối an toàn
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>