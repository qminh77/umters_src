<?php
session_start();
include 'db_config.php';

// Bật báo lỗi để debug (tắt trên production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Lỗi kết nối cơ sở dữ liệu. Vui lòng thử lại sau.");
}
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    die("Lỗi truy vấn cơ sở dữ liệu. Vui lòng thử lại sau.");
}
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
    header("Location: /dashboard");
    exit;
}

// Lấy danh sách email logs
$email_logs = [];
try {
    $stmt = $conn->prepare("SELECT el.id, el.recipient, el.subject, el.content, el.send_time AS sent_at, u.username AS sender 
                            FROM email_logs el 
                            LEFT JOIN users u ON el.sender_id = u.id 
                            ORDER BY el.send_time DESC");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $edit_error = "Lỗi chuẩn bị truy vấn cơ sở dữ liệu. Vui lòng kiểm tra bảng email_logs.";
    } else {
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            $edit_error = "Lỗi thực thi truy vấn cơ sở dữ liệu. Vui lòng thử lại sau.";
        } else {
            $result_logs = $stmt->get_result();
            $email_logs = $result_logs->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    $edit_error = "Lỗi khi lấy lịch sử email: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch Sử Email - Quản Lý Hiện Đại</title>
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

        .email-logs {
            display: grid;
            gap: 1rem;
        }

        .email-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: var(--small-padding);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-gradient-start);
        }

        .email-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .email-card p {
            margin: 0.5rem 0;
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
        }

        .email-card p strong {
            color: var(--text-color);
            margin-right: 0.5rem;
            font-weight: 600;
        }

        .email-card .body {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: var(--button-radius);
            margin-top: 0.5rem;
            font-size: 0.875rem;
            white-space: pre-wrap;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .error-message {
            padding: 0.75rem 1rem;
            border-radius: var(--button-radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 2.5rem;
            animation: slideIn 0.5s ease;
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

            .email-logs {
                grid-template-columns: 1fr;
            }

            .email-card p,
            .email-card .body {
                font-size: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Lịch Sử Email</h1>
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

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-history"></i> Lịch Sử Email</h2>
            <div class="email-logs">
                <?php if (empty($email_logs)): ?>
                    <p>Chưa có email nào được gửi.</p>
                <?php else: ?>
                    <?php foreach ($email_logs as $log): ?>
                        <div class="email-card">
                            <p><strong>Người gửi:</strong> <?php echo htmlspecialchars($log['sender'] ?: 'Unknown'); ?></p>
                            <p><strong>Người nhận:</strong> <?php echo htmlspecialchars($log['recipient']); ?></p>
                            <p><strong>Chủ đề:</strong> <?php echo htmlspecialchars($log['subject']); ?></p>
                            <p><strong>Thời gian:</strong> <?php echo htmlspecialchars($log['sent_at']); ?></p>
                            <div class="body"><?php echo htmlspecialchars($log['content']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const elements = document.querySelectorAll('.content-section, .email-card');
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