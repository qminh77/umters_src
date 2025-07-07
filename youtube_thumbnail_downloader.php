<?php
// Bật debug để hiển thị lỗi (tắt trên production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', '/home/u459537937/domains/umters.club/public_html/error_log.txt');

// Khởi tạo session
if (session_status() === PHP_SESSION_NONE) {
    try {
        session_start();
    } catch (Exception $e) {
        error_log("Lỗi khi khởi tạo session: " . $e->getMessage());
        die("Lỗi hệ thống: Không thể khởi tạo phiên.");
    }
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        error_log("Lỗi khi tạo CSRF token: " . $e->getMessage());
        die("Lỗi hệ thống: Không thể tạo mã bảo mật.");
    }
}

// Kết nối database (cho taskbar.php)
if (!file_exists('db_config.php') || !is_readable('db_config.php')) {
    error_log("File db_config.php not found or not readable");
    die("Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.");
}
include 'db_config.php';

// Kiểm tra kết nối database
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.");
}

// Lấy thông tin user
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $error_message = "Lỗi hệ thống: Không thể truy vấn thông tin người dùng.";
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $error_message = "Lỗi hệ thống: Không thể truy vấn thông tin người dùng.";
    } else {
        $result_user = $stmt->get_result();
        if ($result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
        } else {
            $error_message = "Lỗi: Người dùng không tồn tại.";
            $user = ['username' => 'Unknown', 'full_name' => '', 'is_main_admin' => 0, 'is_super_admin' => 0];
        }
    }
    $stmt->close();
}

// Hàm trích xuất video ID từ URL YouTube
function getYouTubeVideoId($url) {
    try {
        if (strlen($url) > 255) {
            throw new Exception("URL quá dài! Tối đa 255 ký tự.");
        }
        $pattern = '/^(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';
        if (preg_match($pattern, $url, $match)) {
            return $match[1];
        }
        return '';
    } catch (Exception $e) {
        error_log("getYouTubeVideoId error: " . $e->getMessage());
        return '';
    }
}

// Xử lý khi người dùng gửi URL
$thumbnails = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_thumbnail']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    try {
        $youtube_url = filter_input(INPUT_POST, 'youtube_url', FILTER_SANITIZE_URL);
        if (empty($youtube_url) || !filter_var($youtube_url, FILTER_VALIDATE_URL)) {
            throw new Exception("URL không hợp lệ!");
        }
        $video_id = getYouTubeVideoId($youtube_url);
        if (empty($video_id)) {
            throw new Exception("Không thể trích xuất ID video từ URL!");
        }
        if (!ini_get('allow_url_fopen')) {
            throw new Exception("Server không hỗ trợ get_headers(). Vui lòng bật allow_url_fopen trong php.ini.");
        }

        // Tạo danh sách các kích thước thumbnail (ưu tiên hqdefault, mqdefault)
        $sizes = [
            'hqdefault' => ['label' => 'HQ (480x360)', 'width' => 240],
            'mqdefault' => ['label' => 'MQ (320x180)', 'width' => 160],
            'sddefault' => ['label' => 'SD (640x480)', 'width' => 320],
            'maxresdefault' => ['label' => 'HD (1280x720)', 'width' => 320],
            'default' => ['label' => 'Default (120x90)', 'width' => 120]
        ];

        // Tạo URL thumbnail và kiểm tra
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        foreach ($sizes as $size => $info) {
            $thumbnail_url = "https://img.youtube.com/vi/{$video_id}/{$size}.jpg";
            $headers = @get_headers($thumbnail_url, 1, $context);
            if ($headers && strpos($headers[0], '200') !== false) {
                $thumbnails[$size] = [
                    'url' => $thumbnail_url,
                    'label' => $info['label'],
                    'width' => $info['width']
                ];
            }
        }

        if (empty($thumbnails)) {
            throw new Exception("Không tìm thấy thumbnail cho video này!");
        }
        $success = "Đã tìm thấy thumbnail! Chọn kích thước để tải.";
    } catch (Exception $e) {
        error_log("Thumbnail download error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tải Thumbnail YouTube - Quản Lý Hiện Đại</title>
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

        form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 600px;
            margin: 0 auto;
        }

        input[type="url"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            background: #f8fafc;
            transition: all 0.3s ease;
        }

        input[type="url"]:focus {
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.25);
            outline: none;
        }

        button {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .thumbnail-list {
            margin-top: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            justify-items: center;
        }

        .thumbnail-item {
            text-align: center;
            animation: slideIn 0.5s ease;
        }

        .thumbnail-item img {
            border-radius: var(--button-radius);
            margin-bottom: 0.5rem;
            max-width: 100%;
            box-shadow: var(--shadow-sm);
        }

        .thumbnail-item p {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
        }

        .thumbnail-item a {
            display: inline-flex;
            padding: 0.5rem 1rem;
            background: var(--link-color);
            color: white;
            text-decoration: none;
            border-radius: var(--button-radius);
            font-size: 0.75rem;
            transition: all 0.3s ease;
        }

        .thumbnail-item a:hover {
            background: var(--link-hover-color);
            transform: translateY(-2px);
        }

        .error-message, .success-message {
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
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            :root {
                --padding: 1rem;
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

            .thumbnail-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            input[type="url"], button, .thumbnail-item p, .thumbnail-item a {
                font-size: 0.75rem;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const elements = document.querySelectorAll('.content-section, .thumbnail-list, .thumbnail-item');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0, 0, 0.2, 1), transform 0.5s cubic-bezier(0, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

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
        });
    </script>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Tải Thumbnail YouTube</h1>
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

        <?php 
        // Kiểm tra tệp taskbar.php
        if (!file_exists('taskbar.php') || !is_readable('taskbar.php')) {
            error_log("File taskbar.php not found or not readable");
            $error_message = "Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp taskbar.";
        } else {
            include 'taskbar.php';
        }
        ?>

        <?php if (!empty($error_message)): ?>
            <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif (!empty($error)): ?>
            <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <p class="success-message"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title"><i class="fab fa-youtube"></i> Tải Thumbnail YouTube</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="url" name="youtube_url" placeholder="Nhập URL video YouTube (e.g., https://www.youtube.com/watch?v=abc123)" value="<?php echo isset($_POST['youtube_url']) ? htmlspecialchars($_POST['youtube_url']) : ''; ?>" required>
                <button type="submit" name="get_thumbnail"><i class="fas fa-search"></i> Tìm Thumbnail</button>
            </form>

            <?php if (!empty($thumbnails)): ?>
                <div class="thumbnail-list">
                    <?php foreach ($thumbnails as $thumbnail): ?>
                        <div class="thumbnail-item">
                            <img src="<?php echo htmlspecialchars($thumbnail['url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($thumbnail['label'], ENT_QUOTES, 'UTF-8'); ?>" width="<?php echo $thumbnail['width']; ?>">
                            <p><?php echo htmlspecialchars($thumbnail['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <a href="<?php echo htmlspecialchars($thumbnail['url'], ENT_QUOTES, 'UTF-8'); ?>" download><i class="fas fa-download"></i> Tải về</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>