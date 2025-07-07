<?php
session_start();
include 'db_config.php';
include 'media_functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Kiểm tra ID bộ flashcard
if (!isset($_GET['deck_id']) || !is_numeric($_GET['deck_id'])) {
    header("Location: flashcards.php");
    exit;
}

$deck_id = (int)$_GET['deck_id'];

// Lấy thông tin bộ flashcard
$sql_deck = "SELECT * FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
$result_deck = mysqli_query($conn, $sql_deck);

if (mysqli_num_rows($result_deck) == 0) {
    header("Location: flashcards.php");
    exit;
}

$deck = mysqli_fetch_assoc($result_deck);

// Lấy danh sách flashcards trong bộ
$sql_cards = "SELECT f.*, fp.status, fp.next_review_date 
              FROM flashcards f 
              LEFT JOIN flashcard_progress fp ON f.id = fp.flashcard_id AND fp.user_id = $user_id 
              WHERE f.deck_id = $deck_id 
              ORDER BY CASE 
                WHEN fp.status = 'new' THEN 1 
                WHEN fp.status = 'learning' THEN 2 
                WHEN fp.status = 'mastered' THEN 3 
                ELSE 4 
              END, RAND()";
$result_cards = mysqli_query($conn, $sql_cards);

// Đếm số lượng thẻ
$card_count = mysqli_num_rows($result_cards);

