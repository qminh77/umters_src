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

// Lấy flashcards cần ôn tập hôm nay (dựa trên thuật toán giãn cách thời gian)
$today = date('Y-m-d');
$sql_due_cards = "SELECT f.*, d.name as deck_name, fp.status, fp.next_review_date
                 FROM flashcards f
                 JOIN flashcard_decks d ON f.deck_id = d.id
                 JOIN flashcard_progress fp ON f.id = fp.flashcard_id
                 WHERE fp.user_id = $user_id 
                 AND fp.next_review_date <= '$today'
                 ORDER BY fp.next_review_date ASC";
$result_due_cards = mysqli_query($conn, $sql_due_cards);

// Đếm số lượng thẻ cần ôn tập
$card_count = mysqli_num_rows($result_due_cards);

// Lấy tất cả flashcards để sử dụng trong JavaScript
$cards = [];
while ($card = mysqli_fetch_assoc($result_due_cards)) {
    $cards[] = $card;
}

// Xử lý cập nhật trạng thái học tập
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $card_id = (int)$_POST['card_id'];
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    // Tính toán ngày ôn tập tiếp theo dựa trên thuật toán giãn cách thời gian
    $next_review_date = date('Y-m-d'); // Mặc định là hôm nay
    
    if ($status == 'learning') {
        // Nếu đang học, ôn tập sau 1 ngày
        $next_review_date = date('Y-m-d', strtotime('+1 day'));
    } elseif ($status == 'mastered') {
        // Nếu đã thuộc, ôn tập sau 7 ngày
        $next_review_date = date('Y-m-d', strtotime('+7 days'));
    }
    
    // Cập nhật trạng thái
    $sql = "UPDATE flashcard_progress 
            SET status = '$status', next_review_date = '$next_review_date', last_reviewed = NOW() 
            WHERE user_id = $user_id AND flashcard_id = $card_id";
    
    if (mysqli_query($conn, $sql)) {
        // Lấy thông tin deck_id
        $sql_card = "SELECT deck_id FROM flashcards WHERE id = $card_id";
        $result_card = mysqli_query($conn, $sql_card);
        $card_info = mysqli_fetch_assoc($result_card);
        $deck_id = $card_info['deck_id'];
        
        // Thêm vào lịch sử học tập
        $sql_history = "INSERT INTO flashcard_study_history (user_id, flashcard_id, deck_id, study_date) 
                        VALUES ($user_id, $card_id, $deck_id, NOW())";
        mysqli_query($conn, $sql_history);
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ôn tập Flashcards</title>
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
            --success-color: #00E0FF;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --mastered-color: #22c55e;
            --learning-color: #f59e0b;
            --new-color: #3b82f6;
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
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

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.75rem;
            font-weight: 700;
        }

        .logo i {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu a {
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu a:hover {
            color: var(--secondary);
            transform: translateY(-2px);
        }

        .user-menu .btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu .btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* Message styles */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-success::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .alert-error::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Study container */
        .study-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70vh;
            padding: 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .study-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .study-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 2rem;
        }

        .study-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .study-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
        }

        .study-progress {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .progress-text {
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--foreground);
        }

        .progress-bar {
            width: 200px;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-full);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
            transition: width 0.5s ease;
        }

        /* Flashcard */
        .flashcard {
            width: 100%;
            max-width: 600px;
            height: 350px;
            perspective: 1000px;
            margin-bottom: 2rem;
        }

        .flashcard-inner {
            position: relative;
            width: 100%;
            height: 100%;
            text-align: center;
            transition: transform 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .flashcard.flipped .flashcard-inner {
            transform: rotateY(180deg);
        }

        .flashcard-front, .flashcard-back {
            position: absolute;
            width: 100%;
            height: 100%;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            border-radius: var(--radius);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            overflow: auto;
        }

        .flashcard-front {
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.3), rgba(0, 224, 255, 0.3));
            color: var(--foreground);
        }

        .flashcard-back {
            background: linear-gradient(135deg, rgba(255, 61, 255, 0.3), rgba(0, 224, 255, 0.3));
            color: var(--foreground);
            transform: rotateY(180deg);
        }

        .flashcard-content {
            font-size: 1.5rem;
            font-weight: 500;
            max-width: 100%;
            word-wrap: break-word;
        }

        .flashcard-hint {
            position: absolute;
            bottom: 1rem;
            font-size: 0.875rem;
            background: rgba(255, 255, 255, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            backdrop-filter: blur(5px);
            color: var(--foreground-muted);
            transition: all 0.3s ease;
        }

        .flashcard:hover .flashcard-hint {
            opacity: 1;
            transform: translateY(-3px);
        }

        .flashcard-deck {
            position: absolute;
            top: 1rem;
            left: 1rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            background: rgba(255, 255, 255, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
        }

        /* Study actions */
        .study-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            border: none;
            color: white;
        }

        .btn-success {
            background: linear-gradient(90deg, var(--mastered-color), #16a34a);
        }

        .btn-success:hover {
            background: linear-gradient(90deg, #16a34a, var(--mastered-color));
            transform: translateY(-2px);
            box-shadow: var(--glow-secondary);
        }

        .btn-warning {
            background: linear-gradient(90deg, var(--learning-color), #d97706);
        }

        .btn-warning:hover {
            background: linear-gradient(90deg, #d97706, var(--learning-color));
            transform: translateY(-2px);
            box-shadow: var(--glow-accent);
        }

        .btn-info {
            background: linear-gradient(90deg, var(--new-color), #2563eb);
        }

        .btn-info:hover {
            background: linear-gradient(90deg, #2563eb, var(--new-color));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }

        .status-new {
            background: rgba(59, 130, 246, 0.2);
            color: var(--new-color);
        }

        .status-learning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--learning-color);
        }

        .status-mastered {
            background: rgba(34, 197, 94, 0.2);
            color: var(--mastered-color);
        }

        /* Study complete */
        .study-complete {
            text-align: center;
            padding: 3rem 1rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .study-complete::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .study-complete i {
            font-size: 4rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
            animation: float 6s ease-in-out infinite;
        }

        .study-complete h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .study-complete p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            color: var(--foreground-muted);
        }

        .study-stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            text-align: center;
            background: rgba(255, 255, 255, 0.03);
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.05);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--foreground-subtle);
        }

        .study-actions-complete {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
            animation: float 6s ease-in-out infinite;
        }

        .empty-state h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            color: var(--foreground-muted);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .study-header {
                flex-direction: column;
                gap: 1rem;
            }

            .flashcard {
                height: 300px;
            }

            .flashcard-content {
                font-size: 1.25rem;
            }

            .study-actions {
                flex-wrap: wrap;
                justify-content: center;
            }

            .study-stats {
                flex-direction: column;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .flashcard {
                height: 250px;
            }

            .flashcard-content {
                font-size: 1.1rem;
            }

            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.875rem;
            }

            .study-title {
                font-size: 1.5rem;
            }
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .floating {
            animation: float 6s ease-in-out infinite;
        }

        /* Particle background */
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
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="container">
        <div class="page-header">
            <div class="logo">
                <i class="fas fa-layer-group"></i>
                <span>FlashMaster</span>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="flashcards.php"><i class="fas fa-layer-group"></i> Bộ thẻ</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($card_count > 0): ?>
            <div id="study-view" class="study-container">
                <div class="study-header">
                    <h2 class="study-title">
                        <i class="fas fa-sync-alt"></i> Ôn tập hôm nay
                        <span id="current-status-badge" class="status-badge status-new">Mới</span>
                    </h2>
                    <div class="study-progress">
                        <div class="progress-text"><span id="current-card">1</span>/<span id="total-cards"><?php echo $card_count; ?></span></div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div class="flashcard" id="flashcard">
                    <div class="flashcard-inner">
                        <div class="flashcard-front">
                            <div class="flashcard-deck" id="deck-name"></div>
                            <div class="flashcard-content" id="front-content"></div>
                            <div class="flashcard-hint">Nhấp để lật thẻ</div>
                        </div>
                        <div class="flashcard-back">
                            <div class="flashcard-content" id="back-content"></div>
                            <div class="flashcard-hint">Nhấp để lật thẻ</div>
                        </div>
                    </div>
                </div>

                <div class="study-actions">
                    <button id="btn-dont-know" class="btn btn-info">
                        <i class="fas fa-question"></i> Chưa thuộc
                    </button>
                    <button id="btn-learning" class="btn btn-warning">
                        <i class="fas fa-sync-alt"></i> Đang học
                    </button>
                    <button id="btn-know" class="btn btn-success">
                        <i class="fas fa-check"></i> Đã thuộc
                    </button>
                </div>
            </div>

            <div id="complete-view" class="study-complete" style="display: none;">
                <i class="fas fa-trophy floating"></i>
                <h2>Chúc mừng!</h2>
                <p>Bạn đã hoàn thành phiên ôn tập hôm nay.</p>
                
                <div class="study-stats">
                    <div class="stat-item">
                        <div class="stat-value" id="stat-total">0</div>
                        <div class="stat-label">Tổng số thẻ</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="stat-mastered">0</div>
                        <div class="stat-label">Đã thuộc</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="stat-learning">0</div>
                        <div class="stat-label">Đang học</div>
                    </div>
                </div>
                
                <div class="study-actions-complete">
                    <a href="review.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Ôn tập lại
                    </a>
                    <a href="flashcards.php" class="btn btn-secondary">
                        <i class="fas fa-layer-group"></i> Quay lại danh sách
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle floating"></i>
                <h2>Tuyệt vời!</h2>
                <p>Bạn không có thẻ nào cần ôn tập hôm nay.</p>
                <a href="flashcards.php" class="btn btn-primary">
                    <i class="fas fa-layer-group"></i> Quay lại danh sách
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            animateElements('.study-container', 100);
            animateElements('.study-complete', 100);
            animateElements('.empty-state', 50);
            
            const messages = document.querySelectorAll('.alert');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        });

        // Dữ liệu flashcards
        const cards = <?php echo json_encode($cards); ?>;
        let currentCardIndex = 0;
        let totalCards = cards.length;
        let stats = {
            total: totalCards,
            mastered: 0,
            learning: 0,
            new: 0
        };
        
        // Cập nhật trạng thái ban đầu
        updateStats();
        
        // Hiển thị thẻ đầu tiên
        if (totalCards > 0) {
            showCard(currentCardIndex);
        }
        
        // Xử lý sự kiện lật thẻ
        document.getElementById('flashcard')?.addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
        
        // Xử lý các nút đánh giá
        document.getElementById('btn-dont-know')?.addEventListener('click', function() {
            updateCardStatus('new');
        });
        
        document.getElementById('btn-learning')?.addEventListener('click', function() {
            updateCardStatus('learning');
        });
        
        document.getElementById('btn-know')?.addEventListener('click', function() {
            updateCardStatus('mastered');
        });
        
        // Hiển thị thẻ
        function showCard(index) {
            if (index >= totalCards) {
                // Đã học xong tất cả thẻ
                document.getElementById('study-view').style.display = 'none';
                document.getElementById('complete-view').style.display = 'block';
                
                // Cập nhật thống kê
                document.getElementById('stat-total').textContent = stats.total;
                document.getElementById('stat-mastered').textContent = stats.mastered;
                document.getElementById('stat-learning').textContent = stats.learning;
                
                return;
            }
            
            const card = cards[index];
            
            // Cập nhật nội dung thẻ
            document.getElementById('front-content').innerHTML = card.front;
            document.getElementById('back-content').innerHTML = card.back;
            document.getElementById('deck-name').textContent = card.deck_name;
            
            // Đặt lại trạng thái lật
            document.getElementById('flashcard').classList.remove('flipped');
            
            // Cập nhật tiến trình
            document.getElementById('current-card').textContent = index + 1;
            document.querySelector('.progress-fill').style.width = `${((index + 1) / totalCards) * 100}%`;
            
            // Cập nhật trạng thái hiện tại
            const statusBadge = document.getElementById('current-status-badge');
            statusBadge.className = 'status-badge';
            
            let statusText = 'Mới';
            if (card.status === 'learning') {
                statusText = 'Đang học';
                statusBadge.classList.add('status-learning');
            } else if (card.status === 'mastered') {
                statusText = 'Đã thuộc';
                statusBadge.classList.add('status-mastered');
            } else {
                statusBadge.classList.add('status-new');
            }
            
            statusBadge.textContent = statusText;
        }
        
        // Cập nhật trạng thái thẻ
        function updateCardStatus(status) {
            if (currentCardIndex >= totalCards) return;
            
            const card = cards[currentCardIndex];
            const cardId = card.id;
            
            // Gửi yêu cầu AJAX để cập nhật trạng thái
            fetch('review.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `update_status=1&card_id=${cardId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Cập nhật trạng thái trong mảng cards
                    cards[currentCardIndex].status = status;
                    
                    // Cập nhật thống kê
                    updateStats();
                    
                    // Chuyển đến thẻ tiếp theo
                    currentCardIndex++;
                    showCard(currentCardIndex);
                } else {
                    console.error('Lỗi khi cập nhật trạng thái:', data.error);
                }
            })
            .catch(error => {
                console.error('Lỗi:', error);
            });
        }
        
        // Cập nhật thống kê
        function updateStats() {
            stats.mastered = 0;
            stats.learning = 0;
            stats.new = 0;
            
            cards.forEach(card => {
                if (card.status === 'mastered') {
                    stats.mastered++;
                } else if (card.status === 'learning') {
                    stats.learning++;
                } else {
                    stats.new++;
                }
            });
        }

        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }

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
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>