<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Thêm xử lý UTF-8 cho file CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv'])) {
    $deck_id = (int)$_POST['deck_id'];
    
    // Kiểm tra quyền sở hữu bộ flashcard
    $sql_check = "SELECT * FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            
            if ($handle !== FALSE) {
                $count = 0;
                
                // Đọc BOM UTF-8 nếu có
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    // Nếu không có BOM, quay lại đầu file
                    rewind($handle);
                }
                
                // Bỏ qua dòng tiêu đề nếu có
                if (isset($_POST['has_header']) && $_POST['has_header'] == 1) {
                    fgetcsv($handle, 1000, ",");
                }
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) >= 2) {
                        // Đảm bảo dữ liệu là UTF-8
                        $front = mb_convert_encoding($data[0], 'UTF-8', 'auto');
                        $back = mb_convert_encoding($data[1], 'UTF-8', 'auto');
                        
                        $front = mysqli_real_escape_string($conn, $front);
                        $back = mysqli_real_escape_string($conn, $back);
                        
                        $sql = "INSERT INTO flashcards (deck_id, front, back, created_at) VALUES ($deck_id, '$front', '$back', NOW())";
                        
                        if (mysqli_query($conn, $sql)) {
                            $card_id = mysqli_insert_id($conn);
                            
                            // Thêm vào bảng tiến trình học tập
                            $sql_progress = "INSERT INTO flashcard_progress (user_id, flashcard_id, deck_id, status, next_review_date) 
                                             VALUES ($user_id, $card_id, $deck_id, 'new', CURDATE())";
                            mysqli_query($conn, $sql_progress);
                            
                            $count++;
                        }
                    }
                }
                
                fclose($handle);
                
                $success_message = "Đã nhập thành công $count flashcards từ file CSV!";
            } else {
                $error_message = "Không thể đọc file CSV!";
            }
        } else {
            $error_message = "Vui lòng chọn file CSV hợp lệ!";
        }
    } else {
        $error_message = "Bạn không có quyền nhập vào bộ flashcard này!";
    }
}

