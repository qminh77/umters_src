<?php
session_start();
include 'db_config.php';
include 'quiz_functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Kiểm tra ID bộ câu hỏi
if (!isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) {
    header("Location: quizlet.php");
    exit;
}

$quiz_id = (int)$_GET['quiz_id'];

// Lấy thông tin bộ câu hỏi
$sql_quiz = "SELECT * FROM quiz_sets WHERE id = $quiz_id AND user_id = $user_id";
$result_quiz = mysqli_query($conn, $sql_quiz);

if (mysqli_num_rows($result_quiz) == 0) {
    header("Location: quizlet.php");
    exit;
}

$quiz = mysqli_fetch_assoc($result_quiz);

// Lấy danh sách câu hỏi trong bộ
$sql_questions = "SELECT * FROM quiz_questions WHERE quiz_id = $quiz_id ORDER BY id ASC";
$result_questions = mysqli_query($conn, $sql_questions);
$question_count = mysqli_num_rows($result_questions);

// Lấy tất cả câu hỏi để sử dụng trong JavaScript
$questions = [];
while ($question = mysqli_fetch_assoc($result_questions)) {
    // Process media content for display
    $question['question_processed'] = process_quiz_media_content($question['question']);
    $question['option1_processed'] = process_quiz_media_content($question['option1']);
    $question['option2_processed'] = process_quiz_media_content($question['option2']);
    $question['option3_processed'] = process_quiz_media_content($question['option3']);
    $question['option4_processed'] = process_quiz_media_content($question['option4']);
    $question['explanation_processed'] = process_quiz_media_content($question['explanation']);
    
    // Convert correct_answers string to array if it exists
    if ($question['is_multiple_choice'] && !empty($question['correct_answers'])) {
        $question['correct_answers'] = explode(',', $question['correct_answers']);
    }
    
    $questions[] = $question;
}