// Lấy tất cả flashcards để sử dụng trong JavaScript
$cards = [];
while ($card = mysqli_fetch_assoc($result_cards)) {
    // Process media content for display
    $card['front_processed'] = process_media_content($card['front']);
    $card['back_processed'] = process_media_content($card['back']);
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
    
    // Kiểm tra xem đã có bản ghi trong bảng tiến trình chưa
    $sql_check = "SELECT * FROM flashcard_progress WHERE user_id = $user_id AND flashcard_id = $card_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        // Cập nhật bản ghi hiện có
        $sql = "UPDATE flashcard_progress 
                SET status = '$status', next_review_date = '$next_review_date', last_reviewed = NOW() 
                WHERE user_id = $user_id AND flashcard_id = $card_id";
    } else {
        // Tạo bản ghi mới
        $sql = "INSERT INTO flashcard_progress (user_id, flashcard_id, deck_id, status, next_review_date, last_reviewed) 
                VALUES ($user_id, $card_id, $deck_id, '$status', '$next_review_date', NOW())";
    }
    
    if (mysqli_query($conn, $sql)) {
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
    <title>Học Flashcard - <?php echo htmlspecialchars($deck['name']); ?></title>
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
            font-size: 1.5rem;
            font-weight: 700;
        }

        .logo i {
            font-size: 1.8rem;
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
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border-left: 4px solid #FF3D57;
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
            transition: transform 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
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
            overflow-y: auto;
        }

        .flashcard-front {
            background: linear-gradient(135deg, rgba(112, 0, 255, 0.3), rgba(0, 224, 255, 0.3));
            border: 1px solid var(--border);
            color: var(--foreground);
        }

        .flashcard-back {
            background: linear-gradient(135deg, rgba(255, 61, 255, 0.3), rgba(0, 224, 255, 0.3));
            border: 1px solid var(--border);
            color: var(--foreground);
            transform: rotateY(180deg);
        }

        .flashcard-content {
            font-size: 1.25rem;
            font-weight: 500;
            max-width: 100%;
            word-wrap: break-word;
            margin-bottom: 1rem;
            width: 100%;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            max-height: 280px;
        }

        .flashcard-hint {
            position: absolute;
            bottom: 0.75rem;
            font-size: 0.875rem;
            background: rgba(255, 255, 255, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-sm);
            backdrop-filter: blur(5px);
            transition: all 0.3s ease;
            color: var(--foreground-muted);
        }

        .flashcard:hover .flashcard-hint {
            opacity: 1;
            transform: translateY(-3px);
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

        .study-complete i {
            font-size: 3rem;
            color: var(--secondary);
            margin-bottom: 1rem;
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

        /* Media styles */
        .flashcard-media {
            margin: 0.5rem auto;
            border-radius: var(--radius-sm);
            overflow: hidden;
            max-width: 100%;
            position: relative;
            width: auto;
            display: block;
        }

        .flashcard-image {
            text-align: center;
            background-color: rgba(255, 255, 255, 0.05);
            min-height: 100px;
            max-height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .flashcard-image img {
            max-width: 100%;
            height: auto;
            max-height: 180px;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }

        .flashcard-video {
            position: relative;
            min-height: 150px;
            max-height: 180px;
            background-color: rgba(255, 255, 255, 0.05);
            margin: 0 auto;
        }

        .flashcard-video video {
            width: 100%;
            max-height: 180px;
            object-fit: contain;
        }

        .flashcard-youtube {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            margin: 0.5rem auto;
            min-height: 150px;
            max-height: 180px;
            background-color: rgba(255, 255, 255, 0.05);
            width: 100%;
        }

        .flashcard-youtube iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .media-loading {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
            font-size: 1.5rem;
        }

        .loaded .media-loading {
            display: none;
        }

        /* TTS styles */
        .tts-container {
            position: absolute;
            bottom: 0.5rem;
            right: 0.5rem;
            z-index: 10;
        }

        .tts-button {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.3rem 0.6rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tts-button:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .tts-language-selector {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: rgba(30, 30, 60, 0.9);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
            box-shadow: var(--shadow);
            z-index: 20;
            min-width: 150px;
            display: none;
            backdrop-filter: blur(10px);
        }

        .tts-language-selector.active {
            display: block;
        }

        .tts-language-option {
            padding: 0.3rem 0.5rem;
            cursor: pointer;
            border-radius: 0.25rem;
            color: var(--foreground);
        }

        .tts-language-option:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .tts-language-option.selected {
            background: var(--primary);
            color: white;
        }

        /* Study options modal */
        .study-options-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
            background: rgba(0, 0, 0, 0.5);
        }

        .study-options-content {
            background: rgba(30, 30, 60, 0.9);
            border-radius: var(--radius-lg);
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            animation: fadeIn 0.3s ease, slideUp 0.3s ease;
            backdrop-filter: blur(20px);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(30px); }
            to { transform: translateY(0); }
        }

        .study-options-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-light);
        }

        .study-options-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .study-options-title i {
            color: var(--primary-light);
        }

        .study-options-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .option-group {
            background: rgba(255, 255, 255, 0.03);
            padding: 1.25rem;
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .option-group:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .option-group-title {
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .option-group-title i {
            color: var(--primary-light);
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            transition: background-color 0.3s ease;
        }

        .option-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .option-item input[type="radio"],
        .option-item input[type="checkbox"] {
            width: 1.2rem;
            height: 1.2rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .option-item label {
            font-size: 1rem;
            color: var(--foreground);
            cursor: pointer;
            flex: 1;
        }

        .study-options-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .study-options-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--primary-light);
            border-radius: var(--radius);
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 0.75rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .study-options-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* Review card */
        .review-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            border: 1px solid var(--border);
        }

        .review-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .review-card-title {
            font-weight: 600;
            color: var(--foreground);
        }

        .review-card-content {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .review-card-text {
            max-height: 100px;
            overflow: hidden;
            position: relative;
            color: var(--foreground-muted);
        }

        .review-card-text.expanded {
            max-height: none;
        }

        .review-card-text.has-overflow::after {
            content: "...";
            position: absolute;
            bottom: 0;
            right: 0;
            background: rgba(30, 30, 60, 0.9);
            padding: 0 0.5rem;
            color: var(--foreground);
        }

        .review-card-media {
            max-height: 150px;
            overflow: hidden;
            border-radius: var(--radius-sm);
            position: relative;
        }

        .review-card-media img {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
        }

        .review-card-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .review-card-expand {
            background: none;
            border: none;
            color: var(--primary-light);
            cursor: pointer;
            font-size: 0.875rem;
            padding: 0;
            transition: color 0.3s ease;
        }

        .review-card-expand:hover {
            color: var(--secondary);
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

            .study-options-content {
                width: 95%;
                padding: 1.5rem;
            }

            .option-group {
                padding: 1rem;
            }

            .option-item {
                padding: 0.5rem;
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

            .study-options-badge {
                display: block;
                margin: 0.5rem 0 0 0;
                text-align: center;
            }

            .study-title {
                flex-direction: column;
                align-items: flex-start;
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

        <?php if ($error_message): ?>
            <div class="message-container">
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message-container">
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            </div>
        <?php endif; ?>

        <!-- Study Options Modal -->
        <div id="study-options-modal" class="study-options-modal">
            <div class="study-options-content">
                <div class="study-options-header">
                    <h2 class="study-options-title">
                        <i class="fas fa-cog"></i> Tùy chọn học tập
                    </h2>
                </div>
                <div class="study-options-form">
                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-sort-amount-down"></i> Thứ tự thẻ
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="order-default" name="card-order" value="default" checked>
                            <label for="order-default">Mặc định (Ưu tiên thẻ mới)</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="order-shuffle" name="card-order" value="shuffle">
                            <label for="order-shuffle">Xáo trộn ngẫu nhiên</label>
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-exchange-alt"></i> Hiển thị thẻ
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="display-normal" name="card-display" value="normal" checked>
                            <label for="display-normal">Bình thường (Mặt trước → Mặt sau)</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="display-reversed" name="card-display" value="reversed">
                            <label for="display-reversed">Đảo ngược (Mặt sau → Mặt trước)</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="display-mixed" name="card-display" value="mixed">
                            <label for="display-mixed">Hỗn hợp (Ngẫu nhiên cả hai)</label>
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-sliders-h"></i> Tùy chọn khác
                        </h3>
                        <div class="option-item">
                            <input type="checkbox" id="show-all-cards" name="show-all-cards" value="1">
                            <label for="show-all-cards">Hiển thị tất cả thẻ (bao gồm cả thẻ đã thuộc)</label>
                        </div>
                    </div>
                </div>
                <div class="study-options-actions">
                    <button id="start-study-btn" class="btn btn-primary">
                        <i class="fas fa-play"></i> Bắt đầu học
                    </button>
                </div>
            </div>
        </div>

        <div id="study-view" class="study-container" style="display: none;">
            <div class="study-header">
                <h2 class="study-title">
                    <i class="fas fa-play"></i> Đang học: <?php echo htmlspecialchars($deck['name']); ?>
                    <span id="current-status-badge" class="status-badge status-new">Mới</span>
                    <span id="study-mode-badge" class="study-options-badge">
                        <i class="fas fa-random"></i> Xáo trộn
                    </span>
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
            <p>Bạn đã hoàn thành phiên học tập này.</p>
            
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
                <button id="restart-study-btn" class="btn btn-primary">
                    <i class="fas fa-cog"></i> Tùy chỉnh và học lại
                </button>
                <a href="edit_deck.php?id=<?php echo $deck_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Chỉnh sửa bộ thẻ
                </a>
                <a href="flashcards.php" class="btn btn-secondary">
                    <i class="fas fa-layer-group"></i> Quay lại danh sách
                </a>
            </div>
        </div>
    </div>

    <script>
        // Dữ liệu flashcards
        const originalCards = <?php echo json_encode($cards); ?>;
        let cards = [...originalCards];
        let currentCardIndex = 0;
        let totalCards = cards.length;
        let stats = {
            total: totalCards,
            mastered: 0,
            learning: 0,
            new: 0
        };
        
        let selectedLanguage = 'vi-VN';
        let availableVoices = [];
        
        let studyOptions = {
            cardOrder: 'default',
            cardDisplay: 'normal',
            showAllCards: false
        };
        
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            showStudyOptionsModal();
            animateElements('.study-container', 100);
            animateElements('.study-complete', 100);
            animateElements('.study-options-content', 50);
            
            const messages = document.querySelectorAll('.error-message, .success-message');
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
        
        document.getElementById('start-study-btn').addEventListener('click', function() {
            studyOptions.cardOrder = document.querySelector('input[name="card-order"]:checked').value;
            studyOptions.cardDisplay = document.querySelector('input[name="card-display"]:checked').value;
            studyOptions.showAllCards = document.getElementById('show-all-cards').checked;
            
            document.getElementById('study-options-modal').style.display = 'none';
            prepareCards();
            document.getElementById('study-view').style.display = 'flex';
            updateStudyModeBadge();
            updateStats();
            
            if (totalCards > 0) {
                showCard(currentCardIndex);
            } else {
                document.getElementById('study-view').style.display = 'none';
                document.getElementById('complete-view').style.display = 'block';
            }
        });
        
        document.getElementById('restart-study-btn').addEventListener('click', function() {
            document.getElementById('complete-view').style.display = 'none';
            showStudyOptionsModal();
        });
        
        function showStudyOptionsModal() {
            document.getElementById('study-options-modal').style.display = 'flex';
            document.getElementById('order-default').checked = true;
            document.getElementById('display-normal').checked = true;
            document.getElementById('show-all-cards').checked = false;
        }
        
        function prepareCards() {
            cards = [...originalCards];
            if (!studyOptions.showAllCards) {
                cards = cards.filter(card => card.status !== 'mastered');
            }
            if (studyOptions.cardOrder === 'shuffle') {
                shuffleCards(cards);
            }
            totalCards = cards.length;
            stats.total = totalCards;
            document.getElementById('total-cards').textContent = totalCards;
            currentCardIndex = 0;
        }
        
        function shuffleCards(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }
        
        function updateStudyModeBadge() {
            const badge = document.getElementById('study-mode-badge');
            badge.style.display = 'none';
            if (studyOptions.cardOrder === 'shuffle' && studyOptions.cardDisplay === 'normal') {
                badge.innerHTML = '<i class="fas fa-random"></i> Xáo trộn';
                badge.style.display = 'inline-flex';
            } else if (studyOptions.cardOrder === 'default' && studyOptions.cardDisplay === 'reversed') {
                badge.innerHTML = '<i class="fas fa-exchange-alt"></i> Đảo ngược';
                badge.style.display = 'inline-flex';
            } else if (studyOptions.cardOrder === 'shuffle' && studyOptions.cardDisplay === 'reversed') {
                badge.innerHTML = '<i class="fas fa-random"></i> Xáo trộn & Đảo ngược';
                badge.style.display = 'inline-flex';
            } else if (studyOptions.cardDisplay === 'mixed') {
                badge.innerHTML = '<i class="fas fa-dice"></i> Hỗn hợp';
                badge.style.display = 'inline-flex';
            }
        }
        
        document.getElementById('flashcard').addEventListener('click', function() {
            this.classList.toggle('flipped');
        });
        
        document.getElementById('btn-dont-know').addEventListener('click', function() {
            updateCardStatus('new');
        });
        
        document.getElementById('btn-learning').addEventListener('click', function() {
            updateCardStatus('learning');
        });
        
        document.getElementById('btn-know').addEventListener('click', function() {
            updateCardStatus('mastered');
        });
        
        function showCard(index) {
            if (index >= totalCards) {
                document.getElementById('study-view').style.display = 'none';
                document.getElementById('complete-view').style.display = 'block';
                document.getElementById('stat-total').textContent = stats.total;
                document.getElementById('stat-mastered').textContent = stats.mastered;
                document.getElementById('stat-learning').textContent = stats.learning;
                return;
            }
            
            const card = cards[index];
            let frontContent, backContent;
            
            if (studyOptions.cardDisplay === 'normal') {
                frontContent = card.front_processed;
                backContent = card.back_processed;
            } else if (studyOptions.cardDisplay === 'reversed') {
                frontContent = card.back_processed;
                backContent = card.front_processed;
            } else if (studyOptions.cardDisplay === 'mixed') {
                if (Math.random() > 0.5) {
                    frontContent = card.front_processed;
                    backContent = card.back_processed;
                } else {
                    frontContent = card.back_processed;
                    backContent = card.front_processed;
                }
            }
            
            document.getElementById('front-content').innerHTML = frontContent;
            document.getElementById('back-content').innerHTML = backContent;
            document.getElementById('flashcard').classList.remove('flipped');
            document.getElementById('current-card').textContent = index + 1;
            document.querySelector('.progress-fill').style.width = `${((index + 1) / totalCards) * 100}%`;
            
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
            
            const ttsButtons = document.querySelectorAll('.tts-button');
            ttsButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });
        
            setTimeout(() => {
                const images = document.querySelectorAll('.flashcard-image img');
                images.forEach(img => {
                    img.onload = function() {
                        this.parentNode.classList.add('loaded');
                        optimizeContentDisplay();
                    };
                    if (img.complete) {
                        img.parentNode.classList.add('loaded');
                        optimizeContentDisplay();
                    }
                });
            
                const videos = document.querySelectorAll('.flashcard-video video');
                videos.forEach(video => {
                    video.oncanplay = function() {
                        this.parentNode.classList.add('loaded');
                        optimizeContentDisplay();
                    };
                    if (video.readyState >= 3) {
                        video.parentNode.classList.add('loaded');
                        optimizeContentDisplay();
                    }
                });
            
                const iframes = document.querySelectorAll('.flashcard-youtube iframe');
                iframes.forEach(iframe => {
                    iframe.onload = function() {
                        this.parentNode.classList.add('loaded');
                        optimizeContentDisplay();
                    };
                });
                
                optimizeContentDisplay();
            }, 100);
        }

        function optimizeContentDisplay() {
            const frontContent = document.getElementById('front-content');
            const backContent = document.getElementById('back-content');
            
            [frontContent, backContent].forEach(content => {
                if (!content) return;
                
                const textLength = content.textContent.length;
                const mediaCount = content.querySelectorAll('.flashcard-media').length;
                
                content.style.fontSize = '';
                
                if (textLength > 200 && mediaCount > 0) {
                    content.style.fontSize = '1rem';
                } else if (textLength > 100 && mediaCount > 0) {
                    content.style.fontSize = '1.1rem';
                } else if (textLength > 200) {
                    content.style.fontSize = '1.1rem';
                } else if (textLength > 100) {
                    content.style.fontSize = '1.2rem';
                } else {
                    content.style.fontSize = '1.25rem';
                }
                
                const mediaElements = content.querySelectorAll('.flashcard-media');
                
                mediaElements.forEach(media => {
                    media.style.maxHeight = '';
                    const img = media.querySelector('img');
                    if (img) img.style.maxHeight = '';
                    const video = media.querySelector('video');
                    if (video) video.style.maxHeight = '';
                });
                
                if (mediaElements.length > 1) {
                    mediaElements.forEach(media => {
                        media.style.maxHeight = '120px';
                        const img = media.querySelector('img');
                        if (img) img.style.maxHeight = '120px';
                        const video = media.querySelector('video');
                        if (video) video.style.maxHeight = '120px';
                    });
                } else if (mediaElements.length === 1 && textLength > 100) {
                    mediaElements[0].style.maxHeight = '150px';
                    const img = mediaElements[0].querySelector('img');
                    if (img) img.style.maxHeight = '150px';
                    const video = mediaElements[0].querySelector('video');
                    if (video) video.style.maxHeight = '150px';
                } else if (mediaElements.length === 1) {
                    mediaElements[0].style.maxHeight = '180px';
                    const img = mediaElements[0].querySelector('img');
                    if (img) img.style.maxHeight = '180px';
                    const video = mediaElements[0].querySelector('video');
                    if (video) video.style.maxHeight = '180px';
                }
            });
        }
    
        function updateCardStatus(status) {
            if (currentCardIndex >= totalCards) return;
        
            const card = cards[currentCardIndex];
            const cardId = card.id;
        
            fetch('study.php?deck_id=<?php echo $deck_id; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `update_status=1&card_id=${cardId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    cards[currentCardIndex].status = status;
                    const originalIndex = originalCards.findIndex(c => c.id === cardId);
                    if (originalIndex !== -1) {
                        originalCards[originalIndex].status = status;
                    }
                
                    updateStats();
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
    
        function speakText(button) {
            const text = button.getAttribute('data-text');
        
            if ('speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(text);
                const voices = window.speechSynthesis.getVoices();
                const viVoice = voices.find(voice => voice.lang.includes('vi'));
            
                if (viVoice) {
                    utterance.voice = viVoice;
                }
            
                window.speechSynthesis.speak(utterance);
            } else {
                console.log('Trình duyệt của bạn không hỗ trợ Text-to-Speech');
            }
        }
    
        window.speechSynthesis.onvoiceschanged = function() {
            availableVoices = window.speechSynthesis.getVoices();
        };
    
        if ('speechSynthesis' in window) {
            availableVoices = window.speechSynthesis.getVoices();
        }

        function stripHtml(html) {
            let tmp = document.createElement("DIV");
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || "";
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