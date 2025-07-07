<?php
session_start();
include '../db_config.php';
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
    header("Location: index.php");
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
    $question['question_processed'] = process_quiz_media_content($question['question']);
    $question['option1_processed'] = process_quiz_media_content($question['option1']);
    $question['option2_processed'] = process_quiz_media_content($question['option2']);
    $question['option3_processed'] = process_quiz_media_content($question['option3']);
    $question['option4_processed'] = process_quiz_media_content($question['option4']);
    $question['explanation_processed'] = process_quiz_media_content($question['explanation']);
    
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
    
    $sql_check = "SELECT correct_answer FROM quiz_questions WHERE id = $question_id AND quiz_id = $quiz_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        $question = mysqli_fetch_assoc($result_check);
        $is_correct = ($selected_answer == $question['correct_answer']) ? 1 : 0;
    }
    
    $sql_check_progress = "SELECT * FROM quiz_progress WHERE user_id = $user_id AND question_id = $question_id";
    $result_check_progress = mysqli_query($conn, $sql_check_progress);
    
    if (mysqli_num_rows($result_check_progress) > 0) {
        $sql = "UPDATE quiz_progress 
                SET selected_answer = $selected_answer, is_correct = $is_correct, last_studied = NOW() 
                WHERE user_id = $user_id AND question_id = $question_id";
    } else {
        $sql = "INSERT INTO quiz_progress (user_id, quiz_id, question_id, selected_answer, is_correct, last_studied) 
                VALUES ($user_id, $quiz_id, $question_id, $selected_answer, $is_correct, NOW())";
    }
    
    if (mysqli_query($conn, $sql)) {
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
    <title>QuizMaster - <?php echo htmlspecialchars($quiz['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            gap: 1rem;
        }

        .user-menu a {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
        }

        .user-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-menu .btn {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
        }

        .user-menu .btn:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .study-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 70vh;
        }

        .study-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(30, 30, 60, 0.5);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .study-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .study-title i {
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
            color: var(--foreground-muted);
        }

        .progress-bar {
            width: 200px;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.5s ease;
        }

        .quiz-card {
            width: 100%;
            max-width: 800px;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .quiz-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4);
        }

        .quiz-question {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--foreground);
        }

        .quiz-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .quiz-option {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .quiz-option:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(4px);
            box-shadow: var(--shadow-sm);
        }

        .quiz-option.selected {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            color: white;
            border-color: var(--primary-light);
        }

        .quiz-option.correct {
            background: linear-gradient(to right, #00E0FF, #0052CC);
            border-color: var(--secondary);
            color: white;
        }

        .quiz-option.incorrect {
            background: linear-gradient(to right, #FF3D57, #C70039);
            border-color: #FF3D57;
            color: white;
        }

        .quiz-option-marker {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--foreground-muted);
        }

        .quiz-option.selected .quiz-option-marker {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .quiz-option.correct .quiz-option-marker {
            background: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        .quiz-option.incorrect .quiz-option-marker {
            background: #FF3D57;
            color: white;
            border-color: #FF3D57;
        }

        .quiz-explanation {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
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
            color: var(--secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quiz-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            border: 1px solid var(--border);
        }

        .btn-primary {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-check {
            background: linear-gradient(to right, #4A00E0, #8E2DE2);
            color: white;
            border: none;
        }

        .btn-check:hover {
            background: linear-gradient(to right, #6F00FF, #B24CD8);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 0, 224, 0.5);
        }

        .btn-next {
            background: linear-gradient(to right, #00B8D9, #0052CC);
            color: white;
            border: none;
        }

        .btn-next:hover {
            background: linear-gradient(to right, #00E0FF, #0077CC);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 184, 217, 0.5);
        }

        .btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .study-complete {
            text-align: center;
            padding: 3rem 1rem;
            background: rgba(30, 30, 60, 0.5);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .study-complete i {
            font-size: 2rem;
            color: var(--secondary);
            margin-bottom: 1.5rem;
        }

        .study-complete h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--foreground);
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
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--primary), var(--secondary));
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

        .quiz-media {
            margin: 0.5rem 0;
            border-radius: var(--radius-sm);
            overflow: hidden;
            max-width: 100%;
        }

        .quiz-image {
            text-align: center;
            background-color: rgba(255, 255, 255, 0.05);
            min-height: 100px;
            max-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quiz-image img {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain;
        }

        .quiz-video {
            min-height: 150px;
            max-height: 300px;
            background-color: rgba(255, 255, 255, 0.05);
        }

        .quiz-video video {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
        }

        .quiz-youtube {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            margin: 0.5rem auto;
            background-color: rgba(255, 255, 255, 0.05);
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
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
            font-size: 1.5rem;
        }

        .loaded .media-loading {
            display: none;
        }

        .study-options-modal {
            /*position: fixed;*/
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /*background: rgba(0, 0, 0, 0.5);*/
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .study-options-content {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            animation: fadeIn 0.3s ease, slideUp 0.3s ease;
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
            border-bottom: 1px solid var(--border);
        }

        .study-options-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            background: rgba(255, 255, 255, 0.05);
            padding: 1.25rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
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
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            transition: background-color 0.3s ease;
        }

        .option-item:hover {
            background: rgba(255, 255, 255, 0.1);
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
        }

        .study-options-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .timer-container {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-sm);
            padding: 0.5rem 1rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 100;
            border: 1px solid var(--border);
        }

        .timer-icon {
            color: var(--accent);
        }

        .timer-text {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
        }

        .timer-warning {
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

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
            color: var(--secondary);
        }

        .result-feedback.incorrect {
            color: #FF3D57;
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

        .quiz-card.exam-mode .btn-check {
            display: none;
        }

        .quiz-card.exam-mode .quiz-option:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
        }

        .question-timer {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--foreground);
            box-shadow: var(--shadow-sm);
            border: 2px solid var(--primary);
        }

        .question-timer.warning {
            color: var(--accent);
            border-color: var(--accent);
        }

        .question-timer.danger {
            color: #FF3D57;
            border-color: #FF3D57;
            animation: pulse 0.5s infinite;
        }

        .checkbox-label, .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            width: 100%;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            transition: background-color 0.3s ease;
        }

        .checkbox-label:hover, .radio-label:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .checkbox-label input[type="checkbox"],
        .radio-label input[type="radio"] {
            margin: 0;
            width: 1.25rem;
            height: 1.25rem;
            accent-color: var(--primary);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .user-menu {
                flex-wrap: wrap;
                justify-content: center;
            }

            .study-header {
                flex-direction: column;
                gap: 1rem;
            }

            .quiz-card {
                padding: 1rem;
            }

            .quiz-actions {
                flex-direction: column;
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
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-question-circle"></i></div>
                <div class="logo-text">QuizMaster</div>
            </div>
            <div class="user-menu">
                <a href="../dashboard"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="/quiz"><i class="fas fa-question-circle"></i> Quizlet</a>
                <a href="../logout" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </header>

        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

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
                            <input type="number" id="custom-question-count" min="1" max="<?php echo $question_count; ?>" value="10" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid var(--border); background: rgba(255, 255, 255, 0.05); color: var(--foreground);">
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-clock"></i> Chế độ học tập
                        </h3>
                        <div class="option-item">
                            <input type="radio" id="practice-mode" name="study-mode" value="practice" checked>
                            <label for="practice-mode">Chế độ luyện tập</label>
                        </div>
                        <div class="option-item">
                            <input type="radio" id="exam-mode" name="study-mode" value="exam">
                            <label for="exam-mode">Chế độ thi</label>
                        </div>
                        <div class="option-item" id="time-limit-container" style="display: none; margin-top: 10px; padding-left: 25px;">
                            <label for="time-limit">Thời gian làm bài (phút):</label>
                            <input type="number" id="time-limit" min="1" max="180" value="30" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid var(--border); background: rgba(255, 255, 255, 0.05); color: var(--foreground);">
                        </div>
                        <div class="option-item">
                            <input type="radio" id="flash-mode" name="study-mode" value="flash">
                            <label for="flash-mode">Chế độ học nhanh</label>
                        </div>
                        <div class="option-item" id="flash-time-container" style="display: none; margin-top: 10px; padding-left: 25px;">
                            <label for="flash-time-limit">Thời gian mỗi câu (giây):</label>
                            <input type="number" id="flash-time-limit" min="3" max="60" value="10" style="width: 60px; margin-left: 10px; padding: 3px 5px; border-radius: 4px; border: 1px solid var(--border); background: rgba(255, 255, 255, 0.05); color: var(--foreground);">
                        </div>
                    </div>

                    <div class="option-group">
                        <h3 class="option-group-title">
                            <i class="fas fa-sliders-h"></i> Tùy chọn khác
                        </h3>
                        <div class="option-item">
                            <input type="checkbox" id="show-explanation" name="show-explanation" value="1" checked>
                            <label for="show-explanation">Hiển thị giải thích</label>
                        </div>
                        <div class="option-item">
                            <input type="checkbox" id="show-all-questions" name="show-all-questions" value="1">
                            <label for="show-all-questions">Hiển thị tất cả câu hỏi</label>
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
                    <button id="btn-check" class="btn btn-check btn-disabled">
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
                <a href="edit_quiz?id=<?php echo $quiz_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-edit"></i> Chỉnh sửa bộ câu hỏi
                </a>
                <a href="" class="btn btn-secondary">
                    <i class="fas fa-question-circle"></i> Quay lại danh sách
                </a>
            </div>
        </div>
    </div>

    <script>
        const originalQuestions = <?php echo json_encode($questions); ?>;
        let questions = [...originalQuestions];
        let currentQuestionIndex = 0;
        let totalQuestions = questions.length;
        let selectedAnswer = null;
        let isAnswerChecked = false;
        let stats = {
            total: totalQuestions,
            correct: 0,
            incorrect: 0
        };
        let userAnswers = [];
        let studyOptions = {
            questionOrder: 'default',
            showExplanation: true,
            showAllQuestions: false
        };
        let examMode = false;
        let timeLimit = 30;
        let timer = null;
        let timeRemaining = 0;
        let flashMode = false;
        let flashTimeLimit = 10;
        let questionTimer = null;
        let questionTimeRemaining = 0;

        document.addEventListener('DOMContentLoaded', function() {
            showStudyOptionsModal();
        });

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

        document.getElementById('start-study-btn').addEventListener('click', function() {
            studyOptions.questionOrder = document.querySelector('input[name="question-order"]:checked').value;
            studyOptions.showExplanation = document.getElementById('show-explanation').checked;
            studyOptions.showAllQuestions = document.getElementById('show-all-questions').checked;
            
            const questionCountOption = document.querySelector('input[name="question-count"]:checked').value;
            let customQuestionCount = parseInt(document.getElementById('custom-question-count').value);
            
            examMode = document.querySelector('input[name="study-mode"]:checked').value === 'exam';
            if (examMode) {
                timeLimit = parseInt(document.getElementById('time-limit').value);
                timeRemaining = timeLimit * 60;
            }

            flashMode = document.querySelector('input[name="study-mode"]:checked').value === 'flash';
            if (flashMode) {
                flashTimeLimit = parseInt(document.getElementById('flash-time-limit').value);
            }
            
            document.getElementById('study-options-modal').style.display = 'none';
            
            prepareQuestions();
            
            if (questionCountOption === 'custom') {
                if (customQuestionCount > questions.length) {
                    customQuestionCount = questions.length;
                }
                questions = questions.slice(0, customQuestionCount);
                totalQuestions = questions.length;
                document.getElementById('total-questions').textContent = totalQuestions;
            }
            
            document.getElementById('study-view').style.display = 'flex';
            
            if (examMode) {
                startTimer();
                document.getElementById('timer-container').style.display = 'flex';
            }
            
            updateStats();
            
            if (totalQuestions > 0) {
                showQuestion(currentQuestionIndex);
            } else {
                document.getElementById('study-view').style.display = 'none';
                document.getElementById('complete-view').style.display = 'block';
            }
        });

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

        function updateTimerDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            document.getElementById('timer-text').textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        function startQuestionTimer() {
            const timerElement = document.querySelector('.question-timer');
            questionTimer = setInterval(function() {
                questionTimeRemaining--;
                timerElement.textContent = questionTimeRemaining;
                if (questionTimeRemaining <= 5 && questionTimeRemaining > 2) {
                    timerElement.className = 'question-timer warning';
                } else if (questionTimeRemaining <= 2) {
                    timerElement.className = 'question-timer danger';
                }
                if (questionTimeRemaining <= 0) {
                    clearInterval(questionTimer);
                    handleTimeUp();
                }
            }, 1000);
        }

        function handleTimeUp() {
            const feedback = document.getElementById('result-feedback');
            feedback.innerHTML = '<i class="fas fa-clock"></i>';
            feedback.className = 'result-feedback incorrect';
            feedback.classList.add('show');
            
            setTimeout(() => {
                feedback.classList.remove('show');
            }, 1000);
            
            if (selectedAnswer === null) {
                stats.incorrect++;
                userAnswers[currentQuestionIndex] = null;
                const question = questions[currentQuestionIndex];
                updateProgress(question.id, 0);
            }
            
            currentQuestionIndex++;
            
            if (currentQuestionIndex >= totalQuestions) {
                if (examMode || flashMode) {
                    finishExam();
                } else {
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                }
            } else {
                showQuestion(currentQuestionIndex);
            }
        }

        function finishExam() {
            if (questionTimer) {
                clearInterval(questionTimer);
            }
            if (timer) clearInterval(timer);
            document.getElementById('study-view').style.display = 'none';
            document.getElementById('complete-view').style.display = 'block';
            document.getElementById('stat-total').textContent = stats.total;
            document.getElementById('stat-correct').textContent = stats.correct;
            const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
            document.getElementById('stat-accuracy').textContent = accuracy + '%';
            const reviewButton = document.createElement('button');
            reviewButton.className = 'btn btn-primary';
            reviewButton.innerHTML = '<i class="fas fa-search"></i> Xem lại bài làm';
            reviewButton.addEventListener('click', showReviewScreen);
            document.querySelector('.study-actions-complete').insertBefore(reviewButton, document.querySelector('.study-actions-complete').firstChild);
        }

        document.getElementById('restart-study-btn').addEventListener('click', function() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            document.getElementById('timer-container').style.display = 'none';
            document.getElementById('timer-container').classList.remove('timer-warning');
            document.getElementById('complete-view').style.display = 'none';
            showStudyOptionsModal();
        });

        function showStudyOptionsModal() {
            document.getElementById('study-options-modal').style.display = 'flex';
            document.getElementById('order-default').checked = true;
            document.getElementById('show-explanation').checked = true;
            document.getElementById('show-all-questions').checked = false;
        }

        function prepareQuestions() {
            questions = [...originalQuestions];
            if (studyOptions.questionOrder === 'shuffle') {
                shuffleQuestions(questions);
            }
            totalQuestions = questions.length;
            stats.total = totalQuestions;
            document.getElementById('total-questions').textContent = totalQuestions;
            currentQuestionIndex = 0;
            stats.correct = 0;
            stats.incorrect = 0;
            userAnswers = Array(questions.length).fill(null);
        }

        function shuffleQuestions(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array;
        }

        function shuffleOptions(options, correctAnswer) {
            const indexedOptions = options.map((option, index) => ({
                ...option,
                originalIndex: index + 1
            }));
            for (let i = indexedOptions.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [indexedOptions[i], indexedOptions[j]] = [indexedOptions[j], indexedOptions[i]];
            }
            const newCorrectIndex = indexedOptions.findIndex(option => option.originalIndex === correctAnswer);
            indexedOptions.forEach((option, index) => {
                option.index = index + 1;
            });
            return {
                shuffledOptions: indexedOptions,
                newCorrectAnswer: newCorrectIndex + 1
            };
        }

        function showQuestion(index) {
            if (index >= totalQuestions) {
                if (examMode) {
                    finishExam();
                } else {
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                }
                return;
            }
            
            const question = questions[index];
            selectedAnswer = null;
            isAnswerChecked = false;
            document.getElementById('explanation-container').classList.remove('active');
            document.getElementById('question-text').innerHTML = question.question_processed;
            
            const options = [
                { index: 1, text: question.option1_processed },
                { index: 2, text: question.option2_processed },
                { index: 3, text: question.option3_processed },
                { index: 4, text: question.option4_processed }
            ];
            
            const { shuffledOptions, newCorrectAnswer } = shuffleOptions(options, parseInt(question.correct_answer));
            const optionsContainer = document.getElementById('options-container');
            optionsContainer.innerHTML = '';
            
            shuffledOptions.forEach(option => {
                const optionElement = document.createElement('div');
                optionElement.className = 'quiz-option';
                optionElement.dataset.index = option.index;
                
                if (question.is_multiple_choice) {
                    optionElement.innerHTML = `
                        <label class="checkbox-label">
                            <input type="checkbox" name="answer" value="${option.index}">
                            <div class="quiz-option-marker">${getOptionLetter(option.index)}</div>
                            <div>${option.text}</div>
                        </label>
                    `;
                } else {
                    optionElement.innerHTML = `
                        <label class="radio-label">
                            <input type="radio" name="answer" value="${option.index}">
                            <div class="quiz-option-marker">${getOptionLetter(option.index)}</div>
                            <div>${option.text}</div>
                        </label>
                    `;
                }
                
                const input = optionElement.querySelector('input');
                input.addEventListener('change', function() {
                    if (examMode || !isAnswerChecked) {
                        if (question.is_multiple_choice) {
                            const hasSelectedAnswer = Array.from(optionsContainer.querySelectorAll('input[type="checkbox"]:checked')).length > 0;
                            if (examMode) {
                                document.getElementById('btn-next').classList.toggle('btn-disabled', !hasSelectedAnswer);
                            } else {
                                document.getElementById('btn-check').classList.toggle('btn-disabled', !hasSelectedAnswer);
                            }
                        } else {
                            document.querySelectorAll('.quiz-option').forEach(el => el.classList.remove('selected'));
                            optionElement.classList.add('selected');
                            selectedAnswer = parseInt(this.value);
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
            
            document.getElementById('explanation-text').innerHTML = question.explanation_processed || 'Không có giải thích cho câu hỏi này.';
            document.getElementById('current-question').textContent = index + 1;
            document.querySelector('.progress-fill').style.width = `${((index + 1) / totalQuestions) * 100}%`;
            document.getElementById('btn-check').classList.add('btn-disabled');
            document.getElementById('btn-next').classList.add('btn-disabled');
            
            // Cập nhật temp_correct_answer với giá trị đã được điều chỉnh sau khi xáo trộn
            question.temp_correct_answer = newCorrectAnswer;
            
            if (examMode) {
                document.getElementById('quiz-card').classList.add('exam-mode');
            } else {
                document.getElementById('quiz-card').classList.remove('exam-mode');
            }
            
            if (flashMode) {
                let timerElement = document.querySelector('.question-timer');
                if (!timerElement) {
                    timerElement = document.createElement('div');
                    timerElement.className = 'question-timer';
                    document.getElementById('quiz-card').appendChild(timerElement);
                }
                clearInterval(questionTimer);
                questionTimeRemaining = flashTimeLimit;
                timerElement.textContent = questionTimeRemaining;
                timerElement.className = 'question-timer';
                startQuestionTimer();
            }
            
            setTimeout(() => {
                const images = document.querySelectorAll('.quiz-image img');
                images.forEach(img => {
                    img.onload = function() {
                        this.parentNode.classList.add('loaded');
                    };
                    if (img.complete) {
                        img.parentNode.classList.add('loaded');
                    }
                });
                const videos = document.querySelectorAll('.quiz-video video');
                videos.forEach(video => {
                    video.oncanplay = function() {
                        this.parentNode.classList.add('loaded');
                    };
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

        function getOptionLetter(index) {
            return String.fromCharCode(64 + index);
        }

        function showResultFeedback(isCorrect) {
            const feedback = document.getElementById('result-feedback');
            feedback.innerHTML = isCorrect ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
            feedback.className = isCorrect ? 'result-feedback correct' : 'result-feedback incorrect';
            feedback.classList.add('show');
            setTimeout(() => {
                feedback.classList.remove('show');
            }, 1500);
        }

        document.getElementById('btn-check').addEventListener('click', function() {
            if (isAnswerChecked) return;
            
            isAnswerChecked = true;
            const question = questions[currentQuestionIndex];
            const correctAnswer = parseInt(question.temp_correct_answer); // Sử dụng temp_correct_answer đã được điều chỉnh
            
            const isCorrect = selectedAnswer === correctAnswer;
            if (isCorrect) {
                stats.correct++;
                if (!examMode) {
                    showResultFeedback(true);
                }
            } else {
                stats.incorrect++;
                if (!examMode) {
                    showResultFeedback(false);
                }
            }
            
            updateProgress(question.id, selectedAnswer);
            
            if (!examMode) {
                document.querySelectorAll('.quiz-option').forEach(option => {
                    const optionIndex = parseInt(option.dataset.index);
                    if (optionIndex === correctAnswer) {
                        option.classList.add('correct');
                    } else if (optionIndex === selectedAnswer && optionIndex !== correctAnswer) {
                        option.classList.add('incorrect');
                    }
                });
                if (studyOptions.showExplanation) {
                    document.getElementById('explanation-container').classList.add('active');
                }
            }
            
            document.getElementById('btn-next').classList.remove('btn-disabled');
            this.classList.add('btn-disabled');
        });

        document.getElementById('btn-next').addEventListener('click', function() {
            if (flashMode) {
                clearInterval(questionTimer);
            }
            if (examMode && selectedAnswer !== null) {
                const question = questions[currentQuestionIndex];
                const correctAnswer = parseInt(question.temp_correct_answer);
                const isCorrect = selectedAnswer === correctAnswer;
                if (isCorrect) {
                    stats.correct++;
                } else {
                    stats.incorrect++;
                }
                userAnswers[currentQuestionIndex] = selectedAnswer;
                updateProgress(question.id, selectedAnswer);
                currentQuestionIndex++;
                if (currentQuestionIndex >= totalQuestions) {
                    finishExam();
                } else {
                    showQuestion(currentQuestionIndex);
                }
            } else if (!examMode) {
                if (!isAnswerChecked) return;
                currentQuestionIndex++;
                if (currentQuestionIndex >= totalQuestions) {
                    document.getElementById('study-view').style.display = 'none';
                    document.getElementById('complete-view').style.display = 'block';
                    document.getElementById('stat-total').textContent = stats.total;
                    document.getElementById('stat-correct').textContent = stats.correct;
                    const accuracy = stats.total > 0 ? Math.round((stats.correct / stats.total) * 100) : 0;
                    document.getElementById('stat-accuracy').textContent = accuracy + '%';
                } else {
                    showQuestion(currentQuestionIndex);
                }
            }
        });

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

        function updateStats() {
            stats.total = totalQuestions;
            stats.correct = 0;
            stats.incorrect = 0;
        }

        function showReviewScreen() {
            document.getElementById('complete-view').style.display = 'none';
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
            document.getElementById('back-to-results').addEventListener('click', function() {
                document.getElementById('review-view').remove();
                document.getElementById('complete-view').style.display = 'block';
            });
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
                                     background-color: ${isCorrect ? 'rgba(0, 224, 255, 0.1)' : (isUnanswered ? 'rgba(245, 158, 11, 0.1)' : 'rgba(255, 61, 87, 0.1)')};
                                     color: ${isCorrect ? 'var(--secondary)' : (isUnanswered ? 'var(--accent)' : '#FF3D57')};
                                     border: 1px solid ${isCorrect ? 'var(--secondary)' : (isUnanswered ? 'var(--accent)' : '#FF3D57')};">
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
            window.scrollTo(0, 0);
        }
    </script>
</body>
</html>