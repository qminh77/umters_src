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
$quiz = null;
$media_code = null;

// Kiểm tra ID bộ câu hỏi
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$quiz_id = (int)$_GET['id'];

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

// Xử lý cập nhật thông tin bộ câu hỏi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_quiz'])) {
    $quiz_name = trim($_POST['quiz_name']);
    $quiz_description = trim($_POST['quiz_description']);

    // Kiểm tra dữ liệu đầu vào
    if (empty($quiz_name)) {
        $error_message = "Tên bộ câu hỏi không được để trống.";
    } elseif (strlen($quiz_name) > 255) {
        $error_message = "Tên bộ câu hỏi không được vượt quá 255 ký tự.";
    } elseif (strlen($quiz_description) > 1000) {
        $error_message = "Mô tả không được vượt quá 1000 ký tự.";
    } else {
        // Sử dụng prepared statement để cập nhật thông tin
        $stmt = mysqli_prepare($conn, "UPDATE quiz_sets SET name = ?, description = ? WHERE id = ? AND user_id = ?");
        if ($stmt === false) {
            $error_message = "Lỗi chuẩn bị câu lệnh SQL: " . mysqli_error($conn);
            error_log("Prepare statement error in update_quiz: " . mysqli_error($conn));
        } else {
            mysqli_stmt_bind_param($stmt, "ssii", $quiz_name, $quiz_description, $quiz_id, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Thông tin bộ câu hỏi đã được cập nhật!";
                $quiz['name'] = $quiz_name;
                $quiz['description'] = $quiz_description;
            } else {
                $error_message = "Lỗi khi thực thi câu lệnh SQL: " . mysqli_stmt_error($stmt);
                error_log("Execute statement error in update_quiz: " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Xử lý thêm câu hỏi mới
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_question'])) {
    $question = trim($_POST['question']);
    $option1 = trim($_POST['option1']);
    $option2 = trim($_POST['option2']);
    $option3 = trim($_POST['option3']);
    $option4 = trim($_POST['option4']);
    $correct_answer = isset($_POST['correct_answer']) ? (int)$_POST['correct_answer'] : null;
    $explanation = trim($_POST['explanation']);

    // Kiểm tra dữ liệu đầu vào
    if (empty($question) || empty($option1) || empty($option2) || empty($option3) || empty($option4)) {
        $error_message = "Vui lòng điền đầy đủ câu hỏi và các phương án.";
    } elseif ($correct_answer < 1 || $correct_answer > 4) {
        $error_message = "Đáp án đúng không hợp lệ.";
    } else {
        // Sử dụng prepared statement để chèn câu hỏi mới
        $stmt = mysqli_prepare($conn, "INSERT INTO quiz_questions (quiz_id, question, option1, option2, option3, option4, correct_answer, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt === false) {
            $error_message = "Lỗi chuẩn bị câu lệnh SQL: " . mysqli_error($conn);
            error_log("Prepare statement error in add_question: " . mysqli_error($conn));
        } else {
            mysqli_stmt_bind_param($stmt, "isssssis", $quiz_id, $question, $option1, $option2, $option3, $option4, $correct_answer, $explanation);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Câu hỏi đã được thêm thành công!";
            } else {
                $error_message = "Lỗi khi thực thi câu lệnh SQL: " . mysqli_stmt_error($stmt);
                error_log("Execute statement error in add_question: " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Xử lý xóa câu hỏi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    
    // Xóa tiến trình học tập liên quan
    mysqli_query($conn, "DELETE FROM quiz_progress WHERE question_id = $question_id AND user_id = $user_id");
    
    // Xóa câu hỏi
    $sql = "DELETE FROM quiz_questions WHERE id = $question_id AND quiz_id = $quiz_id";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Câu hỏi đã được xóa thành công!";
        $result_questions = mysqli_query($conn, $sql_questions);
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}

// Thêm xử lý xóa nhiều câu hỏi cùng lúc
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_selected_questions'])) {
    if (isset($_POST['selected_questions']) && is_array($_POST['selected_questions'])) {
        $selected_questions = array_map('intval', $_POST['selected_questions']);
        $question_ids = implode(',', $selected_questions);
        
        if (!empty($question_ids)) {
            // Xóa tiến trình học tập liên quan
            mysqli_query($conn, "DELETE FROM quiz_progress WHERE question_id IN ($question_ids) AND user_id = $user_id");
            
            // Xóa các câu hỏi đã chọn
            $sql = "DELETE FROM quiz_questions WHERE id IN ($question_ids) AND quiz_id = $quiz_id";
            
            if (mysqli_query($conn, $sql)) {
                $count = mysqli_affected_rows($conn);
                $success_message = "Đã xóa thành công $count câu hỏi!";
                $result_questions = mysqli_query($conn, $sql_questions);
            } else {
                $error_message = "Lỗi: " . mysqli_error($conn);
            }
        }
    } else {
        $error_message = "Vui lòng chọn ít nhất một câu hỏi để xóa!";
    }
}

// Xử lý cập nhật câu hỏi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_question'])) {
    $question_id = (int)$_POST['question_id'];
    $question = trim($_POST['question']);
    $option1 = trim($_POST['option1']);
    $option2 = trim($_POST['option2']);
    $option3 = trim($_POST['option3']);
    $option4 = trim($_POST['option4']);
    $correct_answer = (int)$_POST['correct_answer'];
    $explanation = trim($_POST['explanation']);
    
    if ($correct_answer < 1 || $correct_answer > 4) {
        $correct_answer = 1;
    }
    
    // Sử dụng prepared statement để cập nhật câu hỏi
    $stmt = mysqli_prepare($conn, "UPDATE quiz_questions SET question = ?, option1 = ?, option2 = ?, option3 = ?, option4 = ?, correct_answer = ?, explanation = ? WHERE id = ? AND quiz_id = ?");
    if ($stmt === false) {
        $error_message = "Lỗi chuẩn bị câu lệnh SQL: " . mysqli_error($conn);
        error_log("Prepare statement error in update_question: " . mysqli_error($conn));
    } else {
        mysqli_stmt_bind_param($stmt, "sssssii", $question, $option1, $option2, $option3, $option4, $correct_answer, $explanation, $question_id, $quiz_id);
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Câu hỏi đã được cập nhật thành công!";
            $result_questions = mysqli_query($conn, $sql_questions);
        } else {
            $error_message = "Lỗi khi thực thi câu lệnh SQL: " . mysqli_stmt_error($stmt);
            error_log("Execute statement error in update_question: " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
    }
}

// Xử lý nhập câu hỏi từ CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        
        $has_header = isset($_POST['has_header']) && $_POST['has_header'] == 1;
        $import_result = import_quiz_questions_from_csv($file, $quiz_id, $user_id, $has_header, $conn);
        
        if ($import_result['count'] > 0) {
            $success_message = "Đã nhập thành công {$import_result['count']} câu hỏi từ file CSV!";
            if (!empty($import_result['errors'])) {
                $error_message = "Có một số lỗi xảy ra: " . implode(", ", $import_result['errors']);
            }
            $result_questions = mysqli_query($conn, $sql_questions);
        } else {
            $error_message = "Không có câu hỏi nào được nhập. " . implode(", ", $import_result['errors']);
        }
    } else {
        $error_message = "Vui lòng chọn file CSV hợp lệ!";
    }
}

// Xử lý xuất câu hỏi ra CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_csv'])) {
    $csv_content = export_quiz_to_csv($quiz_id, $conn);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz_' . $quiz_id . '_export.csv"');
    echo $csv_content;
    exit;
}

// Xử lý upload media
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_media'])) {
    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] == 0) {
        $upload_result = upload_quiz_media_file($_FILES['media_file']);
        
        if ($upload_result) {
            $media_type = $_FILES['media_file']['type'];
            if (strpos($media_type, 'image') !== false) {
                $media_code = "[img:{$upload_result}]";
                echo json_encode(['success' => true, 'media_code' => $media_code]);
                exit;
            } elseif (strpos($media_type, 'video') !== false) {
                $media_code = "[video:{$upload_result}]";
                echo json_encode(['success' => true, 'media_code' => $media_code]);
                exit;
            } else {
                echo json_encode(['success' => false, 'error' => 'Loại media không được hỗ trợ']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Không thể tải lên media. Vui lòng thử lại.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Vui lòng chọn file media hợp lệ.']);
        exit;
    }
}

// Xử lý thêm YouTube link
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_youtube'])) {
    if (isset($_POST['youtube_url']) && !empty($_POST['youtube_url'])) {
        $youtube_url = $_POST['youtube_url'];
        if (preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $youtube_url, $matches)) {
            $video_id = $matches[3];
        } else {
            $video_id = $youtube_url;
        }
        
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $video_id)) {
            $media_code = "[youtube:{$video_id}]";
            echo json_encode(['success' => true, 'media_code' => $media_code]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'ID video YouTube không hợp lệ.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Vui lòng nhập URL hoặc ID video YouTube.']);
        exit;
    }
}

