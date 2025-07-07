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

// Xử lý tạo bộ câu hỏi mới
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_quiz'])) {
   $quiz_name = mysqli_real_escape_string($conn, $_POST['quiz_name']);
   $quiz_description = mysqli_real_escape_string($conn, $_POST['quiz_description']);
   
   $sql = "INSERT INTO quiz_sets (user_id, name, description, created_at) VALUES ($user_id, '$quiz_name', '$quiz_description', NOW())";
   
   if (mysqli_query($conn, $sql)) {
       $quiz_id = mysqli_insert_id($conn);
       
       // Tạo token chia sẻ
       $share_token = generate_quiz_share_token($quiz_id);
       $sql_token = "UPDATE quiz_sets SET share_token = '$share_token' WHERE id = $quiz_id";
       mysqli_query($conn, $sql_token);
       
       $success_message = "Bộ câu hỏi trắc nghiệm đã được tạo thành công!";
   } else {
       $error_message = "Lỗi: " . mysqli_error($conn);
   }
}

// Xử lý xóa bộ câu hỏi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_quiz'])) {
   $quiz_id = (int)$_POST['quiz_id'];
   
   // Xóa các câu hỏi trong bộ
   mysqli_query($conn, "DELETE FROM quiz_questions WHERE quiz_id = $quiz_id");
   
   // Xóa tiến trình học tập
   mysqli_query($conn, "DELETE FROM quiz_progress WHERE quiz_id = $quiz_id AND user_id = $user_id");
   
   // Xóa lịch sử học tập
   mysqli_query($conn, "DELETE FROM quiz_study_history WHERE quiz_id = $quiz_id AND user_id = $user_id");
   
   // Xóa bộ câu hỏi
   $sql = "DELETE FROM quiz_sets WHERE id = $quiz_id AND user_id = $user_id";
   
   if (mysqli_query($conn, $sql)) {
       $success_message = "Bộ câu hỏi đã được xóa thành công!";
   } else {
       $error_message = "Lỗi: " . mysqli_error($conn);
   }
}

// Thêm xử lý nhập câu hỏi vào bài quizlet đã tạo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_to_existing'])) {
    $target_quiz_id = (int)$_POST['target_quiz_id'];
    
    // Kiểm tra quyền sở hữu bài quizlet
    $sql_check_owner = "SELECT id FROM quiz_sets WHERE id = $target_quiz_id AND user_id = $user_id";
    $result_check_owner = mysqli_query($conn, $sql_check_owner);
    
    if (mysqli_num_rows($result_check_owner) == 0) {
        $error_message = "Bạn không có quyền chỉnh sửa bài quizlet này!";
    } else if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        
        // Xử lý nhập từ CSV
        $has_header = isset($_POST['has_header']) && $_POST['has_header'] == 1;
        $import_result = import_quiz_questions_from_csv($file, $target_quiz_id, $user_id, $has_header, $conn);
        
        if ($import_result['count'] > 0) {
            $success_message = "Đã nhập thành công {$import_result['count']} câu hỏi vào bài quizlet!";
            
            if (!empty($import_result['errors'])) {
                $error_message = "Có một số lỗi xảy ra: " . implode(", ", $import_result['errors']);
            }
        } else {
            $error_message = "Không có câu hỏi nào được nhập. " . implode(", ", $import_result['errors']);
        }
    } else {
        $error_message = "Vui lòng chọn file CSV hợp lệ!";
    }
}

// Xử lý nhập bộ câu hỏi từ link chia sẻ
if (isset($_GET['import']) && isset($_GET['token'])) {
   $quiz_id = (int)$_GET['import'];
   $share_token = mysqli_real_escape_string($conn, $_GET['token']);
   
   $result = share_quiz_set($quiz_id, $share_token, $user_id, $conn);
   
   if ($result['success']) {
       $success_message = "Bộ câu hỏi đã được nhập thành công!";
   } else {
       $error_message = "Lỗi: " . $result['message'];
   }
}

