<?php
session_start();
include '../db_config.php';
include 'quiz_functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
   header("Location: ../index.php");
   exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Lấy ID bộ câu hỏi nếu được chỉ định
$quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;

// Lấy danh sách bộ câu hỏi của người dùng
$sql_quizzes = "SELECT id, name FROM quiz_sets WHERE user_id = $user_id ORDER BY name ASC";
$result_quizzes = mysqli_query($conn, $sql_quizzes);

// Xử lý lọc và tìm kiếm
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$search_condition = '';
if (!empty($search)) {
    $search_condition = " AND (q.question LIKE '%$search%' OR q.option1 LIKE '%$search%' OR q.option2 LIKE '%$search%' OR q.option3 LIKE '%$search%' OR q.option4 LIKE '%$search%')";
}

// Xử lý xóa lịch sử học tập
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clear_history'])) {
    $clear_quiz_id = (int)$_POST['quiz_id'];
    
    // Xóa lịch sử học tập
    $sql_clear = "DELETE FROM quiz_progress WHERE user_id = $user_id";
    if ($clear_quiz_id > 0) {
        $sql_clear .= " AND quiz_id = $clear_quiz_id";
    }
    
    if (mysqli_query($conn, $sql_clear)) {
        $success_message = "Lịch sử học tập đã được xóa thành công!";
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}

// Lấy dữ liệu kết quả học tập
$questions = [];
$total_correct = 0;
$total_incorrect = 0;

if ($quiz_id > 0) {
    // Kiểm tra quyền sở hữu bộ câu hỏi
    $sql_check = "SELECT id FROM quiz_sets WHERE id = $quiz_id AND user_id = $user_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) == 0) {
        $error_message = "Bạn không có quyền truy cập bộ câu hỏi này!";
        $quiz_id = 0;
    }
}

if ($quiz_id > 0) {
    // Lấy tên bộ câu hỏi
    $sql_quiz_name = "SELECT name FROM quiz_sets WHERE id = $quiz_id";
    $result_quiz_name = mysqli_query($conn, $sql_quiz_name);
    $quiz_name = mysqli_fetch_assoc($result_quiz_name)['name'];
    
    // Lấy danh sách câu hỏi và kết quả
    $sql = "SELECT q.*, qp.selected_answer, qp.is_correct, qp.last_studied 
            FROM quiz_questions q 
            JOIN quiz_progress qp ON q.id = qp.question_id 
            WHERE qp.user_id = $user_id AND qp.quiz_id = $quiz_id";
    
    // Áp dụng bộ lọc
    if ($filter == 'correct') {
        $sql .= " AND qp.is_correct = 1";
    } elseif ($filter == 'incorrect') {
        $sql .= " AND qp.is_correct = 0";
    }
    
    // Áp dụng tìm kiếm
    $sql .= $search_condition;
    
    // Sắp xếp theo thời gian học gần nhất
    $sql .= " ORDER BY qp.last_studied DESC";
    
    $result = mysqli_query($conn, $sql);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $questions[] = $row;
        if ($row['is_correct']) {
            $total_correct++;
        } else {
            $total_incorrect++;
        }
    }
} else {
    // Lấy thống kê tổng hợp từ tất cả các bộ câu hỏi
    $sql_stats = "SELECT 
                    SUM(CASE WHEN qp.is_correct = 1 THEN 1 ELSE 0 END) as total_correct,
                    SUM(CASE WHEN qp.is_correct = 0 THEN 1 ELSE 0 END) as total_incorrect
                FROM quiz_progress qp
                WHERE qp.user_id = $user_id";
    $result_stats = mysqli_query($conn, $sql_stats);
    $stats = mysqli_fetch_assoc($result_stats);
    
    $total_correct = $stats['total_correct'] ?? 0;
    $total_incorrect = $stats['total_incorrect'] ?? 0;
    
    // Lấy danh sách câu hỏi và kết quả từ tất cả các bộ câu hỏi
    $sql = "SELECT q.*, qp.selected_answer, qp.is_correct, qp.last_studied, qs.name as quiz_name
            FROM quiz_questions q 
            JOIN quiz_progress qp ON q.id = qp.question_id 
            JOIN quiz_sets qs ON qp.quiz_id = qs.id
            WHERE qp.user_id = $user_id";
    
    // Áp dụng bộ lọc
    if ($filter == 'correct') {
        $sql .= " AND qp.is_correct = 1";
    } elseif ($filter == 'incorrect') {
        $sql .= " AND qp.is_correct = 0";
    }
    
    // Áp dụng tìm kiếm
    $sql .= $search_condition;
    
    // Sắp xếp theo thời gian học gần nhất
    $sql .= " ORDER BY qp.last_studied DESC LIMIT 100"; // Giới hạn 100 kết quả gần nhất
    
    $result = mysqli_query($conn, $sql);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $questions[] = $row;
    }
}

