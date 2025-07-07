<?php
session_start();
require_once 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Khởi tạo biến
$message = '';
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user
$sql_user = "SELECT username, is_main_admin, is_super_admin FROM users WHERE id = ?";
$stmt = $conn->prepare($sql_user);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
if ($result_user->num_rows > 0) {
    $user = $result_user->fetch_assoc();
} else {
    $message = "Lỗi khi lấy thông tin user: " . mysqli_error($conn);
    $user = ['username' => 'Unknown', 'is_main_admin' => 0, 'is_super_admin' => 0];
}
$stmt->close();

// Phân trang lịch sử job
$jobs_per_page = 10;
$page_jobs = isset($_GET['page_jobs']) ? (int)$_GET['page_jobs'] : 1;
if ($page_jobs < 1) $page_jobs = 1;
$offset_jobs = ($page_jobs - 1) * $jobs_per_page;

$sql_total_jobs = "SELECT COUNT(*) as total FROM video_jobs WHERE user_id = ?";
$stmt = $conn->prepare($sql_total_jobs);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_jobs = $stmt->get_result()->fetch_assoc()['total'];
$total_pages_jobs = ceil($total_jobs / $jobs_per_page);
$stmt->close();

$sql_jobs = "SELECT * FROM video_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($sql_jobs);
$stmt->bind_param("iii", $user_id, $offset_jobs, $jobs_per_page);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Xử lý xóa job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_job']) && isset($_POST['job_id'])) {
    $job_id = (int)$_POST['job_id'];
    $stmt = $conn->prepare("SELECT input_path, output_path FROM video_jobs WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $job_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $job = $result->fetch_assoc();
        if (file_exists($job['input_path'])) unlink($job['input_path']);
        if (file_exists($job['output_path'])) unlink($job['output_path']);
        $stmt = $conn->prepare("DELETE FROM video_jobs WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $job_id, $user_id);
        if ($stmt->execute()) {
            $message = "Xóa tác vụ chuyển đổi thành công!";
        } else {
            $message = "Lỗi khi xóa tác vụ: " . mysqli_error($conn);
        }
    } else {
        $message = "Tác vụ không tồn tại hoặc bạn không có quyền xóa!";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chuyển đổi Video</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #74ebd5, #acb6e5);
            --container-bg: rgba(255, 255, 255, 0.98);
            --card-bg: #ffffff;
            --form-bg: #f8fafc;
            --hover-bg: #f1f5f9;
            --text-color: #334155;
            --text-secondary: #64748b;
            --link-color: #38bdf8;
            --link-hover-color: #0ea5e9;
            --error-color: #ef4444;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --delete-color: #ef4444;
            --delete-hover-color: #dc2626;
            --download-color: #22c55e;
            --download-hover-color: #16a34a;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
            --border-radius: 1rem;
            --small-radius: 0.75rem;
            --button-radius: 1.5rem;
            --padding: 2.5rem;
            --small-padding: 1rem;
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
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
            padding: var(--padding);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .dashboard-container:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
            padding-bottom: 0.5rem;
        }

        h2:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 2px;
        }

        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--small-radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-message {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .error-message:before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .success-message:before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .video-form-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 992px) {
            .video-form-container {
                grid-template-columns: 1fr 1fr;
            }
        }

        .video-form-section {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .video-form-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .video-form-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .video-form-title i {
            color: var(--primary-gradient-start);
        }

        .video-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .video-select,
        .video-input {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid rgba(116, 235, 213, 0.5);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            transition: all 0.3s ease;
            width: 100%;
            color: var(--text-color);
            box-shadow: var(--shadow-sm);
        }

        .video-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2374ebd5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }

        .video-select:focus,
        .video-input:focus {
            outline: none;
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.25);
        }

        .video-input[type="file"] {
            padding: 0.6rem;
            border-style: dashed;
            cursor: pointer;
            background: rgba(116, 235, 213, 0.05);
        }

        .video-input[type="file"]:hover {
            border-color: var(--primary-gradient-start);
            background: rgba(116, 235, 213, 0.1);
        }

        .field-label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .video-button {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            box-shadow: 0 4px 10px rgba(116, 235, 213, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
            align-self: center;
        }

        .video-button:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 15px rgba(116, 235, 213, 0.4);
        }

        .video-button:disabled {
            background: #d1d5db;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .progress-container {
            display: none;
            margin-top: 1rem;
            background: var(--card-bg);
            padding: 1rem;
            border-radius: var(--small-radius);
            box-shadow: var(--shadow-sm);
        }

        .progress-container.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e5e7eb;
            border-radius: var(--small-radius);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            width: 0;
            transition: width 0.3s ease;
        }

        .progress-text {
            margin-top: 0.5rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .video-result-section {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .video-result-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .video-history {
            grid-column: 1 / -1;
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-top: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .video-history-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .video-history-title i {
            color: var(--primary-gradient-start);
        }

        .video-history-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .video-history-card {
            background: var(--card-bg);
            border-radius: var(--small-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }

        .video-history-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .video-history-info {
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .video-history-file {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .video-history-file i {
            color: var(--primary-gradient-start);
        }

        .video-history-status {
            color: var(--text-secondary);
            font-size: 0.9rem;
            word-break: break-all;
        }

        .video-history-dates {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .video-history-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: #f8fafc;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        .video-history-btn {
            flex: 1;
            padding: 0.5rem;
            border-radius: var(--small-radius);
            font-size: 0.9rem;
            font-weight: 500;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            text-decoration: none;
        }

        .video-download-btn {
            background: var(--download-color);
            color: white;
            border: none;
        }

        .video-download-btn:hover {
            background: var(--download-hover-color);
            transform: translateY(-2px);
        }

        .video-delete-btn {
            background: var(--delete-color);
            color: white;
            border: none;
            cursor: pointer;
        }

        .video-delete-btn:hover {
            background: var(--delete-hover-color);
            transform: translateY(-2px);
        }

        .no-video-history {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
            font-style: italic;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .no-video-history i {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.5;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            align-items: center;
        }

        .pagination-link {
            padding: 0.5rem 1rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pagination-link:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            :root {
                --padding: 1.5rem;
                --small-padding: 0.75rem;
            }

            .dashboard-container {
                padding: var(--padding);
                margin: 1rem;
            }

            .video-form-container {
                grid-template-columns: 1fr;
            }

            .video-history-list {
                grid-template-columns: 1fr;
            }

            .video-button {
                width: 100%;
            }

            .pagination {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            :root {
                --padding: 1rem;
                --small-padding: 0.5rem;
            }

            .dashboard-container {
                padding: var(--padding);
                margin: 0.5rem;
            }

            .video-form-title, .video-history-title {
                font-size: 1rem;
            }

            .video-select, .video-input {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }

            .video-button {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }

            .video-history-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .video-history-btn {
                width: 100%;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('video-form');
            const uploadButton = form.querySelector('.video-button');
            const progressContainer = document.querySelector('.progress-container');
            const progressFill = document.querySelector('.progress-fill');
            const progressText = document.querySelector('.progress-text');
            const messageContainer = document.querySelector('.message-container');

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(form);
                const xhr = new XMLHttpRequest();

                // Reset giao diện
                progressContainer.classList.remove('active');
                progressFill.style.width = '0%';
                progressText.textContent = 'Đang tải lên...';
                uploadButton.disabled = true;

                // Hiển thị thanh tiến trình
                progressContainer.classList.add('active');

                // Theo dõi tiến trình
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = (e.loaded / e.total) * 100;
                        progressFill.style.width = `${percent}%`;
                        progressText.textContent = `Đã tải lên ${percent.toFixed(1)}%`;
                    }
                });

                // Xử lý hoàn tất
                xhr.addEventListener('load', function() {
                    uploadButton.disabled = false;
                    progressContainer.classList.remove('active');
                    let response;
                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (e) {
                        response = { status: 'error', message: 'Phản hồi không hợp lệ từ server' };
                    }
                    const messageDiv = document.createElement('div');
                    messageDiv.className = response.status === 'success' ? 'success-message' : 'error-message';
                    messageDiv.textContent = response.message;
                    messageContainer.innerHTML = '';
                    messageContainer.appendChild(messageDiv);

                    // Tự động ẩn thông báo
                    setTimeout(() => {
                        messageDiv.style.opacity = '0';
                        messageDiv.style.transform = 'translateY(-10px)';
                        messageDiv.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                        setTimeout(() => messageDiv.remove(), 500);
                    }, 5000);

                    // Làm mới lịch sử nếu thành công
                    if (response.status === 'success') {
                        setTimeout(() => location.reload(), 1000);
                    }
                });

                // Xử lý lỗi
                xhr.addEventListener('error', function() {
                    uploadButton.disabled = false;
                    progressContainer.classList.remove('active');
                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'error-message';
                    messageDiv.textContent = 'Lỗi kết nối khi tải lên';
                    messageContainer.innerHTML = '';
                    messageContainer.appendChild(messageDiv);
                });

                xhr.open('POST', 'api/upload_video.php', true);
                xhr.send(formData);
            });

            // Poll trạng thái job
            const jobs = document.querySelectorAll('.video-history-card');
            jobs.forEach(job => {
                const jobId = job.dataset.jobId;
                if (job.querySelector('.video-history-status').textContent.includes('processing')) {
                    setInterval(() => {
                        fetch(`api/check_job.php?job_id=${jobId}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.status) {
                                    job.querySelector('.video-history-status').textContent = `Trạng thái: ${data.status}`;
                                    if (data.status === 'completed' && data.output_path) {
                                        const downloadBtn = job.querySelector('.video-download-btn');
                                        downloadBtn.style.display = 'flex';
                                        downloadBtn.href = data.output_path;
                                    }
                                }
                            });
                    }, 5000);
                }
            });
        });
    </script>
</head>
<body>
    <div class="dashboard-container">
        <h2>Chuyển đổi Video</h2>
        <?php include 'taskbar.php'; ?>

        <div class="message-container">
            <?php if ($message): ?>
                <div class="<?php echo strpos($message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="video-form-container">
            <div class="video-form-section">
                <div class="video-form-title">
                    <i class="fas fa-video"></i> Chuyển đổi Video
                </div>
                <form class="video-form" id="video-form" enctype="multipart/form-data">
                    <div>
                        <label class="field-label">Chọn video (MP4, AVI, MOV, WMV)</label>
                        <input type="file" name="video_file" accept="video/mp4,video/avi,video/quicktime,video/x-ms-wmv" class="video-input" required>
                    </div>
                    <div>
                        <label class="field-label">Định dạng đầu ra</label>
                        <select name="output_format" class="video-select" required>
                            <option value="">Chọn định dạng</option>
                            <option value="mp4">MP4</option>
                            <option value="avi">AVI</option>
                            <option value="mov">MOV</option>
                        </select>
                    </div>
                    <button type="submit" class="video-button">
                        <i class="fas fa-exchange-alt"></i> Chuyển đổi
                    </button>
                </form>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">Đang tải lên...</div>
                </div>
            </div>

            <div class="video-result-section">
                <div class="video-form-title">
                    <i class="fas fa-info-circle"></i> Hướng dẫn
                </div>
                <div style="padding: 1rem; text-align: left;">
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Chọn file video cần chuyển đổi</p>
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Chọn định dạng đầu ra (MP4, AVI, MOV)</p>
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Nhấn nút "Chuyển đổi"</p>
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Theo dõi trạng thái trong lịch sử</p>
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Tải video đã chuyển đổi khi hoàn tất</p>
                </div>
            </div>

            <div class="video-history">
                <div class="video-history-title">
                    <i class="fas fa-history"></i> Lịch sử chuyển đổi
                </div>
                <?php if (empty($jobs)): ?>
                    <div class="no-video-history">
                        <i class="fas fa-video"></i>
                        <p>Chưa có video nào được chuyển đổi. Hãy thử chuyển đổi video đầu tiên!</p>
                    </div>
                <?php else: ?>
                    <div class="video-history-list">
                        <?php foreach ($jobs as $job): ?>
                            <div class="video-history-card" data-job-id="<?php echo htmlspecialchars($job['job_id']); ?>">
                                <div class="video-history-info">
                                    <div class="video-history-file">
                                        <i class="fas fa-file-video"></i>
                                        <?php echo htmlspecialchars(basename($job['output_path'])); ?>
                                    </div>
                                    <div class="video-history-status">
                                        Trạng thái: <?php echo ucfirst(htmlspecialchars($job['status'])); ?>
                                    </div>
                                    <div class="video-history-dates">
                                        <span><i class="far fa-calendar-plus"></i> Tạo: <?php echo date('d/m/Y H:i', strtotime($job['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="video-history-actions">
                                    <a href="<?php echo htmlspecialchars($job['output_path']); ?>" download 
                                       class="video-history-btn video-download-btn" 
                                       style="display: <?php echo $job['status'] === 'completed' ? 'flex' : 'none'; ?>">
                                        <i class="fas fa-download"></i> Tải xuống
                                    </a>
                                    <form method="POST" style="flex: 1;">
                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                        <button type="submit" name="delete_job" class="video-history-btn video-delete-btn" 
                                                onclick="return confirm('Bạn có chắc muốn xóa tác vụ này? Hành động này không thể hoàn tác.');">
                                            <i class="fas fa-trash-alt"></i> Xóa
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_pages_jobs > 1): ?>
                        <div class="pagination">
                            <?php if ($page_jobs > 1): ?>
                                <a href="video_converter.php?page_jobs=<?php echo $page_jobs - 1; ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i> Trang trước
                                </a>
                            <?php endif; ?>
                            <span class="pagination-info">Trang <?php echo $page_jobs; ?> / <?php echo $total_pages_jobs; ?></span>
                            <?php if ($page_jobs < $total_pages_jobs): ?>
                                <a href="video_converter.php?page_jobs=<?php echo $page_jobs + 1; ?>" class="pagination-link">
                                    Trang sau <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php mysqli_close($conn); ?>