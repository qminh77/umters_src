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

// Lấy danh sách bộ flashcard của người dùng
$sql_decks = "SELECT d.*, 
              (SELECT COUNT(*) FROM flashcards f WHERE f.deck_id = d.id) as card_count,
              (SELECT COUNT(*) FROM flashcard_progress fp WHERE fp.deck_id = d.id AND fp.user_id = $user_id AND fp.status = 'mastered') as mastered_count
              FROM flashcard_decks d 
              WHERE d.user_id = $user_id 
              ORDER BY d.created_at DESC";
$result_decks = mysqli_query($conn, $sql_decks);

// Lấy thống kê học tập
$sql_stats = "SELECT 
              COUNT(*) as total_cards,
              SUM(CASE WHEN status = 'mastered' THEN 1 ELSE 0 END) as mastered_cards,
              SUM(CASE WHEN status = 'learning' THEN 1 ELSE 0 END) as learning_cards,
              SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_cards
              FROM flashcard_progress 
              WHERE user_id = $user_id";
$result_stats = mysqli_query($conn, $sql_stats);
$stats = mysqli_fetch_assoc($result_stats);

// Lấy lịch sử học tập 7 ngày gần nhất
$sql_history = "SELECT DATE(study_date) as study_day, COUNT(*) as cards_studied 
                FROM flashcard_study_history 
                WHERE user_id = $user_id 
                GROUP BY DATE(study_date) 
                ORDER BY study_day DESC 
                LIMIT 7";
$result_history = mysqli_query($conn, $sql_history);
$history_data = [];
while ($row = mysqli_fetch_assoc($result_history)) {
    $history_data[$row['study_day']] = $row['cards_studied'];
}

// Lấy flashcards cần ôn tập hôm nay (dựa trên thuật toán giãn cách thời gian)
$today = date('Y-m-d');
$sql_due_cards = "SELECT f.*, d.name as deck_name, fp.status, fp.next_review_date
                 FROM flashcards f
                 JOIN flashcard_decks d ON f.deck_id = d.id
                 JOIN flashcard_progress fp ON f.id = fp.flashcard_id
                 WHERE fp.user_id = $user_id 
                 AND fp.next_review_date <= '$today'
                 ORDER BY fp.next_review_date ASC
                 LIMIT 10";
$result_due_cards = mysqli_query($conn, $sql_due_cards);

// Xử lý tạo bộ flashcard mới
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_deck'])) {
    $deck_name = mysqli_real_escape_string($conn, $_POST['deck_name']);
    $deck_description = mysqli_real_escape_string($conn, $_POST['deck_description']);
    
    $sql = "INSERT INTO flashcard_decks (name, description, user_id, created_at) 
            VALUES ('$deck_name', '$deck_description', $user_id, NOW())";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Bộ flashcard đã được tạo thành công!";
        $deck_id = mysqli_insert_id($conn);
        header("Location: edit_deck.php?id=$deck_id");
        exit;
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}

