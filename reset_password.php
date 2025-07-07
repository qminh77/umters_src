<?php
session_start();
include 'db_config.php';

if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);

    // Kiểm tra token
    $sql = "SELECT user_id, expires_at FROM password_resets WHERE token = '$token'";
    $result = mysqli_query($conn, $sql);
    if (mysqli_num_rows($result) > 0) {
        $reset = mysqli_fetch_assoc($result);
        $user_id = $reset['user_id'];
        $expires_at = strtotime($reset['expires_at']);
        $now = strtotime(date('Y-m-d H:i:s'));

        if ($now <= $expires_at) {
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $sql_update = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql_update);
                mysqli_stmt_bind_param($stmt, "si", $new_password, $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    // Xóa token sau khi sử dụng
                    $sql_delete = "DELETE FROM password_resets WHERE token = '$token'";
                    mysqli_query($conn, $sql_delete);
                    $success = "Mật khẩu đã được đặt lại thành công! Vui lòng đăng nhập.";
                } else {
                    $error = "Lỗi khi đặt lại mật khẩu!";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Link đã hết hạn!";
        }
    } else {
        $error = "Link không hợp lệ!";
    }
} else {
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt Lại Mật Khẩu</title>
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

        .reset-container {
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

        input[type="password"] {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

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

        .back-to-login {
            margin-top: 10px;
            color: #6e8efb;
            text-decoration: none;
            font-size: 12px;
        }

        .back-to-login:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .reset-container {
                padding: 20px;
                margin: 10px;
            }

            h2 {
                font-size: 20px;
            }

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
    <div class="reset-container">
        <h2>Đặt Lại Mật Khẩu</h2>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <?php if (isset($success)) echo "<p class='success'>$success</p>"; ?>
        <?php if (!isset($success) && !isset($error) || isset($error)): ?>
            <form method="POST">
                <input type="password" name="new_password" placeholder="Mật khẩu mới" required>
                <button type="submit">Đặt Lại Mật Khẩu</button>
            </form>
        <?php endif; ?>
        <a href="index.php" class="back-to-login">Quay lại Đăng Nhập</a>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>