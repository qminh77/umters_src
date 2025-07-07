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

// Lấy thông tin user hiện tại
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

// Lấy thông tin admin cần chỉnh sửa
$admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;
$stmt = $conn->prepare("SELECT id, username, phone, email, full_name, class, address, is_main_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result_admin = $stmt->get_result();
if ($result_admin && $result_admin->num_rows > 0) {
    $admin = $result_admin->fetch_assoc();
} else {
    $edit_error = "Admin không tồn tại.";
    header("Location: manage_users.php");
    exit;
}
$stmt->close();

// Xử lý cập nhật thông tin admin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_admin']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $full_name = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_STRING);
    $class = filter_input(INPUT_POST, 'class', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
    $is_main_admin = isset($_POST['is_main_admin']) ? 1 : 0;

    // Kiểm tra email hợp lệ
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $edit_error = "Email không hợp lệ!";
    } else {
        $stmt = $conn->prepare("UPDATE users SET phone = ?, email = ?, full_name = ?, class = ?, address = ?, is_main_admin = ? WHERE id = ?");
        $stmt->bind_param("sssssii", $phone, $email, $full_name, $class, $address, $is_main_admin, $admin_id);
        if ($stmt->execute()) {
            $success_message = "Cập nhật thông tin admin thành công!";
            // Cập nhật lại $admin
            $admin['phone'] = $phone;
            $admin['email'] = $email;
            $admin['full_name'] = $full_name;
            $admin['class'] = $class;
            $admin['address'] = $address;
            $admin['is_main_admin'] = $is_main_admin;
        } else {
            $edit_error = "Lỗi khi cập nhật thông tin admin: " . $conn->error;
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
    <title>Chỉnh Sửa User - Quản Lý Hiện Đại</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #74ebd5, #acb6e5);
            --container-bg: rgba(255, 255, 255, 0.95);
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --text-secondary: #64748b;
            --link-color: #3b82f6;
            --link-hover-color: #2563eb;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --border-radius: 1.5rem;
            --button-radius: 0.75rem;
            --padding: 1.5rem;
            --small-padding: 1rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
            line-height: 1.6;
        }

        .dashboard-container {
            background: var(--container-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1400px;
            padding: var(--padding);
            margin: 1.5rem auto;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            z-index: 5;
        }

        .dashboard-container:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-title {
            font-size: 1.875rem;
            font-weight: 700;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradientShift 5s ease infinite;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .user-avatar:hover {
            transform: rotate(360deg);
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-color);
        }

        .user-role {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .content-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: var(--padding);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            opacity: 0.8;
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary-gradient-start);
            font-size: 1.25rem;
        }

        .form-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: var(--padding);
            box-shadow: var(--shadow-sm);
            max-width: 600px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="checkbox"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-group input[type="checkbox"] {
            width: auto;
            margin-right: 0.5rem;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus {
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.25);
            outline: none;
        }

        .form-group button {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-group button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .error-message,
        .success-message {
            padding: 0.75rem 1rem;
            border-radius: var(--button-radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 2.5rem;
            animation: slideIn 0.5s ease;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            :root {
                --padding: 1rem;
                --small-padding: 0.75rem;
            }

            .dashboard-container {
                margin: 1rem;
                padding: var(--padding);
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .form-container {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            .form-group input,
            .form-group button {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Chỉnh Sửa User</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                <div class="user-details">
                    <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                    <p class="user-role">
                        <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                    </p>
                </div>
            </div>
        </div>

        <?php include 'taskbar.php'; ?>

        <?php if ($edit_error): ?>
            <p class="error-message"><?php echo htmlspecialchars($edit_error); ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-user-edit"></i> Chỉnh Sửa Thông Tin Admin</h2>
            <div class="form-container">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="username">Tên đăng nhập (không thể thay đổi)</label>
                        <input type="text" id="username" value="<?php echo htmlspecialchars($admin['username']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label for="phone">Số điện thoại</label>
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email'] ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="full_name">Họ tên</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($admin['full_name'] ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="class">Lớp</label>
                        <input type="text" id="class" name="class" value="<?php echo htmlspecialchars($admin['class'] ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="address">Địa chỉ</label>
                        <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($admin['address'] ?: ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_main_admin" value="1" <?php echo $admin['is_main_admin'] ? 'checked' : ''; ?>> Main Admin
                        </label>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="update_admin">Cập Nhật</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const elements = document.querySelectorAll('.content-section, .form-container');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>