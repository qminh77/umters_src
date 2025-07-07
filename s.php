<?php
include 'db_config.php';

if (!isset($_GET['c']) || empty($_GET['c'])) {
    die("Short link không hợp lệ!");
}

$short_code = mysqli_real_escape_string($conn, $_GET['c']);
$sql = "SELECT original_url, access_count, password, expiration_date FROM shortlinks WHERE short_code = '$short_code'";
$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $link = mysqli_fetch_assoc($result);

    // Kiểm tra ngày hết hạn
    if ($link['expiration_date'] && strtotime($link['expiration_date']) < time()) {
        die("Short link đã hết hạn!");
    }

    // Kiểm tra mật khẩu
    if ($link['password']) {
        if (!isset($_POST['password'])) {
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
                        --primary-color: #007bff;
                        --secondary-color: #6c757d;
                        --background-color: #f4f7fa;
                        --card-bg: #ffffff;
                        --text-color: #333333;
                        --border-color: #e0e0e0;
                        --shadow-color: rgba(0, 0, 0, 0.1);
                        --error-color: #dc3545;
                        --success-color: #28a745;
                        --primary-gradient-start: #007bff;
                        --primary-gradient-end: #0056b3;
                        --button-radius: 8px;
                        --card-radius: 12px;
                        --small-radius: 4px;
                        --transition-speed: 0.3s;
                    }

                    body {
                        font-family: 'Poppins', sans-serif;
                        background-color: var(--background-color);
                        color: var(--text-color);
                        margin: 0;
                        padding: 0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                        line-height: 1.6;
                    }

                    .password-form {
                        max-width: 400px;
                        width: 90%;
                        margin: 20px;
                        padding: 30px;
                        background: var(--card-bg);
                        border-radius: var(--card-radius);
                        box-shadow: 0 4px 20px var(--shadow-color);
                        text-align: center;
                    }

                    h2 {
                        color: var(--primary-color);
                        margin-bottom: 20px;
                        font-size: 1.5rem;
                        font-weight: 600;
                    }

                    .form-group {
                        margin-bottom: 20px;
                        position: relative;
                    }

                    input[type="password"] {
                        width: 100%;
                        padding: 12px 40px 12px 15px;
                        border: 2px solid var(--border-color);
                        border-radius: var(--small-radius);
                        font-size: 1rem;
                        box-sizing: border-box;
                        transition: border-color var(--transition-speed) ease;
                    }

                    input[type="password"]:focus {
                        outline: none;
                        border-color: var(--primary-color);
                    }

                    .form-group i {
                        position: absolute;
                        right: 15px;
                        top: 50%;
                        transform: translateY(-50%);
                        color: var(--secondary-color);
                        font-size: 1rem;
                    }

                    button {
                        padding: 12px 25px;
                        background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
                        color: white;
                        border: none;
                        border-radius: var(--button-radius);
                        cursor: pointer;
                        font-size: 1rem;
                        font-weight: 500;
                        transition: all var(--transition-speed) ease;
                        width: 100%;
                    }

                    button:hover {
                        background: linear-gradient(90deg, var(--primary-gradient-end), var(--primary-gradient-start));
                        transform: scale(1.05);
                    }

                    .error-message {
                        color: var(--error-color);
                        margin-top: 10px;
                        font-size: 0.9rem;
                        font-weight: 500;
                    }

                    /* Responsive */
                    @media (max-width: 480px) {
                        .password-form {
                            padding: 20px;
                            width: 95%;
                        }

                        h2 {
                            font-size: 1.3rem;
                        }

                        input[type="password"] {
                            font-size: 0.9rem;
                            padding: 10px 35px 10px 12px;
                        }

                        button {
                            font-size: 0.9rem;
                            padding: 10px 20px;
                        }
                    }

                    @media (max-width: 320px) {
                        .password-form {
                            padding: 15px;
                        }

                        h2 {
                            font-size: 1.2rem;
                        }

                        input[type="password"] {
                            font-size: 0.85rem;
                            padding: 8px 30px 8px 10px;
                        }

                        button {
                            font-size: 0.85rem;
                            padding: 8px 15px;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="password-form">
                    <h2>Short link được bảo vệ bằng mật khẩu</h2>
                    <form method="POST" action="s.php?c=<?php echo htmlspecialchars($short_code); ?>">
                        <div class="form-group">
                            <input type="password" name="password" placeholder="Nhập mật khẩu" required>
                            <i class="fas fa-lock"></i>
                        </div>
                        <button type="submit">Xác nhận</button>
                    </form>
                </div>
            </body>
            </html>
            <?php
            exit;
        } else {
            $input_password = trim($_POST['password']);
            if (!password_verify($input_password, $link['password'])) {
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
                            --primary-color: #007bff;
                            --secondary-color: #6c757d;
                            --background-color: #f4f7fa;
                            --card-bg: #ffffff;
                            --text-color: #333333;
                            --border-color: #e0e0e0;
                            --shadow-color: rgba(0, 0, 0, 0.1);
                            --error-color: #dc3545;
                            --success-color: #28a745;
                            --primary-gradient-start: #007bff;
                            --primary-gradient-end: #0056b3;
                            --button-radius: 15px;
                            --card-radius: 21px;
                            --small-radius: 21px;
                            --transition-speed: 0.3s;
                        }

                        body {
                            font-family: 'Poppins', sans-serif;
                            background-color: var(--background-color);
                            color: var(--text-color);
                            margin: 0;
                            padding: 0;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            min-height: 100vh;
                            line-height: 1.6;
                        }

                        .password-form {
                            max-width: 400px;
                            width: 90%;
                            margin: 20px;
                            padding: 30px;
                            background: var(--card-bg);
                            border-radius: var(--card-radius);
                            box-shadow: 0 4px 20px var(--shadow-color);
                            text-align: center;
                        }

                        h2 {
                            color: var(--primary-color);
                            margin-bottom: 20px;
                            font-size: 1.5rem;
                            font-weight: 600;
                        }

                        .form-group {
                            margin-bottom: 20px;
                            position: relative;
                        }

                        input[type="password"] {
                            width: 100%;
                            padding: 12px 40px 12px 15px;
                            border: 2px solid var(--border-color);
                            border-radius: var(--small-radius);
                            font-size: 1rem;
                            box-sizing: border-box;
                            transition: border-color var(--transition-speed) ease;
                        }

                        input[type="password"]:focus {
                            outline: none;
                            border-color: var(--primary-color);
                        }

                        .form-group i {
                            position: absolute;
                            right: 15px;
                            top: 50%;
                            transform: translateY(-50%);
                            color: var(--secondary-color);
                            font-size: 1rem;
                        }

                        button {
                            padding: 12px 25px;
                            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
                            color: white;
                            border: none;
                            border-radius: var(--button-radius);
                            cursor: pointer;
                            font-size: 1rem;
                            font-weight: 500;
                            transition: all var(--transition-speed) ease;
                            width: 100%;
                        }

                        button:hover {
                            background: linear-gradient(90deg, var(--primary-gradient-end), var(--primary-gradient-start));
                            transform: scale(1.05);
                        }

                        .error-message {
                            color: var(--error-color);
                            margin-top: 10px;
                            font-size: 0.9rem;
                            font-weight: 500;
                        }

                        /* Responsive */
                        @media (max-width: 480px) {
                            .password-form {
                                padding: 20px;
                                width: 95%;
                            }

                            h2 {
                                font-size: 1.3rem;
                            }

                            input[type="password"] {
                                font-size: 0.9rem;
                                padding: 10px 35px 10px 12px;
                            }

                            button {
                                font-size: 0.9rem;
                                padding: 10px 20px;
                            }
                        }

                        @media (max-width: 320px) {
                            .password-form {
                                padding: 15px;
                            }

                            h2 {
                                font-size: 1.2rem;
                            }

                            input[type="password"] {
                                font-size: 0.85rem;
                                padding: 8px 30px 8px 10px;
                            }

                            button {
                                font-size: 0.85rem;
                                padding: 8px 15px;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="password-form">
                        <h2>Short link được bảo vệ bằng mật khẩu</h2>
                        <form method="POST" action="s.php?c=<?php echo htmlspecialchars($short_code); ?>">
                            <div class="form-group">
                                <input type="password" name="password" placeholder="Nhập mật khẩu" required>
                                <i class="fas fa-lock"></i>
                            </div>
                            <button type="submit">Xác nhận</button>
                            <p class="error-message">Mật khẩu không đúng! Vui lòng thử lại.</p>
                        </form>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }
        }
    }

    // Tăng số lần truy cập
    $new_access_count = $link['access_count'] + 1;
    $sql_update = "UPDATE shortlinks SET access_count = $new_access_count WHERE short_code = '$short_code'";
    mysqli_query($conn, $sql_update);

    // Chuyển hướng
    header("Location: " . $link['original_url']);
    exit;
} else {
    die("Short link không tồn tại!");
}

mysqli_close($conn);
?>