// If it's an AJAX request, stop here
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chỉnh sửa bộ câu hỏi - <?php echo htmlspecialchars($quiz['name']); ?></title>
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

        .card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: var(--primary-light);
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--foreground-muted);
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            border-radius: 21px 21px 0 0;
        }

        .tab:hover {
            color: var(--foreground);
            background: rgba(255, 255, 255, 0.1);
        }

        .tab.active {
            color: var(--foreground);
            border-bottom-color: var(--primary);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--foreground);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            color: var(--foreground);
            background: rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
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

        .btn-danger {
            background: linear-gradient(to right, #FF3D57, #C70039);
            color: white;
            border: none;
        }

        .btn-danger:hover {
            background: linear-gradient(to right, #FF5069, #DC143C);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.3);
        }

        .question-list {
            margin-top: 1.5rem;
        }

        .question-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
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

        .question-number {
            background: var(--primary);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .question-text {
            font-weight: 500;
            color: var(--foreground);
            margin-bottom: 1rem;
            flex: 1;
        }

        .question-actions {
            display: flex;
            gap: 0.5rem;
        }

        .question-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .question-option {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            border: 1px solid var(--border);
            position: relative;
            padding-left: 2rem;
        }

        .question-option.correct {
            border-color: var(--secondary);
            background: rgba(0, 224, 255, 0.1);
        }

        .question-option-marker {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--foreground-muted);
        }

        .question-option.correct .question-option-marker {
            background: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        .question-explanation {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            color: var(--foreground-muted);
            font-size: 0.875rem;
            margin-top: 1rem;
        }

        .question-explanation-title {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--foreground-muted);
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--foreground-muted);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
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
            backdrop-filter: blur(5px);
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
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 600px;
            padding: 2rem;
            position: relative;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
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
            font-weight: 600;
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
            color: #FF3D57;
        }

        .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .radio-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .import-options {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-group label {
            margin-bottom: 0;
        }

        .file-input-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .file-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            color: var(--foreground);
            background: rgba(255, 255, 255, 0.05);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input:hover {
            border-color: var(--primary-light);
            background: rgba(255, 255, 255, 0.1);
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

        .media-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .quiz-media {
            margin: 1rem 0;
            border-radius: var(--radius-sm);
            overflow: hidden;
            max-width: 100%;
        }

        .quiz-image {
            text-align: center;
            background: rgba(255, 255, 255, 0.05);
            min-height: 100px;
        }

        .quiz-image img {
            max-width: 100%;
            height: auto;
            object-fit: contain;
        }

        .quiz-video {
            min-height: 150px;
            background: rgba(255, 255, 255, 0.05);
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
            background: rgba(255, odoro, 255, 0.05);
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
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
            font-size: 1.5rem;
        }

        .loaded .media-loading {
            display: none;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            color: var(--foreground);
            padding: 1rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            z-index: 1100;
            display: none;
            animation: fadeIn 0.3s ease;
            border: 1px solid var(--border);
        }

        .toast.success {
            border-left: 4px solid var(--secondary);
        }

        .toast.error {
            border-left: 4px solid #FF3D57;
        }

        .checkbox-label, .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .answer-options {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .bulk-actions {
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

            .question-options {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                margin-bottom: 0.5rem;
                text-align: center;
            }

            .tab.active {
                border-color: var(--primary);
                border-width: 1px;
            }
        }

        @media (max-width: 480px) {
            .modal-content {
                padding: 1.5rem;
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
                <a href="../flashcards"><i class="fas fa-layer-group"></i> Flashcards</a>
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

        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-edit"></i> Chỉnh sửa bộ câu hỏi</h2>
                <div>
                    <button class="btn btn-secondary btn-sm" onclick="openModal('import-modal')">
                        <i class="fas fa-file-import"></i> Nhập từ CSV
                    </button>
                    <form method="POST" action="" style="display: inline;">
                        <button type="submit" name="export_csv" class="btn btn-secondary btn-sm">
                            <i class="fas fa-file-export"></i> Xuất ra CSV
                        </button>
                    </form>
                    <a href="study_quiz.php?quiz_id=<?php echo $quiz_id; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-play"></i> Học ngay
                    </a>
                </div>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="switchTab('quiz-info')">Thông tin bộ câu hỏi</div>
                <div class="tab" onclick="switchTab('questions')">Danh sách câu hỏi</div>
                <div class="tab" onclick="switchTab('add-question')">Thêm câu hỏi mới</div>
            </div>

            <div id="quiz-info" class="tab-content active">
                <form method="POST" class="quiz-info-form">
                    <input type="hidden" name="update_quiz" value="1">
                    <div class="form-group">
                        <label for="quiz_name">Tên bộ câu hỏi</label>
                        <input type="text" id="quiz_name" name="quiz_name" class="form-control" value="<?php echo htmlspecialchars($quiz['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="quiz_description">Mô tả</label>
                        <textarea id="quiz_description" name="quiz_description" class="form-control"><?php echo htmlspecialchars($quiz['description']); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Cập nhật thông tin</button>
                </form>
            </div>

            <div id="questions" class="tab-content">
                <?php if (mysqli_num_rows($result_questions) > 0): ?>
                    <form method="POST" action="" id="questions-form">
                        <div class="bulk-actions">
                            <div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="selectAllQuestions(true)">
                                    <i class="fas fa-check-square"></i> Chọn tất cả
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="selectAllQuestions(false)">
                                    <i class="fas fa-square"></i> Bỏ chọn tất cả
                                </button>
                            </div>
                            <div>
                                <button type="submit" name="delete_selected_questions" class="btn btn-danger btn-sm" onclick="return confirmDeleteSelected()">
                                    <i class="fas fa-trash"></i> Xóa đã chọn (<span id="selected-count">0</span>)
                                </button>
                            </div>
                        </div>
                        
                        <div class="question-list">
                            <?php $question_number = 1; ?>
                            <?php while ($question = mysqli_fetch_assoc($result_questions)): ?>
                                <div class="question-item">
                                    <div class="question-header">
                                        <div style="display: flex; align-items: flex-start;">
                                            <div style="margin-right: 10px;">
                                                <input type="checkbox" name="selected_questions[]" value="<?php echo $question['id']; ?>" class="question-checkbox" onchange="updateSelectedCount()">
                                            </div>
                                            <div class="question-number"><?php echo $question_number++; ?></div>
                                            <div class="question-text">
                                                <?php echo process_quiz_media_content($question['question']); ?>
                                            </div>
                                        </div>
                                        <div class="question-actions">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="editQuestion(<?php echo $question['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteQuestion(<?php echo $question['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="question-options">
                                        <div class="question-option <?php echo ($question['correct_answer'] == 1) ? 'correct' : ''; ?>">
                                            <div class="question-option-marker">A</div>
                                            <?php echo process_quiz_media_content($question['option1']); ?>
                                        </div>
                                        <div class="question-option <?php echo ($question['correct_answer'] == 2) ? 'correct' : ''; ?>">
                                            <div class="question-option-marker">B</div>
                                            <?php echo process_quiz_media_content($question['option2']); ?>
                                        </div>
                                        <div class="question-option <?php echo ($question['correct_answer'] == 3) ? 'correct' : ''; ?>">
                                            <div class="question-option-marker">C</div>
                                            <?php echo process_quiz_media_content($question['option3']); ?>
                                        </div>
                                        <div class="question-option <?php echo ($question['correct_answer'] == 4) ? 'correct' : ''; ?>">
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
                            <?php endwhile; ?>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-question-circle"></i>
                        <p>Bộ câu hỏi này chưa có câu hỏi nào. Hãy thêm câu hỏi đầu tiên!</p>
                        <button class="btn btn-primary" onclick="switchTab('add-question')">
                            <i class="fas fa-plus"></i> Thêm câu hỏi mới
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div id="add-question" class="tab-content">
                <div class="media-toolbar">
                    <button class="btn btn-secondary btn-sm" onclick="openModal('upload-media-modal')">
                        <i class="fas fa-image"></i> Thêm hình ảnh/video
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="openModal('youtube-modal')">
                        <i class="fab fa-youtube"></i> Thêm YouTube
                    </button>
                </div>
                
                <form method="POST" class="question-form">
                    <input type="hidden" name="add_question" value="1">
                    <div class="form-group">
                        <label for="question">Câu hỏi</label>
                        <textarea id="question" name="question" class="form-control" required></textarea>
                    </div>
                    <div class="options-container">
                        <div class="form-group">
                            <label for="option1">Phương án A</label>
                            <input type="text" id="option1" name="option1" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="option2">Phương án B</label>
                            <input type="text" id="option2" name="option2" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="option3">Phương án C</label>
                            <input type="text" id="option3" name="option3" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="option4">Phương án D</label>
                            <input type="text" id="option4" name="option4" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Đáp án đúng</label>
                        <div class="answer-options">
                            <label class="radio-label">
                                <input type="radio" name="correct_answer" value="1" required>
                                A
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="correct_answer" value="2">
                                B
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="correct_answer" value="3">
                                C
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="correct_answer" value="4">
                                D
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="explanation">Giải thích (tùy chọn)</label>
                        <textarea id="explanation" name="explanation" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Thêm câu hỏi</button>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-question-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chỉnh sửa câu hỏi</h3>
                <button class="modal-close" onclick="closeModal('edit-question-modal')">×</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="edit_question_id" name="question_id">
                <div class="form-group">
                    <label for="edit_question">Câu hỏi</label>
                    <textarea id="edit_question" name="question" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_option1">Phương án A</label>
                    <textarea id="edit_option1" name="option1" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_option2">Phương án B</label>
                    <textarea id="edit_option2" name="option2" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_option3">Phương án C</label>
                    <textarea id="edit_option3" name="option3" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_option4">Phương án D</label>
                    <textarea id="edit_option4" name="option4" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label>Đáp án đúng</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="edit_correct_answer_1" name="correct_answer" value="1">
                            <label for="edit_correct_answer_1">A</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="edit_correct_answer_2" name="correct_answer" value="2">
                            <label for="edit_correct_answer_2">B</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="edit_correct_answer_3" name="correct_answer" value="3">
                            <label for="edit_correct_answer_3">C</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="edit_correct_answer_4" name="correct_answer" value="4">
                            <label for="edit_correct_answer_4">D</label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_explanation">Giải thích (tùy chọn)</label>
                    <textarea id="edit_explanation" name="explanation" class="form-control"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-question-modal')">Hủy</button>
                    <button type="submit" name="update_question" class="btn btn-primary">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-question-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Xác nhận xóa</h3>
                <button class="modal-close" onclick="closeModal('delete-question-modal')">×</button>
            </div>
            <p>Bạn có chắc chắn muốn xóa câu hỏi này?</p>
            <form method="POST" action="">
                <input type="hidden" id="delete_question_id" name="question_id">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('delete-question-modal')">Hủy</button>
                    <button type="submit" name="delete_question" class="btn btn-danger">Xóa vĩnh viễn</button>
                </div>
            </form>
        </div>
    </div>

    <div id="import-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Nhập câu hỏi từ CSV</h3>
                <button class="modal-close" onclick="closeModal('import-modal')">×</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <p>Tải lên file CSV với định dạng: cột 1 = câu hỏi, cột 2-5 = các phương án, cột 6 = đáp án đúng (1-4), cột 7 = giải thích (tùy chọn)</p>
                
                <div class="file-input-wrapper">
                    <div class="file-input">
                        <i class="fas fa-file-csv"></i> Chọn file CSV
                        <input type="file" name="csv_file" accept=".csv" required>
                    </div>
                </div>
                
                <div class="import-options">
                    <div class="checkbox-group">
                        <input type="checkbox" id="has_header" name="has_header" value="1">
                        <label for="has_header">File có dòng tiêu đề</label>
                    </div>
                    <p><small>Lưu ý: Các câu hỏi đã tồn tại sẽ không bị ghi đè.</small></p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('import-modal')">Hủy</button>
                    <button type="submit" name="import_csv" class="btn btn-primary">Nhập dữ liệu</button>
                </div>
                
                <div class="form-group" style="margin-top: 1rem;">
                    <a href="#" onclick="downloadTemplate(); return false;" class="btn btn-secondary btn-sm" style="text-decoration: none;">
                        <i class="fas fa-download"></i> Tải mẫu CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="upload-media-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Tải lên hình ảnh/video</h3>
                <button class="modal-close" onclick="closeModal('upload-media-modal')">×</button>
            </div>
            <form id="upload-media-form" enctype="multipart/form-data">
                <p>Tải lên hình ảnh hoặc video để chèn vào câu hỏi hoặc phương án:</p>
                
                <div class="file-input-wrapper">
                    <div class="file-input">
                        <i class="fas fa-file-image"></i> Chọn hình ảnh/video
                        <input type="file" name="media_file" accept="image/*,video/mp4,video/webm" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Hướng dẫn sử dụng:</label>
                    <ul style="margin-left: 1.5rem; margin-bottom: 1rem;">
                        <li>Hình ảnh: <code>[img:URL]</code></li>
                        <li>Video: <code>[video:URL]</code></li>
                        <li>YouTube: <code>[youtube:VIDEO_ID]</code></li>
                    </ul>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('upload-media-modal')">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tải lên</button>
                </div>
            </form>
        </div>
    </div>

    <div id="youtube-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Thêm video YouTube</h3>
                <button class="modal-close" onclick="closeModal('youtube-modal')">×</button>
            </div>
            <form id="youtube-form">
                <p>Nhập URL hoặc ID video YouTube:</p>
                
                <div class="form-group">
                    <input type="text" name="youtube_url" class="form-control" placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ hoặc dQw4w9WgXcQ" required>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('youtube-modal')">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo mã</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        function switchTab(tabId) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            document.querySelector(`.tab[onclick="switchTab('${tabId}')"]`).classList.add('active');
        }

        function clearForm() {
            document.getElementById('question').value = '';
            document.getElementById('option1').value = '';
            document.getElementById('option2').value = '';
            document.getElementById('option3').value = '';
            document.getElementById('option4').value = '';
            document.querySelector('input[name="correct_answer"][value="1"]').checked = true;
            document.getElementById('explanation').value = '';
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function editQuestion(questionId) {
            fetch('get_question.php?id=' + questionId, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('edit_question_id').value = data.question.id;
                    document.getElementById('edit_question').value = data.question.question;
                    document.getElementById('edit_option1').value = data.question.option1;
                    document.getElementById('edit_option2').value = data.question.option2;
                    document.getElementById('edit_option3').value = data.question.option3;
                    document.getElementById('edit_option4').value = data.question.option4;
                    document.getElementById('edit_explanation').value = data.question.explanation;
                    document.getElementById('edit_correct_answer_' + data.question.correct_answer).checked = true;
                    openModal('edit-question-modal');
                } else {
                    showToast('Không thể tải thông tin câu hỏi: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Đã xảy ra lỗi khi tải thông tin câu hỏi', 'error');
            });
        }

        function confirmDeleteQuestion(questionId) {
            document.getElementById('delete_question_id').value = questionId;
            openModal('delete-question-modal');
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type;
            toast.style.display = 'block';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }

        function insertMediaCode(mediaCode, targetElement) {
            const textarea = document.getElementById(targetElement || 'question');
            if (textarea) {
                const startPos = textarea.selectionStart;
                const endPos = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, startPos) + mediaCode + textarea.value.substring(endPos);
                showToast('Đã chèn media thành công!');
            }
        }

        document.getElementById('upload-media-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('upload_media', '1');
            fetch('edit_quiz.php?id=<?php echo $quiz_id; ?>', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    insertMediaCode(data.media_code);
                    closeModal('upload-media-modal');
                    document.getElementById('upload-media-form').reset();
                } else {
                    showToast(data.error, 'error');
                }
            })
            .catch(error => {
                showToast('Đã xảy ra lỗi khi tải lên media', 'error');
                console.error('Error:', error);
            });
        });

        document.getElementById('youtube-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('add_youtube', '1');
            fetch('edit_quiz.php?id=<?php echo $quiz_id; ?>', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    insertMediaCode(data.media_code);
                    closeModal('youtube-modal');
                    document.getElementById('youtube-form').reset();
                } else {
                    showToast(data.error, 'error');
                }
            })
            .catch(error => {
                showToast('Đã xảy ra lỗi khi thêm YouTube', 'error');
                console.error('Error:', error);
            });
        });

        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
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
        });

        function downloadTemplate() {
            const csvContent = "question,option1,option2,option3,option4,correct_answer,explanation\n" +
                               "Sample Question,Option A,Option B,Option C,Option D,1,Explanation here";
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            if (navigator.msSaveBlob) {
                navigator.msSaveBlob(blob, "quiz_template.csv");
            } else {
                const link = document.createElement("a");
                if (link.download !== undefined) {
                    const url = URL.createObjectURL(blob);
                    link.setAttribute("href", url);
                    link.setAttribute("download", "quiz_template.csv");
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            }
        }

        function selectAllQuestions(select) {
            const checkboxes = document.querySelectorAll('.question-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = select;
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const selectedCheckboxes = document.querySelectorAll('.question-checkbox:checked');
            const selectedCount = selectedCheckboxes.length;
            document.getElementById('selected-count').textContent = selectedCount;
        }

        function confirmDeleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.question-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Vui lòng chọn ít nhất một câu hỏi để xóa.');
                return false;
            }
            return confirm('Bạn có chắc chắn muốn xóa các câu hỏi đã chọn?');
        }
    </script>
</body>
</html>