// Tính tỷ lệ chính xác
$total_questions = $total_correct + $total_incorrect;
$accuracy_rate = $total_questions > 0 ? round(($total_correct / $total_questions) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết quả học tập - Quizlet</title>
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--primary-gradient-start);
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: var(--padding);
            margin-bottom: 2rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            text-align: center;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .stat-correct .stat-value {
            color: var(--correct-color);
        }

        .stat-incorrect .stat-value {
            color: var(--incorrect-color);
        }

        .stat-total .stat-value {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .filter-select {
            padding: 0.5rem 1rem;
            border-radius: var(--small-radius);
            border: 1px solid rgba(0, 0, 0, 0.1);
            background-color: var(--form-bg);
            color: var(--text-color);
            font-size: 0.875rem;
            cursor: pointer;
        }

        .search-form {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .search-input {
            padding: 0.5rem 1rem;
            border-radius: var(--small-radius);
            border: 1px solid rgba(0, 0, 0, 0.1);
            background-color: var(--form-bg);
            color: var(--text-color);
            font-size: 0.875rem;
            min-width: 200px;
        }

        .search-btn {
            padding: 0.5rem 1rem;
            border-radius: var(--small-radius);
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab.active {
            color: var(--text-color);
            border-bottom-color: var(--primary-gradient-start);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .question-list {
            margin-top: 1.5rem;
        }

        .question-item {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            position: relative;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }

        .question-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .question-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .question-status.correct {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--correct-color);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .question-status.incorrect {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--incorrect-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .question-text {
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 1rem;
            padding-right: 6rem; /* Space for status badge */
        }

        .question-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .question-meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .question-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .question-option {
            background: white;
            border-radius: var(--small-radius);
            padding: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            position: relative;
            padding-left: 2rem;
        }

        .question-option.correct {
            border-color: var(--correct-color);
            background-color: rgba(34, 197, 94, 0.05);
        }

        .question-option.selected {
            border-color: var(--info-color);
            background-color: rgba(59, 130, 246, 0.05);
        }

        .question-option.selected.incorrect {
            border-color: var(--incorrect-color);
            background-color: rgba(239, 68, 68, 0.05);
        }

        .question-option-marker {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--form-bg);
            border: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .question-option.correct .question-option-marker {
            background: var(--correct-color);
            color: white;
            border-color: var(--correct-color);
        }

        .question-option.selected .question-option-marker {
            background: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }

        .question-option.selected.incorrect .question-option-marker {
            background: var(--incorrect-color);
            color: white;
            border-color: var(--incorrect-color);
        }

        .question-explanation {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--small-radius);
            padding: 0.75rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 1rem;
        }

        .question-explanation-title {
            font-weight: 600;
            color: var(--info-color);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: var(--small-radius);
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

        .btn-danger {
            background: var(--error-color);
            color: white;
            border: none;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination-item {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--small-radius);
            background: var(--form-bg);
            color: var(--text-color);
            text-decoration: none;
            transition: all var(--transition-speed) ease;
        }

        .pagination-item:hover {
            background: var(--hover-bg);
            transform: translateY(-2px);
        }

        .pagination-item.active {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
        }

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
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 500px;
            padding: 2rem;
            position: relative;
            transform: translateY(20px);
            transition: transform 0.3s ease;
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
            font-weight: 600;
            color: var(--text-color);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color var(--transition-speed) ease;
        }

        .modal-close:hover {
            color: var(--error-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(116, 235, 213, 0.3);
            border-radius: var(--small-radius);
            font-size: 1rem;
            color: var(--text-color);
            transition: all var(--transition-speed) ease;
            background-color: var(--form-bg);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.25);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
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

            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .search-form {
                width: 100%;
                margin-left: 0;
            }

            .question-options {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .tabs {
                flex-direction: column;
                border-bottom: none;
            }

            .tab {
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: var(--small-radius);
                margin-bottom: 0.5rem;
                text-align: center;
            }

            .tab.active {
                border-color: var(--primary-gradient-start);
                border-width: 1px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-question-circle"></i>
                <span>QuizMaster</span>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="flashcards.php"><i class="fas fa-layer-group"></i> Flashcards</a>
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

        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-chart-bar"></i> Kết quả học tập</h1>
            <div>
                <button class="btn btn-secondary btn-sm" onclick="openModal('clear-history-modal')">
                    <i class="fas fa-trash"></i> Xóa lịch sử
                </button>
                <a href="quizlet.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-question-circle"></i> Quay lại Quizlet
                </a>
            </div>
        </div>

        <div class="card">
            <div class="filters">
                <div class="filter-group">
                    <label class="filter-label">Bộ câu hỏi:</label>
                    <select class="filter-select" id="quiz-select" onchange="changeQuiz(this.value)">
                        <option value="0">Tất cả bộ câu hỏi</option>
                        <?php while ($quiz_row = mysqli_fetch_assoc($result_quizzes)): ?>
                            <option value="<?php echo $quiz_row['id']; ?>" <?php echo ($quiz_id == $quiz_row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($quiz_row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Hiển thị:</label>
                    <select class="filter-select" id="filter-select" onchange="changeFilter(this.value)">
                        <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>Tất cả câu hỏi</option>
                        <option value="correct" <?php echo ($filter == 'correct') ? 'selected' : ''; ?>>Câu làm đúng</option>
                        <option value="incorrect" <?php echo ($filter == 'incorrect') ? 'selected' : ''; ?>>Câu làm sai</option>
                    </select>
                </div>
                <form class="search-form" action="" method="GET">
                    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                    <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                    <input type="text" name="search" class="search-input" placeholder="Tìm kiếm câu hỏi..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                </form>
            </div>

            <div class="stats-container">
                <div class="stat-card stat-correct">
                    <div class="stat-value"><?php echo $total_correct; ?></div>
                    <div class="stat-label">Câu làm đúng</div>
                </div>
                <div class="stat-card stat-incorrect">
                    <div class="stat-value"><?php echo $total_incorrect; ?></div>
                    <div class="stat-label">Câu làm sai</div>
                </div>
                <div class="stat-card stat-total">
                    <div class="stat-value"><?php echo $total_questions; ?></div>
                    <div class="stat-label">Tổng số câu đã làm</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $accuracy_rate; ?>%</div>
                    <div class="stat-label">Tỷ lệ chính xác</div>
                </div>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="switchTab('all-questions')">
                    <i class="fas fa-list"></i> Tất cả câu hỏi
                </div>
                <div class="tab" onclick="switchTab('correct-questions')">
                    <i class="fas fa-check-circle"></i> Câu làm đúng
                </div>
                <div class="tab" onclick="switchTab('incorrect-questions')">
                    <i class="fas fa-times-circle"></i> Câu làm sai
                </div>
            </div>

            <div id="all-questions" class="tab-content active">
                <?php if (count($questions) > 0): ?>
                    <div class="question-list">
                        <?php foreach ($questions as $question): ?>
                            <div class="question-item">
                                <div class="question-status <?php echo $question['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                    <?php if ($question['is_correct']): ?>
                                        <i class="fas fa-check-circle"></i> Đúng
                                    <?php else: ?>
                                        <i class="fas fa-times-circle"></i> Sai
                                    <?php endif; ?>
                                </div>
                                <div class="question-text">
                                    <?php echo process_quiz_media_content($question['question']); ?>
                                </div>
                                <div class="question-meta">
                                    <?php if (isset($question['quiz_name'])): ?>
                                        <div class="question-meta-item">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($question['quiz_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="question-meta-item">
                                        <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($question['last_studied'])); ?>
                                    </div>
                                </div>
                                <div class="question-options">
                                    <div class="question-option <?php echo ($question['correct_answer'] == 1) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 1) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">A</div>
                                        <?php echo process_quiz_media_content($question['option1']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 2) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 2) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">B</div>
                                        <?php echo process_quiz_media_content($question['option2']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 3) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 3) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">C</div>
                                        <?php echo process_quiz_media_content($question['option3']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 4) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 4) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">D</div>
                                        <?php echo process_quiz_media_content($question['option4']); ?>
                                    </div>
                                </div>
                                <?php if (!empty($question['explanation'])): ?>
                                    <div class="question-explanation">
                                        <div class="question-explanation-title">
                                            <i class="fas fa-info-circle"></i> Giải thích
                                        </div>
                                        <?php echo process_quiz_media_content($question['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <p>Không tìm thấy kết quả học tập nào.</p>
                        <a href="quizlet.php" class="btn btn-primary">
                            <i class="fas fa-play"></i> Bắt đầu học ngay
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div id="correct-questions" class="tab-content">
                <?php 
                $correct_questions = array_filter($questions, function($q) {
                    return $q['is_correct'] == 1;
                });
                
                if (count($correct_questions) > 0): 
                ?>
                    <div class="question-list">
                        <?php foreach ($correct_questions as $question): ?>
                            <div class="question-item">
                                <div class="question-status correct">
                                    <i class="fas fa-check-circle"></i> Đúng
                                </div>
                                <div class="question-text">
                                    <?php echo process_quiz_media_content($question['question']); ?>
                                </div>
                                <div class="question-meta">
                                    <?php if (isset($question['quiz_name'])): ?>
                                        <div class="question-meta-item">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($question['quiz_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="question-meta-item">
                                        <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($question['last_studied'])); ?>
                                    </div>
                                </div>
                                <div class="question-options">
                                    <div class="question-option <?php echo ($question['correct_answer'] == 1) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 1) ? 'selected' : ''; ?>">
                                        <div class="question-option-marker">A</div>
                                        <?php echo process_quiz_media_content($question['option1']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 2) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 2) ? 'selected' : ''; ?>">
                                        <div class="question-option-marker">B</div>
                                        <?php echo process_quiz_media_content($question['option2']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 3) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 3) ? 'selected' : ''; ?>">
                                        <div class="question-option-marker">C</div>
                                        <?php echo process_quiz_media_content($question['option3']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 4) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 4) ? 'selected' : ''; ?>">
                                        <div class="question-option-marker">D</div>
                                        <?php echo process_quiz_media_content($question['option4']); ?>
                                    </div>
                                </div>
                                <?php if (!empty($question['explanation'])): ?>
                                    <div class="question-explanation">
                                        <div class="question-explanation-title">
                                            <i class="fas fa-info-circle"></i> Giải thích
                                        </div>
                                        <?php echo process_quiz_media_content($question['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>Không tìm thấy câu hỏi làm đúng nào.</p>
                        <a href="quizlet.php" class="btn btn-primary">
                            <i class="fas fa-play"></i> Bắt đầu học ngay
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div id="incorrect-questions" class="tab-content">
                <?php 
                $incorrect_questions = array_filter($questions, function($q) {
                    return $q['is_correct'] == 0;
                });
                
                if (count($incorrect_questions) > 0): 
                ?>
                    <div class="question-list">
                        <?php foreach ($incorrect_questions as $question): ?>
                            <div class="question-item">
                                <div class="question-status incorrect">
                                    <i class="fas fa-times-circle"></i> Sai
                                </div>
                                <div class="question-text">
                                    <?php echo process_quiz_media_content($question['question']); ?>
                                </div>
                                <div class="question-meta">
                                    <?php if (isset($question['quiz_name'])): ?>
                                        <div class="question-meta-item">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($question['quiz_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="question-meta-item">
                                        <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($question['last_studied'])); ?>
                                    </div>
                                </div>
                                <div class="question-options">
                                    <div class="question-option <?php echo ($question['correct_answer'] == 1) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 1) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">A</div>
                                        <?php echo process_quiz_media_content($question['option1']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 2) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 2) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">B</div>
                                        <?php echo process_quiz_media_content($question['option2']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 3) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 3) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">C</div>
                                        <?php echo process_quiz_media_content($question['option3']); ?>
                                    </div>
                                    <div class="question-option <?php echo ($question['correct_answer'] == 4) ? 'correct' : ''; ?> <?php echo ($question['selected_answer'] == 4) ? 'selected' . (($question['selected_answer'] != $question['correct_answer']) ? ' incorrect' : '') : ''; ?>">
                                        <div class="question-option-marker">D</div>
                                        <?php echo process_quiz_media_content($question['option4']); ?>
                                    </div>
                                </div>
                                <?php if (!empty($question['explanation'])): ?>
                                    <div class="question-explanation">
                                        <div class="question-explanation-title">
                                            <i class="fas fa-info-circle"></i> Giải thích
                                        </div>
                                        <?php echo process_quiz_media_content($question['explanation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-times-circle"></i>
                        <p>Không tìm thấy câu hỏi làm sai nào.</p>
                        <a href="quizlet.php" class="btn btn-primary">
                            <i class="fas fa-play"></i> Bắt đầu học ngay
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal xác nhận xóa lịch sử -->
    <div id="clear-history-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Xác nhận xóa lịch sử</h3>
                <button class="modal-close" onclick="closeModal('clear-history-modal')">&times;</button>
            </div>
            <p>Bạn có chắc chắn muốn xóa lịch sử học tập?</p>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="clear_quiz_id">Chọn bộ câu hỏi:</label>
                    <select id="clear_quiz_id" name="quiz_id" class="form-control">
                        <option value="0">Tất cả bộ câu hỏi</option>
                        <?php 
                        mysqli_data_seek($result_quizzes, 0);
                        while ($quiz_row = mysqli_fetch_assoc($result_quizzes)): 
                        ?>
                            <option value="<?php echo $quiz_row['id']; ?>">
                                <?php echo htmlspecialchars($quiz_row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('clear-history-modal')">Hủy</button>
                    <button type="submit" name="clear_history" class="btn btn-danger">Xóa lịch sử</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Chuyển tab
        function switchTab(tabId) {
            // Ẩn tất cả tab content
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Bỏ active tất cả tab
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Hiển thị tab được chọn
            document.getElementById(tabId).classList.add('active');
            
            // Active tab được chọn
            const selectedTab = document.querySelector(`.tab[onclick="switchTab('${tabId}')"]`);
            selectedTab.classList.add('active');
        }
        
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
        
        // Thay đổi bộ câu hỏi
        function changeQuiz(quizId) {
            window.location.href = 'quiz_results.php?quiz_id=' + quizId + '&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>';
        }
        
        // Thay đổi bộ lọc
        function changeFilter(filter) {
            window.location.href = 'quiz_results.php?quiz_id=<?php echo $quiz_id; ?>&filter=' + filter + '&search=<?php echo urlencode($search); ?>';
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