// Xử lý xóa bộ flashcard
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_deck'])) {
    $deck_id = (int)$_POST['deck_id'];
    
    // Xóa tất cả flashcards trong bộ
    mysqli_query($conn, "DELETE FROM flashcards WHERE deck_id = $deck_id AND deck_id IN (SELECT id FROM flashcard_decks WHERE user_id = $user_id)");
    
    // Xóa tiến trình học tập liên quan
    mysqli_query($conn, "DELETE FROM flashcard_progress WHERE deck_id = $deck_id AND user_id = $user_id");
    
    // Xóa bộ flashcard
    $sql = "DELETE FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Bộ flashcard đã được xóa thành công!";
        header("Location: flashcards.php");
        exit;
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hệ Thống Flashcard - Quản Lý</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        --delete-color: #FF3D57;
        --delete-hover-color: #FF1A3A;
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

    .back-to-dashboard {
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

    .back-to-dashboard:hover {
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

    /* Dashboard layout */
    .dashboard {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 2rem;
    }

    .main-content {
        display: flex;
        flex-direction: column;
        gap: 2rem;
    }

    .sidebar {
        display: flex;
        flex-direction: column;
        gap: 2rem;
    }
    
    /* Cards & sections */
    .card {
        background: rgba(30, 30, 60, 0.5);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card::before {
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

    .card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        position: relative;
        z-index: 1;
    }

    .card-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        position: relative;
        padding-bottom: 0.5rem;
    }

    .card-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 40px;
        height: 3px;
        background: linear-gradient(to right, var(--primary), var(--secondary));
        border-radius: var(--radius-full);
    }

    .card-title i {
        font-size: 1.25rem;
        background: linear-gradient(to right, var(--primary-light), var(--secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* Stats grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: var(--card);
        border-radius: var(--radius-sm);
        padding: 1.25rem 1rem;
        text-align: center;
        transition: all 0.3s ease;
        border: 1px solid var(--border-light);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        background: var(--card-hover);
        box-shadow: var(--shadow-sm);
        border-color: var(--border);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        background: linear-gradient(135deg, var(--primary-light), var(--secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .stat-label {
        font-size: 0.875rem;
        color: var(--foreground-muted);
    }

    /* Deck grid */
    .deck-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
    }

    .deck-card {
        background: var(--card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        overflow: hidden;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .deck-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow);
        border-color: var(--border);
        background: var(--card-hover);
    }

    .deck-header {
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        position: relative;
        overflow: hidden;
    }

    .deck-header::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: 
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.2), transparent 70%),
            linear-gradient(to bottom, rgba(255, 255, 255, 0.1), transparent);
        pointer-events: none;
    }

    .deck-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: white;
    }

    .deck-meta {
        display: flex;
        justify-content: space-between;
        font-size: 0.875rem;
        color: rgba(255, 255, 255, 0.8);
    }

    .deck-body {
        padding: 1.5rem;
        flex: 1;
    }

    .deck-description {
        color: var(--foreground-muted);
        margin-bottom: 1.25rem;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .deck-progress {
        margin-top: 1rem;
    }

    .progress-bar {
        height: 8px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 0.5rem;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--secondary), var(--accent));
        border-radius: 4px;
        transition: width 0.5s ease;
    }

    .progress-stats {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: var(--foreground-subtle);
    }

    .deck-footer {
        padding: 1rem;
        background: rgba(0, 0, 0, 0.2);
        border-top: 1px solid var(--border);
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
    }

    .deck-btn {
        padding: 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 500;
        text-align: center;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }

    .btn-primary {
        background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        color: white;
    }

    .btn-primary:hover {
        background: linear-gradient(90deg, var(--primary-light), var(--primary));
        transform: translateY(-2px);
        box-shadow: var(--glow);
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

    .btn-danger {
        background: var(--delete-color);
        color: white;
    }

    .btn-danger:hover {
        background: var(--delete-hover-color);
        transform: translateY(-2px);
        box-shadow: 0 0 15px rgba(255, 61, 87, 0.4);
    }

    /* Due cards */
    .due-cards {
        margin-top: 1rem;
    }

    .due-card-item {
        padding: 1rem;
        background: var(--card);
        border-radius: var(--radius-sm);
        margin-bottom: 0.75rem;
        transition: all 0.3s ease;
        border: 1px solid var(--border-light);
    }

    .due-card-item:hover {
        transform: translateY(-3px);
        background: var(--card-hover);
        border-color: var(--border);
        box-shadow: var(--shadow-sm);
    }

    .due-card-title {
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: var(--foreground);
    }

    .due-card-deck {
        font-size: 0.875rem;
        color: var(--foreground-muted);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .due-card-status {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 1rem;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .status-new {
        background: rgba(59, 130, 246, 0.1);
        color: var(--new-color);
        border: 1px solid rgba(59, 130, 246, 0.2);
    }

    .status-learning {
        background: rgba(245, 158, 11, 0.1);
        color: var(--learning-color);
        border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .status-mastered {
        background: rgba(34, 197, 94, 0.1);
        color: var(--mastered-color);
        border: 1px solid rgba(34, 197, 94, 0.2);
    }

    /* Empty states */
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--foreground-muted);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--primary-light), var(--secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        opacity: 0.8;
    }

    .empty-state p {
        font-size: 1.1rem;
        margin-bottom: 1.5rem;
    }

    /* Modals */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
        padding: 1rem;
        backdrop-filter: blur(3px);
    }

    .modal.active {
        display: flex;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-content {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow-lg);
        width: 100%;
        max-width: 500px;
        padding: 2rem;
        position: relative;
        transform: translateY(20px);
        transition: transform 0.3s ease;
        border: 1px solid var(--border);
    }

    .modal.active .modal-content {
        transform: translateY(0);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .modal-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--foreground);
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--foreground-muted);
        transition: color 0.3s ease;
    }

    .modal-close:hover {
        color: var(--delete-color);
    }

    /* Forms */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: var(--foreground);
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

    textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 1.5rem;
    }

    /* Buttons */
    .btn {
        padding: 0.75rem 1.25rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border: none;
        text-decoration: none;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }

    .btn-action {
        width: 100%;
        padding: 0.75rem;
        background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        color: white;
        margin-top: 1rem;
        font-weight: 600;
    }

    .btn-action:hover {
        background: linear-gradient(90deg, var(--primary-light), var(--primary));
        transform: translateY(-2px);
        box-shadow: var(--glow);
    }

    /* ChartJS customization */
    #studyHistoryChart {
        margin-top: 1.5rem;
        position: relative;
        z-index: 1;
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
    @media (max-width: 992px) {
        .dashboard {
            grid-template-columns: 1fr;
        }
        
        .sidebar {
            order: -1;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 1rem;
        }

        .page-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
            padding: 1.25rem;
        }
        
        .back-to-dashboard {
            width: 100%;
            justify-content: center;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .deck-footer {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .form-actions .btn {
            width: 100%;
        }
    }
  </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-layer-group"></i> Hệ Thống Flashcard</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
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

        <div class="dashboard">
            <div class="main-content">
                <!-- Thống kê -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-chart-pie"></i> Thống kê học tập</h2>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['total_cards'] ?? 0; ?></div>
                            <div class="stat-label">Tổng số thẻ</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['mastered_cards'] ?? 0; ?></div>
                            <div class="stat-label">Đã thuộc</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['learning_cards'] ?? 0; ?></div>
                            <div class="stat-label">Đang học</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['new_cards'] ?? 0; ?></div>
                            <div class="stat-label">Chưa học</div>
                        </div>
                    </div>
                    <canvas id="studyHistoryChart" height="100"></canvas>
                </div>

                <!-- Danh sách bộ flashcard -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-layer-group"></i> Bộ Flashcard của bạn</h2>
                        <button class="btn btn-primary btn-sm" onclick="openModal('create-deck-modal')">
                            <i class="fas fa-plus"></i> Tạo bộ mới
                        </button>
                    </div>
                    
                    <?php if (mysqli_num_rows($result_decks) > 0): ?>
                        <div class="deck-grid">
                            <?php while ($deck = mysqli_fetch_assoc($result_decks)): ?>
                                <div class="deck-card">
                                    <div class="deck-header">
                                        <h3 class="deck-title"><?php echo htmlspecialchars($deck['name']); ?></h3>
                                        <div class="deck-meta">
                                            <span><?php echo $deck['card_count']; ?> thẻ</span>
                                            <span>Tạo: <?php echo date('d/m/Y', strtotime($deck['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="deck-body">
                                        <p class="deck-description"><?php echo htmlspecialchars($deck['description']); ?></p>
                                        <div class="deck-progress">
                                            <?php 
                                            $progress = $deck['card_count'] > 0 ? ($deck['mastered_count'] / $deck['card_count']) * 100 : 0;
                                            ?>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <div class="progress-stats">
                                                <span><?php echo $deck['mastered_count']; ?> / <?php echo $deck['card_count']; ?> đã thuộc</span>
                                                <span><?php echo round($progress); ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="deck-footer">
                                        <a href="study.php?deck_id=<?php echo $deck['id']; ?>" class="deck-btn btn-primary">
                                            <i class="fas fa-play"></i> Học
                                        </a>
                                        <a href="edit_deck.php?id=<?php echo $deck['id']; ?>" class="deck-btn btn-secondary">
                                            <i class="fas fa-edit"></i> Sửa
                                        </a>
                                        <button class="deck-btn btn-danger" onclick="confirmDeleteDeck(<?php echo $deck['id']; ?>, '<?php echo htmlspecialchars($deck['name']); ?>')">
                                            <i class="fas fa-trash"></i> Xóa
                                        </button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-layer-group"></i>
                            <p>Bạn chưa có bộ flashcard nào. Hãy tạo bộ đầu tiên!</p>
                            <button class="btn btn-primary" onclick="openModal('create-deck-modal')">
                                <i class="fas fa-plus"></i> Tạo bộ flashcard
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar">
                <!-- Thẻ cần ôn tập -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-clock"></i> Cần ôn tập hôm nay</h2>
                    </div>
                    
                    <?php if (mysqli_num_rows($result_due_cards) > 0): ?>
                        <div class="due-cards">
                            <?php while ($card = mysqli_fetch_assoc($result_due_cards)): ?>
                                <div class="due-card-item">
                                    <div class="due-card-title"><?php echo htmlspecialchars($card['front']); ?></div>
                                    <div class="due-card-deck">
                                        <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($card['deck_name']); ?>
                                    </div>
                                    <div class="due-card-status status-<?php echo $card['status']; ?>">
                                        <?php 
                                        $status_text = '';
                                        switch($card['status']) {
                                            case 'new': $status_text = 'Mới'; break;
                                            case 'learning': $status_text = 'Đang học'; break;
                                            case 'mastered': $status_text = 'Đã thuộc'; break;
                                        }
                                        echo $status_text;
                                        ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <a href="review.php" class="btn btn-action">
                                <i class="fas fa-sync-alt"></i> Ôn tập tất cả
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>Không có thẻ nào cần ôn tập hôm nay!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Xuất dữ liệu -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fas fa-file-export"></i> Công cụ</h2>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 0.5rem;">
                        <a href="export.php" class="btn btn-secondary" style="width: 100%;">
                            <i class="fas fa-file-csv"></i> Xuất dữ liệu CSV
                        </a>
                        <a href="import.php" class="btn btn-secondary" style="width: 100%;">
                            <i class="fas fa-file-import"></i> Nhập dữ liệu
                        </a>
                        <a href="shared_decks.php" class="btn btn-secondary" style="width: 100%;">
                            <i class="fas fa-share-alt"></i> Bộ thẻ được chia sẻ
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal tạo bộ flashcard mới -->
    <div id="create-deck-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Tạo bộ Flashcard mới</h3>
                <button class="modal-close" onclick="closeModal('create-deck-modal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="deck_name">Tên bộ flashcard</label>
                    <input type="text" id="deck_name" name="deck_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="deck_description">Mô tả</label>
                    <textarea id="deck_description" name="deck_description" class="form-control"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('create-deck-modal')">Hủy</button>
                    <button type="submit" name="create_deck" class="btn btn-primary">Tạo bộ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="delete-deck-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Xác nhận xóa</h3>
                <button class="modal-close" onclick="closeModal('delete-deck-modal')">&times;</button>
            </div>
            <p>Bạn có chắc chắn muốn xóa bộ flashcard "<span id="delete-deck-name"></span>"?</p>
            <p style="margin-top: 0.5rem; color: var(--foreground-muted);">Tất cả thẻ và dữ liệu học tập liên quan sẽ bị xóa vĩnh viễn.</p>
            <form method="POST" action="" id="delete-deck-form">
                <input type="hidden" name="deck_id" id="delete-deck-id">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('delete-deck-modal')">Hủy</button>
                    <button type="submit" name="delete_deck" class="btn btn-danger">Xóa vĩnh viễn</button>
                </div>
            </form>
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

        // Biểu đồ lịch sử học tập với màu sắc dark theme
        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particles
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.card', 100);
            animateElements('.stat-card', 50);
            animateElements('.deck-card', 50);
            animateElements('.due-card-item', 30);
            
            const ctx = document.getElementById('studyHistoryChart').getContext('2d');
            
            // Dữ liệu lịch sử học tập
            const historyData = <?php 
                $labels = [];
                $data = [];
                
                // Lấy 7 ngày gần nhất
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = date('d/m', strtotime($date));
                    $data[] = isset($history_data[$date]) ? $history_data[$date] : 0;
                }
                
                echo json_encode([
                    'labels' => $labels,
                    'data' => $data
                ]);
            ?>;
            
            // Cấu hình dark theme cho Chart.js
            Chart.defaults.color = 'rgba(255, 255, 255, 0.7)';
            Chart.defaults.scale.grid.color = 'rgba(255, 255, 255, 0.1)';
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: historyData.labels,
                    datasets: [{
                        label: 'Số thẻ đã học',
                        data: historyData.data,
                        backgroundColor: 'rgba(0, 224, 255, 0.2)',
                        borderColor: '#00E0FF',
                        borderWidth: 2,
                        pointBackgroundColor: '#00E0FF',
                        pointBorderColor: '#12122A',
                        pointHoverBackgroundColor: '#FF3DFF',
                        pointHoverBorderColor: '#12122A',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'Outfit',
                                    size: 12
                                },
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Lịch sử học tập 7 ngày gần đây',
                            color: 'rgba(255, 255, 255, 0.9)',
                            font: {
                                family: 'Outfit',
                                size: 14,
                                weight: '600'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(18, 18, 42, 0.9)',
                            titleFont: {
                                family: 'Outfit',
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                family: 'Outfit',
                                size: 13
                            },
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y + ' thẻ đã học';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                font: {
                                    family: 'Outfit'
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                font: {
                                    family: 'Outfit'
                                }
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 4,
                            hoverRadius: 6
                        }
                    }
                }
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

        // Mở modal
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Đóng modal
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Xác nhận xóa bộ flashcard
        function confirmDeleteDeck(deckId, deckName) {
            document.getElementById('delete-deck-id').value = deckId;
            document.getElementById('delete-deck-name').textContent = deckName;
            openModal('delete-deck-modal');
        }

        // Đóng modal khi click bên ngoài
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>