// Xử lý cập nhật tiến trình học tập
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_progress'])) {
    $question_id = (int)$_POST['question_id'];
    $selected_answer = (int)$_POST['selected_answer'];
    $is_correct = 0;
    
    // Kiểm tra câu trả lời
    $sql_check = "SELECT correct_answer FROM quiz_questions WHERE id = $question_id AND quiz_id = $quiz_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        $question = mysqli_fetch_assoc($result_check);
        $is_correct = ($selected_answer == $question['correct_answer']) ? 1 : 0;
    }
    
    // Kiểm tra xem đã có bản ghi trong bảng tiến trình chưa
    $sql_check_progress = "SELECT * FROM quiz_progress WHERE user_id = $user_id AND question_id = $question_id";
    $result_check_progress = mysqli_query($conn, $sql_check_progress);
    
    if (mysqli_num_rows($result_check_progress) > 0) {
        // Cập nhật bản ghi hiện có
        $sql = "UPDATE quiz_progress 
                SET selected_answer = $selected_answer, is_correct = $is_correct, last_studied = NOW() 
                WHERE user_id = $user_id AND question_id = $question_id";
    } else {
        // Tạo bản ghi mới
        $sql = "INSERT INTO quiz_progress (user_id, quiz_id, question_id, selected_answer, is_correct, last_studied) 
                VALUES ($user_id, $quiz_id, $question_id, $selected_answer, $is_correct, NOW())";
    }
    
    if (mysqli_query($conn, $sql)) {
        // Thêm vào lịch sử học tập
        $sql_history = "INSERT INTO quiz_study_history (user_id, quiz_id, question_id, selected_answer, is_correct, study_date) 
                        VALUES ($user_id, $quiz_id, $question_id, $selected_answer, $is_correct, NOW())";
        mysqli_query($conn, $sql_history);
        
        echo json_encode(['success' => true, 'is_correct' => $is_correct]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Lấy thống kê học tập
$stats = get_quiz_statistics($quiz_id, $user_id, $conn);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Học Quizlet - <?php echo htmlspecialchars($quiz['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            /* Gradient và màu chính */
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #f5f7fa, #e4e9f2);
            
            /* Màu nền */
            --container-bg: rgba(255, 255, 255, 0.98);
            --card-bg: #ffffff;
            --form-bg: #f8fafc;
            --hover-bg: #f1f5f9;
            
            /* Màu chữ */
            --text-color: #334155;
            --text-secondary: #64748b;
            --link-color: #38bdf8;
            --link-hover-color: #0ea5e9;
            
            /* Màu trạng thái */
            --error-color: #ef4444;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --correct-color: #22c55e;
            --incorrect-color: #ef4444;
            
            /* Hiệu ứng và bo góc */
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
            --border-radius: 1rem;
            --small-radius: 0.75rem;
            --button-radius: 1.5rem;
            
            /* Khoảng cách */
            --padding: 2rem;
            --small-padding: 1rem;
            
            /* Hiệu ứng */
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .logo i {
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-speed) ease;
        }

        .user-menu a:hover {
            color: var(--link-color);
        }

        .user-menu .btn {
            padding: 0.5rem 1rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu .btn:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(116, 235, 213, 0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--small-radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .study-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70vh;
        }

        .study-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 2rem;
        }

        .study-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .study-title i {
            color: var(--primary-gradient-start);
        }

        .study-progress {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .progress-text {
            top: 30%;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .progress-bar {
            width: 200px;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .quiz-card {
            width: 100%;
            max-width: 800px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .quiz-question {
            font-size: 1.25rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .quiz-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .quiz-option {
            background: var(--form-bg);
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--small-radius);
            padding: 1rem;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .quiz-option:hover {
            background: var(--hover-bg);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .quiz-option.selected {
            border-color: var(--primary-gradient-start);
            background: rgba(116, 235, 213, 0.1);
        }

        .quiz-option.correct {
            border-color: var(--correct-color);
            background: rgba(34, 197, 94, 0.1);
        }

        .quiz-option.incorrect {
            border-color: var(--incorrect-color);
            background: rgba(239, 68, 68, 0.1);
        }

        .quiz-option-marker {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .quiz-option.selected .quiz-option-marker {
            background: var(--primary-gradient-start);
            color: white;
            border-color: var(--primary-gradient-start);
        }

        .quiz-option.correct .quiz-option-marker {
            background: var(--correct-color);
            color: white;
            border-color: var(--correct-color);
        }

        .quiz-option.incorrect .quiz-option-marker {
            background: var(--incorrect-color);
            color: white;
            border-color: var(--incorrect-color);
        }

        .quiz-explanation {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--small-radius);
            padding: 1rem;
            margin-top: 1rem;
            display: none;
        }

        .quiz-explanation.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .quiz-explanation-title {
            font-weight: 600;
            color: var(--info-color);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quiz-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all股权 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(116, 235, 213, 0.3);
        }

        .btn-secondary {
            background: var(--form-bg);
            color: var(--text-color);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .btn-secondary:hover {
            background: var(--hover-bg);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .btn-check {
            background: var(--info-color);
            color: white;
            border: none;
        }

        .btn-check:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
        }

        .btn-next {
            background: var(--success-color);
            color: white;
            border: none;
        }

        .btn-next:hover {
            background: #16a34a;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(34, 197, 94, 0.3);
        }

        .btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .study-complete {
            text-align: center;
            padding: 3rem 1rem;
        }

        .study-complete i {
            font-size: 1.5rem;
            color: var(--success-color);
            /*margin-bottom: 1.5rem;*/
        }

        .study-complete h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .study-complete p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            color: var(--text-secondary);
        }

        .study-stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .study-actions-complete {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Quiz media styles */
        .quiz-media {
            margin: 0.5rem 0;
            border-radius: 0.5rem;
            overflow: hidden;
            max-width: 100%;
            position: relative;
        }

        .quiz-image {
            text-align: center;
            background-color: rgba(0, 0, 0, 0.03);
            min-height: 100px;
            max-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quiz-image img {
            max-width: 100%;
            max-height: 300px;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }

        .quiz-video {
            position: relative;
            min-height: 150px;
            max-height: 300px;
            background-color: rgba(0, 0, 0, 0.03);
        }

        .quiz-video video {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
        }

        .quiz-youtube {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            margin: 0.5rem auto;
            background-color: rgba(0, 0, 0, 0.03);
            width: 100%;
        }

        .quiz-youtube iframe {
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
            background-color: rgba(0, 0, 0, 0.1);
            color: var(--text-color);
            font-size: 1.5rem;
        }

        .loaded .media-loading {
            display: none;
        }

        /* Study options modal */
        .study-options-modal {
            /*position: fixed;*/
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /*background-color: rgba(0, 0, 0, 0.5);*/
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .study-options-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow);
            animation: fadeIn 0.3s ease, slideUp 0.3s ease;
            border: 1px solid rgba(116, 235, 213, 0.2);
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
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .study-options-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .study-options-title i {
            color: var(--primary-gradient-start);
            font-size: 1.1rem;
        }

        .study-options-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .option-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            background: var(--form-bg);
            padding: 1.25rem;
            border-radius: var(--small-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all var(--transition-speed) ease;
        }

        .option-group:hover {
            box-shadow: var(--shadow-sm);
            border-color: rgba(116, 235, 213, 0.3);
        }

        .option-group-title {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .option-group-title i {
            color: var(--primary-gradient-start);
            font-size: 1.1rem;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.25rem;
            transition: background-color var(--transition-speed) ease;
        }

        .option-item:hover {
            background-color: rgba(116, 235, 213, 0.1);
        }

        .option-item input[type="radio"],
        .option-item input[type="checkbox"] {
            width: 1.2rem;
            height: 1.2rem;
            accent-color: var(--primary-gradient-start);
            cursor: pointer;
        }

        .option-item label {
            font-size: 1rem;
            color: var(--text-color);
            cursor: pointer;
            flex: 1;
        }

        .study-options-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .logo {
                justify-content: center;
            }

            .user-menu {
                justify-content: center;
                flex-wrap: wrap;
            }

            .study-header {
                flex-direction: column;
                gap: 1rem;
            }

            .quiz-card {
                padding: 1.5rem;
            }

            .quiz-actions {
                flex-direction: column;
                gap: 1rem;
            }

            .quiz-actions .btn {
                width: 100%;
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
            .quiz-option {
                padding: 0.75rem;
            }

            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.875rem;
            }
        }

        .timer-container {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: var(--small-radius);
            padding: 0.5rem 1rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 100;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .timer-icon {
            color: var(--warning-color);
        }

        .timer-text {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .timer-warning {
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        /* Thêm hiệu ứng cho kết quả đúng/sai */
        .result-feedback {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 5rem;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        
        .result-feedback.correct {
            color: var(--correct-color);
        }
        
        .result-feedback.incorrect {
            color: var(--incorrect-color);
        }
        
        .result-feedback.show {
            opacity: 1;
            animation: fadeInOut 1.5s ease;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
            20% { opacity: 1; transform: translate(-50%, -50%) scale(1.2); }
            80% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
            100% { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
        }

    /* CSS cho chế độ thi */
    .quiz-card.exam-mode .btn-check {
        display: none;
    }
    
    .quiz-card.exam-mode .quiz-option {
        cursor: pointer;
    }
    
    .quiz-card.exam-mode .quiz-option:hover {
        background: var(--hover-bg);
        border-color: var(--primary-gradient-start);
    }

    .question-timer {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: var(--text-color);
        box-shadow: var(--shadow-sm);
        border: 2px solid var(--primary-gradient-start);
        transition: all 0.3s ease;
    }

    .question-timer.warning {
        color: var(--warning-color);
        border-color: var(--warning-color);
    }

    .question-timer.danger {
        color: var(--incorrect-color);
        border-color: var(--incorrect-color);
        animation: pulse 0.5s infinite;
    }

    .checkbox-label, .radio-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        width: 100%;
        padding: 0.5rem;
        border-radius: var(--small-radius);
        transition: background-color 0.2s ease;
    }

    .checkbox-label:hover, .radio-label:hover {
        background-color: var(--hover-bg);
    }

    .checkbox-label input[type="checkbox"],
    .radio-label input[type="radio"] {
        margin: 0;
        width: 1.25rem;
        height: 1.25rem;
    }

    .quiz-option.selected {
        background-color: var(--primary-gradient-start);
        color: white;
    }

    .quiz-option.correct {
        background-color: var(--correct-color);
        color: white;
    }

    .quiz-option.incorrect {
        background-color: var(--incorrect-color);
        color: white;
    }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-question-circle"></i></span>
                <span>QuizMaster</span>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="quizlet.php"><i class="fas fa-question-circle"></i> Quizlet</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </header>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
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
                            <i class="fas fa-sort-amount-down"></i> Thứ tự câu hỏi
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="order-default" name="question-order" value="default" checked>
                            <label for="order-default">Mặc định</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="order-shuffle" name="question-order" value="shuffle">
                            <label for="order-shuffle">Xáo trộn ngẫu nhiên</label>
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-random"></i> Số lượng câu hỏi
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="all-questions" name="question-count" value="all" checked>
                            <label for="all-questions">Tất cả câu hỏi</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="custom-questions" name="question-count" value="custom">
                            <label for="custom-questions">Số lượng tùy chọn:</label>
                            <input type="number" id="custom-question-count" min="1" max="<?php echo $question_count; ?>" value="10" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid #ccc;">
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-clock"></i> Chế độ học tập
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="practice-mode" name="study-mode" value="practice" checked>
                            <label for="practice-mode">Chế độ luyện tập (hiển thị đáp án)</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="exam-mode" name="study-mode" value="exam">
                            <label for="exam-mode">Chế độ thi (không hiển thị đáp án)</label>
                        </div>
                        <div class="option-item" id="time-limit-container" style="display: none; margin-top: 10px; padding-left: 25px;">
                            <label for="time-limit">Thời gian làm bài (phút):</label>
                            <input type="number" id="time-limit" min="1" max="180" value="30" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid #ccc;">
                        </div>
                        <!-- Thêm tùy chọn Chế độ học nhanh -->
                        <div class="option-item">
                            <input type="radio" id="flash-mode" name="study-mode" value="flash">
                            <label for="flash-mode">Chế độ học nhanh (giới hạn thời gian mỗi câu)</label>
                        </div>
                        <div class="option-item" id="flash-time-container" style="display: none; margin-top: 10px; padding-left: 25px;">
                            <label for="flash-time-limit">Thời gian mỗi câu (giây):</label>
                            <input type="number" id="flash-time-limit" min="3" max="60" value="10" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid #ccc;">
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-sliders-h"></i> Tùy chọn khác
                        </h3>
                        <div class="option-item">
                            <input type="checkbox" id="show-explanation" name="show-explanation" value="1" checked>
                            <label for="show-explanation">Hiển thị giải thích sau khi trả lời</label>
                        </div>
                        <div class="option-item">
                            <input type="checkbox" id="show-all-questions" name="show-all-questions" value="1">
                            <label for="show-all-questions">Hiển thị tất cả câu hỏi (bao gồm cả câu đã học)</label>
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

        <div id="timer-container" class="timer-container" style="display: none;">
            <div class="timer-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="timer-text" id="timer-text">30:00</div>
        </div>

        <!-- Phản hồi kết quả -->
        <div id="result-feedback" class="result-feedback">
            <i class="fas fa-check-circle"></i>
        </div>

        <div id="study-view" class="study-container" style="display: none;">
            <div class="study-header">
                <h2 class="study-title">
                    <i class="fas fa-play"></i> Đang học: <?php echo htmlspecialchars($quiz['name']); ?>
                </h2>
                <div class="study-progress">
                    <div class="progress-text"><span id="current-question">1</span>/<span id="total-questions"><?php echo $question_count; ?></span></div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <div class="quiz-card" id="quiz-card">
                <div class="quiz-question" id="question-text"></div>
                <div class="quiz-options" id="options-container"></div>
                <div class="quiz-explanation" id="explanation-container">
                    <div class="quiz-explanation-title">
                        <i class="fas fa-info-circle"></i> Giải thích
                    </div>
                    <div id="explanation-text"></div>
                </div>
                <div class="quiz-actions">
                    <button id="btn-check" class="btn btn-check">
                        <i class="fas fa-check"></i> Kiểm tra
                    </button>
                    <button id="btn-next" class="btn btn-next btn-disabled">
                        <i class="fas fa-arrow-right"></i> Câu tiếp theo
                    </button>
                </div>
            </div>
        </div>

        <div id="complete-view" class="study-complete" style="display: none;">
            <i class="fas fa-trophy"></i>
            <h2>Chúc mừng!</h2>
            <p>Bạn đã hoàn thành phiên học tập này.</p>
            
            <div class="study-stats">
                <div class="stat-item">
                    <div class="stat-value" id="stat-total">0</div>
                    <div class="stat-label">Tổng số câu hỏi</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="stat-correct">0</div>
                    <div class="stat-label">Trả lời đúng</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="stat-accuracy">0%</div>
                    <div class="stat-label">Độ chính xác</div>
                </div>
            </div>
            
            <div class="study-actions-complete">
                <button id="restart-study-btn" class="btn btn-primary">
                    <i class="fas fa-cog"></i> Tùy chỉnh và học lại
                </button>
                <a href="edit_quiz.php?id=<?php echo $quiz_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Chỉnh sửa bộ câu hỏi
                </a>
                <a href="quizlet.php" class="btn btn-secondary">
                    <i class="fas fa-question-circle"></i> Quay lại danh sách
                </a>
            </div>
        </div>
    </div>

    <script>
        // Dữ liệu câu hỏi
        const originalQuestions = <?php echo json_encode($questions); ?>;
        let questions = [...originalQuestions]; // Tạo bản sao để xử lý
        let currentQuestionIndex = 0;
        let totalQuestions = questions.length;
        let selectedAnswer = null;
        let isAnswerChecked = false;
        let stats = {
            total: totalQuestions,
            correct: 0,
            incorrect: 0
        };
        
        // Thêm biến để lưu trữ câu trả lời của người dùng
        let userAnswers = [];
        
        // Biến lưu trữ tùy chọn học tập
        let studyOptions = {
            questionOrder: 'default',
            showExplanation: true,
            showAllQuestions: false
        };

        // Biến cho chế độ thi
        let examMode = false;
        let timeLimit = 30; // phút
        let timer = null;
        let timeRemaining = 0;

        // Biến cho chế độ học nhanh
        let flashMode = false;
        let flashTimeLimit = 10; // giây
        let questionTimer = null;
        let questionTimeRemaining = 0;
        
        // Hiển thị modal tùy chọn học tập khi trang được tải
        document.addEventListener('DOMContentLoaded', function() {
            showStudyOptionsModal();
        });

        // Hiển thị/ẩn tùy chọn thời gian khi chọn chế độ thi
        document.getElementById('exam-mode').addEventListener('change', function() {
            document.getElementById('time-limit-container').style.display = 'block';
            document.getElementById('flash-time-container').style.display = 'none';
        });

        document.getElementById('practice-mode').addEventListener('change', function() {
            document.getElementById('time-limit-container').style.display = 'none';
            document.getElementById('flash-time-container').style.display = 'none';
        });

        document.getElementById('flash-mode').addEventListener('change', function() {
            document.getElementById('time-limit-container').style.display = 'none';
            document.getElementById('flash-time-container').style.display = 'block';
        });
        
        // Xử lý nút bắt đầu học
        document.getElementById('start-study-btn').addEventListener('click', function() {
            // Lấy tùy chọn từ form
            studyOptions.questionOrder = document.querySelector('input[name="question-order"]:checked').value;
            studyOptions.showExplanation = document.getElementById('show-explanation').checked;
            studyOptions.showAllQuestions = document.getElementById('show-all-questions').checked;
            
            // Lấy tùy chọn số lượng câu hỏi
            const questionCountOption = document.querySelector('input[name="question-count"]:checked').value;
            let customQuestionCount = parseInt(document.getElementById('custom-question-count').value);
            
            // Lấy tùy chọn chế độ học tập
            examMode = document.querySelector('input[name="study-mode"]:checked').value === 'exam';
            if (examMode) {
                timeLimit = parseInt(document.getElementById('time-limit').value);
                timeRemaining = timeLimit * 60; // Chuyển đổi sang giây
            }

            // Kiểm tra chế độ học nhanh
            flashMode = document.querySelector('input[name="study-mode"]:checked').value === 'flash';
            if (flashMode) {
                flashTimeLimit = parseInt(document.getElementById('flash-time-limit').value);
            }
            
            // Ẩn modal
            document.getElementById('study-options-modal').style.display = 'none';
            
            // Chuẩn bị câu hỏi dựa trên tùy chọn
            prepareQuestions();
            
            // Giới hạn số lượng câu hỏi nếu được chọn
            if (questionCountOption === 'custom') {
                if (customQuestionCount > questions.length) {
                    customQuestionCount = questions.length;
                }
                questions = questions.slice(0, customQuestionCount);
                totalQuestions = questions.length;
                document.getElementById('total-questions').textContent = totalQuestions;
            }
            
            // Hiển thị giao diện học tập
            document.getElementById('study-view').style.display = 'flex';
            
            // Bắt đầu đếm thời gian nếu ở chế độ thi
            if (examMode) {
                startTimer();
                document.getElementById('timer-container').style.display = 'flex';
            }
            
            // Cập nhật trạng thái ban đầu
            updateStats();
            
            // Hiển thị câu hỏi đầu tiên
            if (totalQuestions > 0) {
                showQuestion(currentQuestionIndex);
            } else {
                document.getElementById('study-view').style.display = 'none';
                document.getElementById('complete-view').style.display = 'block';
            }
        });

        // Bắt đầu đếm thời gian
        function startTimer() {
            if (timer) clearInterval(timer);
            
            updateTimerDisplay();
            
            timer = setInterval(function() {
                timeRemaining--;
                updateTimerDisplay();
                
                if (timeRemaining <= 60) {
                    document.getElementById('timer-container').classList.add('timer-warning');
                }
                
                if (timeRemaining <= 0) {
                    clearInterval(timer);
                    finishExam();
                }
            }, 1000);
        }

        // Cập nhật hiển thị thời gian
        function updateTimerDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            document.getElementById('timer-text').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        // Bắt đầu đếm ngược cho mỗi câu hỏi
        function startQuestionTimer() {
            const timerElement = document.querySelector('.question-timer');
            
            questionTimer = setInterval(function() {
                questionTimeRemaining--;
                
                // Cập nhật hiển thị
                timerElement.textContent = questionTimeRemaining;
                
                // Thêm class cảnh báo khi còn ít thời gian
                if (questionTimeRemaining <= 5 && questionTimeRemaining > 2) {
                    timerElement.className = 'question-timer warning';
                } else if (questionTimeRemaining <= 2) {
                    timerElement.className = 'question-timer danger';
                }
                
                // Khi hết thời gian
                if (questionTimeRemaining <= 0) {
                    clearInterval(questionTimer);
                    
                    // Tự động chọn câu trả lời và chuyển sang câu tiếp theo
                    handleTimeUp();
                }
            }, 1000);
        }

        // Xử lý khi hết thời gian cho câu hỏi
        function handleTimeUp() {
            // Hiển thị thông báo hết thời gian
            const feedback = document.getElementById('result-feedback');
            feedback.innerHTML = '<i class="fas fa-clock"></i>';
            feedback.className = 'result-feedback incorrect';
            feedback.classList.add('show');
            
            setTimeout(() => {
                feedback.classList.remove('show');
            }, 1000);
            
            // Nếu chưa chọn đáp án, đánh dấu là trả lời sai
            if (selectedAnswer === null) {
                stats.incorrect++;
                
                // Lưu câu trả lời là null (không trả lời)
                userAnswers[currentQuestionIndex] = null;
                
                // Gửi kết quả lên server (có thể thêm trường hợp đặc biệt cho câu không trả lời)
                const question = questions[currentQuestionIndex];
                updateProgress(question.id, 0); // 0 đại diện cho không trả lời
            }
            
            // Chuyển sang câu tiếp theo
            currentQuestionIndex++;
            
            // Kiểm tra nếu đã hoàn thành tất cả câu hỏi
            if (currentQuestionIndex >= totalQuestions) {
                // Kết thúc bài học/thi
                if (examMode || flashMode) {
                    finishExam();
                } else {
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    
                    // Cập nhật thống kê
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    
                    // Tính độ chính xác
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                }
            } else {
                showQuestion(currentQuestionIndex);
            }
        }

        // Kết thúc bài thi
        function finishExam() {
            // Dừng đồng hồ đếm ngược của chế độ học nhanh
            if (questionTimer) {
                clearInterval(questionTimer);
            }

            // Dừng đếm thời gian
            if (timer) clearInterval(timer);
            
            // Hiển thị kết quả
            document.getElementById('study-view').style.display = 'none';
            document.getElementById('complete-view').style.display = 'block';
            
            // Cập nhật thống kê
            document.getElementById('stat-total').textContent = stats.total;
            document.getElementById('stat-correct').textContent = stats.correct;
            
            // Tính độ chính xác
            const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
            document.getElementById('stat-accuracy').textContent = accuracy + '%';
            
            // Thêm nút xem lại bài làm
            const reviewButton = document.createElement('button');
            reviewButton.className = 'btn btn-primary';
            reviewButton.innerHTML = '<i class="fas fa-search"></i> Xem lại bài làm';
            reviewButton.addEventListener('click', showReviewScreen);
            
            // Thêm nút vào đầu danh sách các nút
            const actionsContainer = document.querySelector('.study-actions-complete');
            actionsContainer.insertBefore(reviewButton, actionsContainer.firstChild);
        }
        
        // Xử lý nút học lại
        document.getElementById('restart-study-btn').addEventListener('click', function() {
            // Dừng đếm thời gian nếu đang chạy
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            
            // Ẩn đồng hồ đếm thời gian
            document.getElementById('timer-container').style.display = 'none';
            document.getElementById('timer-container').classList.remove('timer-warning');
            
            // Ẩn giao diện hoàn thành
            document.getElementById('complete-view').style.display = 'none';
            
            // Hiển thị modal tùy chọn
            showStudyOptionsModal();
        });
        
        // Hiển thị modal tùy chọn học tập
        function showStudyOptionsModal() {
            document.getElementById('study-options-modal').style.display = 'flex';
            
            // Reset form về giá trị mặc định
            document.getElementById('order-default').checked = true;
            document.getElementById('show-explanation').checked = true;
            document.getElementById('show-all-questions').checked = false;
        }
        
        // Chuẩn bị câu hỏi dựa trên tùy chọn
        function prepareQuestions() {
            // Tạo bản sao từ câu hỏi gốc
            questions = [...originalQuestions];
            
            // Xáo trộn câu hỏi nếu được chọn
            if (studyOptions.questionOrder === 'shuffle') {
                shuffleQuestions(questions);
            }
            
            // Cập nhật tổng số câu hỏi
            totalQuestions = questions.length;
            stats.total = totalQuestions;
            document.getElementById('total-questions').textContent = totalQuestions;
            
            // Reset index
            currentQuestionIndex = 0;
            
            // Reset stats
            stats.correct = 0;
            stats.incorrect = 0;
            
            // Reset mảng userAnswers
            userAnswers = Array(questions.length).fill(null);
        }
        
        // Xáo trộn mảng câu hỏi (thuật toán Fisher-Yates)
        function shuffleQuestions(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }
        
        // Xáo trộn mảng đáp án
        function shuffleOptions(options, correctAnswer) {
            // Tạo mảng các đáp án với chỉ số gốc
            const indexedOptions = options.map((option, index) => ({
                ...option,
                originalIndex: index + 1
            }));
            
            // Xáo trộn mảng (Fisher-Yates)
            for (let i = indexedOptions.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [indexedOptions[i], indexedOptions[j]] = [indexedOptions[j], indexedOptions[i]];
            }
            
            // Tìm chỉ số mới của đáp án đúng
            const newCorrectIndex = indexedOptions.findIndex(option => option.originalIndex === correctAnswer);
            
            // Gán lại chỉ số mới cho các đáp án (1, 2, 3, 4)
            indexedOptions.forEach((option, index) => {
                option.index = index + 1;
            });
            
            return {
                shuffledOptions: indexedOptions,
                newCorrectAnswer: newCorrectIndex + 1
            };
        }
        
        // Hiển thị câu hỏi
        function showQuestion(index) {
            if (index >= totalQuestions) {
                if (examMode) {
                    finishExam();
                } else {
                    // Đã học xong tất cả câu hỏi
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    
                    // Cập nhật thống kê
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    
                    // Tính độ chính xác
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                }
                
                return;
            }
            
            const question = questions[index];
            
            // Reset trạng thái
            selectedAnswer = null;
            isAnswerChecked = false;
            
            // Ẩn giải thích
            document.getElementById('explanation-container').classList.remove('active');
            
            // Cập nhật nội dung câu hỏi
            document.getElementById('question-text').innerHTML = question.question_processed;
            
            // Chuẩn bị các phương án
            const options = [
                { index: 1, text: question.option1_processed },
                { index: 2, text: question.option2_processed },
                { index: 3, text: question.option3_processed },
                { index: 4, text: question.option4_processed }
            ];
            
            // Xáo trộn các phương án
            const { shuffledOptions, newCorrectAnswer } = shuffleOptions(options, parseInt(question.correct_answer));
            
            // Cập nhật các phương án
            const optionsContainer = document.getElementById('options-container');
            optionsContainer.innerHTML = '';
            
            shuffledOptions.forEach(option => {
                const optionElement = document.createElement('div');
                optionElement.className = 'quiz-option';
                optionElement.dataset.index = option.index;
                
                if (question.is_multiple_choice) {
                    // Sử dụng checkbox cho câu hỏi nhiều đáp án
                    optionElement.innerHTML = `
                        <label class="checkbox-label">
                            <input type="checkbox" name="answer" value="${option.index}">
                            <div class="quiz-option-marker">${getOptionLetter(option.index)}</div>
                            <div>${option.text}</div>
                        </label>
                    `;
                } else {
                    // Sử dụng radio button cho câu hỏi một đáp án
                    optionElement.innerHTML = `
                        <label class="radio-label">
                            <input type="radio" name="answer" value="${option.index}">
                            <div class="quiz-option-marker">${getOptionLetter(option.index)}</div>
                            <div>${option.text}</div>
                        </label>
                    `;
                }
                
                // Thêm sự kiện click
                optionElement.addEventListener('click', function() {
                    if (examMode || !isAnswerChecked) {
                        if (question.is_multiple_choice) {
                            // Đối với câu hỏi nhiều đáp án, toggle checkbox
                            const checkbox = this.querySelector('input[type="checkbox"]');
                            checkbox.checked = !checkbox.checked;
                            
                            // Kiểm tra xem có ít nhất một đáp án được chọn không
                            const hasSelectedAnswer = Array.from(optionsContainer.querySelectorAll('input[type="checkbox"]'))
                                .some(checkbox => checkbox.checked);
                            
                            if (examMode) {
                                document.getElementById('btn-next').classList.toggle('btn-disabled', !hasSelectedAnswer);
                            } else {
                                document.getElementById('btn-check').classList.toggle('btn-disabled', !hasSelectedAnswer);
                            }
                        } else {
                            // Đối với câu hỏi một đáp án, chọn radio button
                            document.querySelectorAll('.quiz-option').forEach(el => {
                                el.classList.remove('selected');
                            });
                            this.classList.add('selected');
                            
                            if (examMode) {
                                document.getElementById('btn-next').classList.remove('btn-disabled');
                            } else {
                                document.getElementById('btn-check').classList.remove('btn-disabled');
                            }
                        }
                    }
                });
                
                optionsContainer.appendChild(optionElement);
            });
            
            // Cập nhật giải thích
            document.getElementById('explanation-text').innerHTML = question.explanation_processed || 'Không có giải thích cho câu hỏi này.';
            
            // Cập nhật tiến trình
            document.getElementById('current-question').textContent = index + 1;
            document.querySelector('.progress-fill').style.width = `${((index + 1) / totalQuestions) * 100}%`;
            
            // Reset trạng thái các nút
            document.getElementById('btn-check').classList.add('btn-disabled');
            document.getElementById('btn-next').classList.add('btn-disabled');
            
            // Cập nhật đáp án đúng tạm thời cho câu hỏi hiện tại
            question.temp_correct_answer = question.is_multiple_choice ? 
                question.correct_answers : 
                question.correct_answer;
            
            // Cập nhật class cho quiz-card dựa vào chế độ thi
            if (examMode) {
                document.getElementById('quiz-card').classList.add('exam-mode');
            } else {
                document.getElementById('quiz-card').classList.remove('exam-mode');
            }
            
            // Thêm đồng hồ đếm ngược cho chế độ học nhanh
            if (flashMode) {
                // Tạo hoặc cập nhật đồng hồ đếm ngược
                let timerElement = document.querySelector('.question-timer');
                if (!timerElement) {
                    timerElement = document.createElement('div');
                    timerElement.className = 'question-timer';
                    document.getElementById('quiz-card').appendChild(timerElement);
                }
                
                // Reset đồng hồ đếm ngược
                clearInterval(questionTimer);
                questionTimeRemaining = flashTimeLimit;
                timerElement.textContent = questionTimeRemaining;
                timerElement.className = 'question-timer';
                
                // Bắt đầu đếm ngược
                startQuestionTimer();
            }
            
            // Đảm bảo các media được hiển thị đúng
            setTimeout(() => {
                const images = document.querySelectorAll('.quiz-image img');
                images.forEach(img => {
                    img.onload = function() {
                        this.parentNode.classList.add('loaded');
                    };
                    // Nếu ảnh đã được tải trước đó
                    if (img.complete) {
                        img.parentNode.classList.add('loaded');
                    }
                });
            
                const videos = document.querySelectorAll('.quiz-video video');
                videos.forEach(video => {
                    video.oncanplay = function() {
                        this.parentNode.classList.add('loaded');
                    };
                    // Nếu video đã được tải trước đó
                    if (video.readyState >= 3) {
                        video.parentNode.classList.add('loaded');
                    }
                });
            
                const iframes = document.querySelectorAll('.quiz-youtube iframe');
                iframes.forEach(iframe => {
                    iframe.onload = function() {
                        this.parentNode.classList.add('loaded');
                    };
                });
            }, 100);
        }
        
        // Chuyển đổi số thành chữ cái (1 -> A, 2 -> B, ...)
        function getOptionLetter(index) {
            return String.fromCharCode(64 + index);
        }
        
        // Hiển thị phản hồi kết quả
        function showResultFeedback(isCorrect) {
            const feedback = document.getElementById('result-feedback');
            feedback.innerHTML = isCorrect ? 
                '<i class="fas fa-check-circle"></i>' : 
                '<i class="fas fa-times-circle"></i>';
            feedback.className = isCorrect ? 
                'result-feedback correct' : 
                'result-feedback incorrect';
            
            feedback.classList.add('show');
            
            setTimeout(() => {
                feedback.classList.remove('show');
            }, 1500);
        }
        
        // Xử lý nút kiểm tra
        document.getElementById('btn-check').addEventListener('click', function() {
            if (selectedAnswer === null || isAnswerChecked) return;
            
            isAnswerChecked = true;
            const question = questions[currentQuestionIndex];
            const correctAnswer = parseInt(question.temp_correct_answer || question.correct_answer);
            
            // Kiểm tra đáp án và cập nhật thống kê
            const isCorrect = selectedAnswer === correctAnswer;
            
            if (isCorrect) {
                stats.correct++;
                // Hiển thị phản hồi đúng
                if (!examMode) {
                    showResultFeedback(true);
                }
            } else {
                stats.incorrect++;
                // Hiển thị phản hồi sai
                if (!examMode) {
                    showResultFeedback(false);
                }
            }
            
            // Gửi kết quả lên server
            updateProgress(question.id, selectedAnswer);
            
            // Trong chế độ luyện tập, hiển thị đáp án đúng và sai
            if (!examMode) {
                // Hiển thị đáp án đúng và sai
                document.querySelectorAll('.quiz-option').forEach(option => {
                    const optionIndex = parseInt(option.dataset.index);
                    
                    if (optionIndex === correctAnswer) {
                        option.classList.add('correct');
                    } else if (optionIndex === selectedAnswer && optionIndex !== correctAnswer) {
                        option.classList.add('incorrect');
                    }
                });
                
                // Hiển thị giải thích nếu được chọn
                if (studyOptions.showExplanation) {
                    document.getElementById('explanation-container').classList.add('active');
                }
            }
            
            // Kích hoạt nút tiếp theo
            document.getElementById('btn-next').classList.remove('btn-disabled');
            this.classList.add('btn-disabled');
        });
        
        // Xử lý nút tiếp theo
        document.getElementById('btn-next').addEventListener('click', function() {
            // Dừng đồng hồ đếm ngược nếu đang ở chế độ học nhanh
            if (flashMode) {
                clearInterval(questionTimer);
            }

            // Trong chế độ thi, ghi nhận đáp án khi nhấn nút tiếp theo
            if (examMode && selectedAnswer !== null) {
                const question = questions[currentQuestionIndex];
                const correctAnswer = parseInt(question.temp_correct_answer || question.correct_answer);
                
                // Kiểm tra đáp án và cập nhật thống kê
                const isCorrect = selectedAnswer === correctAnswer;
                if (isCorrect) {
                    stats.correct++;
                } else {
                    stats.incorrect++;
                }
                
                // Lưu câu trả lời của người dùng
                userAnswers[currentQuestionIndex] = selectedAnswer;
                
                // Gửi kết quả lên server
                updateProgress(question.id, selectedAnswer);
                
                // Chuyển sang câu tiếp theo
                currentQuestionIndex++;
                
                // Kiểm tra nếu đã hoàn thành tất cả câu hỏi
                if (currentQuestionIndex >= totalQuestions) {
                    finishExam();
                } else {
                    showQuestion(currentQuestionIndex);
                }
            } 
            // Trong chế độ luyện tập, kiểm tra xem đã kiểm tra đáp án chưa
            else if (!examMode) {
                if (!isAnswerChecked) return;
                
                currentQuestionIndex++;
                
                // Kiểm tra nếu đã hoàn thành tất cả câu hỏi
                if (currentQuestionIndex >= totalQuestions) {
                    // Đã học xong tất cả câu hỏi
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    
                    // Cập nhật thống kê
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    
                    // Tính độ chính xác
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                } else {
                    showQuestion(currentQuestionIndex);
                }
            }
        });
        
        // Cập nhật tiến trình lên server
        function updateProgress(questionId, selectedAnswer) {
            fetch('study_quiz.php?quiz_id=<?php echo $quiz_id; ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `update_progress=1&question_id=${questionId}&selected_answer=${selectedAnswer}`
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Lỗi khi cập nhật tiến trình:', data.error);
                }
            })
            .catch(error => {
                console.error('Lỗi:', error);
            });
        }
        
        // Cập nhật thống kê
        function updateStats() {
            stats.total = totalQuestions;
            stats.correct = 0;
            stats.incorrect = 0;
        }
        
        // Thêm hàm hiển thị màn hình xem lại
        function showReviewScreen() {
            // Ẩn màn hình kết quả
            document.getElementById('complete-view').style.display = 'none';
            
            // Tạo và hiển thị màn hình xem lại
            const reviewContainer = document.createElement('div');
            reviewContainer.className = 'study-container';
            reviewContainer.id = 'review-view';
            reviewContainer.innerHTML = `
                <div class="study-header">
                    <h2 class="study-title">
                        <i class="fas fa-search"></i> Xem lại bài làm
                    </h2>
                    <button id="back-to-results" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Quay lại kết quả
                    </button>
                </div>
                <div id="review-questions-container"></div>
            `;
            
            document.querySelector('.container').appendChild(reviewContainer);
            
            // Thêm sự kiện cho nút quay lại
            document.getElementById('back-to-results').addEventListener('click', function() {
                document.getElementById('review-view').remove();
                document.getElementById('complete-view').style.display = 'block';
            });
            
            // Hiển thị các câu hỏi và đáp án
            const reviewQuestionsContainer = document.getElementById('review-questions-container');
            
            questions.forEach((question, index) => {
                const userAnswer = userAnswers[index];
                const correctAnswer = parseInt(question.correct_answer);
                const isCorrect = userAnswer === correctAnswer;
                const isUnanswered = userAnswer === null;

                const questionElement = document.createElement('div');
                questionElement.className = 'quiz-card';
                questionElement.innerHTML = `
                    <div class="quiz-question-header" style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <h3>Câu hỏi ${index + 1}</h3>
                        <span class="quiz-result-badge ${isCorrect ? 'correct' : (isUnanswered ? 'warning' : 'incorrect')}" 
                              style="padding: 0.25rem 0.75rem; border-radius: 1rem; font-weight: 500; 
                                     background-color: ${isCorrect ? 'rgba(34, 197, 94, 0.1)' : (isUnanswered ? 'rgba(245, 158, 11, 0.1)' : 'rgba(239, 68, 68, 0.1)')};
                                     color: ${isCorrect ? 'var(--correct-color)' : (isUnanswered ? 'var(--warning-color)' : 'var(--incorrect-color)')};
                                     border: 1px solid ${isCorrect ? 'var(--correct-color)' : (isUnanswered ? 'var(--warning-color)' : 'var(--incorrect-color)')};">
                            ${isCorrect ? '<i class="fas fa-check"></i> Đúng' : (isUnanswered ? '<i class="fas fa-clock"></i> Hết thời gian' : '<i class="fas fa-times"></i> Sai')}
                        </span>
                    </div>
                    <div class="quiz-question">${question.question_processed}</div>
                    <div class="quiz-options review-options"></div>
                    ${question.explanation ? `
                        <div class="quiz-explanation active">
                            <div class="quiz-explanation-title">
                                <i class="fas fa-info-circle"></i> Giải thích
                            </div>
                            <div>${question.explanation_processed}</div>
                        </div>
                    ` : ''}
                `;
                
                reviewQuestionsContainer.appendChild(questionElement);
                
                // Thêm các phương án
                const optionsContainer = questionElement.querySelector('.review-options');
                const options = [
                    { index: 1, text: question.option1_processed },
                    { index: 2, text: question.option2_processed },
                    { index: 3, text: question.option3_processed },
                    { index: 4, text: question.option4_processed }
                ];
                
                options.forEach(option => {
                    const optionElement = document.createElement('div');
                    optionElement.className = 'quiz-option';
                    
                    // Đánh dấu đáp án đúng và đáp án người dùng đã chọn
                    if (option.index === correctAnswer) {
                        optionElement.classList.add('correct');
                    } else if (option.index === userAnswer && option.index !== correctAnswer) {
                        optionElement.classList.add('incorrect');
                    }
                    
                    optionElement.innerHTML = `
                        <div class="quiz-option-marker">${getOptionLetter(option.index)}</div>
                        <div>${option.text}</div>
                    `;
                    
                    optionsContainer.appendChild(optionElement);
                });
            });
            
            // Thêm CSS cho màn hình xem lại
            const style = document.createElement('style');
            style.textContent = `
                #review-questions-container {
                    width: 100%;
                    max-width: 800px;
                    display: flex;
                    flex-direction: column;
                    gap: 2rem;
                    margin-bottom: 2rem;
                }
                
                .review-options .quiz-option {
                    cursor: default;
                }
                
                .review-options .quiz-option:hover {
                    transform: none;
                    box-shadow: none;
                }
            `;
            document.head.appendChild(style);
            
            // Cuộn lên đầu trang
            window.scrollTo(0, 0);
        }
    </script>
</body>
</html>