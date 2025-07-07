<?php
session_start();
include 'db_config.php';
include 'functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Xử lý lưu script
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_script'])) {
    $script_name = trim($_POST['script_name']);
    $script_content = trim($_POST['script_content']);
    
    if (empty($script_name)) {
        $error_message = "Vui lòng nhập tên kịch bản!";
    } else {
        $stmt = $conn->prepare("INSERT INTO teleprompter_scripts (user_id, script_name, script_content) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $script_name, $script_content);
        
        if ($stmt->execute()) {
            $success_message = "Đã lưu kịch bản thành công!";
        } else {
            $error_message = "Lỗi khi lưu kịch bản: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Lấy danh sách scripts của user
$scripts = [];
$stmt = $conn->prepare("SELECT id, script_name, created_at FROM teleprompter_scripts WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $scripts[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teleprompter - VinPhim</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.0-rc.2/dist/quill.snow.css">
    <style>
        :root {
            --bg-primary: #121212;
            --bg-secondary: #1e1e1e;
            --bg-tertiary: #252525;
            --bg-card: #2d2d2d;
            --bg-hover: #333333;
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0a0;
            --text-muted: #707070;
            --accent-primary: #8a2be2;
            --accent-secondary: #6a5acd;
            --accent-gradient: linear-gradient(135deg, #8a2be2, #6a5acd);
            --border-color: #404040;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .back-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }

        .back-button:hover {
            background-color: var(--bg-hover);
            transform: translateY(-2px);
        }

        .back-button i {
            font-size: 0.9rem;
        }

        .teleprompter-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .editor-section, .control-panel {
            background-color: var(--bg-secondary);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--accent-primary);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-group input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            color: var(--text-primary);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input[type="text"]:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 2px rgba(138, 43, 226, 0.25);
        }

        /* Quill Editor Customization */
        .ql-toolbar.ql-snow {
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
            border-bottom: none;
        }

        .ql-container.ql-snow {
            border: 1px solid var(--border-color);
            border-bottom-left-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
            background-color: var(--bg-tertiary);
            min-height: 300px;
        }

        .ql-editor {
            color: var(--text-primary);
            font-size: 1.1rem;
            line-height: 1.6;
        }

        .ql-editor.ql-blank::before {
            color: var(--text-muted);
        }

        .ql-snow .ql-stroke {
            stroke: var(--text-secondary);
        }

        .ql-snow .ql-fill {
            fill: var(--text-secondary);
        }

        .ql-snow.ql-toolbar button:hover .ql-stroke, 
        .ql-snow.ql-toolbar button.ql-active .ql-stroke {
            stroke: var(--accent-primary);
        }

        .ql-snow.ql-toolbar button:hover .ql-fill, 
        .ql-snow.ql-toolbar button.ql-active .ql-fill {
            fill: var(--accent-primary);
        }

        .save-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
            width: 100%;
        }

        .save-button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(138, 43, 226, 0.3);
        }

        .control-group {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .control-group:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .control-group h3 {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .control-group h3 i {
            color: var(--accent-secondary);
            font-size: 0.9rem;
        }

        .speed-control {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .speed-control input[type="range"] {
            flex: 1;
            -webkit-appearance: none;
            height: 6px;
            background: var(--bg-tertiary);
            border-radius: 3px;
            outline: none;
        }

        .speed-control input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: var(--accent-primary);
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
        }

        .speed-control input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.1);
        }

        .speed-control span {
            min-width: 70px;
            padding: 0.3rem 0.6rem;
            background-color: var(--bg-tertiary);
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .display-settings {
            display: grid;
            gap: 1rem;
        }

        .display-settings select {
            width: 100%;
            padding: 0.6rem 1rem;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            color: var(--text-primary);
            font-size: 0.95rem;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a0a0a0' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.7rem center;
            background-size: 1em;
        }

        .display-settings select:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .color-picker {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .color-picker label {
            flex: 1;
            font-size: 0.95rem;
            color: var(--text-secondary);
        }

        .color-picker input[type="color"] {
            -webkit-appearance: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: var(--border-radius);
            background: none;
            cursor: pointer;
        }

        .color-picker input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        .color-picker input[type="color"]::-webkit-color-swatch {
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            font-size: 0.95rem;
            color: var(--text-secondary);
        }

        .checkbox-label input[type="checkbox"] {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            cursor: pointer;
            position: relative;
            transition: var(--transition);
        }

        .checkbox-label input[type="checkbox"]:checked {
            background-color: var(--accent-primary);
            border-color: var(--accent-primary);
        }

        .checkbox-label input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.8rem;
        }

        .playback-controls {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .playback-controls button {
            padding: 0.75rem 0.5rem;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .playback-controls button:hover {
            background-color: var(--bg-hover);
            transform: translateY(-2px);
        }

        .playback-controls button i {
            font-size: 0.8rem;
        }

        #startButton {
            background: linear-gradient(135deg, #4CAF50, #2E7D32);
            border: none;
        }

        #pauseButton {
            background: linear-gradient(135deg, #FF9800, #F57C00);
            border: none;
        }

        #resetButton {
            background: linear-gradient(135deg, #F44336, #D32F2F);
            border: none;
        }

        .scripts-list {
            background-color: var(--bg-secondary);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .scripts-list-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .scripts-list-header i {
            color: var(--accent-primary);
        }

        .script-item {
            background-color: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }

        .script-item:hover {
            background-color: var(--bg-hover);
            transform: translateX(5px);
            border-color: var(--accent-secondary);
        }

        .script-info {
            flex: 1;
        }

        .script-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
        }

        .script-date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .script-date i {
            font-size: 0.75rem;
        }

        .script-actions {
            display: flex;
            gap: 0.5rem;
        }

        .script-actions button {
            width: 36px;
            height: 36px;
            border-radius: var(--border-radius);
            border: none;
            background-color: var(--bg-card);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .script-actions button:hover {
            transform: translateY(-2px);
        }

        .script-actions button:first-child {
            background: linear-gradient(135deg, #2196F3, #0D47A1);
        }

        .script-actions button:last-child {
            background: linear-gradient(135deg, #F44336, #B71C1C);
        }

        .message {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .error-message {
            background-color: rgba(220, 38, 38, 0.1);
            color: #ef4444;
            border-left: 4px solid #ef4444;
        }

        .success-message {
            background-color: rgba(22, 163, 74, 0.1);
            color: #22c55e;
            border-left: 4px solid #22c55e;
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

        /* Teleprompter Display */
        .teleprompter-display {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #000;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            display: none;
            overflow: hidden;
        }

        .teleprompter-content {
            color: #fff;
            font-size: 2rem;
            line-height: 1.5;
            text-align: center;
            max-width: 80%;
            transform: translateY(50vh);
            transition: transform 0.1s linear;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .mirror-mode {
            transform: scaleX(-1) translateY(50vh);
        }

        .teleprompter-controls {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 1rem;
            z-index: 1001;
            background-color: rgba(0, 0, 0, 0.7);
            padding: 0.75rem;
            border-radius: 50px;
            opacity: 0.3;
            transition: opacity 0.3s ease;
        }

        .teleprompter-controls:hover {
            opacity: 1;
        }

        .teleprompter-controls button {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .teleprompter-controls button:hover {
            transform: scale(1.1);
        }

        .teleprompter-exit {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background-color: rgba(244, 67, 54, 0.7);
            color: white;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .teleprompter-exit:hover {
            background-color: #f44336;
            transform: scale(1.1);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .container {
                padding: 1.5rem;
            }
            
            .teleprompter-grid {
                grid-template-columns: 1fr;
            }
            
            .control-panel {
                order: -1;
            }
        }

        @media (max-width: 576px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .playback-controls {
                grid-template-columns: 1fr;
            }
            
            .script-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .script-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Teleprompter</h1>
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="message error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="message success-message">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <div class="teleprompter-grid">
            <div class="editor-section">
                <h2 class="section-title"><i class="fas fa-edit"></i> Soạn thảo kịch bản</h2>
                <form class="script-form" method="POST">
                    <div class="form-group">
                        <label for="script_name">Tên kịch bản</label>
                        <input type="text" id="script_name" name="script_name" required placeholder="Nhập tên kịch bản...">
                    </div>
                    <div class="form-group">
                        <label for="editor">Nội dung</label>
                        <div id="editor"></div>
                        <input type="hidden" name="script_content" id="script_content">
                    </div>
                    <button type="submit" name="save_script" class="save-button">
                        <i class="fas fa-save"></i> Lưu kịch bản
                    </button>
                </form>
            </div>

            <div class="control-panel">
                <h2 class="section-title"><i class="fas fa-sliders-h"></i> Điều khiển</h2>
                
                <div class="control-group">
                    <h3><i class="fas fa-tachometer-alt"></i> Tốc độ cuộn</h3>
                    <div class="speed-control">
                        <input type="range" id="scrollSpeed" min="10" max="200" value="50">
                        <span id="speedValue">50 px/s</span>
                    </div>
                </div>

                <div class="control-group">
                    <h3><i class="fas fa-palette"></i> Hiển thị</h3>
                    <div class="display-settings">
                        <select id="fontSize">
                            <option value="12">Cỡ chữ nhỏ (12px)</option>
                            <option value="16" selected>Cỡ chữ trung bình (16px)</option>
                            <option value="24">Cỡ chữ lớn (24px)</option>
                            <option value="36">Cỡ chữ rất lớn (36px)</option>
                            <option value="48">Cỡ chữ cực lớn (48px)</option>
                        </select>
                        
                        <select id="fontFamily">
                            <option value="Arial">Arial</option>
                            <option value="'Open Sans'">Open Sans</option>
                            <option value="'Roboto'">Roboto</option>
                            <option value="'Times New Roman'">Times New Roman</option>
                            <option value="'Courier New'">Courier New</option>
                            <option value="'Georgia'">Georgia</option>
                            <option value="'Verdana'">Verdana</option>
                            <option value="'Tahoma'">Tahoma</option>
                        </select>
                        
                        <div class="color-picker">
                            <label for="textColor">Màu chữ</label>
                            <input type="color" id="textColor" value="#ffffff">
                        </div>
                        
                        <div class="color-picker">
                            <label for="backgroundColor">Màu nền</label>
                            <input type="color" id="backgroundColor" value="#000000">
                        </div>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" id="mirrorMode">
                            Chế độ gương
                        </label>
                    </div>
                </div>

                <div class="control-group">
                    <h3><i class="fas fa-play-circle"></i> Điều khiển phát</h3>
                    <div class="playback-controls">
                        <button id="startButton">
                            <i class="fas fa-play"></i> Bắt đầu
                        </button>
                        <button id="pauseButton">
                            <i class="fas fa-pause"></i> Tạm dừng
                        </button>
                        <button id="resetButton">
                            <i class="fas fa-undo"></i> Đặt lại
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="scripts-list">
            <h2 class="section-title scripts-list-header"><i class="fas fa-list-alt"></i> Kịch bản đã lưu</h2>
            <?php if (count($scripts) > 0): ?>
                <?php foreach ($scripts as $script): ?>
                    <div class="script-item">
                        <div class="script-info">
                            <div class="script-name"><?php echo htmlspecialchars($script['script_name']); ?></div>
                            <div class="script-date">
                                <i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($script['created_at'])); ?>
                            </div>
                        </div>
                        <div class="script-actions">
                            <button onclick="loadScript(<?php echo $script['id']; ?>)" title="Chỉnh sửa">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteScript(<?php echo $script['id']; ?>)" title="Xóa">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-scripts">
                    <p>Chưa có kịch bản nào được lưu.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Teleprompter Display -->
    <div class="teleprompter-display" id="teleprompterDisplay">
        <div class="teleprompter-content" id="teleprompterContent"></div>
        
        <div class="teleprompter-controls">
            <button id="tpSlower" title="Chậm hơn"><i class="fas fa-minus"></i></button>
            <button id="tpPlayPause" title="Phát/Tạm dừng"><i class="fas fa-pause"></i></button>
            <button id="tpFaster" title="Nhanh hơn"><i class="fas fa-plus"></i></button>
        </div>
        
        <button class="teleprompter-exit" id="tpExit" title="Thoát"><i class="fas fa-times"></i></button>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.0-rc.2/dist/quill.js"></script>
    <script>
        // Khởi tạo Quill editor
        const quill = new Quill('#editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['clean']
                ]
            },
            placeholder: 'Nhập nội dung kịch bản của bạn tại đây...'
        });

        // Lưu nội dung vào hidden input trước khi submit
        document.querySelector('.script-form').onsubmit = function() {
            document.getElementById('script_content').value = quill.root.innerHTML;
            return true;
        };

        // Điều khiển tốc độ cuộn
        const scrollSpeed = document.getElementById('scrollSpeed');
        const speedValue = document.getElementById('speedValue');
        const teleprompterContent = document.getElementById('teleprompterContent');
        const teleprompterDisplay = document.getElementById('teleprompterDisplay');
        let scrollInterval;
        let currentPosition = 0;
        let isPlaying = false;

        scrollSpeed.oninput = function() {
            speedValue.textContent = this.value + ' px/s';
            if (isPlaying) {
                clearInterval(scrollInterval);
                startScrolling();
            }
        };

        // Điều khiển hiển thị
        document.getElementById('fontSize').onchange = function() {
            teleprompterContent.style.fontSize = this.value + 'px';
        };

        document.getElementById('fontFamily').onchange = function() {
            teleprompterContent.style.fontFamily = this.value;
        };

        document.getElementById('textColor').onchange = function() {
            teleprompterContent.style.color = this.value;
        };

        document.getElementById('backgroundColor').onchange = function() {
            teleprompterDisplay.style.backgroundColor = this.value;
        };

        document.getElementById('mirrorMode').onchange = function() {
            teleprompterContent.classList.toggle('mirror-mode', this.checked);
        };

        // Điều khiển phát
        function startScrolling() {
            isPlaying = true;
            document.getElementById('tpPlayPause').innerHTML = '<i class="fas fa-pause"></i>';
            scrollInterval = setInterval(() => {
                currentPosition += parseInt(scrollSpeed.value) / 10;
                
                if (teleprompterContent.classList.contains('mirror-mode')) {
                    teleprompterContent.style.transform = `scaleX(-1) translateY(${50 - currentPosition}vh)`;
                } else {
                    teleprompterContent.style.transform = `translateY(${50 - currentPosition}vh)`;
                }
                
                // Dừng khi đã cuộn hết nội dung
                const contentHeight = teleprompterContent.offsetHeight;
                const windowHeight = window.innerHeight;
                if (currentPosition * (windowHeight / 100) > contentHeight + windowHeight) {
                    clearInterval(scrollInterval);
                    isPlaying = false;
                    document.getElementById('tpPlayPause').innerHTML = '<i class="fas fa-play"></i>';
                }
            }, 100);
        }

        function pauseScrolling() {
            clearInterval(scrollInterval);
            isPlaying = false;
            document.getElementById('tpPlayPause').innerHTML = '<i class="fas fa-play"></i>';
        }

        function resetScrolling() {
            clearInterval(scrollInterval);
            isPlaying = false;
            currentPosition = 0;
            
            if (teleprompterContent.classList.contains('mirror-mode')) {
                teleprompterContent.style.transform = 'scaleX(-1) translateY(50vh)';
            } else {
                teleprompterContent.style.transform = 'translateY(50vh)';
            }
            
            document.getElementById('tpPlayPause').innerHTML = '<i class="fas fa-play"></i>';
        }

        document.getElementById('startButton').onclick = function() {
            teleprompterDisplay.style.display = 'flex';
            teleprompterContent.innerHTML = quill.root.innerHTML;
            resetScrolling();
            startScrolling();
        };

        document.getElementById('pauseButton').onclick = function() {
            if (isPlaying) {
                pauseScrolling();
            } else {
                startScrolling();
            }
        };

        document.getElementById('resetButton').onclick = function() {
            resetScrolling();
        };

        // Teleprompter controls
        document.getElementById('tpPlayPause').onclick = function() {
            if (isPlaying) {
                pauseScrolling();
            } else {
                startScrolling();
            }
        };

        document.getElementById('tpSlower').onclick = function() {
            const currentSpeed = parseInt(scrollSpeed.value);
            if (currentSpeed > 10) {
                scrollSpeed.value = currentSpeed - 10;
                speedValue.textContent = scrollSpeed.value + ' px/s';
                if (isPlaying) {
                    clearInterval(scrollInterval);
                    startScrolling();
                }
            }
        };

        document.getElementById('tpFaster').onclick = function() {
            const currentSpeed = parseInt(scrollSpeed.value);
            if (currentSpeed < 200) {
                scrollSpeed.value = currentSpeed + 10;
                speedValue.textContent = scrollSpeed.value + ' px/s';
                if (isPlaying) {
                    clearInterval(scrollInterval);
                    startScrolling();
                }
            }
        };

        document.getElementById('tpExit').onclick = function() {
            teleprompterDisplay.style.display = 'none';
            resetScrolling();
        };

        // Phím tắt
        document.addEventListener('keydown', function(e) {
            if (teleprompterDisplay.style.display === 'flex') {
                if (e.code === 'Space') {
                    e.preventDefault();
                    if (isPlaying) {
                        pauseScrolling();
                    } else {
                        startScrolling();
                    }
                } else if (e.code === 'Escape') {
                    teleprompterDisplay.style.display = 'none';
                    resetScrolling();
                } else if (e.code === 'ArrowUp') {
                    document.getElementById('tpFaster').click();
                } else if (e.code === 'ArrowDown') {
                    document.getElementById('tpSlower').click();
                }
            }
        });

        // Tải script
        function loadScript(scriptId) {
            fetch(`get_script.php?id=${scriptId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        document.getElementById('script_name').value = data.name;
                        quill.root.innerHTML = data.content;
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Xóa script
        function deleteScript(scriptId) {
            if (confirm('Bạn có chắc chắn muốn xóa kịch bản này?')) {
                fetch('delete_script.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${scriptId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || 'Lỗi khi xóa kịch bản');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }

        // Hiển thị thông báo tự động ẩn sau 5 giây
        const messages = document.querySelectorAll('.message');
        if (messages.length > 0) {
            setTimeout(() => {
                messages.forEach(message => {
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 300);
                });
            }, 5000);
        }
    </script>
</body>
</html>