// Thêm xử lý nhập bài học hàng loạt từ nhiều link
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_import'])) {
    $links = $_POST['import_links'];
    $links_array = preg_split('/\r\n|\r|\n/', $links);
    $links_array = array_filter($links_array); // Loại bỏ các dòng trống
    
    $import_results = [];
    $success_count = 0;
    $error_count = 0;
    
    foreach ($links_array as $link) {
        $link = trim($link);
        if (empty($link)) continue;
        
        // Phân tích URL để lấy quiz_id và token
        if (preg_match('/import=(\d+)&token=([a-f0-9]+)/', $link, $matches)) {
            $quiz_id = (int)$matches[1];
            $share_token = $matches[2];
            
            $result = share_quiz_set($quiz_id, $share_token, $user_id, $conn);
            
            if ($result['success']) {
                $import_results[] = [
                    'link' => $link,
                    'success' => true,
                    'message' => "Đã nhập thành công bài học #" . $quiz_id
                ];
                $success_count++;
            } else {
                $import_results[] = [
                    'link' => $link,
                    'success' => false,
                    'message' => "Lỗi: " . $result['message']
                ];
                $error_count++;
            }
        } else {
            $import_results[] = [
                'link' => $link,
                'success' => false,
                'message' => "Link không hợp lệ"
            ];
            $error_count++;
        }
    }
    
    // Hiển thị thông báo tổng hợp
    if ($success_count > 0) {
        $success_message = "Đã nhập thành công $success_count bài học.";
    }
    if ($error_count > 0) {
        $error_message = "Có $error_count link không thể nhập.";
    }
    
    // Lưu kết quả chi tiết vào session để hiển thị
    $_SESSION['import_results'] = $import_results;
}