// Lấy danh sách bộ flashcard của người dùng
$sql_decks = "SELECT * FROM flashcard_decks WHERE user_id = $user_id ORDER BY created_at DESC";
$result_decks = mysqli_query($conn, $sql_decks);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập Flashcards - Quản Lý</title>
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
            
            /* Màu trạng thái */
            --error-color: #FF3D57;
            --success-color: #22c55e;
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
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-light), var(--secondary-light));
        }

        /* Main container */
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-links {
            display: flex;
            gap: 1rem;
        }

        .back-to-dashboard,
        .back-to-flashcards {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-dashboard:hover,
        .back-to-flashcards:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Message alerts */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .error-message::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border-left: 4px solid var(--secondary);
        }

        .success-message::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Content */
        .content-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 80% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .section-title i {
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Import instructions */
        .import-instructions {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-light);
            position: relative;
            z-index: 1;
        }

        .import-instructions h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            color: var(--foreground);
            font-weight: 600;
        }

        .import-instructions h3 i {
            color: var(--secondary);
        }

        .import-instructions ol {
            padding-left: 1.5rem;
            color: var(--foreground-muted);
        }

        .import-instructions li {
            margin-bottom: 0.75rem;
        }

        .import-instructions code {
            background: rgba(0, 0, 0, 0.3);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-family: monospace;
            color: var(--accent-light);
            display: inline-block;
            margin-top: 0.5rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        select.form-control option {
            background-color: var(--surface);
            color: var(--foreground);
            padding: 0.5rem;
        }

        /* File input */
        .file-input-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
            z-index: 1;
        }

        .file-input {
            width: 100%;
            padding: 2.5rem 1.5rem;
            border: 2px dashed var(--primary);
            border-radius: var(--radius);
            color: var(--foreground-muted);
            transition: all 0.3s ease;
            background-color: rgba(112, 0, 255, 0.05);
            text-align: center;
            cursor: pointer;
            position: relative;
        }

        .file-input:hover {
            border-color: var(--accent);
            background-color: rgba(112, 0, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .file-input input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-input-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            color: var(--primary-light);
            background: linear-gradient(135deg, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .checkbox-group input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid var(--border);
            border-radius: 4px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .checkbox-group input[type="checkbox"]:checked {
            background: var(--primary);
            border-color: var(--primary-light);
        }

        .checkbox-group input[type="checkbox"]:checked::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 12px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
        }

        .checkbox-group input[type="checkbox"]:hover {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.1);
        }

        .checkbox-group label {
            font-weight: 500;
            color: var(--foreground);
            font-size: 0.9rem;
            margin-bottom: 0;
            cursor: pointer;
        }

        /* Buttons */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            position: relative;
            z-index: 1;
        }

        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: none;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .btn::before {
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

        .btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--foreground-muted);
            position: relative;
            z-index: 1;
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1.25rem;
            background: linear-gradient(135deg, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0.8;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }

        /* Particles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            pointer-events: none;
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }
            
            .back-links {
                width: 100%;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .back-to-dashboard,
            .back-to-flashcards {
                width: 100%;
                justify-content: center;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
            
            .import-instructions {
                padding: 1rem;
            }
            
            .import-instructions h3 {
                font-size: 1rem;
            }
            
            .file-input {
                padding: 1.5rem 1rem;
            }
            
            .file-input-icon {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-file-import"></i> Nhập Flashcards</h1>
            <div class="back-links">
                <a href="flashcards.php" class="back-to-flashcards">
                    <i class="fas fa-layer-group"></i> Quay lại Flashcards
                </a>
                <a href="dashboard.php" class="back-to-dashboard">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
            </div>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-file-import"></i> Nhập Flashcards từ CSV</h2>
            </div>

            <div class="import-instructions">
                <h3><i class="fas fa-info-circle"></i> Hướng dẫn nhập CSV</h3>
                <ol>
                    <li>Tạo file CSV với 2 cột: cột 1 = mặt trước, cột 2 = mặt sau</li>
                    <li>Đảm bảo file CSV được mã hóa UTF-8 để hỗ trợ tiếng Việt</li>
                    <li>Nếu file có dòng tiêu đề, hãy đánh dấu tùy chọn "File có dòng tiêu đề"</li>
                    <li>Ví dụ nội dung file CSV:
                        <code>Mặt trước,Mặt sau<br>Hello,Xin chào<br>Thank you,Cảm ơn</code>
                    </li>
                </ol>
            </div>

            <?php if (mysqli_num_rows($result_decks) > 0): ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="deck_id">Chọn bộ flashcard để nhập vào</label>
                        <select id="deck_id" name="deck_id" class="form-control" required>
                            <option value="">-- Chọn bộ flashcard --</option>
                            <?php while ($deck = mysqli_fetch_assoc($result_decks)): ?>
                                <option value="<?php echo $deck['id']; ?>"><?php echo htmlspecialchars($deck['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="file-input-wrapper">
                        <div class="file-input">
                            <div class="file-input-icon"><i class="fas fa-file-csv"></i></div>
                            <div>Kéo thả file CSV vào đây hoặc nhấp để chọn file</div>
                            <input type="file" name="csv_file" accept=".csv" required>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="has_header" name="has_header" value="1">
                        <label for="has_header">File có dòng tiêu đề</label>
                    </div>
                    
                    <div class="form-actions">
                        <a href="flashcards.php" class="btn btn-secondary">Hủy</a>
                        <button type="submit" name="import_csv" class="btn btn-primary">
                            <i class="fas fa-file-import"></i> Nhập dữ liệu
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-layer-group"></i>
                    <p>Bạn chưa có bộ flashcard nào. Hãy tạo bộ đầu tiên!</p>
                    <a href="flashcards.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tạo bộ flashcard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                // Random color
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Animation cho các phần tử
        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * delay);
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particles
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.content-section', 100);
            animateElements('.import-instructions', 200);
            animateElements('.form-group', 50);
            animateElements('.file-input-wrapper', 250);
            
            // Hiệu ứng hiển thị tên file
            document.querySelector('input[type="file"]').addEventListener('change', function(e) {
                const fileName = e.target.files[0]?.name || 'Không có file nào được chọn';
                const fileInput = document.querySelector('.file-input');
                fileInput.innerHTML = `<div class="file-input-icon"><i class="fas fa-file-csv"></i></div><div>${fileName}</div>`;
                fileInput.appendChild(e.target);
            });
            
            // Hiển thị thông báo
            setTimeout(() => {
                const messages = document.querySelectorAll('.error-message, .success-message');
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
</body>
</html>
<?php mysqli_close($conn); ?>