// Lấy danh sách bộ câu hỏi của người dùng
$sql = "SELECT q.*, 
       (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count,
       (SELECT COUNT(*) FROM quiz_progress WHERE quiz_id = q.id AND user_id = $user_id) as progress_count
       FROM quiz_sets q 
       WHERE q.user_id = $user_id 
       ORDER BY q.created_at DESC";
$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Quizlet - Bộ câu hỏi trắc nghiệm</title>
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
            
            /* Transitions */
            --transition-fast: 0.2s ease;
            --transition: 0.3s ease;
            --transition-slow: 0.5s ease;
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
           background-image: 
               radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
               radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
               radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
           background-attachment: fixed;
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
           border-bottom: 1px solid var(--border);
       }

       .logo {
           display: flex;
           align-items: center;
           gap: 0.75rem;
           font-size: 1.5rem;
           font-weight: 700;
           color: var(--foreground);
           text-decoration: none;
       }

       .logo i {
           color: var(--primary-light);
           font-size: 1.75rem;
       }

       .user-menu {
           display: flex;
           align-items: center;
           gap: 1.5rem;
       }

       .user-menu a {
           color: var(--foreground-muted);
           text-decoration: none;
           font-weight: 500;
           transition: all var(--transition-fast);
           display: flex;
           align-items: center;
           gap: 0.5rem;
       }

       .user-menu a:hover {
           color: var(--foreground);
           transform: translateY(-2px);
       }

       .btn {
           padding: 0.75rem 1.5rem;
           border-radius: var(--radius-full);
           font-weight: 600;
           cursor: pointer;
           transition: all var(--transition);
           text-decoration: none;
           display: inline-flex;
           align-items: center;
           justify-content: center;
           gap: 0.5rem;
           font-size: 0.95rem;
           border: none;
           font-family: 'Outfit', sans-serif;
       }

       .btn-sm {
           padding: 0.5rem 1rem;
           font-size: 0.875rem;
       }

       .btn-primary {
           background: linear-gradient(135deg, var(--primary), var(--primary-dark));
           color: white;
           box-shadow: var(--glow);
       }

       .btn-primary:hover {
           background: linear-gradient(135deg, var(--primary-light), var(--primary));
           transform: translateY(-2px);
           box-shadow: var(--glow), 0 10px 20px rgba(112, 0, 255, 0.3);
       }

       .btn-secondary {
           background: rgba(255, 255, 255, 0.1);
           color: var(--foreground);
           border: 1px solid var(--border);
           backdrop-filter: blur(10px);
       }

       .btn-secondary:hover {
           background: rgba(255, 255, 255, 0.15);
           transform: translateY(-2px);
           box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
           border-color: var(--primary-light);
       }

       .btn-accent {
           background: linear-gradient(135deg, var(--accent), var(--accent-dark));
           color: white;
           box-shadow: var(--glow-accent);
       }

       .btn-accent:hover {
           background: linear-gradient(135deg, var(--accent-light), var(--accent));
           transform: translateY(-2px);
           box-shadow: var(--glow-accent), 0 10px 20px rgba(255, 61, 255, 0.3);
       }

       .alert {
           padding: 1rem 1.5rem;
           border-radius: var(--radius);
           margin-bottom: 1.5rem;
           font-weight: 500;
           display: flex;
           align-items: center;
           gap: 0.75rem;
           backdrop-filter: blur(10px);
           animation: slideIn 0.5s ease;
       }

       @keyframes slideIn {
           from { transform: translateY(-20px); opacity: 0; }
           to { transform: translateY(0); opacity: 1; }
       }

       .alert-success {
           background-color: rgba(0, 224, 255, 0.1);
           color: var(--secondary-light);
           border: 1px solid rgba(0, 224, 255, 0.3);
       }

       .alert-error {
           background-color: rgba(255, 61, 255, 0.1);
           color: var(--accent-light);
           border: 1px solid rgba(255, 61, 255, 0.3);
       }

       .page-header {
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-bottom: 2rem;
       }

       .page-title {
           font-size: 1.75rem;
           font-weight: 700;
           color: var(--foreground);
           display: flex;
           align-items: center;
           gap: 0.75rem;
       }

       .page-title i {
           color: var(--primary-light);
           font-size: 1.5rem;
       }

       .quiz-grid {
           display: grid;
           grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .quiz-card {
           background: var(--card);
           border-radius: var(--radius);
           box-shadow: var(--shadow-sm);
           padding: 1.5rem;
           transition: all var(--transition);
           position: relative;
           overflow: hidden;
           border: 1px solid var(--border);
           backdrop-filter: blur(10px);
       }

       .quiz-card::before {
           content: '';
           position: absolute;
           top: 0;
           left: 0;
           width: 100%;
           height: 4px;
           background: linear-gradient(90deg, var(--primary), var(--accent));
           opacity: 0.7;
           transition: all var(--transition);
       }

       .quiz-card:hover {
           transform: translateY(-5px);
           box-shadow: var(--shadow);
           border-color: var(--primary-light);
           background: var(--card-hover);
       }

       .quiz-card:hover::before {
           opacity: 1;
           height: 6px;
       }

       .quiz-card-header {
           display: flex;
           justify-content: space-between;
           align-items: flex-start;
           margin-bottom: 2.5rem;
       }

       .quiz-card-title {
           font-size: 1.25rem;
           font-weight: 600;
           color: var(--foreground);
           margin-bottom: 0.5rem;
       }

       .quiz-card-description {
           color: var(--foreground-muted);
           font-size: 0.875rem;
           margin-bottom: 1rem;
           display: -webkit-box;
           -webkit-line-clamp: 2;
           -webkit-box-orient: vertical;
           overflow: hidden;
       }

       .quiz-card-stats {
           display: flex;
           gap: 1rem;
           margin-bottom: 1rem;
           font-size: 0.875rem;
       }

       .quiz-card-stat {
           display: flex;
           align-items: center;
           gap: 0.5rem;
           color: var(--foreground-muted);
       }

       .quiz-card-stat i {
           color: var(--secondary);
       }

       .quiz-card-actions {
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-top: 1.5rem;
       }

       .quiz-card-menu {
           position: relative;
       }

       .quiz-card-menu-btn {
           background: none;
           border: none;
           font-size: 1.25rem;
           color: var(--foreground-muted);
           cursor: pointer;
           transition: color var(--transition-fast);
           padding: 0.5rem;
           border-radius: var(--radius-full);
       }

       .quiz-card-menu-btn:hover {
           color: var(--foreground);
           background: rgba(255, 255, 255, 0.1);
       }

       .quiz-card-menu-dropdown {
           position: absolute;
           top: 100%;
           right: 0;
           background: var(--surface);
           border-radius: var(--radius);
           box-shadow: var(--shadow);
           min-width: 200px;
           z-index: 10;
           display: none;
           border: 1px solid var(--border);
           overflow: hidden;
       }

       .quiz-card-menu-dropdown.active {
           display: block;
           animation: fadeIn 0.3s ease;
       }

       @keyframes fadeIn {
           from { opacity: 0; transform: translateY(-10px); }
           to { opacity: 1; transform: translateY(0); }
       }

       .quiz-card-menu-item {
           padding: 0.75rem 1rem;
           display: flex;
           align-items: center;
           gap: 0.75rem;
           color: var(--foreground-muted);
           text-decoration: none;
           transition: all var(--transition-fast);
           cursor: pointer;
           font-weight: 500;
       }

       .quiz-card-menu-item:hover {
           background-color: var(--surface-light);
           color: var(--foreground);
       }

       .quiz-card-menu-item.delete {
           color: var(--accent-light);
       }

       .quiz-card-menu-item.delete:hover {
           background-color: rgba(255, 61, 255, 0.1);
       }

       .quiz-card-menu-item i {
           font-size: 1rem;
           width: 20px;
           text-align: center;
       }

       .quiz-card-progress {
           height: 6px;
           background-color: rgba(255, 255, 255, 0.1);
           border-radius: var(--radius-full);
           overflow: hidden;
           margin-top: 1rem;
       }

       .quiz-card-progress-bar {
           height: 100%;
           background: linear-gradient(90deg, var(--primary), var(--accent));
           border-radius: var(--radius-full);
           transition: width 0.5s ease;
       }

       .empty-state {
           text-align: center;
           padding: 4rem 1rem;
           background: var(--card);
           border-radius: var(--radius);
           box-shadow: var(--shadow-sm);
           border: 1px solid var(--border);
           backdrop-filter: blur(10px);
       }

       .empty-state i {
           font-size: 4rem;
           color: var(--primary-light);
           margin-bottom: 1.5rem;
           opacity: 0.7;
           animation: pulse 3s infinite ease-in-out;
       }

       @keyframes pulse {
           0%, 100% { transform: scale(1); opacity: 0.7; }
           50% { transform: scale(1.1); opacity: 1; }
       }

       .empty-state h3 {
           font-size: 1.75rem;
           margin-bottom: 1rem;
           color: var(--foreground);
           font-weight: 700;
       }

       .empty-state p {
           color: var(--foreground-muted);
           margin-bottom: 2rem;
           max-width: 500px;
           margin-left: auto;
           margin-right: auto;
       }

       .modal {
           display: none;
           position: fixed;
           top: 0;
           left: 0;
           width: 100%;
           height: 100%;
           background: rgba(0, 0, 0, 0.7);
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

       .modal-content {
           background: var(--surface);
           border-radius: var(--radius);
           box-shadow: var(--shadow-lg);
           width: 100%;
           max-width: 550px;
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
           padding-bottom: 1rem;
           border-bottom: 1px solid var(--border);
       }

       .modal-title {
           font-size: 1.5rem;
           font-weight: 700;
           color: var(--foreground);
       }

       .modal-close {
           background: none;
           border: none;
           font-size: 1.5rem;
           cursor: pointer;
           color: var(--foreground-muted);
           transition: color var(--transition-fast);
           width: 40px;
           height: 40px;
           border-radius: var(--radius-full);
           display: flex;
           align-items: center;
           justify-content: center;
       }

       .modal-close:hover {
           color: var(--accent-light);
           background: rgba(255, 61, 255, 0.1);
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
           padding: 0.875rem 1.25rem;
           border: 2px solid var(--border);
           border-radius: var(--radius);
           font-size: 1rem;
           color: var(--foreground);
           transition: all var(--transition-fast);
           background-color: var(--surface-light);
           font-family: 'Outfit', sans-serif;
       }

       .form-control:focus {
           outline: none;
           border-color: var(--primary);
           box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.25);
       }

       .form-control::placeholder {
           color: var(--foreground-subtle);
       }

       textarea.form-control {
           min-height: 120px;
           resize: vertical;
       }

       .form-actions {
           display: flex;
           justify-content: flex-end;
           gap: 1rem;
           margin-top: 2rem;
       }

       .share-link {
           padding: 0.875rem 1.25rem;
           background: var(--surface-light);
           border: 1px solid var(--border);
           border-radius: var(--radius);
           display: flex;
           align-items: center;
           margin-top: 1.5rem;
       }

       .share-link input {
           flex: 1;
           border: none;
           background: transparent;
           color: var(--foreground);
           font-size: 0.95rem;
           padding: 0;
           font-family: 'Outfit', sans-serif;
       }

       .share-link input:focus {
           outline: none;
       }

       .share-link button {
           background: none;
           border: none;
           color: var(--secondary);
           cursor: pointer;
           font-size: 1.25rem;
           padding: 0.5rem;
           margin-left: 0.5rem;
           border-radius: var(--radius-full);
           transition: all var(--transition-fast);
           width: 40px;
           height: 40px;
           display: flex;
           align-items: center;
           justify-content: center;
       }

       .share-link button:hover {
           color: var(--secondary-light);
           background: rgba(0, 224, 255, 0.1);
       }

       .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input {
            background-color: var(--surface-light);
            border: 1px solid var(--border);
            color: var(--foreground);
            padding: 0.875rem 1.25rem;
            border-radius: var(--radius);
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all var(--transition-fast);
            width: 100%;
        }

        .file-input:hover {
            background-color: var(--surface);
            border-color: var(--primary-light);
        }

        .file-input input[type=file] {
            font-size: 100px;
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .import-options {
            margin-top: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .checkbox-group input[type=checkbox] {
            appearance: none;
            -webkit-appearance: none;
            background-color: var(--surface-light);
            border: 2px solid var(--border);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            display: inline-block;
            position: relative;
            cursor: pointer;
            height: 1.25rem;
            width: 1.25rem;
            transition: all var(--transition-fast);
        }

        .checkbox-group input[type=checkbox]:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .checkbox-group input[type=checkbox]:checked::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.75rem;
            color: #fff;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .checkbox-group label {
            cursor: pointer;
            margin-bottom: 0;
        }

        .import-results {
            margin-top: 2rem;
            border-top: 1px solid var(--border);
            padding-top: 1.5rem;
        }

        .import-results h4 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--foreground);
            font-weight: 600;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .results-table th,
        .results-table td {
            text-align: left;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .results-table th {
            color: var(--foreground);
            font-weight: 600;
        }

        .results-table td {
            color: var(--foreground-muted);
            word-break: break-all;
        }

        .results-table .success {
            color: var(--secondary-light);
        }

        .results-table .error {
            color: var(--accent-light);
        }

        .results-container {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface-light);
        }

        .results-container::-webkit-scrollbar {
            width: 8px;
        }

        .results-container::-webkit-scrollbar-track {
            background: var(--surface-light);
            border-radius: var(--radius);
        }

        .results-container::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: var(--radius);
        }

        .results-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        .note {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            margin-top: 1rem;
        }

        .template-link {
            color: var(--secondary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            font-weight: 500;
            transition: all var(--transition-fast);
        }

        .template-link:hover {
            color: var(--secondary-light);
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
                padding-bottom: 1.5rem;
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
                gap: 1.5rem;
                text-align: center;
                margin-bottom: 2rem;
            }

            .page-header .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .quiz-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .modal-content {
                padding: 1.5rem;
            }
        }

        /* Animations */
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .quiz-card {
            animation: slideUp 0.5s ease;
            animation-fill-mode: both;
        }

        .quiz-card:nth-child(1) { animation-delay: 0.1s; }
        .quiz-card:nth-child(2) { animation-delay: 0.2s; }
        .quiz-card:nth-child(3) { animation-delay: 0.3s; }
        .quiz-card:nth-child(4) { animation-delay: 0.4s; }
        .quiz-card:nth-child(5) { animation-delay: 0.5s; }
        .quiz-card:nth-child(6) { animation-delay: 0.6s; }
   </style>
</head>
<body>
   <div class="container">
       <header>
           <a href="../dashboard.php" class="logo">
               <i class="fas fa-question-circle"></i>
               <span>QuizMaster</span>
           </a>
           <div class="user-menu">
               <a href="../dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
               <a href="../flashcards"><i class="fas fa-layer-group"></i> Flashcards</a>
               <a href="../logout.php" class="btn btn-primary"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
           </div>
       </header>

       <?php if ($error_message): ?>
           <div class="alert alert-error">
               <i class="fas fa-exclamation-circle"></i>
               <?php echo $error_message; ?>
           </div>
       <?php endif; ?>
       <?php if ($success_message): ?>
           <div class="alert alert-success">
               <i class="fas fa-check-circle"></i>
               <?php echo $success_message; ?>
           </div>
       <?php endif; ?>

       <div class="page-header">
           <h1 class="page-title"><i class="fas fa-question-circle"></i> Bộ câu hỏi trắc nghiệm</h1>
           <div>
               <button class="btn btn-secondary" onclick="openModal('bulk-import-modal')" style="margin-right: 0.75rem;">
                   <i class="fas fa-file-import"></i> Nhập hàng loạt
               </button>
               <button class="btn btn-secondary" onclick="openModal('import-to-existing-modal')" style="margin-right: 0.75rem;">
                   <i class="fas fa-file-import"></i> Nạp câu hỏi
               </button>
               <button class="btn btn-primary" onclick="openModal('create-quiz-modal')">
                   <i class="fas fa-plus"></i> Tạo bộ câu hỏi mới
               </button>
           </div>
       </div>

       <?php if (mysqli_num_rows($result) > 0): ?>
           <div class="quiz-grid">
               <?php while ($quiz = mysqli_fetch_assoc($result)): ?>
                   <div class="quiz-card">
                       <div class="quiz-card-header">
                           <div>
                               <h3 class="quiz-card-title"><?php echo htmlspecialchars($quiz['name']); ?></h3>
                               <p class="quiz-card-description"><?php echo htmlspecialchars($quiz['description']); ?></p>
                           </div>
                           <div class="quiz-card-menu">
                               <button class="quiz-card-menu-btn" onclick="toggleMenu(<?php echo $quiz['id']; ?>)">
                                   <i class="fas fa-ellipsis-v"></i>
                               </button>
                               <div class="quiz-card-menu-dropdown" id="menu-<?php echo $quiz['id']; ?>">
                                   <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="quiz-card-menu-item">
                                       <i class="fas fa-edit"></i> Chỉnh sửa
                                   </a>
                                   <a href="study_quiz.php?quiz_id=<?php echo $quiz['id']; ?>" class="quiz-card-menu-item">
                                       <i class="fas fa-play"></i> Học ngay
                                   </a>
                                   <div class="quiz-card-menu-item" onclick="openShareModal(<?php echo $quiz['id']; ?>, '<?php echo $quiz['share_token']; ?>')">
                                       <i class="fas fa-share-alt"></i> Chia sẻ
                                   </div>
                                   <div class="quiz-card-menu-item delete" onclick="confirmDelete(<?php echo $quiz['id']; ?>, '<?php echo htmlspecialchars($quiz['name']); ?>')">
                                       <i class="fas fa-trash"></i> Xóa
                                   </div>
                               </div>
                           </div>
                       </div>
                       <div class="quiz-card-stats">
                           <div class="quiz-card-stat">
                               <i class="fas fa-question-circle"></i> <?php echo $quiz['question_count']; ?> câu hỏi
                           </div>
                           <div class="quiz-card-stat">
                               <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($quiz['created_at'])); ?>
                           </div>
                       </div>
                       <?php
                           // Tính phần trăm hoàn thành
                           $progress_percent = 0;
                           if ($quiz['question_count'] > 0 && $quiz['progress_count'] > 0) {
                               $progress_percent = min(100, round(($quiz['progress_count'] / $quiz['question_count']) * 100));
                           }
                       ?>
                       <div class="quiz-card-progress">
                           <div class="quiz-card-progress-bar" style="width: <?php echo $progress_percent; ?>%"></div>
                       </div>
                       <div class="quiz-card-actions">
                           <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="btn btn-secondary btn-sm">
                               <i class="fas fa-edit"></i> Chỉnh sửa
                           </a>
                           <a href="study_quiz.php?quiz_id=<?php echo $quiz['id']; ?>" class="btn btn-primary btn-sm">
                               <i class="fas fa-play"></i> Học ngay
                           </a>
                       </div>
                   </div>
               <?php endwhile; ?>
           </div>
       <?php else: ?>
           <div class="empty-state">
               <i class="fas fa-question-circle"></i>
               <h3>Chưa có bộ câu hỏi nào</h3>
               <p>Tạo bộ câu hỏi trắc nghiệm đầu tiên của bạn để bắt đầu học tập!</p>
               <button class="btn btn-primary" onclick="openModal('create-quiz-modal')">
                   <i class="fas fa-plus"></i> Tạo bộ câu hỏi mới
               </button>
           </div>
       <?php endif; ?>
   </div>

   <!-- Modal tạo bộ câu hỏi mới -->
   <div id="create-quiz-modal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">Tạo bộ câu hỏi mới</h3>
               <button class="modal-close" onclick="closeModal('create-quiz-modal')">&times;</button>
           </div>
           <form method="POST" action="">
               <div class="form-group">
                   <label for="quiz_name">Tên bộ câu hỏi</label>
                   <input type="text" id="quiz_name" name="quiz_name" class="form-control" placeholder="Nhập tên bộ câu hỏi" required>
               </div>
               <div class="form-group">
                   <label for="quiz_description">Mô tả</label>
                   <textarea id="quiz_description" name="quiz_description" class="form-control" placeholder="Mô tả ngắn về bộ câu hỏi này"></textarea>
               </div>
               <div class="form-actions">
                   <button type="button" class="btn btn-secondary" onclick="closeModal('create-quiz-modal')">Hủy</button>
                   <button type="submit" name="create_quiz" class="btn btn-primary">Tạo bộ câu hỏi</button>
               </div>
           </form>
       </div>
   </div>

   <!-- Modal xác nhận xóa -->
   <div id="delete-modal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">Xác nhận xóa</h3>
               <button class="modal-close" onclick="closeModal('delete-modal')">&times;</button>
           </div>
           <p>Bạn có chắc chắn muốn xóa bộ câu hỏi "<span id="delete-quiz-name"></span>"?</p>
           <p class="note">Tất cả câu hỏi và tiến trình học tập sẽ bị xóa vĩnh viễn.</p>
           <form method="POST" action="">
               <input type="hidden" id="delete_quiz_id" name="quiz_id">
               <div class="form-actions">
                   <button type="button" class="btn btn-secondary" onclick="closeModal('delete-modal')">Hủy</button>
                   <button type="submit" name="delete_quiz" class="btn btn-accent">Xóa vĩnh viễn</button>
               </div>
           </form>
       </div>
   </div>

   <!-- Modal chia sẻ -->
   <div id="share-modal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">Chia sẻ bộ câu hỏi</h3>
               <button class="modal-close" onclick="closeModal('share-modal')">&times;</button>
           </div>
           <p>Sao chép liên kết dưới đây để chia sẻ bộ câu hỏi này với người khác:</p>
           <div class="share-link">
               <input type="text" id="share-link-input" readonly>
               <button onclick="copyShareLink()"><i class="fas fa-copy"></i></button>
           </div>
           <p class="note">Lưu ý: Người nhận sẽ nhận được một bản sao của bộ câu hỏi này.</p>
       </div>
   </div>

   <!-- Modal import câu hỏi vào quizlet đã có -->
   <div id="import-to-existing-modal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">Nạp câu hỏi vào Quizlet đã có</h3>
               <button class="modal-close" onclick="closeModal('import-to-existing-modal')">&times;</button>
           </div>
           <form method="POST" action="" enctype="multipart/form-data">
               <input type="hidden" name="import_to_existing" value="1">
               <div class="form-group">
                   <label for="target_quiz_id">Chọn bài Quizlet</label>
                   <select id="target_quiz_id" name="target_quiz_id" class="form-control" required>
                       <option value="">-- Chọn bài quizlet --</option>
                       <?php
                    // Reset con trỏ kết quả để lấy lại danh sách bài quizlet
                    mysqli_data_seek($result, 0);
                    while ($quiz = mysqli_fetch_assoc($result)) {
                        echo '<option value="' . $quiz['id'] . '">' . htmlspecialchars($quiz['name']) . ' (' . $quiz['question_count'] . ' câu hỏi)</option>';
                    }
                    ?>
                   </select>
               </div>
               <p>Tải lên file CSV với định dạng: cột 1 = câu hỏi, cột 2-5 = các phương án, cột 6 = đáp án đúng (1-4), cột 7 = giải thích (tùy chọn)</p>
               <div class="form-group">
                   <label>Chọn file CSV</label>
                   <div class="file-input-wrapper">
                       <div class="file-input">
                           <i class="fas fa-file-csv"></i> Chọn file CSV 
                           <input type="file" name="csv_file" accept=".csv" required>
                       </div>
                   </div>
               </div>
               <div class="import-options">
                   <div class="checkbox-group">
                       <input type="checkbox" id="has_header" name="has_header" value="1" checked>
                       <label for="has_header">File có dòng tiêu đề</label>
                   </div>
                   <p class="note">Lưu ý: Các câu hỏi đã tồn tại sẽ không bị ghi đè.</p>
               </div>
               <div class="form-actions">
                   <button type="button" class="btn btn-secondary" onclick="closeModal('import-to-existing-modal')">Hủy</button>
                   <button type="submit" class="btn btn-primary">Nạp câu hỏi</button>
               </div>
               <a href="#" onclick="downloadTemplate()" class="template-link">
                   <i class="fas fa-download"></i> Tải mẫu CSV
               </a>
           </form>
       </div>
   </div>

   <!-- Modal nhập hàng loạt từ link -->
   <div id="bulk-import-modal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">Nhập bài học hàng loạt</h3>
               <button class="modal-close" onclick="closeModal('bulk-import-modal')">&times;</button>
           </div>
           <form method="POST" action="">
               <p>Dán các link chia sẻ bài học vào ô bên dưới (mỗi link một dòng):</p>
               
               <div class="form-group">
                   <textarea name="import_links" class="form-control" rows="8" placeholder="https://umters.club/quizlet.php?import=11&token=dd23f1c13dfdd31a47efee0b5f69c779&#10;https://umters.club/quizlet.php?import=12&token=ae03e1fe679f9cf5beebcecfe6f1fc0b" required></textarea>
               </div>
               
               <div class="form-actions">
                   <button type="button" class="btn btn-secondary" onclick="closeModal('bulk-import-modal')">Hủy</button>
                   <button type="submit" name="bulk_import" class="btn btn-primary">Nhập bài học</button>
               </div>
           </form>
           
           <?php if (isset($_SESSION['import_results']) && !empty($_SESSION['import_results'])): ?>
           <div class="import-results">
               <h4>Kết quả nhập bài học</h4>
               <div class="results-container">
                   <table class="results-table">
                       <thead>
                           <tr>
                               <th>Link</th>
                               <th>Kết quả</th>
                           </tr>
                       </thead>
                       <tbody>
                           <?php foreach ($_SESSION['import_results'] as $result): ?>
                           <tr>
                               <td>
                                   <?php echo htmlspecialchars(substr($result['link'], 0, 50) . (strlen($result['link']) > 50 ? '...' : '')); ?>
                               </td>
                               <td class="<?php echo $result['success'] ? 'success' : 'error'; ?>">
                                   <?php echo htmlspecialchars($result['message']); ?>
                               </td>
                           </tr>
                           <?php endforeach; ?>
                       </tbody>
                   </table>
               </div>
               <div style="margin-top: 1.5rem; text-align: right;">
                   <button class="btn btn-secondary btn-sm" onclick="clearImportResults()">
                       <i class="fas fa-trash-alt"></i> Xóa kết quả
                   </button>
               </div>
           </div>
           <?php endif; ?>
       </div>
   </div>

   <script>
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
    
    // Hiển thị/ẩn menu
    function toggleMenu(quizId) {
        const menu = document.getElementById('menu-' + quizId);
        
        // Đóng tất cả các menu khác
        const allMenus = document.querySelectorAll('.quiz-card-menu-dropdown');
        allMenus.forEach(m => {
            if (m.id !== 'menu-' + quizId) {
                m.classList.remove('active');
            }
        });
        
        // Hiển thị/ẩn menu hiện tại
        menu.classList.toggle('active');
        
        // Đóng menu khi click bên ngoài
        document.addEventListener('click', function closeMenuOutside(e) {
            if (!e.target.closest('.quiz-card-menu')) {
                menu.classList.remove('active');
                document.removeEventListener('click', closeMenuOutside);
            }
        });
    }
    
    // Xác nhận xóa
    function confirmDelete(quizId, quizName) {
        document.getElementById('delete_quiz_id').value = quizId;
        document.getElementById('delete-quiz-name').textContent = quizName;
        openModal('delete-modal');
    }
    
    // Mở modal chia sẻ
    function openShareModal(quizId, shareToken) {
        const shareLink = window.location.origin + window.location.pathname + '?import=' + quizId + '&token=' + shareToken;
        document.getElementById('share-link-input').value = shareLink;
        openModal('share-modal');
    }
    
    // Sao chép link chia sẻ
    function copyShareLink() {
        const shareInput = document.getElementById('share-link-input');
        shareInput.select();
        document.execCommand('copy');
        
        // Hiển thị thông báo đã sao chép
        const button = document.querySelector('.share-link button i');
        button.className = 'fas fa-check';
        setTimeout(() => {
            button.className = 'fas fa-copy';
        }, 2000);
    }
    
    // Tải mẫu CSV
    function downloadTemplate() {
        // Tạo nội dung mẫu CSV
        const csvContent = "Question,Option 1,Option 2,Option 3,Option 4,Correct Answer (1-4),Explanation (Optional)\n" +
                          "What is the capital of France?,Paris,London,Berlin,Madrid,1,Paris is the capital of France\n" +
                          "Which planet is known as the Red Planet?,Earth,Mars,Jupiter,Venus,2,Mars is known as the Red Planet due to its reddish appearance";
        
        // Tạo blob và link tải xuống
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement("a");
        
        // Tạo URL cho blob
        const url = URL.createObjectURL(blob);
        
        // Thiết lập thuộc tính cho link
        link.setAttribute("href", url);
        link.setAttribute("download", "quiz_template.csv");
        link.style.visibility = 'hidden';
        
        // Thêm link vào DOM
        document.body.appendChild(link);
        
        // Click vào link để tải xuống
        link.click();
        
        // Xóa link
        document.body.removeChild(link);
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

    // Xóa kết quả nhập
    function clearImportResults() {
        // Gửi AJAX request để xóa kết quả nhập trong session
        fetch('clear_import_results.php', {
            method: 'POST'
        })
        .then(response => {
            // Reload trang sau khi xóa kết quả
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    // Tự động ẩn thông báo sau 5 giây
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        if (alerts.length > 0) {
            setTimeout(() => {
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        }
    });
</script>
</body>
</html>
